<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use EKvedaras\DCBEventStoreIlluminate\CommitRetries\BackoffExponentially;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\BackoffStrategy;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\CommitRetryStrategy;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\RetryCommitOnDeadLock;
use Illuminate\Database\Connection;
use Psr\Clock\ClockInterface;
use Wwwision\DCBEventStore\Helpers\SystemClock;

final readonly class IlluminateEventStoreConfiguration
{
    public function __construct(
        public Connection $connection,
        public string $eventTableName,
        public ClockInterface $clock,
        public CommitRetryStrategy $commitRetryStrategy = new RetryCommitOnDeadLock(),
        public BackoffStrategy $backoffStrategy = new BackoffExponentially(),
    ) {
    }

    public static function create(Connection $connection, string $eventTableName): self
    {
        return new self(
            connection:     $connection,
            eventTableName: $eventTableName,
            clock:          new SystemClock(),
        );
    }

    public function withClock(ClockInterface $clock): self
    {
        return new self(
            connection: $this->connection,
            eventTableName: $this->eventTableName,
            clock: $clock,
            commitRetryStrategy: $this->commitRetryStrategy,
            backoffStrategy: $this->backoffStrategy,
        );
    }

    public function withCommitRetryStrategy(CommitRetryStrategy $strategy): self
    {
        return new self(
            connection:          $this->connection,
            eventTableName:      $this->eventTableName,
            clock:               $this->clock,
            commitRetryStrategy: $strategy,
            backoffStrategy:     $this->backoffStrategy,
        );
    }

    public function withBackoffStrategy(BackoffStrategy $strategy): self
    {
        return new self(
            connection:          $this->connection,
            eventTableName:      $this->eventTableName,
            clock:               $this->clock,
            commitRetryStrategy: $this->commitRetryStrategy,
            backoffStrategy:     $strategy,
        );
    }
}
