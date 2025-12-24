<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;
use Webmozart\Assert\Assert;
use Wwwision\DCBEventStore\AppendCondition\AppendCondition;
use Wwwision\DCBEventStore\Event\Event;
use Wwwision\DCBEventStore\Event\Events;
use Wwwision\DCBEventStore\EventStore;
use Wwwision\DCBEventStore\Exceptions\ConditionalAppendFailed;
use Wwwision\DCBEventStore\Query\Query;
use Wwwision\DCBEventStore\Query\QueryItem;
use Wwwision\DCBEventStore\ReadOptions;
use Wwwision\DCBEventStore\SequencedEvent\SequencedEvent;
use Wwwision\DCBEventStore\SequencedEvent\SequencedEvents;

use Wwwision\DCBEventStore\SequencedEvent\SequencePosition;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final readonly class IlluminateEventStore implements EventStore
{
    public function __construct(
        private IlluminateEventStoreConfiguration $config,
    ) {
    }

    public static function create(Connection $connection, string $eventTableName): self
    {
        return new self(
            IlluminateEventStoreConfiguration::create($connection, $eventTableName),
        );
    }

    public function read(Query $query, ?ReadOptions $options = null): SequencedEvents
    {
        $backwards = $options->backwards ?? false;
        $queryBuilder = $this->config->connection
            ->table($this->config->eventTableName, 'events')
            ->select('events.*')
            ->orderBy('events.sequence_number', $backwards ? 'DESC' : 'ASC');
        if ($options !== null && $options->from !== null) {
            $operator = $backwards ? '<=' : '>=';
            $queryBuilder->where('events.sequence_number', $operator, $options->from->value);
        }
        if ($options !== null && $options->limit !== null) {
            $queryBuilder->limit($options->limit);
        }
        $this->applyQueryConstraints($queryBuilder, $query);

        /** @var LazyCollection<int, stdClass> $cursor */
        $cursor = $queryBuilder->cursor();
        return SequencedEvents::create(static function () use ($cursor) {
            foreach ($cursor as $row) {
                yield self::databaseRowToEventEnvelope((array)$row);
            }
        });
    }

    public function append(Events|Event $events, ?AppendCondition $condition = null): void
    {
        Assert::eq($this->config->connection->transactionLevel(), 0, 'Failed to commit events because a database transaction is active already');

        $now = $this->config->clock->now();
        if ($events instanceof Event) {
            $events = Events::fromArray([$events]);
        }

        $selects = null;
        foreach ($events as $event) {
            try {
                $tags = json_encode($event->tags, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(sprintf('Failed to JSON encode tags: %s', $e->getMessage()), 1686304410, $e);
            }

            $selectQuery = $this->config->connection->query()
                ->selectRaw('? as type', [$event->type->value])
                ->selectRaw('? as data', [$event->data->value])
                ->when($this->config->connection instanceof PostgresConnection, function (Builder $query) use ($now, $tags, $event) {
                    $query
                        ->selectRaw('?::jsonb as metadata', [json_encode($event->metadata->value, JSON_THROW_ON_ERROR)])
                        ->selectRaw('?::jsonb as tags', [$tags])
                        ->selectRaw('?::timestamp as recorded_at', [$now->format('Y-m-d H:i:s')]);
                }, function (Builder $query) use ($now, $tags, $event) {
                    $query
                        ->selectRaw('? as metadata', [json_encode($event->metadata->value, JSON_THROW_ON_ERROR)])
                        ->selectRaw('? as tags', [$tags])
                        ->selectRaw('? as recorded_at', [$now->format('Y-m-d H:i:s')]);
                });

            if (!isset($selects)) {
                $selects = $selectQuery;
            } else {
                $selects->unionAll($selectQuery);
            }
        }
        Assert::isInstanceOf($selects, Builder::class);

        $columns = ['type', 'data', 'metadata', 'tags', 'recorded_at'];
        $newEvents = $this->config->connection
            ->table($selects, 'new_events')
            ->select($columns)
            ->unless($condition === null, function (Builder $query) use ($condition) {
                Assert::notNull($condition);
                $sequenceQuery = $this->config->connection
                    ->table($this->config->eventTableName, 'events')
                    ->select('events.sequence_number')
                    ->orderByDesc('events.sequence_number')
                    ->limit(1);

                $this->applyQueryConstraints($sequenceQuery, $condition->failIfEventsMatch);

                if ($condition->after === null) {
                    $query->whereNotExists($sequenceQuery);
                } else {
                    $query->where(DB::raw($condition->after->value), $sequenceQuery);
                }

                return $query;
            });

        $insertQuery = $this->config->connection->table($this->config->eventTableName);

        $affectedRows = $this->commit(
            fn () => $insertQuery->insertUsing($columns, $newEvents)
        );

        if ($affectedRows === 0 && $condition !== null) {
            throw $condition->after === null ? ConditionalAppendFailed::becauseMatchingEventsExist() : ConditionalAppendFailed::becauseMatchingEventsExistAfterSequencePosition($condition->after);
        }
    }

    // -------------------------------------

    /** @param Closure(): int $statement */
    private function commit(Closure $statement): int
    {
        $retryWaitInterval = null;
        $exhaustedRetryAttempts = 0;

        while (true) {
            try {
                if ($this->config->connection instanceof PostgresConnection) {
                    $this->config->connection->statement('BEGIN ISOLATION LEVEL SERIALIZABLE');
                    $affectedRows = $statement();
                    $this->config->connection->statement('COMMIT');

                    return $affectedRows;
                }

                return $this->config->connection->transaction($statement);
            } catch (Throwable $e) {
                if ($this->config->commitRetryStrategy->shouldRetry($e)) {
                    if (!$this->config->backoffStrategy->canRetry($exhaustedRetryAttempts)) {
                        throw new RuntimeException("Failed after {$exhaustedRetryAttempts} retry attempts", 1686565685, $e);
                    }

                    $retryWaitInterval = $this->config->backoffStrategy->getNextRetryWaitTime(
                        current: $retryWaitInterval,
                        exhaustedAttempts: $exhaustedRetryAttempts,
                    );

                    Sleep::for($retryWaitInterval);

                    $exhaustedRetryAttempts++;
                } else {
                    throw new RuntimeException("Failed to commit events (error code: {$e->getCode()}): {$e->getMessage()}", 1685956215, $e);
                }
            } finally {
                if ($this->config->connection instanceof PostgresConnection) {
                    $this->config->connection->statement('ROLLBACK');
                }
            }
        }
    }

    private function applyQueryConstraints(Builder $queryBuilder, Query $query): void
    {
        if (!$query->hasItems()) {
            return;
        }
        $criterionSelects = null;
        foreach ($query as $queryItem) {
            $select = $this->config->connection
                ->table($this->config->eventTableName)
                ->select('sequence_number');
            $this->applyQueryItemConstraints($queryItem, $select);

            if (!isset($criterionSelects)) {
                $criterionSelects = $select;
            } else {
                $criterionSelects->unionAll($select);
            }
        }

        Assert::isInstanceOf($criterionSelects, Builder::class);

        $queryBuilder->joinSub(
            $this->config->connection
                ->query()
                ->select('h.sequence_number')
                ->fromSub($criterionSelects, 'h')
                ->groupBy('h.sequence_number'),
            'eh',
            'eh.sequence_number',
            'events.sequence_number'
        );
    }

    private function applyQueryItemConstraints(QueryItem $queryItem, Builder $queryBuilder): void
    {
        if ($queryItem->eventTypes !== null) {
            $queryBuilder->whereIn('type', $queryItem->eventTypes->toStringArray());
        }
        if ($queryItem->tags !== null) {
            foreach ($queryItem->tags as $tag) {
                $queryBuilder->whereJsonContains('tags', $tag->value);
            }
        }

        if ($queryItem->onlyLastEvent) {
            $queryBuilder->select(DB::raw('MAX(sequence_number) AS sequence_number'));
        }
    }

    /**
     * @param array<string, scalar> $row
     */
    private static function databaseRowToEventEnvelope(array $row): SequencedEvent
    {
        Assert::numeric($row['sequence_number']);
        Assert::string($row['type']);
        Assert::string($row['recorded_at']);
        Assert::true(is_array($row['data']) || is_string($row['data']));
        Assert::true(is_null($row['tags']) || is_array($row['tags']) || is_string($row['tags']));
        $tagsArray = json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR);
        Assert::isList($tagsArray);
        Assert::allString($tagsArray);
        Assert::true(is_null($row['metadata']) || is_array($row['metadata']) || is_string($row['metadata']));

        $recordedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['recorded_at']);
        Assert::isInstanceOf($recordedAt, DateTimeImmutable::class);
        return new SequencedEvent(
            SequencePosition::fromInteger((int)$row['sequence_number']),
            $recordedAt,
            Event::create(
                $row['type'],
                $row['data'],
                $tagsArray,
                $row['metadata'],
            ),
        );
    }
}
