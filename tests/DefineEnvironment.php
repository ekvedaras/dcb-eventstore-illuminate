<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests;

trait DefineEnvironment
{
    protected static $eventTableName = 'dcb_events_test';

    protected static function defineEnvironment($app)
    {
        $dsn = getenv('DCB_TEST_DSN');
        if (!is_string($dsn)) {
            $config = [
                'driver' => 'sqlite',
                'database' => 'test.sqlite',
            ];
        } else {
            $parts = parse_url($dsn);
            $config = [
                'driver' => $parts['scheme'],
                'host' => $parts['host'],
                'port' => $parts['port'] ?? null,
                'database' => str($parts['path'] ?? $dsn)->afterLast('/')->toString(),
                'username' => $parts['user'] ?? null,
                'password' => $parts['pass'] ?? null,
            ];
        }

        $app['config']->set('dcb_event_store.events_table_name', self::$eventTableName);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $config);
    }
}