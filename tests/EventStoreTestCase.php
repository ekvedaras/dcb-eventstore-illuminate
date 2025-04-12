<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Override;
use Wwwision\DCBEventStore\Tests\Integration\EventStoreTestBase;
use Illuminate\Foundation\Testing;
use \Orchestra\Testbench\Concerns;

abstract class EventStoreTestCase extends EventStoreTestBase
{
    use OrchestraTestBench;
}