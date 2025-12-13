<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests\CommitRetries;

use Carbon\CarbonInterval;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\BackoffLinearly;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackoffLinearly::class)]
final class BackoffLinearlyTest extends TestCase
{
    public function test_can_retry_until_max_attempts(): void
    {
        $strategy = new BackoffLinearly(maxAttempts: 2);

        self::assertTrue($strategy->canRetry(0));
        self::assertTrue($strategy->canRetry(1));
        self::assertFalse($strategy->canRetry(2));
    }

    public function test_wait_time_grows_linearly(): void
    {
        $initialInterval = CarbonInterval::milliseconds(200);
        $strategy = new BackoffLinearly(
            maxAttempts: 4,
            waitInitiallyFor: $initialInterval,
            waitTimeMultiplier: 2,
        );

        $firstWait = $strategy->getNextRetryWaitTime(current: null, exhaustedAttempts: 0);
        $secondWait = $strategy->getNextRetryWaitTime(current: clone $firstWait, exhaustedAttempts: 1);
        $thirdWait = $strategy->getNextRetryWaitTime(current: clone $secondWait, exhaustedAttempts: 2);

        self::assertSame(200, (int) $firstWait->totalMilliseconds);
        self::assertSame(400, (int) $secondWait->totalMilliseconds);
        self::assertSame(800, (int) $thirdWait->totalMilliseconds);
    }
}