<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const STAFF_HOST = 'https://staff.kabarangay.com';

    protected function assertSourceContainsString(string $needle, string $source, string $message = ''): void
    {
        $this->assertStringContainsString($this->compactSource($needle), $this->compactSource($source), $message);
    }

    protected function compactSource(string $source): string
    {
        // Source contract checks must tolerate line wrapping and trailing commas.
        $source = str_replace(["{' '}", '{" "}'], ' ', $source);

        return preg_replace('/,([)\]}])/', '$1', preg_replace('/\s+/', '', $source));
    }

    private const ISOLATED_TEST_ENVIRONMENT = [
        'APP_CONFIG_CACHE' => 'bootstrap/cache/phpunit-config-cache-disabled.php',
        'APP_ENV' => 'testing',
        'APP_URL' => self::STAFF_HOST,
        'STAFF_DOMAIN' => 'staff.kabarangay.com',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'BCRYPT_ROUNDS' => '4',
    ];

    public function createApplication()
    {
        foreach (self::ISOLATED_TEST_ENVIRONMENT as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $app = parent::createApplication();

        self::assertIsolatedTestDatabase($app);

        // HTTP feature tests exercise PHP responses without a separate Node SSR service.
        $app['config']->set('inertia.ssr.enabled', false);

        return $app;
    }

    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if ($user instanceof \App\Models\User && ! session()->has('_without_staff_assignment')) {
            $assignment = $user->staffAssignments()->where('is_active', true)->first();
            if ($assignment) {
                session(['staff_assignment_id' => $assignment->id]);
            } else {
                session()->forget('staff_assignment_id');
            }
        }

        return $this;
    }

    protected function prepareUrlForRequest($uri): string
    {
        if (is_string($uri) && ($uri === '/' || $uri === '/login' || $uri === '/logout' || preg_match('#^/(?:admin|clerk|lupon|pwd|senior|api/qr)(?:/|$)#', $uri) === 1)) {
            $uri = self::STAFF_HOST.$uri;
        }

        return parent::prepareUrlForRequest($uri);
    }

    private static function assertIsolatedTestDatabase(Application $app): void
    {
        $fromCache = $app->make('config_loaded_from_cache') === true;
        $name = (string) $app['config']->get('database.default');
        $connection = (array) $app['config']->get('database.connections.'.$name, []);
        $driver = isset($connection['driver']) ? (string) $connection['driver'] : 'missing';
        $isInMemory = ($connection['database'] ?? null) === ':memory:';
        $hasEmptyUrl = ! isset($connection['url']) || $connection['url'] === '';

        if ($fromCache || $driver !== 'sqlite' || ! $isInMemory || ! $hasEmptyUrl) {
            throw new RuntimeException(
                'Test database isolation guard failed: the test database must be an in-memory SQLite '
                .'database loaded from config files, not from the application config cache. '
                .sprintf(
                    'Actual driver: "%s"; in-memory: %s; empty DB URL: %s; loaded from config cache: %s. '
                    .'No database queries were executed. Verify that phpunit.xml declares '
                    .'<env ... force="true"> for DB_CONNECTION, DB_DATABASE, DB_URL and APP_CONFIG_CACHE, '
                    .'and that no inherited environment variable overrides them.',
                    $driver,
                    $isInMemory ? 'yes' : 'no',
                    $hasEmptyUrl ? 'yes' : 'no',
                    $fromCache ? 'yes' : 'no'
                )
            );
        }
    }
}
