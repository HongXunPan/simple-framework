<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;

final readonly class ListenerMap
{
    /**
     * @var array<class-string<Event>, list<class-string>>
     */
    private array $listeners;

    /**
     * @param array<class-string<Event>, list<class-string>>|null $listeners
     */
    public function __construct(?array $listeners = null)
    {
        $listeners ??= config('events.listeners', []);
        if (!is_array($listeners)) {
            throw new EventConfigException('events.listeners 必须是数组');
        }

        $this->listeners = $listeners;
    }

    /**
     * @return list<class-string>
     */
    public function listenersFor(Event $event): array
    {
        $eventClass = $event::class;
        $listeners = $this->listeners[$eventClass] ?? [];
        if (!is_array($listeners)) {
            throw new EventConfigException("{$eventClass} 的事件监听器配置必须是数组");
        }

        foreach ($listeners as $listener) {
            if (!is_string($listener) || $listener === '') {
                throw new EventConfigException("{$eventClass} 的事件监听器必须是非空类名");
            }
        }

        return array_values($listeners);
    }
}
