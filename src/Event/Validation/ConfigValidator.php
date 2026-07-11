<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Validation;

use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Driver\Driver;
use HongXunPan\Framework\Event\Driver\RedisStreamDriver;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use Throwable;

final class ConfigValidator
{
    /**
     * @param array<mixed> $events
     * @param array<mixed> $listeners
     * @return class-string<Driver>|null
     */
    public function resolveDriverClass(array $events, array $listeners): ?string
    {
        $requiresDriver = $this->hasQueuedListener($listeners);
        if (!array_key_exists('driver', $events)) {
            if ($requiresDriver) {
                throw new EventConfigException('存在 ShouldQueue 监听器时必须配置 events.driver.class');
            }

            return null;
        }

        $driver = $events['driver'];
        if (!is_array($driver)) {
            throw new EventConfigException('events.driver 必须是数组');
        }

        $driverClass = $driver['class'] ?? null;
        if (!is_string($driverClass) || $driverClass === '') {
            throw new EventConfigException(
                'events.driver.class 必须是非空类名，实际为：' . get_debug_type($driverClass),
            );
        }
        if (!class_exists($driverClass) || !is_a($driverClass, Driver::class, true)) {
            throw new EventConfigException("events.driver.class 必须实现 Driver：{$driverClass}");
        }

        if (is_a($driverClass, RedisStreamDriver::class, true)) {
            $this->validateRedisStreamDriver($driver);
        }

        return $driverClass;
    }

    /**
     * @param class-string<Driver> $driverClass
     * @return class-string<Consumer>
     */
    public function resolveConsumerClass(string $driverClass): string
    {
        try {
            $consumerClass = $driverClass::consumer();
        } catch (Throwable $throwable) {
            throw new EventConfigException(
                "Event Driver 无法声明 Consumer：{$driverClass}",
                previous: $throwable,
            );
        }

        if (!class_exists($consumerClass) || !is_a($consumerClass, Consumer::class, true)) {
            throw new EventConfigException(
                "Event Driver 的 consumer() 必须返回 Consumer 类：{$driverClass}",
            );
        }

        return $consumerClass;
    }

    /** @param array<mixed> $listeners */
    private function hasQueuedListener(array $listeners): bool
    {
        foreach ($listeners as $eventListeners) {
            if (!is_array($eventListeners)) {
                continue;
            }

            foreach ($eventListeners as $listenerClass) {
                if (is_string($listenerClass) && is_a($listenerClass, ShouldQueue::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<mixed> $driver */
    private function validateRedisStreamDriver(array $driver): void
    {
        $connection = $this->nonEmptyString($driver, 'connection');
        $stream = $this->nonEmptyString($driver, 'stream');
        $failedStream = $this->nonEmptyString($driver, 'failed_stream');
        $this->nonEmptyString($driver, 'group');

        foreach (['block_ms', 'batch_size', 'claim_idle_ms', 'failed_max_length'] as $key) {
            $this->positiveInt($driver, $key);
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

        if (!extension_loaded('redis') || !class_exists(\Redis::class)
            || !method_exists(\Redis::class, 'xAdd')) {
            throw new EventConfigException('RedisStreamDriver 需要支持 xAdd 的 phpredis 扩展');
        }
    }

    /** @param array<mixed> $config */
    private function nonEmptyString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new EventConfigException("events.driver.{$key} 必须是非空字符串");
        }

        return $value;
    }

    /** @param array<mixed> $config */
    private function positiveInt(array $config, string $key): int
    {
        $value = $config[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new EventConfigException("events.driver.{$key} 必须是正整数");
        }

        return $value;
    }
}
