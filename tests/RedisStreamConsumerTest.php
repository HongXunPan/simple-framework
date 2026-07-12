<?php

declare(strict_types=1);

use HongXunPan\DB\Redis\Redis as WorkerRedisManager;
use HongXunPan\Framework\Core\Application as WorkerApplication;
use HongXunPan\Framework\Event\Bootstrap\EventBootstrapper as WorkerEventBootstrapper;
use HongXunPan\Framework\Event\Consumer\Consumer as WorkerConsumer;
use HongXunPan\Framework\Event\Consumer\RedisStreamConsumer as WorkerRedisStreamConsumer;
use HongXunPan\Framework\Event\Event as WorkerEvent;
use HongXunPan\Framework\Event\Exception\EventConsumeException;
use HongXunPan\Framework\Event\Listener\ShouldQueue as WorkerShouldQueue;
use HongXunPan\Framework\Event\Worker\EventWorker;
use HongXunPan\Tools\Config\Config as WorkerConfig;
use HongXunPan\Tools\Env\Env as WorkerEnv;

final readonly class WorkerOccurred implements WorkerEvent
{
    public function __construct(public string $name)
    {
    }
}

final class WorkerInvocationLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class WorkerRedisFailureDouble extends Redis
{
    public bool $failAcknowledgement = false;
    public bool $failDeletion = false;

    public function xAck(string $key, string $group, array $ids): int|false
    {
        if ($this->failAcknowledgement) {
            throw new RedisException('模拟 XACK 失败');
        }

        return parent::xAck($key, $group, $ids);
    }

    public function xDel(string $key, array $ids): Redis|int|false
    {
        if ($this->failDeletion) {
            throw new RedisException('模拟 XDEL 失败');
        }

        return parent::xDel($key, $ids);
    }
}

final readonly class WorkerFirstListener implements WorkerShouldQueue
{
    public function __construct(private WorkerInvocationLog $log)
    {
    }

    public function handle(WorkerOccurred $event): void
    {
        $this->log->entries[] = 'first:' . $event->name;
    }
}

final readonly class WorkerSecondListener implements WorkerShouldQueue
{
    public function __construct(private WorkerInvocationLog $log)
    {
    }

    public function handle(WorkerOccurred $event): void
    {
        $this->log->entries[] = 'second:' . $event->name;
    }
}

final readonly class WorkerFailingListener implements WorkerShouldQueue
{
    public function __construct(private WorkerInvocationLog $log)
    {
    }

    public function handle(WorkerOccurred $event): void
    {
        $this->log->entries[] = 'failed:' . $event->name;
        throw new RuntimeException('token=secret-value mobile=13800138000');
    }
}

/**
 * @param list<class-string<WorkerShouldQueue>> $listeners
 * @param array<string, mixed> $driverOverrides
 * @param array<int, mixed> $redisOptions
 * @return array{
 *     redis: Redis,
 *     worker: EventWorker,
 *     log: WorkerInvocationLog,
 *     stream: string,
 *     group: string,
 *     failed_stream: string
 * }
 */
function bootRedisStreamConsumer(
    array $listeners,
    array $driverOverrides = [],
    array $redisOptions = [],
): array
{
    putenv('DEBUG=false');
    $loaded = (new ReflectionClass(WorkerEnv::class))->getProperty('loaded');
    $loaded->setValue(null, true);

    $suffix = bin2hex(random_bytes(6));
    $connection = 'event-worker-' . $suffix;
    $stream = 'simple-framework:event-worker:' . $suffix;
    $group = 'simple-framework-worker-' . $suffix;
    $failedStream = $stream . ':failed';
    $redisConfig = [
        'host' => 'gplus-redis',
        'port' => 6379,
        'timeout' => 1.0,
        'readTimeout' => 1.0,
    ];
    $driverConfig = array_replace([
        'class' => HongXunPan\Framework\Event\Driver\RedisStreamDriver::class,
        'connection' => $connection,
        'stream' => $stream,
        'group' => $group,
        'failed_stream' => $failedStream,
        'block_ms' => 1,
        'batch_size' => 10,
        'claim_idle_ms' => 1,
        'failed_max_length' => 1000,
    ], $driverOverrides);

    WorkerConfig::$config = [
        'app' => ['timezone' => 'Asia/Shanghai'],
        'singleton' => [],
        'boot' => [[WorkerEventBootstrapper::class, 'boot']],
        'database' => ['redis' => [$connection => $redisConfig]],
        'events' => [
            'driver' => $driverConfig,
            'listeners' => [WorkerOccurred::class => $listeners],
        ],
    ];
    WorkerRedisManager::setConfig($redisConfig, $connection, $redisOptions);

    $application = new WorkerApplication();
    WorkerApplication::setInstance($application);
    $application->init('/tmp/simple-framework-event-tests');

    $log = new WorkerInvocationLog();
    $application->instance(WorkerInvocationLog::class, $log);
    $redis = WorkerRedisManager::connection($connection)->getConnection();
    $redis->del($stream, $failedStream);

    return [
        'redis' => $redis,
        'worker' => app(EventWorker::class),
        'log' => $log,
        'stream' => $stream,
        'group' => $group,
        'failed_stream' => $failedStream,
    ];
}

