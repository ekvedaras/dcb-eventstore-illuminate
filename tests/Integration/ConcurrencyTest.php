<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests\Integration;

use EKvedaras\DCBEventStoreIlluminate\IlluminateEventStore;
use EKvedaras\DCBEventStoreIlluminate\Tests\DefineEnvironment;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Webmozart\Assert\Assert;
use Wwwision\DCBEventStore\EventStore;
use Wwwision\DCBEventStore\Tests\Integration\EventStoreConcurrencyTestBase;

use const PHP_EOL;

#[CoversNothing]
final class ConcurrencyTest extends EventStoreConcurrencyTestBase
{
    use DefineEnvironment;

    private static ?IlluminateEventStore $eventStore = null;
    private static ?Connection $connection = null;

    public static function prepare(): void
    {
        $connection = self::connection();
        self::createEventStore();
        $connection->table(self::$eventTableName)->truncate();

        echo PHP_EOL . 'Prepared tables for ' . $connection::class . PHP_EOL;
    }

    public static function cleanup(): void
    {
        $connection = self::connection();
        $connection->table(self::$eventTableName)->truncate();
    }

    protected static function createEventStore(): EventStore
    {
        if (self::$eventStore === null) {
            self::$eventStore = IlluminateEventStore::create(self::connection(), self::$eventTableName);
        }
        return self::$eventStore;
    }

    private static function connection(): Connection
    {
        if (self::$connection === null) {
            if (file_exists(__DIR__ . '/../../vendor/symfony/polyfill-php85/bootstrap.php')) {
                require __DIR__ . '/../../vendor/symfony/polyfill-php85/bootstrap.php';
            }
            require __DIR__ . '/../../vendor/laravel/framework/src/Illuminate/Support/helpers.php';
            require __DIR__ . '/../../vendor/laravel/framework/src/Illuminate/Collections/helpers.php';
            if (file_exists(__DIR__ . '/../../vendor/laravel/framework/src/Illuminate/Collections/functions.php')) {
                require __DIR__ . '/../../vendor/laravel/framework/src/Illuminate/Collections/functions.php';
            }

            $app = new Application();
            $app->instance('config', new Repository([]));
            $app->register(DatabaseServiceProvider::class);
            Facade::setFacadeApplication($app);
            DB::setFacadeApplication($app);

            self::defineEnvironment($app);

            $connection = DB::connection('testing',);
            Assert::isInstanceOf($connection, Connection::class);

            self::$connection = $connection;
        }
        return self::$connection;
    }

    #[Test]
    #[Group('validate')]
    public function validate(): void
    {
        self::validateEvents();
    }
}
