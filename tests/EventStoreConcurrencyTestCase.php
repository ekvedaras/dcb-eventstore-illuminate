<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests;

use Wwwision\DCBEventStore\Tests\Integration\EventStoreConcurrencyTestBase;


abstract class EventStoreConcurrencyTestCase extends EventStoreConcurrencyTestBase
{
    use OrchestraTestBench;
}