/** @param array{redis: Redis, stream: string, failed_stream: string} $context */
function cleanupRedisStreamConsumer(array $context): void
{
    $context['redis']->del($context['stream'], $context['failed_stream']);
}

function injectWorkerRedisFailureDouble(bool $failAcknowledgement, bool $failDeletion): void
{
    $redis = new WorkerRedisFailureDouble();
    $redis->connect('gplus-redis', 6379, 1.0, null, 0, 1.0);
    $redis->failAcknowledgement = $failAcknowledgement;
    $redis->failDeletion = $failDeletion;

    $consumer = app(WorkerConsumer::class);
    if (!$consumer instanceof WorkerRedisStreamConsumer) {
        throw new RuntimeException('当前 Consumer 不是 RedisStreamConsumer');
    }

    $property = new ReflectionProperty($consumer, 'redis');
    $property->setValue($consumer, $redis);
}

$workerFailures = [];

$workerAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$workerAssertThrows = static function (
    string $exceptionClass,
    callable $callback,
    string $message,
): Throwable {
    try {
        $callback();
    } catch (Throwable $throwable) {
        if (!$throwable instanceof $exceptionClass) {
            throw new RuntimeException(
                $message . '；期望异常：' . $exceptionClass . '；实际异常：' . $throwable::class,
            );
        }

        return $throwable;
    }

    throw new RuntimeException($message . '；未抛出预期异常：' . $exceptionClass);
};

