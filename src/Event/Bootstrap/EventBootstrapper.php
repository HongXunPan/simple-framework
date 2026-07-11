<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Bootstrap;

use HongXunPan\Framework\Event\Dispatch\Dispatcher;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ListenerRegistry;

final class EventBootstrapper
{
    public static function boot(): void
    {
        app()->singleton(ListenerRegistry::class);
        app()->singleton(Dispatcher::class);
        $dispatcher = app(Dispatcher::class);

        $listeners = config('events.listeners', []);
        if (!is_array($listeners)) {
            throw new EventConfigException('events.listeners 必须是数组');
        }

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
