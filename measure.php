<?php

declare(strict_types=1);

// This probe must never expose authenticated pages or database timings over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Resident;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

define('LARAVEL_START', microtime(true));
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$bootStarted = hrtime(true);
$app->make(ConsoleKernel::class)->bootstrap();
$kernel = $app->make(Kernel::class);
$bootstrapMs = (hrtime(true) - $bootStarted) / 1e6;

// Exercise the real web middleware without writing benchmark sessions to the
// application's session database/files. The application cache is not flushed.
config(['session.driver' => 'array', 'app.debug' => false]);
$warmRuns = 3;
$queryCount = 0;
DB::listen(function () use (&$queryCount): void {
    $queryCount++;
});

/** @return array{ms: float, queries: int, value: mixed, error: ?Throwable} */
function probeSample(callable $callback): array
{
    global $queryCount;
    $before = $queryCount;
    $start = hrtime(true);
    $value = null;
    $error = null;
    try {
        $value = $callback();
    } catch (Throwable $exception) {
        $error = $exception;
    }

    return ['ms' => (hrtime(true) - $start) / 1e6, 'queries' => $queryCount - $before, 'value' => $value, 'error' => $error];
}

/** @return array{cold: array, warm: array} */
function probeBenchmark(callable $callback): array
{
    global $warmRuns;
    $cold = probeSample($callback);
    $warm = [];
    if ($cold['error'] === null) {
        for ($run = 0; $run < $warmRuns; $run++) {
            $warm[] = probeSample($callback);
        }
        usort($warm, fn (array $left, array $right) => $left['ms'] <=> $right['ms']);
    }

    return ['cold' => $cold, 'warm' => $warm === [] ? $cold : $warm[intdiv(count($warm), 2)]];
}

function probeTable(array $headers, array $rows): void
{
    $widths = array_map('strlen', $headers);
    foreach ($rows as $row) {
        foreach ($row as $column => $value) {
            $widths[$column] = max($widths[$column], strlen((string) $value));
        }
    }
    $line = '+-'.implode('-+-', array_map(fn (int $width) => str_repeat('-', $width), $widths)).'-+'.PHP_EOL;
    echo $line;
    foreach ([$headers, ...$rows] as $index => $row) {
        echo '| '.implode(' | ', array_map(fn ($value, $column) => str_pad((string) $value, $widths[$column]), $row, array_keys($row))).' |'.PHP_EOL;
        if ($index === 0) {
            echo $line;
        }
    }
    echo $line;
}

function probeRow(string $label, array $result): array
{
    $cold = $result['cold'];
    $warm = $result['warm'];
    $error = $cold['error'] ?? $warm['error'];
    // Do not print connection strings, query bindings, or exception messages.
    $status = $error ? 'ERROR: '.class_basename($error) : 'OK';
    if ($cold['value'] instanceof Response && $warm['value'] instanceof Response) {
        $status = $cold['value']->getStatusCode().'/'.$warm['value']->getStatusCode();
    }

    return [$label, number_format($cold['ms'], 2, '.', ''), $error ? '-' : number_format($warm['ms'], 2, '.', ''), $cold['queries'].'/'.$warm['queries'], $status];
}

function probeRequest(string $path, ?int $staffId, ?int $residentId, array $partial = []): Response
{
    global $app, $kernel;

    // Reset request-local authentication/session state between simulated visits.
    Auth::forgetGuards();
    $app['session']->forgetDrivers();
    $app->forgetInstance('session.store');
    $session = $app['session']->driver();
    $session->start();
    if ($staffId !== null) {
        $session->put(Auth::guard('web')->getName(), $staffId);
    }
    if ($residentId !== null) {
        $session->put('resident_id', $residentId);
    }
    $sessionId = $session->getId();
    $session->save();
    Auth::forgetGuards();

    $cookie = $app['encrypter']->encrypt(
        CookieValuePrefix::create($session->getName(), $app['encrypter']->getKey()).$sessionId,
        false,
    );

    $request = Request::create(rtrim((string) config('app.url'), '/').$path, 'GET', [], [$session->getName() => $cookie], [], [
        'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_X_INERTIA' => 'true',
    ]);
    $request->headers->set('X-Inertia-Version', (string) $app->make(HandleInertiaRequests::class)->version($request));
    foreach ($partial as $header => $value) {
        $request->headers->set($header, $value);
    }

    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}

echo 'KaBarangay performance probe'.PHP_EOL;
echo 'Bootstrap: '.number_format($bootstrapMs, 2).' ms; warm = median of '.$warmRuns.' repeats.'.PHP_EOL;
echo 'Bootstrap is measured once and excluded from the route rows below.'.PHP_EOL;
echo 'Cold = first call in this process; shared application/database caches are NOT flushed.'.PHP_EOL;
echo 'Deferred props: '.(config('inertia.defer_heavy_props', true) ? 'on' : 'off').PHP_EOL;
echo 'Routes use the real HTTP kernel, existing active accounts, and isolated in-memory sessions.'.PHP_EOL;
echo 'No business records are created or changed; normal application-cache fills may occur.'.PHP_EOL.PHP_EOL;

