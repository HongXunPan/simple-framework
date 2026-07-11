<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Dispatch;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Listener\ListenerCaller;
use HongXunPan\Framework\Event\Listener\ListenerMap;

final readonly class Dispatcher
{
    public function __construct(
        private ListenerMap $listeners,
        private ListenerCaller $caller,
    ) {
    }

    public function dispatch(Event $event): void
    {
        foreach ($this->listeners->listenersFor($event) as $listenerClass) {
            $this->caller->call($listenerClass, $event);
        }
    }
}
