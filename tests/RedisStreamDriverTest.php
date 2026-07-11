<?php

declare(strict_types=1);

use HongXunPan\DB\Redis\Redis as RedisManager;
use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Event\Bootstrap\EventBootstrapper;
use HongXunPan\Framework\Event\Dispatch\Envelope;
use HongXunPan\Framework\Event\Driver\RedisStreamDriver;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Exception\EventPublishException;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Framework\Event\Serialization\Serializer;
use HongXunPan\Framework\Event\Validation\ConfigValidator;
use HongXunPan\Tools\Config\Config;
use HongXunPan\Tools\Env\Env;

final readonly class RedisPublishedOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final readonly class RedisPublishedListener implements ShouldQueue
{
    public function handle(RedisPublishedOccurred $event): void
    {
    }
}

final class ThrowingEventSerializer implements Serializer
{
    public function serialize(Envelope $envelope): string
    {
        throw new RuntimeException('测试序列化失败');
    }

    public function deserialize(string $payload): Envelope
    {
        throw new LogicException('测试不调用反序列化');
    }

    public function assertSupports(string $eventClass): void
    {
    }
}

final class FixedEventSerializer implements Serializer
{
    public function serialize(Envelope $envelope): string
    {
        return '{"envelope_version":1}';
    }

    public function deserialize(string $payload): Envelope
    {
        throw new LogicException('测试不调用反序列化');
    }

    public function assertSupports(string $eventClass): void
    {
    }
}

/** @return array<string, mixed> */
function redisDriverConfig(string $connection, string $stream): array
{
    return [
        'class' => RedisStreamDriver::class,
        'connection' => $connection,
        'stream' => $stream,
        'group' => 'simple-framework-tests',
        'failed_stream' => $stream . ':failed',
        'block_ms' => 1000,
        'batch_size' => 10,
        'claim_idle_ms' => 60000,
        'failed_max_length' => 1000,
    ];
}

/**
 * @param array<string, mixed> $driverConfig
 */
function bootRedisEventApplication(array $driverConfig, string $connectionName): Application
{
    putenv('DEBUG=false');
    $loaded = (new ReflectionClass(Env::class))->getProperty('loaded');
    $loaded->setValue(null, true);

    $redisConfig = [
        'host' => 'gplus-redis',
        'port' => 6379,
        'timeout' => 1.0,
        'readTimeout' => 1.0,
    ];
    Config::$config = [
        'app' => ['timezone' => 'Asia/Shanghai'],
        'singleton' => [],
        'boot' => [[EventBootstrapper::class, 'boot']],
        'database' => ['redis' => [$connectionName => $redisConfig]],
        'events' => [
            'driver' => $driverConfig,
            'listeners' => [
                RedisPublishedOccurred::class => [RedisPublishedListener::class],
            ],
        ],
    ];

    RedisManager::setConfig($redisConfig, $connectionName);
    $application = new Application();
    Application::setInstance($application);
    $application->init('/tmp/simple-framework-event-tests');

    return $application;
}

$redisFailures = [];

$redisAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$redisAssertThrows = static function (
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

$runRedis = static function (string $name, callable $test) use (&$redisFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $redisFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runRedis('Redis Driver 配置缺项时启动校验失败', static function () use ($redisAssertThrows): void {
    $connection = 'event-config-test';
    $stream = 'simple-framework:event-config-test';
    $validConfig = redisDriverConfig($connection, $stream);
    Config::$config = [
        'database' => [
            'redis' => [$connection => ['host' => 'gplus-redis', 'port' => 6379]],
        ],
    ];
    $listeners = [RedisPublishedOccurred::class => [RedisPublishedListener::class]];

    foreach ([
        'connection',
        'stream',
        'group',
        'failed_stream',
        'block_ms',
        'batch_size',
        'claim_idle_ms',
        'failed_max_length',
    ] as $missingKey) {
        $driverConfig = $validConfig;
        unset($driverConfig[$missingKey]);

        $redisAssertThrows(
            EventConfigException::class,
            static fn () => (new ConfigValidator())->resolveDriverClass(
                ['driver' => $driverConfig],
                $listeners,
            ),
            'Redis Driver 缺少配置时未失败：' . $missingKey,
        );
    }
});

$runRedis('Redis Driver 连接名必须存在于 database.redis', static function () use ($redisAssertThrows): void {
    Config::$config = ['database' => ['redis' => []]];

    $redisAssertThrows(
        EventConfigException::class,
        static fn () => (new ConfigValidator())->resolveDriverClass(
            ['driver' => redisDriverConfig('missing-connection', 'simple-framework:missing')],
            [RedisPublishedOccurred::class => [RedisPublishedListener::class]],
        ),
        '不存在的 Redis 连接配置未在启动期失败',
    );
});

$runRedis('Redis Driver 包装序列化异常', static function () use ($redisAssertSame, $redisAssertThrows): void {
    Config::$config = [
        'events' => [
            'driver' => [
                'connection' => 'unused',
                'stream' => 'simple-framework:unused',
            ],
        ],
    ];
    $driver = new RedisStreamDriver(new ThrowingEventSerializer());

    $throwable = $redisAssertThrows(
        EventPublishException::class,
        static fn () => $driver->publish(new Envelope(
            eventId: 'event-serialize-failed',
            occurredAt: new DateTimeImmutable(),
            event: new RedisPublishedOccurred('序列化失败'),
            listeners: [RedisPublishedListener::class],
        )),
        '序列化异常未包装为 EventPublishException',
    );

    $redisAssertSame('测试序列化失败', $throwable->getPrevious()?->getMessage(), '原始序列化异常未保留');
});

$runRedis('Redis Driver 包装连接异常', static function () use ($redisAssertThrows): void {
    $connection = 'missing-runtime-' . bin2hex(random_bytes(6));
    Config::$config = [
        'events' => [
            'driver' => [
                'connection' => $connection,
                'stream' => 'simple-framework:missing-runtime',
            ],
        ],
    ];
    $driver = new RedisStreamDriver(new FixedEventSerializer());

    $throwable = $redisAssertThrows(
        EventPublishException::class,
        static fn () => $driver->publish(new Envelope(
            eventId: 'event-redis-failed',
            occurredAt: new DateTimeImmutable(),
            event: new RedisPublishedOccurred('Redis 失败'),
            listeners: [RedisPublishedListener::class],
        )),
        'Redis 连接异常未包装为 EventPublishException',
    );

    if ($throwable->getPrevious() === null) {
        throw new RuntimeException('Redis 底层异常未保留');
    }
});

$runRedis('一次 dispatch 只写入一条 Redis Stream 消息', static function () use ($redisAssertSame): void {
    $suffix = bin2hex(random_bytes(6));
    $connection = 'event-publish-' . $suffix;
    $stream = 'simple-framework:event-publish:' . $suffix;
    bootRedisEventApplication(redisDriverConfig($connection, $stream), $connection);

    $redis = RedisManager::connection($connection)->getConnection();
    $redis->del($stream);

    try {
        event(new RedisPublishedOccurred('真实 Redis 发布'));

        $entries = $redis->xRange($stream, '-', '+');
        $redisAssertSame(1, count($entries), '一次 dispatch 未严格产生一条 stream entry');
        $entry = array_values($entries)[0] ?? [];
        $redisAssertSame(['message'], array_keys($entry), 'stream entry 未保持单 message 字段');

        $message = json_decode($entry['message'], true, flags: JSON_THROW_ON_ERROR);
        $redisAssertSame(1, $message['envelope_version'], 'Redis 消息 Envelope 版本错误');
        $redisAssertSame(RedisPublishedOccurred::class, $message['event_class'], 'Redis 消息 Event class 错误');
        $redisAssertSame(
            [RedisPublishedListener::class],
            $message['listeners'],
            'Redis 消息未冻结异步 listener',
        );
    } finally {
        $redis->del($stream);
    }
});

if ($redisFailures !== []) {
    foreach ($redisFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'Redis Streams 发布端测试通过。' . PHP_EOL;
