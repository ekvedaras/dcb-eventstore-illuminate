<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Psr\Clock\ClockInterface;
use Wwwision\DCBEventStore\Helpers\SystemClock;

final class IlluminateEventStoreConfiguration
{
    private int $dynamicParameterCount = 0;

    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $eventTableName,
        public readonly ClockInterface $clock,
    ) {
    }

    public static function create(ConnectionInterface $connection, string $eventTableName): self
    {
        return new self(
            $connection,
            $eventTableName,
            new SystemClock(),
        );
    }

    public function withClock(ClockInterface $clock): self
    {
        return new self($this->connection, $this->eventTableName, $clock);
    }

    public function createUniqueParameterName(): string
    {
        return 'param_' . (++$this->dynamicParameterCount);
    }

    public function resetUniqueParameterCount(): void
    {
        $this->dynamicParameterCount = 0;
    }

    public function isPostgreSQL(): bool
    {
        return $this->connection instanceof PostgresConnection;
    }

    public function isSQLite(): bool
    {
        return $this->connection instanceof SQLiteConnection;
    }
}