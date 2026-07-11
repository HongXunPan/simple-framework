<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Validation\EventValidator;
use HongXunPan\Framework\Event\Validation\ListenerValidator;

final class ListenerRegistry
{
    /**
     * @var array<class-string<Event>, list<class-string>>
     */
    private array $listeners = [];

    public function __construct(
        private readonly EventValidator $events,
        private readonly ListenerValidator $listenersValidator,
    ) {
    }

    /**
     * @param class-string $eventClass
     * @param class-string $listenerClass
     */
    public function addListener(string $eventClass, string $listenerClass): void
    {
        $this->events->validate($eventClass);
        $this->listenersValidator->validate($listenerClass, $eventClass);

        $listeners = $this->listeners[$eventClass] ?? [];
        if (in_array($listenerClass, $listeners, true)) {
            throw new EventConfigException(
                "事件监听器重复注册：{$eventClass} -> {$listenerClass}",
            );
        }

        $this->listeners[$eventClass][] = $listenerClass;
    }

    /**
     * @return list<class-string>
     */
    public function listenersFor(Event $event): array
    {
        return $this->listeners[$event::class] ?? [];
    }
}
