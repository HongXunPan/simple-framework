<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Driver;

use HongXunPan\DB\Redis\Redis as RedisManager;
use HongXunPan\Framework\Event\Consumer\RedisStreamConsumer;
use HongXunPan\Framework\Event\Dispatch\Envelope;
use HongXunPan\Framework\Event\Exception\EventPublishException;
use HongXunPan\Framework\Event\Serialization\Serializer;
use Throwable;

final readonly class RedisStreamDriver implements Driver
{
    private const string MESSAGE_FIELD = 'message';

    public function __construct(private Serializer $serializer)
    {
    }

    public static function consumer(): string
    {
        return RedisStreamConsumer::class;
    }

    public function publish(Envelope $envelope): void
    {
        $connection = config('events.driver.connection');
        $stream = config('events.driver.stream');
        if (!is_string($connection) || $connection === '' || !is_string($stream) || $stream === '') {
            throw new EventPublishException('RedisStreamDriver 尚未完成有效启动配置');
        }

        try {
            $payload = $this->serializer->serialize($envelope);
        } catch (Throwable $throwable) {
            throw new EventPublishException(
                "Event 序列化失败：{$envelope->eventId}",
                previous: $throwable,
            );
        }

        try {
            $redis = RedisManager::connection($connection)->getConnection();
            $streamId = $redis->xAdd($stream, '*', [self::MESSAGE_FIELD => $payload]);
        } catch (Throwable $throwable) {
            throw new EventPublishException(
                "Event 发布到 Redis Stream 失败：{$envelope->eventId}",
                previous: $throwable,
            );
        }

        if (!is_string($streamId) || preg_match('/^\d+-\d+$/D', $streamId) !== 1) {
            throw new EventPublishException(
                "Redis Stream 未返回有效消息 ID：{$envelope->eventId}",
            );
        }
    }
}
