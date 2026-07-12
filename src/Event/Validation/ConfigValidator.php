<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Validation;

use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Driver\Driver;
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

        $this->validateDriverConfig($driverClass, $driver);

        return $driverClass;
    }

    /**
     * @param class-string<Driver> $driverClass
     * @return class-string<Consumer>
     */
    public function resolveConsumerClass(string $driverClass): string
    {
        try {
            $consumerClass = $driverClass::consumerClass();
        } catch (Throwable $throwable) {
            throw new EventConfigException(
                "Event Driver 无法声明 Consumer：{$driverClass}",
                previous: $throwable,
            );
        }

        if (!class_exists($consumerClass) || !is_a($consumerClass, Consumer::class, true)) {
            throw new EventConfigException(
                "Event Driver 的 consumerClass() 必须返回 Consumer 类：{$driverClass}",
            );
        }

        return $consumerClass;
    }

    /**
     * @param class-string<Driver> $driverClass
     * @param array<mixed> $config
     */
    private function validateDriverConfig(string $driverClass, array $config): void
    {
        try {
            $driverClass::validateConfig($config);
        } catch (EventConfigException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw new EventConfigException(
                "Event Driver 配置校验失败：{$driverClass}",
                previous: $throwable,
            );
        }
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

}
