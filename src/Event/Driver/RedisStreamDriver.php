<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Driver;

use HongXunPan\DB\Redis\Redis as RedisManager;
use HongXunPan\Framework\Event\Consumer\RedisStreamConsumer;
use HongXunPan\Framework\Event\Dispatch\EventMessage;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Exception\EventPublishException;
use HongXunPan\Framework\Event\Serialization\Serializer;
use Throwable;

final readonly class RedisStreamDriver implements Driver
{
    private const string MESSAGE_FIELD = 'message';

    public function __construct(private Serializer $serializer)
    {
    }

    public static function validateConfig(array $config): void
    {
        $connection = self::nonEmptyString($config, 'connection');
        $stream = self::nonEmptyString($config, 'stream');
        $failedStream = self::nonEmptyString($config, 'failed_stream');
        self::nonEmptyString($config, 'group');

        foreach (['block_ms', 'batch_size', 'claim_idle_ms', 'failed_max_length'] as $key) {
            self::positiveInt($config, $key);
        }

        if ($stream === $failedStream) {
            throw new EventConfigException('events.driver.stream 与 failed_stream 不能相同');
        }

        $redisConnections = config('database.redis', []);
        if (!is_array($redisConnections) || !isset($redisConnections[$connection])
            || !is_array($redisConnections[$connection])) {
            throw new EventConfigException(
                "events.driver.connection 未对应有效的 database.redis 配置：{$connection}",
            );
        }

        $requiredMethods = ['xAdd', 'xGroup', 'xReadGroup', 'xAutoClaim', 'xAck', 'xDel'];
        if (!extension_loaded('redis') || !class_exists(\Redis::class)) {
            throw new EventConfigException('RedisStreamDriver 需要 phpredis 扩展');
        }
        foreach ($requiredMethods as $method) {
            if (!method_exists(\Redis::class, $method)) {
                throw new EventConfigException("RedisStreamDriver 需要 phpredis::{$method}()");
            }
        }
    }

    public static function consumer(): string
    {
        return RedisStreamConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
        $connection = config('events.driver.connection');
        $stream = config('events.driver.stream');
        if (!is_string($connection) || $connection === '' || !is_string($stream) || $stream === '') {
            throw new EventPublishException('RedisStreamDriver 尚未完成有效启动配置');
        }

        try {
            $payload = $this->serializer->serialize($message);
        } catch (Throwable $throwable) {
            throw new EventPublishException(
                "Event 序列化失败：{$message->eventId}",
                previous: $throwable,
            );
        }

        try {
            $redis = RedisManager::connection($connection)->getConnection();
            $streamId = $redis->xAdd($stream, '*', [self::MESSAGE_FIELD => $payload]);
        } catch (Throwable $throwable) {
            throw new EventPublishException(
                "Event 发布到 Redis Stream 失败：{$message->eventId}",
                previous: $throwable,
            );
        }

        if (!is_string($streamId) || preg_match('/^\d+-\d+$/D', $streamId) !== 1) {
            throw new EventPublishException(
                "Redis Stream 未返回有效消息 ID：{$message->eventId}",
            );
        }
    }

    /** @param array<mixed> $config */
    private static function nonEmptyString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new EventConfigException("events.driver.{$key} 必须是非空字符串");
        }

        return $value;
    }

    /** @param array<mixed> $config */
    private static function positiveInt(array $config, string $key): int
    {
        $value = $config[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new EventConfigException("events.driver.{$key} 必须是正整数");
        }

        return $value;
    }
}
