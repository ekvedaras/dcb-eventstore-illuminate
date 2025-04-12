<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests\Integration;

use EKvedaras\DCBEventStoreIlluminate\Tests\EventStoreTestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use EKvedaras\DCBEventStoreIlluminate\IlluminateEventStore;

use Webmozart\Assert\Assert;

use function getenv;
use function is_string;

#[CoversClass(IlluminateEventStore::class)]
final class IlluminateEventStoreTest extends EventStoreTestCase
{
    protected function createEventStore(): IlluminateEventStore
    {
        $eventTableName = 'dcb_events_test';

        $dsn = getenv('DCB_TEST_DSN');
        if (!is_string($dsn)) {
            $config = [
                'driver' => 'sqlite',
                'database' => 'test.sqlite',
            ];
        } else {
            $config = [
                'driver' => str($dsn)->before('://')->toString(),
                'url' => str($dsn)->beforeLast('/')->toString(),
                'database' => str($dsn)->afterLast('/')->toString(),
            ];
        }
        $connection = DB::connectUsing('testing', $config);
        Assert::isInstanceOf($connection, Connection::class);
        $eventStore = IlluminateEventStore::create($connection, $eventTableName);
        $eventStore->setup();
        $connection->table($eventTableName)->truncate();

        return $eventStore;
    }

}