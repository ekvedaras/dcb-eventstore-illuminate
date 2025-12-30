<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests\Integration;

use EKvedaras\DCBEventStoreIlluminate\CommitRetries\BackoffExponentially;
use EKvedaras\DCBEventStoreIlluminate\IlluminateEventStoreConfiguration;
use EKvedaras\DCBEventStoreIlluminate\Tests\OrchestraTestBench;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\CoversClass;
use EKvedaras\DCBEventStoreIlluminate\IlluminateEventStore;

use Webmozart\Assert\Assert;

use Wwwision\DCBEventStore\Tests\Integration\EventStoreTestBase;

#[CoversClass(IlluminateEventStore::class)]
final class IlluminateEventStoreTest extends EventStoreTestBase
{
    use OrchestraTestBench;

    protected function createEventStore(): IlluminateEventStore
    {
        $connection = DB::connection('testing');
        Assert::isInstanceOf($connection, Connection::class);
        $eventStoreConfig = IlluminateEventStoreConfiguration::create($connection, config('dcb_event_store.events_table_name'))
            ->withClock($this->getTestClock())
            ->withBackoffStrategy(
                new BackoffExponentially(
                    maxAttempts: 20,
                    rate:        4,
                )
            );
        $eventStore = new IlluminateEventStore($eventStoreConfig);
        $connection->table(config('dcb_event_store.events_table_name'))->truncate();

        Sleep::fake();

        return $eventStore;
    }
}