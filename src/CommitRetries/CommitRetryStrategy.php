<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\CommitRetries;

use Throwable;

interface CommitRetryStrategy
{
    public function shouldRetry(Throwable $exception): bool;
}