$runWorker = static function (string $name, callable $test) use (&$workerFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $workerFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runWorker('Worker 按顺序执行并完成 ACK 与删除', static function () use ($workerAssertSame): void {
    $context = bootRedisStreamConsumer([WorkerFirstListener::class, WorkerSecondListener::class]);

    try {
        event(new WorkerOccurred('success'));

        $workerAssertSame(1, $context['worker']->runOnce(), 'Worker 单轮消费数量错误');
        $workerAssertSame(
            ['first:success', 'second:success'],
            $context['log']->entries,
            'Worker 未按冻结顺序执行 listener',
        );
        $workerAssertSame(0, $context['redis']->xLen($context['stream']), '成功消息未从主 Stream 删除');
        $pending = $context['redis']->xPending($context['stream'], $context['group']);
        $workerAssertSame(0, $pending[0] ?? null, '成功消息仍残留在 pending');
        $workerAssertSame(0, $context['worker']->runOnce(), '空队列重复消费了消息');
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('Redis key 前缀不影响新消息消费', static function () use ($workerAssertSame): void {
    $context = bootRedisStreamConsumer(
        [WorkerFirstListener::class],
        redisOptions: [Redis::OPT_PREFIX => 'simple-framework-test:'],
    );

    try {
        event(new WorkerOccurred('prefixed'));

        $workerAssertSame(1, $context['worker']->runOnce(), '带前缀的 Stream 消息未被消费');
        $workerAssertSame(['first:prefixed'], $context['log']->entries, '带前缀消息未执行 listener');
        $workerAssertSame(0, $context['redis']->xLen($context['stream']), '带前缀消息未从主 Stream 删除');
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('XACK 失败时保留 pending 并抛出消费异常', static function () use (
    $workerAssertSame,
    $workerAssertThrows,
): void {
    $context = bootRedisStreamConsumer([WorkerFirstListener::class]);

    try {
        event(new WorkerOccurred('ack-failed'));
        injectWorkerRedisFailureDouble(failAcknowledgement: true, failDeletion: false);

        $workerAssertThrows(
            EventConsumeException::class,
            static fn () => $context['worker']->runOnce(),
            'XACK 失败时 Worker 未抛消费异常',
        );

        $pending = $context['redis']->xPending($context['stream'], $context['group']);
        $workerAssertSame(1, $pending[0] ?? null, 'XACK 失败后消息未保留 pending');
        $workerAssertSame(1, $context['redis']->xLen($context['stream']), 'XACK 失败后主消息被删除');
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('XDEL 失败不推翻 ACK 终态并保留可清理残留', static function () use ($workerAssertSame): void {
    $context = bootRedisStreamConsumer([WorkerFirstListener::class]);

    try {
        event(new WorkerOccurred('delete-failed'));
        injectWorkerRedisFailureDouble(failAcknowledgement: false, failDeletion: true);

        $workerAssertSame(1, $context['worker']->runOnce(), 'XDEL 失败不应中断已完成的消费');

        $pending = $context['redis']->xPending($context['stream'], $context['group']);
        $workerAssertSame(0, $pending[0] ?? null, 'XDEL 失败后已 ACK 消息仍残留 pending');
        $workerAssertSame(1, $context['redis']->xLen($context['stream']), 'XDEL 失败后未保留可清理残留');
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('batch_size 限制单轮消费总量', static function () use ($workerAssertSame): void {
    $context = bootRedisStreamConsumer([WorkerFirstListener::class], ['batch_size' => 2]);

    try {
        event(new WorkerOccurred('batch-1'));
        event(new WorkerOccurred('batch-2'));
        event(new WorkerOccurred('batch-3'));

        $workerAssertSame(2, $context['worker']->runOnce(), '单轮消费超过或未达到 batch_size');
        $workerAssertSame(1, $context['redis']->xLen($context['stream']), '单轮消费后剩余消息数量错误');
        $workerAssertSame(1, $context['worker']->runOnce(), '第二轮未消费剩余消息');
        $workerAssertSame(
            ['first:batch-1', 'first:batch-2', 'first:batch-3'],
            $context['log']->entries,
            '分批消费顺序错误',
        );
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('listener 失败后继续执行并写入 failed stream', static function () use ($workerAssertSame): void {
    $context = bootRedisStreamConsumer([
        WorkerFirstListener::class,
        WorkerFailingListener::class,
        WorkerSecondListener::class,
    ]);

    try {
        event(new WorkerOccurred('partial-failed'));
        $sourceEntries = $context['redis']->xRange($context['stream'], '-', '+');
        $sourceMessageId = array_key_first($sourceEntries);
        $context['worker']->runOnce();

        $workerAssertSame(
            ['first:partial-failed', 'failed:partial-failed', 'second:partial-failed'],
            $context['log']->entries,
            '失败 listener 阻断了后续 listener',
        );
        $workerAssertSame(0, $context['redis']->xLen($context['stream']), '失败消息完成归档后未删除');
        $failedEntries = $context['redis']->xRange($context['failed_stream'], '-', '+');
        $workerAssertSame(1, count($failedEntries), '失败消息未写入 failed stream');

        $failedFields = array_values($failedEntries)[0] ?? [];
        $failure = json_decode($failedFields['failure'], true, flags: JSON_THROW_ON_ERROR);
        $workerAssertSame(
            $sourceMessageId,
            $failure['message_id'],
            '失败摘要未保存原始消息 ID',
        );
        $workerAssertSame(3, $failure['listener_total'], '失败摘要 listener 总数错误');
        $workerAssertSame(
            [true, false, true],
            array_column($failure['listeners'], 'succeeded'),
            '失败摘要未保留逐 listener 结果',
        );
        $workerAssertSame(
            'token=[REDACTED] mobile=1**********',
            $failure['listeners'][1]['error_message'],
            '失败摘要未清理敏感信息',
        );
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('failed stream 写入失败时保留 pending', static function () use ($workerAssertSame, $workerAssertThrows): void {
    $context = bootRedisStreamConsumer([WorkerFailingListener::class]);

    try {
        $context['redis']->set($context['failed_stream'], 'occupied-by-string');
        event(new WorkerOccurred('failed-stream-error'));

        $workerAssertThrows(
            EventConsumeException::class,
            static fn () => $context['worker']->runOnce(),
            'failed stream 写入失败时 Worker 未抛消费异常',
        );

        $pending = $context['redis']->xPending($context['stream'], $context['group']);
        $workerAssertSame(1, $pending[0] ?? null, 'failed stream 写入失败后消息未保留 pending');
        $workerAssertSame(1, $context['redis']->xLen($context['stream']), 'failed stream 写入失败后主消息被删除');
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('Worker 使用 XAUTOCLAIM 回收僵尸 pending', static function () use ($workerAssertSame): void {
    $context = bootRedisStreamConsumer([WorkerFirstListener::class]);

    try {
        event(new WorkerOccurred('reclaimed'));
        $context['redis']->xGroup('CREATE', $context['stream'], $context['group'], '0', true);
        $context['redis']->xReadGroup(
            $context['group'],
            'dead-consumer',
            [$context['stream'] => '>'],
            1,
            1,
        );
        usleep(5000);

        $workerAssertSame(1, $context['worker']->runOnce(), 'Worker 未回收 pending 消息');
        $workerAssertSame(['first:reclaimed'], $context['log']->entries, '回收消息未被执行');
        $pending = $context['redis']->xPending($context['stream'], $context['group']);
        $workerAssertSame(0, $pending[0] ?? null, '回收消息执行后仍残留 pending');
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

$runWorker('非法 JSON 消息进入 failed stream', static function () use ($workerAssertSame): void {
    $context = bootRedisStreamConsumer([WorkerFirstListener::class]);

    try {
        $context['redis']->xAdd($context['stream'], '*', ['message' => '{invalid-json']);

        $workerAssertSame(1, $context['worker']->runOnce(), '非法 JSON 消息未被消费处理');
        $workerAssertSame(0, $context['redis']->xLen($context['stream']), '非法 JSON 消息未从主 Stream 清理');
        $workerAssertSame(1, $context['redis']->xLen($context['failed_stream']), '非法 JSON 未进入 failed stream');
    } finally {
        cleanupRedisStreamConsumer($context);
    }
});

if ($workerFailures !== []) {
    foreach ($workerFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'Redis Streams 消费集成测试通过。' . PHP_EOL;
