<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests\CommitRetries;

use Carbon\CarbonInterval;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\BackoffExponentially;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackoffExponentially::class)]
final class BackoffExponentiallyTest extends TestCase
{
    public function test_can_retry_until_max_attempts(): void
    {
        $strategy = new BackoffExponentially(maxAttempts: 3);

        self::assertTrue($strategy->canRetry(0));
        self::assertTrue($strategy->canRetry(2));
        self::assertFalse($strategy->canRetry(3));
    }

    public function test_wait_time_grows_exponentially(): void
    {
        $waitInitiallyFor = CarbonInterval::milliseconds(100);
        $strategyFactory = static fn (): BackoffExponentially => new BackoffExponentially(
            maxAttempts: 5,
            waitInitiallyFor: clone $waitInitiallyFor,
            rate: 3,
        );

        $firstWait = $strategyFactory()->getNextRetryWaitTime(current: null, exhaustedAttempts: 0);
        $secondWait = $strategyFactory()->getNextRetryWaitTime(current: null, exhaustedAttempts: 1);
        $thirdWait = $strategyFactory()->getNextRetryWaitTime(current: null, exhaustedAttempts: 2);

        self::assertSame(100, (int) $firstWait->totalMilliseconds);
        self::assertSame(300, (int) $secondWait->totalMilliseconds);
        self::assertSame(900, (int) $thirdWait->totalMilliseconds);
    }
}