<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Dispatch;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Listener\ListenerCaller;
use HongXunPan\Framework\Event\Listener\ListenerRegistry;

final readonly class Dispatcher
{
    public function __construct(
        private ListenerRegistry $listeners,
        private ListenerCaller $caller,
    ) {
    }

    /**
     * @param class-string $eventClass
     * @param class-string $listenerClass
     */
    public function addListener(string $eventClass, string $listenerClass): void
    {
        $this->listeners->addListener($eventClass, $listenerClass);
    }

    public function dispatch(Event $event): void
    {
        foreach ($this->listeners->listenersFor($event) as $listenerClass) {
            $this->caller->call($listenerClass, $event);
        }
    }
}
