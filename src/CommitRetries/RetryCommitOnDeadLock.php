<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\CommitRetries;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConcurrencyErrorDetector as PdoCodeOrMessageBasedDeadLockDetector;
use Throwable;

final readonly class RetryCommitOnDeadLock implements CommitRetryStrategy
{
    public function __construct(
        private ConcurrencyErrorDetector $detector = new PdoCodeOrMessageBasedDeadLockDetector(),
    ) {
    }

    public function shouldRetry(Throwable $exception): bool
    {
        return $this->detector->causedByConcurrencyError($exception);
    }
}
