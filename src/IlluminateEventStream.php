<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use DateTimeImmutable;
use Illuminate\Support\LazyCollection;
use stdClass;
use Traversable;
use Webmozart\Assert\Assert;
use Wwwision\DCBEventStore\EventStream;
use Wwwision\DCBEventStore\Types\Event;
use Wwwision\DCBEventStore\Types\EventEnvelope;
use Wwwision\DCBEventStore\Types\SequenceNumber;

final readonly class IlluminateEventStream implements EventStream
{
    public function __construct(
        /** @var LazyCollection<int, stdClass> */
        private LazyCollection $result,
    ) {
    }

    public function getIterator(): Traversable
    {
        foreach ($this->result as $row) {
            yield self::databaseRowToEventEnvelope((array)$row);
        }
    }

    public function first(): ?EventEnvelope
    {
        $row = $this->result->first();
        if ($row === null) {
            return null;
        }
        return self::databaseRowToEventEnvelope((array) $row);
    }

    // -----------------------------------

    /**
     * @param array<mixed> $row
     * @return EventEnvelope
     */
    private static function databaseRowToEventEnvelope(array $row): EventEnvelope
    {
        Assert::numeric($row['sequence_number']);
        $recordedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['recorded_at']);
        Assert::isInstanceOf($recordedAt, DateTimeImmutable::class);
        return new EventEnvelope(
            SequenceNumber::fromInteger((int)$row['sequence_number']),
            $recordedAt,
            Event::create(
                $row['type'],
                $row['data'],
                $row['tags'],
                $row['metadata'],
            ),
        );
    }
}
