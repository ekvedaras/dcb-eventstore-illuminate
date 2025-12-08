<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\CommitRetries;

use Carbon\CarbonInterval;

interface BackoffStrategy
{
    public function canRetry(int $exhaustedAttempts): bool;

    /** @param non-negative-int $exhaustedAttempts */
    public function getNextRetryWaitTime(?CarbonInterval $current, int $exhaustedAttempts): CarbonInterval;
}
