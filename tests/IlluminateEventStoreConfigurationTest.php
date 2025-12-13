<?php

declare(strict_types=1);

namespace EKvedaras\DCBEventStoreIlluminate\Tests;

use EKvedaras\DCBEventStoreIlluminate\CommitRetries\BackoffExponentially;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\BackoffStrategy;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\CommitRetryStrategy;
use EKvedaras\DCBEventStoreIlluminate\CommitRetries\RetryCommitOnDeadLock;
use EKvedaras\DCBEventStoreIlluminate\IlluminateEventStoreConfiguration;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Wwwision\DCBEventStore\Helpers\SystemClock;

#[CoversClass(IlluminateEventStoreConfiguration::class)]
final class IlluminateEventStoreConfigurationTest extends TestCase
{
    public function test_create_uses_default_dependencies(): void
    {
        $connection = $this->createStub(Connection::class);

        $config = IlluminateEventStoreConfiguration::create($connection, 'events');

        self::assertSame($connection, $config->connection);
        self::assertSame('events', $config->eventTableName);
        self::assertInstanceOf(SystemClock::class, $config->clock);
        self::assertInstanceOf(RetryCommitOnDeadLock::class, $config->commitRetryStrategy);
        self::assertInstanceOf(BackoffExponentially::class, $config->backoffStrategy);
    }

    public function test_with_clock_returns_new_instance_with_replaced_clock(): void
    {
        $connection = $this->createStub(Connection::class);
        $initialClock = $this->createStub(ClockInterface::class);
        $commitRetryStrategy = $this->createStub(CommitRetryStrategy::class);
        $backoffStrategy = $this->createStub(BackoffStrategy::class);

        $config = new IlluminateEventStoreConfiguration(
            connection: $connection,
            eventTableName: 'events',
            clock: $initialClock,
            commitRetryStrategy: $commitRetryStrategy,
            backoffStrategy: $backoffStrategy,
        );

        $newClock = $this->createStub(ClockInterface::class);

        $newConfig = $config->withClock($newClock);

        self::assertNotSame($config, $newConfig);
        self::assertSame($newClock, $newConfig->clock);
        self::assertSame($initialClock, $config->clock);
        self::assertSame($connection, $newConfig->connection);
        self::assertSame($commitRetryStrategy, $newConfig->commitRetryStrategy);
        self::assertSame($backoffStrategy, $newConfig->backoffStrategy);
    }

    public function test_with_commit_retry_strategy_returns_new_instance_with_replaced_strategy(): void
    {
        $connection = $this->createStub(Connection::class);
        $clock = $this->createStub(ClockInterface::class);
        $initialCommitRetry = $this->createStub(CommitRetryStrategy::class);
        $backoffStrategy = $this->createStub(BackoffStrategy::class);

        $config = new IlluminateEventStoreConfiguration(
            connection: $connection,
            eventTableName: 'events',
            clock: $clock,
            commitRetryStrategy: $initialCommitRetry,
            backoffStrategy: $backoffStrategy,
        );

        $newCommitRetry = $this->createStub(CommitRetryStrategy::class);

        $newConfig = $config->withCommitRetryStrategy($newCommitRetry);

        self::assertNotSame($config, $newConfig);
        self::assertSame($newCommitRetry, $newConfig->commitRetryStrategy);
        self::assertSame($initialCommitRetry, $config->commitRetryStrategy);
        self::assertSame($clock, $newConfig->clock);
        self::assertSame($connection, $newConfig->connection);
        self::assertSame($backoffStrategy, $newConfig->backoffStrategy);
    }

    public function test_with_backoff_strategy_returns_new_instance_with_replaced_strategy(): void
    {
        $connection = $this->createStub(Connection::class);
        $clock = $this->createStub(ClockInterface::class);
        $commitRetryStrategy = $this->createStub(CommitRetryStrategy::class);
        $initialBackoff = $this->createStub(BackoffStrategy::class);

        $config = new IlluminateEventStoreConfiguration(
            connection: $connection,
            eventTableName: 'events',
            clock: $clock,
            commitRetryStrategy: $commitRetryStrategy,
            backoffStrategy: $initialBackoff,
        );

        $newBackoff = $this->createStub(BackoffStrategy::class);

        $newConfig = $config->withBackoffStrategy($newBackoff);

        self::assertNotSame($config, $newConfig);
        self::assertSame($newBackoff, $newConfig->backoffStrategy);
        self::assertSame($initialBackoff, $config->backoffStrategy);
        self::assertSame($clock, $newConfig->clock);
        self::assertSame($connection, $newConfig->connection);
        self::assertSame($commitRetryStrategy, $newConfig->commitRetryStrategy);
    }
}