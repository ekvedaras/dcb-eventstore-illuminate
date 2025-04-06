<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate;

use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
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

use function implode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final class IlluminateEventStore implements EventStore, Setupable
{

    public function __construct(
        private readonly IlluminateEventStoreConfiguration $config
    ) {
    }

    public static function create(ConnectionInterface $connection, string $eventTableName): self
    {
        $config = IlluminateEventStoreConfiguration::create($connection, $eventTableName);
        return new self($config);
    }

    public function setup(): void
    {
        try {
            // TODO find replacement, @see https://github.com/doctrine/dbal/blob/4.2.x/UPGRADE.md#deprecated-schemadifftosql-and-schemadifftosavesql
            foreach ($this->getSchemaDiff()->toSaveSql($this->config->platform) as $statement) {
                $this->config->connection->executeStatement($statement);
            }
            if ($this->config->isPostgreSQL()) {
                $this->config->connection->executeStatement('CREATE INDEX IF NOT EXISTS tags ON ' . $this->config->eventTableName . ' USING gin (tags jsonb_path_ops)');
            }
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to setup event store: %s', $e->getMessage()), 1687010035, $e);
        }
    }

    private function getSchemaDiff(): SchemaDiff
    {
        $schemaManager = $this->config->connection->createSchemaManager();
        return $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $this->databaseSchema());
    }

    /**
     * @throws SchemaException
     */
    private function databaseSchema(): Schema
    {
        $schema = new Schema();
        $eventsTable = $schema->createTable($this->config->eventTableName);
        // The monotonic sequence number
        $eventsTable->addColumn('sequence_number', Types::INTEGER, ['autoincrement' => true]);
        // The event type in the format "<BoundedContext>:<EventType>"
        $eventsTable->addColumn('type', Types::STRING, ['length' => 255]);
        // The event payload (usually serialized as JSON)
        $eventsTable->addColumn('data', Types::TEXT);
        // Optional event metadata as key-value pairs
        $eventsTable->addColumn('metadata', Types::TEXT, ['notnull' => false, 'platformOptions' => ['jsonb' => true]]);
        // The event tags (aka domain ids) as JSON
        $eventsTable->addColumn('tags', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        // When the event was appended originally
        $eventsTable->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);

        $eventsTable->setPrimaryKey(['sequence_number']);

        return $schema;
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

        $parameters = [];
        $selects = [];
        $eventIndex = 0;
        $now = $this->config->clock->now();
        if ($events instanceof Event) {
            $events = Events::fromArray([$events]);
        }
        foreach ($events as $event) {
            $selects[] = "SELECT :e{$eventIndex}_type type, :e{$eventIndex}_data data, :e{$eventIndex}_metadata metadata, :e{$eventIndex}_tags" . ($this->config->isPostgreSQL() ? '::jsonb' : '') . " tags, :e{$eventIndex}_recordedAt" . ($this->config->isPostgreSQL() ? '::timestamp' : '') . " recorded_at";
            try {
                $tags = json_encode($event->tags, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(sprintf('Failed to JSON encode tags: %s', $e->getMessage()), 1686304410, $e);
            }
            $parameters['e' . $eventIndex . '_type'] = $event->type->value;
            $parameters['e' . $eventIndex . '_data'] = $event->data->value;
            $parameters['e' . $eventIndex . '_metadata'] = json_encode($event->metadata->value, JSON_THROW_ON_ERROR);
            $parameters['e' . $eventIndex . '_tags'] = $tags;
            $parameters['e' . $eventIndex . '_recordedAt'] = $now->format('Y-m-d H:i:s');
            $eventIndex++;
        }
        $unionSelects = implode(' UNION ALL ', $selects);

        $statement = "INSERT INTO {$this->config->eventTableName} (type, data, metadata, tags, recorded_at) SELECT * FROM ( $unionSelects ) new_events";
        if (!$condition->expectedHighestSequenceNumber->isAny()) {
            $queryBuilder = $this->config->connection->table($this->config->eventTableName)->select('events.sequence_number')->orderBy('events.sequence_number', 'DESC')->limit(1);
            $this->addStreamQueryConstraints($queryBuilder, $condition->query);
            if ($condition->expectedHighestSequenceNumber->isNone()) {
                $statement .= ' WHERE NOT EXISTS (' . $queryBuilder->toSql() . ')';
            } else {
                $statement .= ' WHERE (' . $queryBuilder->toSql() . ') = :highestSequenceNumber';
                $parameters['highestSequenceNumber'] = $condition->expectedHighestSequenceNumber->extractSequenceNumber()->value;
            }
        }
        $affectedRows = $this->commitStatement($statement, $parameters);
        if ($affectedRows === 0 && !$condition->expectedHighestSequenceNumber->isAny()) {
            throw $condition->expectedHighestSequenceNumber->isNone() ? ConditionalAppendFailed::becauseNoEventWhereExpected() : ConditionalAppendFailed::becauseHighestExpectedSequenceNumberDoesNotMatch($condition->expectedHighestSequenceNumber);
        }
    }

    // -------------------------------------

    /**
     * @param array<int<0, max>|string, mixed> $parameters
     */
    private function commitStatement(string $statement, array $parameters): int
    {
        $retryWaitInterval = 0.005;
        $maxRetryAttempts = 10;
        $retryAttempt = 0;
        while (true) {
            try {
                if ($this->config->isPostgreSQL()) {
                    $this->config->connection->statement('BEGIN ISOLATION LEVEL SERIALIZABLE');
                }
                $affectedRows = (int)$this->config->connection->affectingStatement($statement, $parameters);
                if ($this->config->isPostgreSQL()) {
                    $this->config->connection->commit();
                }
                return $affectedRows;
            } catch (DeadlockException $e) {
                if ($retryAttempt >= $maxRetryAttempts) {
                    throw new RuntimeException(sprintf('Failed after %d retry attempts', $retryAttempt), 1686565685, $e);
                }
                usleep((int)($retryWaitInterval * 1E6));
                $retryAttempt ++;
                $retryWaitInterval *= 2;
            } catch (QueryException $e) {
                throw new RuntimeException(sprintf('Failed to commit events (error code: %d): %s', (int)$e->getCode(), $e->getMessage()), 1685956215, $e);
            } finally {
                if ($this->config->isPostgreSQL()) {
                    $this->config->connection->rollBack();
                }
            }
        }
    }

    private function addStreamQueryConstraints(Builder $queryBuilder, StreamQuery $streamQuery): void
    {
        if ($streamQuery->isWildcard()) {
            return;
        }
        $criterionStatements = [];
        foreach ($streamQuery->criteria as $criterion) {
            $criterionQueryBuilder = $this->config->connection
                ->table($this->config->eventTableName, 'events')
                ->select('sequence_number');
            $this->applyCriterionConstraints($criterion, $criterionQueryBuilder);
            $criterionStatements[] = $criterionQueryBuilder->toSql();
        }
        $joinQueryBuilder = $this->config->connection
            ->table($this->config->connection->raw('(' . implode(' UNION ALL ', $criterionStatements) . ')'), 'h')
            ->select('sequence_number')
            ->groupBy('h.sequence_number');
        $queryBuilder->joinSub($joinQueryBuilder, 'eh', 'eh.sequence_number', 'events.sequence_number');
    }

    private function applyCriterionConstraints(EventTypesAndTagsCriterion $criterion, Builder $queryBuilder): void
    {
        if ($criterion->eventTypes !== null) {
            $queryBuilder->whereIn('type', $criterion->eventTypes->toStringArray());
        }
        if ($criterion->tags !== null) {
            if ($this->config->isSQLite()) {
                $queryBuilder->whereNotExists(
                    $queryBuilder->newQuery()
                        ->fromRaw('JSON_EACH(?)', $criterion->tags)
                        ->whereNotIn(
                            'value',
                            $queryBuilder->newQuery()
                                ->select('value')
                                ->fromRaw('JSON_EACH(tags)')
                        )
                );
            } elseif ($this->config->isPostgreSQL()) {
                $queryBuilder->whereJsonContains('tags', $criterion->tags);
            } else {
                $queryBuilder->whereRaw('JSON_CONTAINS(tags, ?)', $criterion->tags);
            }
        }
        if ($criterion->onlyLastEvent) {
            $queryBuilder->selectRaw('MAX(sequence_number) AS sequence_number');
        }
    }
}