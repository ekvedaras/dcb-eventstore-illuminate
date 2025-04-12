<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use Illuminate\Database\Connection;
use Psr\Clock\ClockInterface;
use Wwwision\DCBEventStore\Helpers\SystemClock;

final readonly class IlluminateEventStoreConfiguration
{
    public function __construct(
        public Connection $connection,
        public string $eventTableName,
        public ClockInterface $clock,
    ) {
    }

    public static function create(Connection $connection, string $eventTableName): self
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
}