$connectionName = (string) config('database.default');
DB::purge($connectionName);
$connection = DB::connection($connectionName);
$driver = $connection->getDriverName();
$persistent = (bool) ($connection->getConfig('options')[PDO::ATTR_PERSISTENT] ?? false);
echo 'Database: '.$connectionName.' ('.$driver.'); persistent PDO: '.($persistent ? 'on' : 'off').PHP_EOL;
if ($driver !== 'pgsql') {
    echo 'NOTE: This is the configured database, not a PostgreSQL/Supabase benchmark.'.PHP_EOL;
}

$connect = probeBenchmark(fn () => $connection->getPdo());
$databaseRows = [probeRow('Acquire PDO connection', $connect)];
if ($connect['cold']['error'] !== null) {
    probeTable(['Database operation', 'Cold ms', 'Warm ms', 'SQL C/W', 'Result'], $databaseRows);
    echo 'Connection failed; database-backed routes cannot be benchmarked. Check Laravel database configuration.'.PHP_EOL;
    exit(1);
}
$databaseRows[] = probeRow('SELECT 1 round trip', probeBenchmark(fn () => $connection->select('SELECT 1')));

try {
    $staff = DB::table('users')->where('status', 'active')->whereNotNull('barangay_id')
        ->selectRaw('role, MIN(id) as id')->groupBy('role')->pluck('id', 'role');
    $residentId = Resident::query()->where('status', 'active')
        ->whereHas('account', fn ($query) => $query->where('is_active', true))
        ->orderBy('id')->value('id');
    $barangayId = DB::table('users')->where('status', 'active')->whereNotNull('barangay_id')->orderBy('id')->value('barangay_id');
    if ($barangayId !== null) {
        $databaseRows[] = probeRow('Scoped active-resident COUNT', probeBenchmark(fn () => $connection->table('residents')
            ->where('barangay_id', $barangayId)->where('status', 'active')->count()));
    }
} catch (Throwable $exception) {
    probeTable(['Database operation', 'Cold ms', 'Warm ms', 'SQL C/W', 'Result'], $databaseRows);
    echo 'Account/table discovery failed ('.class_basename($exception).'); route benchmarks are unavailable. Check application configuration/logs.'.PHP_EOL;
    exit(1);
}
probeTable(['Database operation', 'Cold ms', 'Warm ms', 'SQL C/W', 'Result'], $databaseRows);

$routes = [
    ['Admin Dashboard', '/admin', $staff['admin'] ?? null, null],
    ['Resident Dashboard', '/resident', null, $residentId],
    ['Resident Profile', '/resident/profile', null, $residentId],
    ['Clerk Dashboard', '/clerk/dashboard', $staff['clerk'] ?? null, null],
    ['Clerk Certificates', '/clerk/certificates', $staff['clerk'] ?? null, null],
    ['Lupon Cases', '/lupon/cases', $staff['lupon'] ?? null, null],
    ['Senior Dashboard', '/senior/dashboard', $staff['senior'] ?? $staff['senior_staff'] ?? null, null],
    ['PWD Dashboard', '/pwd/dashboard', $staff['pwd'] ?? $staff['pwd_staff'] ?? null, null],
];
$routeRows = [];
$failed = false;
$shells = [];
foreach ($routes as [$label, $path, $staffId, $resident]) {
    if ($staffId === null && $resident === null) {
        $routeRows[] = [$label, '-', '-', '-', 'SKIP: no active account'];

        continue;
    }

    $staffId = $staffId === null ? null : (int) $staffId;
    $resident = $resident === null ? null : (int) $resident;
    $shell = probeBenchmark(fn () => probeRequest($path, $staffId, $resident));
    $routeRows[] = probeRow($label.' shell', $shell);
    $response = $shell['cold']['value'];
    if (! $response instanceof Response || $response->getStatusCode() !== 200 || $shell['warm']['error'] !== null || $shell['warm']['value']->getStatusCode() !== 200) {
        $failed = true;

        continue;
    }
    $shells[] = $shell;
    $page = json_decode((string) $response->getContent(), true);
    if (! is_array($page) || ! isset($page['component'], $page['props'])) {
        $failed = true;
        $routeRows[] = [$label.' deferred', '-', '-', '-', 'ERROR: not an Inertia response'];

        continue;
    }
    foreach ($page['deferredProps'] ?? [] as $group => $keys) {
        $deferred = probeBenchmark(fn () => probeRequest($path, $staffId, $resident, [
            'X-Inertia-Partial-Component' => $page['component'],
            'X-Inertia-Partial-Data' => implode(',', $keys),
        ]));
        $routeRows[] = probeRow($label.' data:'.$group, $deferred);
        foreach ([$deferred['cold'], $deferred['warm']] as $sample) {
            $failed = $failed || $sample['error'] !== null || ! $sample['value'] instanceof Response || $sample['value']->getStatusCode() !== 200;
        }
    }
}

echo PHP_EOL.'Route timings (initial shell and subsequent deferred groups are separate requests)'.PHP_EOL;
probeTable(['Page / request', 'Cold ms', 'Warm ms', 'SQL C/W', 'HTTP C/W'], $routeRows);
$fast = count(array_filter($shells, fn (array $sample) => $sample['warm']['ms'] < 1000));
echo $fast.'/'.count($shells).' measured page shells have warm server latency below 1,000 ms.'.PHP_EOL;
echo 'These are in-process server + database timings, not browser click-to-paint measurements.'.PHP_EOL;
echo 'Browser networking, JS loading, rendering, and client-side prefetch are not included.'.PHP_EOL;
exit($failed ? 1 : 0);
