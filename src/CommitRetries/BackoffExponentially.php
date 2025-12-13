<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\CommitRetries;

use Carbon\CarbonInterval;

final readonly class BackoffExponentially implements BackoffStrategy
{
    public function __construct(
        /** @var positive-int */
        public int $maxAttempts = 10,
        public CarbonInterval $waitInitiallyFor = new CarbonInterval(seconds: 0.1),
        /** @var positive-int|float */
        public int|float $rate = 3,
    ) {
    }

    public function canRetry(int $exhaustedAttempts): bool
    {
        return $exhaustedAttempts < $this->maxAttempts;
    }

    public function getNextRetryWaitTime(?CarbonInterval $current, int $exhaustedAttempts): CarbonInterval
    {
        return $this->waitInitiallyFor->multiply($this->rate ** $exhaustedAttempts);
    }
}
