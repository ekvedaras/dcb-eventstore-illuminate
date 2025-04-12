<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Webmozart\Assert\Assert;
use Wwwision\DCBEventStore\EventStore;
use Wwwision\DCBEventStore\EventStream;
use Wwwision\DCBEventStore\Exceptions\ConditionalAppendFailed;
use Wwwision\DCBEventStore\Setupable;
use Wwwision\DCBEventStore\Types\AppendCondition;
use Wwwision\DCBEventStore\Types\Event;
use Wwwision\DCBEventStore\Types\Events;
use Wwwision\DCBEventStore\Types\ReadOptions;
use Wwwision\DCBEventStore\Types\StreamQuery\Criteria\EventTypesAndTagsCriterion;
use Wwwision\DCBEventStore\Types\StreamQuery\StreamQuery;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final readonly class IlluminateEventStore implements EventStore, Setupable
{

    public function __construct(
        private IlluminateEventStoreConfiguration $config
    ) {
    }

    public static function create(Connection $connection, string $eventTableName): self
    {
        $config = IlluminateEventStoreConfiguration::create($connection, $eventTableName);
        return new self($config);
    }

    public function setup(): void
    {
        try {
            if (Schema::hasTable($this->config->eventTableName)) {
                return;
            }

            Schema::create($this->config->eventTableName, function (Blueprint $table): void {
                $table->increments('sequence_number');
                $table->string('type');
                $table->text('data');
                $table->jsonb('metadata')->nullable();
                $table->jsonb('tags');
                $table->dateTime('recorded_at');
            });

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement("create index tags on {$this->config->eventTableName} using gin(tags jsonb_path_ops)");
            }
        } catch (QueryException $e) {
            throw new RuntimeException(sprintf('Failed to setup event store: %s', $e->getMessage()), 1687010035, $e);
        }
    }

    public function read(StreamQuery $query, ?ReadOptions $options = null): EventStream
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
        $this->addStreamQueryConstraints($queryBuilder, $query);
        return new IlluminateEventStream($queryBuilder->cursor());
    }

    public function append(Events|Event $events, AppendCondition $condition): void
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
            ->unless($condition->expectedHighestSequenceNumber->isAny(), function (Builder $query) use ($condition) {
                $sequenceQuery = $this->config->connection
                    ->table($this->config->eventTableName, 'events')
                    ->select('events.sequence_number')
                    ->orderByDesc('events.sequence_number')
                    ->limit(1);

                $this->addStreamQueryConstraints($sequenceQuery, $condition->query);

                if ($condition->expectedHighestSequenceNumber->isNone()) {
                    $query->whereNotExists($sequenceQuery);
                } else {
                    $query->where(DB::raw($condition->expectedHighestSequenceNumber->extractSequenceNumber()->value), $sequenceQuery);
                }

                return $query;
            });

        $insertQuery = $this->config->connection->table($this->config->eventTableName);

        $affectedRows = $this->commit(
            fn () => $insertQuery->insertUsing($columns, $newEvents)
        );

        if ($affectedRows === 0 && !$condition->expectedHighestSequenceNumber->isAny()) {
            throw $condition->expectedHighestSequenceNumber->isNone() ? ConditionalAppendFailed::becauseNoEventWhereExpected() : ConditionalAppendFailed::becauseHighestExpectedSequenceNumberDoesNotMatch($condition->expectedHighestSequenceNumber);
        }
    }

    // -------------------------------------

    /** @param Closure(): int $statement */
    private function commit(Closure $statement): int
    {
        $retryWaitInterval = 0.005;
        $maxRetryAttempts = 10;
        $retryAttempt = 0;
        while (true) {
            try {
                if ($this->config->connection instanceof PostgresConnection) {
                    $this->config->connection->statement('BEGIN ISOLATION LEVEL SERIALIZABLE');
                    $affectedRows = $statement();
                    $this->config->connection->statement('COMMIT');

                    return $affectedRows;
                }

                return $this->config->connection->transaction($statement);
            } catch (QueryException $e) {
                if ((int) $e->getCode() === 40001) {
                    if ($retryAttempt >= $maxRetryAttempts) {
                        throw new RuntimeException(sprintf('Failed after %d retry attempts', $retryAttempt), 1686565685, $e);
                    }
                    usleep((int)($retryWaitInterval * 1E6));
                    $retryAttempt ++;
                    $retryWaitInterval *= 2;
                } else {
                    throw new RuntimeException(sprintf('Failed to commit events (error code: %d): %s', (int)$e->getCode(), $e->getMessage()), 1685956215, $e);
                }
            } finally {
                if ($this->config->connection instanceof PostgresConnection) {
                    $this->config->connection->statement('ROLLBACK');
                }
            }
        }
    }

    private function addStreamQueryConstraints(Builder $queryBuilder, StreamQuery $streamQuery): void
    {
        if ($streamQuery->isWildcard()) {
            return;
        }
        $criterionSelects = null;
        foreach ($streamQuery->criteria as $criterion) {
            $select = $this->config->connection
                ->table($this->config->eventTableName)
                ->select('sequence_number');
            $this->applyCriterionConstraints($criterion, $select);

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

    private function applyCriterionConstraints(EventTypesAndTagsCriterion $criterion, Builder $queryBuilder): void
    {
        if ($criterion->eventTypes !== null) {
            $queryBuilder->whereIn('type', $criterion->eventTypes->toStringArray());
        }
        if ($criterion->tags !== null) {
            foreach ($criterion->tags as $tag) {
                $queryBuilder->whereJsonContains('tags', $tag->value);
            }
        }

        if ($criterion->onlyLastEvent) {
            $queryBuilder->select(DB::raw('MAX(sequence_number) AS sequence_number'));
        }
    }
}
