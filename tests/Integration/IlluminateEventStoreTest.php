<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests\Integration;

use EKvedaras\DCBEventStoreIlluminate\Tests\EventStoreTestCase;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use EKvedaras\DCBEventStoreIlluminate\IlluminateEventStore;

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
            $dsn = 'sqlite://test.sqlite';
            $config = [
                'driver' => 'sqlite',
                'database' => str($dsn)->after('://')->toString(),
            ];
        } else {
            $config = [
                'driver' => str($dsn)->before('://')->toString(),
                'database' => $dsn,
            ];
        }
        $connection = DB::connectUsing('testing', $config);
        $eventStore = IlluminateEventStore::create($connection, $eventTableName);
        $eventStore->setup();
        if ($connection instanceof SQLiteConnection) {
            $connection->statement('DELETE FROM ' . $eventTableName);
            $connection->statement('UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME="' . $eventTableName . '"');
        } elseif ($connection instanceof PostgresConnection) {
            $connection->statement('TRUNCATE TABLE ' . $eventTableName . ' RESTART IDENTITY');
        } else {
            $connection->statement('TRUNCATE TABLE ' . $eventTableName);
        }
        return $eventStore;
    }

}