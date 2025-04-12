<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests\Integration;

use EKvedaras\DCBEventStoreIlluminate\IlluminateEventStore;
use EKvedaras\DCBEventStoreIlluminate\Tests\EventStoreConcurrencyTestCase;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversNothing;
use Webmozart\Assert\Assert;
use Wwwision\DCBEventStore\EventStore;
use function getenv;
use function is_string;
use const PHP_EOL;

#[CoversNothing]
final class ConcurrencyTest extends EventStoreConcurrencyTestCase
{
    private static ?IlluminateEventStore $eventStore = null;
    private static ?Connection $connection = null;

    public static function prepare(): void
    {
        $connection = self::connection();
        $eventStore = self::createEventStore();
        $eventStore->setup();
        $connection->table(self::eventTableName())->truncate();

        echo PHP_EOL . 'Prepared tables for ' . $connection::class . PHP_EOL;
    }

    public static function cleanup(): void
    {
        $connection = self::connection();
        $connection->table(self::eventTableName())->truncate();
    }

    protected static function createEventStore(): EventStore
    {
        if (self::$eventStore === null) {
            self::$eventStore = IlluminateEventStore::create(self::connection(), self::eventTableName());
        }
        return self::$eventStore;
    }

    private static function connection(): Connection
    {
        if (self::$connection === null) {
            require __DIR__ . '/../../vendor/laravel/framework/src/Illuminate/Support/helpers.php';
            require __DIR__ . '/../../vendor/laravel/framework/src/Illuminate/Collections/helpers.php';

            $app = new Application();

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

            $app->instance('config', new Repository(['database' => [
                'default' => 'testing',
                'connections' => ['testing' => $config],
            ]]));
            $app->register(DatabaseServiceProvider::class);
            Facade::setFacadeApplication($app);
            DB::setFacadeApplication($app);

            $connection = DB::connectUsing('testing', $config);
            Assert::isInstanceOf($connection, Connection::class);

            self::$connection = $connection;
        }
        return self::$connection;
    }

    private static function eventTableName(): string
    {
        return 'dcb_events_test';
    }

}