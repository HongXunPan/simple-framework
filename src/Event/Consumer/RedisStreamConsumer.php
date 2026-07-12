<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Consumer;

use HongXunPan\DB\Redis\Redis as RedisManager;
use HongXunPan\Framework\Event\Exception\EventConsumeException;
use HongXunPan\Framework\Event\Execution\Failure;
use JsonException;
use Redis;
use RedisException;
use Throwable;

final class RedisStreamConsumer implements Consumer
{
    private const string MESSAGE_FIELD = 'message';
    private const string FAILURE_FIELD = 'failure';

    private readonly string $connection;
    private readonly string $stream;
    private readonly string $group;
    private readonly string $failedStream;
    private readonly int $blockMs;
    private readonly int $batchSize;
    private readonly int $claimIdleMs;
    private readonly int $failedMaxLength;
    private readonly string $consumer;
    private ?Redis $redis = null;
    private bool $groupReady = false;
    private string $claimCursor = '0-0';

    public function __construct(?string $consumer = null)
    {
        $this->connection = $this->stringConfig('connection');
        $this->stream = $this->stringConfig('stream');
        $this->group = $this->stringConfig('group');
        $this->failedStream = $this->stringConfig('failed_stream');
        $this->blockMs = $this->intConfig('block_ms');
        $this->batchSize = $this->intConfig('batch_size');
        $this->claimIdleMs = $this->intConfig('claim_idle_ms');
        $this->failedMaxLength = $this->intConfig('failed_max_length');
        $this->consumer = $consumer ?: $this->createConsumerName();
    }

    public function receive(): iterable
    {
        $this->ensureGroup();

        $messages = $this->messages($this->claimPending());
        $remaining = $this->batchSize - count($messages);
        if ($remaining > 0) {
            array_push($messages, ...$this->messages($this->readNew($remaining)));
        }

        return $messages;
    }

    public function acknowledge(Message $message): void
    {
        try {
            $acknowledged = $this->redis()->xAck($this->stream, $this->group, [$message->id]);
        } catch (Throwable $throwable) {
            throw new EventConsumeException("Redis 消息 ACK 失败：{$message->id}", previous: $throwable);
        }
        if ($acknowledged !== 1) {
            throw new EventConsumeException("Redis 消息 ACK 数量异常：{$message->id}");
        }

        try {
            $this->redis()->xDel($this->stream, [$message->id]);
        } catch (Throwable) {
            // XACK 已形成消费终态；XDEL 只清理主 Stream 残留，失败不得伪装成可重放的消费失败。
        }
    }

    public function fail(Message $message, Failure $failure): void
    {
        $failurePayload = $this->serializeFailure($failure);

        try {
            $failedId = $this->redis()->xAdd(
                $this->failedStream,
                '*',
                [self::MESSAGE_FIELD => $message->body, self::FAILURE_FIELD => $failurePayload],
                $this->failedMaxLength,
                true,
            );
        } catch (Throwable $throwable) {
            throw new EventConsumeException('失败消息写入 failed stream 失败', previous: $throwable);
        }

        if (!is_string($failedId) || preg_match('/^\d+-\d+$/D', $failedId) !== 1) {
            throw new EventConsumeException('failed stream 未返回有效消息 ID');
        }

        $this->acknowledge($message);
    }

    private function serializeFailure(Failure $failure): string
    {
        try {
            return json_encode(
                $failure->toArray(),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (JsonException $exception) {
            throw new EventConsumeException('失败消息摘要编码失败', previous: $exception);
        }
    }

    private function ensureGroup(): void
    {
        if ($this->groupReady) {
            return;
        }

        try {
            $this->redis()->xGroup('CREATE', $this->stream, $this->group, '0', true);
        } catch (RedisException $exception) {
            if (!str_contains($exception->getMessage(), 'BUSYGROUP')) {
                throw new EventConsumeException('Redis Consumer Group 创建失败', previous: $exception);
            }
        } catch (Throwable $throwable) {
            throw new EventConsumeException('Redis Consumer Group 初始化失败', previous: $throwable);
        }

        $this->groupReady = true;
    }

    /** @return array<string, array<string, string>> */
    private function claimPending(): array
    {
        try {
            $claimed = $this->redis()->xAutoClaim(
                $this->stream,
                $this->group,
                $this->consumer,
                $this->claimIdleMs,
                $this->claimCursor,
                $this->batchSize,
                false,
            );
        } catch (Throwable $throwable) {
            throw new EventConsumeException('Redis pending 消息回收失败', previous: $throwable);
        }

        if (!is_array($claimed)) {
            return [];
        }

        $this->claimCursor = is_string($claimed[0] ?? null) ? $claimed[0] : '0-0';

        return is_array($claimed[1] ?? null) ? $claimed[1] : [];
    }

    /** @return array<string, array<string, string>> */
    private function readNew(int $count): array
    {
        try {
            $streams = $this->redis()->xReadGroup(
                $this->group,
                $this->consumer,
                [$this->stream => '>'],
                $count,
                $this->blockMs,
            );
        } catch (Throwable $throwable) {
            throw new EventConsumeException('Redis 新消息读取失败', previous: $throwable);
        }

        if (!is_array($streams)) {
            return [];
        }

        if ($streams === []) {
            return [];
        }

        if (count($streams) !== 1) {
            throw new EventConsumeException('Redis 新消息返回的 Stream 数量异常');
        }

        $entries = array_values($streams)[0];

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param array<string, array<string, string>> $entries
     * @return list<Message>
     */
    private function messages(array $entries): array
    {
        $messages = [];
        foreach ($entries as $streamId => $fields) {
            $body = $fields[self::MESSAGE_FIELD] ?? '';
            $messages[] = new Message($streamId, is_string($body) ? $body : '');
        }

        return $messages;
    }

    private function redis(): Redis
    {
        return $this->redis ??= RedisManager::connection($this->connection)->getConnection();
    }

    private function stringConfig(string $key): string
    {
        $value = config("events.driver.{$key}");
        if (!is_string($value) || $value === '') {
            throw new EventConsumeException("events.driver.{$key} 未完成有效配置");
        }

        return $value;
    }

    private function intConfig(string $key): int
    {
        $value = config("events.driver.{$key}");
        if (!is_int($value) || $value < 1) {
            throw new EventConsumeException("events.driver.{$key} 未完成有效配置");
        }

        return $value;
    }

    private function createConsumerName(): string
    {
        $host = preg_replace('/[^a-zA-Z0-9_-]+/', '-', gethostname() ?: 'worker') ?: 'worker';

        return sprintf('%s-%d-%s', $host, getmypid() ?: 0, bin2hex(random_bytes(4)));
    }
}
