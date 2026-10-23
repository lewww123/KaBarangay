<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const ISOLATED_TEST_ENVIRONMENT = [
        'APP_CONFIG_CACHE'     => 'bootstrap/cache/phpunit-config-cache-disabled.php',
        'APP_ENV'              => 'testing',
        'DB_CONNECTION'        => 'sqlite',
        'DB_DATABASE'          => ':memory:',
        'DB_URL'               => '',
        'CACHE_STORE'          => 'array',
        'QUEUE_CONNECTION'     => 'sync',
        'SESSION_DRIVER'       => 'array',
        'MAIL_MAILER'          => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'BCRYPT_ROUNDS'        => '4',
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

        return $app;
    }

    private static function assertIsolatedTestDatabase(Application $app): void
    {
        $fromCache   = $app->make('config_loaded_from_cache') === true;
        $name        = (string) $app['config']->get('database.default');
        $connection  = (array) $app['config']->get('database.connections.'.$name, []);
        $driver      = isset($connection['driver']) ? (string) $connection['driver'] : 'missing';
        $isInMemory  = ($connection['database'] ?? null) === ':memory:';
        $hasEmptyUrl = ! isset($connection['url']) || '' === $connection['url'];

        if ($fromCache || 'sqlite' !== $driver || ! $isInMemory || ! $hasEmptyUrl) {
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
