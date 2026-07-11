<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Bootstrap;

use HongXunPan\Framework\Event\Dispatch\Dispatcher;
use HongXunPan\Framework\Event\Driver\Driver;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ListenerRegistry;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Framework\Event\Serialization\Serializer;
use HongXunPan\Framework\Event\Serialization\SymfonySerializer;
use HongXunPan\Framework\Event\Validation\EventValidator;

final class EventBootstrapper
{
    public static function boot(): void
    {
        app()->singleton(EventValidator::class);
        app()->singleton(Serializer::class, SymfonySerializer::class);
        app()->singleton(ListenerRegistry::class);
        app()->singleton(Dispatcher::class);

        $events = config('events', []);
        if (!is_array($events)) {
            throw new EventConfigException('events 配置必须是数组');
        }

        $listeners = $events['listeners'] ?? [];
        if (!is_array($listeners)) {
            throw new EventConfigException('events.listeners 必须是数组');
        }

        self::bindConfiguredDriver($listeners, $events);

        $dispatcher = app(Dispatcher::class);
        foreach ($listeners as $eventClass => $eventListeners) {
            if (!is_string($eventClass) || $eventClass === '') {
                throw new EventConfigException('events.listeners 的事件类必须是非空类名');
            }
            if (!is_array($eventListeners)) {
                throw new EventConfigException("{$eventClass} 的事件监听器配置必须是数组");
            }

            foreach ($eventListeners as $listenerClass) {
                if (!is_string($listenerClass) || $listenerClass === '') {
                    throw new EventConfigException("{$eventClass} 的事件监听器必须是非空类名");
                }

                $dispatcher->addListener($eventClass, $listenerClass);
            }
        }
    }

    /**
     * @param array<mixed> $listeners
     * @param array<mixed> $events
     */
    private static function bindConfiguredDriver(array $listeners, array $events): void
    {
        $requiresDriver = false;
        foreach ($listeners as $eventListeners) {
            if (!is_array($eventListeners)) {
                continue;
            }

            foreach ($eventListeners as $listenerClass) {
                if (is_string($listenerClass) && is_a($listenerClass, ShouldQueue::class, true)) {
                    $requiresDriver = true;
                    break 2;
                }
            }
        }

        if (!array_key_exists('driver', $events)) {
            if ($requiresDriver) {
                throw new EventConfigException('存在 ShouldQueue 监听器时必须配置 events.driver.class');
            }

            return;
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

        app()->singleton(Driver::class, $driverClass);
    }
}
