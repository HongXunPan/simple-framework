<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Bootstrap;

use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Consumer\RedisStreamConsumer;
use HongXunPan\Framework\Event\Dispatch\Dispatcher;
use HongXunPan\Framework\Event\Driver\Driver;
use HongXunPan\Framework\Event\Driver\RedisStreamDriver;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ListenerRegistry;
use HongXunPan\Framework\Event\Serialization\Serializer;
use HongXunPan\Framework\Event\Serialization\SymfonySerializer;
use HongXunPan\Framework\Event\Validation\ConfigValidator;
use HongXunPan\Framework\Event\Validation\EventValidator;
use HongXunPan\Framework\Event\Worker\EnvelopeRunner;
use HongXunPan\Framework\Event\Worker\EventWorker;

final class EventBootstrapper
{
    public static function boot(): void
    {
        app()->singleton(EventValidator::class);
        app()->singleton(ConfigValidator::class);
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

        $driverClass = app(ConfigValidator::class)->resolveDriverClass($events, $listeners);
        if ($driverClass !== null) {
            app()->singleton(Driver::class, $driverClass);
            if (is_a($driverClass, RedisStreamDriver::class, true)) {
                app()->singleton(Consumer::class, RedisStreamConsumer::class);
                app()->singleton(EnvelopeRunner::class);
                app()->singleton(EventWorker::class);
            }
        }

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

}
