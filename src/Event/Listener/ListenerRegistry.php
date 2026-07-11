<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Validation\ListenerValidator;
use ReflectionClass;

final class ListenerRegistry
{
    /**
     * @var array<class-string<Event>, list<class-string>>
     */
    private array $listeners = [];

    public function __construct(private readonly ListenerValidator $validator)
    {
    }

    /**
     * @param class-string $eventClass
     * @param class-string $listenerClass
     */
    public function addListener(string $eventClass, string $listenerClass): void
    {
        $this->validateEventClass($eventClass);
        $this->validator->validate($listenerClass, $eventClass);

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

    /**
     * @param class-string $eventClass
     */
    private function validateEventClass(string $eventClass): void
    {
        if (!class_exists($eventClass)) {
            throw new EventConfigException("事件类不存在：{$eventClass}");
        }

        $event = new ReflectionClass($eventClass);
        if (!$event->isInstantiable() || !$event->implementsInterface(Event::class)) {
            throw new EventConfigException("事件类必须是可实例化的 Event：{$eventClass}");
        }
    }
}
