<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Dispatch;

use DateTimeImmutable;
use HongXunPan\Framework\Core\Request;
use HongXunPan\Framework\Event\Driver\Driver;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ListenerInvoker;
use HongXunPan\Framework\Event\Listener\ListenerRegistry;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Framework\Event\Message\EventMessage;

final readonly class Dispatcher
{
    public function __construct(
        private ListenerRegistry $listeners,
        private ListenerInvoker $invoker,
        private Request $request,
    ) {
    }

    /**
     * @param class-string $eventClass
     * @param class-string $listenerClass
     */
    public function addListener(string $eventClass, string $listenerClass): void
    {
        if (is_a($listenerClass, ShouldQueue::class, true) && !app()->bound(Driver::class)) {
            throw new EventConfigException(
                '注册 ShouldQueue 监听器前必须配置并启动 events.driver.class',
            );
        }

        $this->listeners->addListener($eventClass, $listenerClass);
    }

    public function dispatch(Event $event): void
    {
        /** @var list<class-string<ShouldQueue>> $queuedListeners */
        $queuedListeners = [];

        foreach ($this->listeners->listenersFor($event) as $listenerClass) {
            if (is_a($listenerClass, ShouldQueue::class, true)) {
                $queuedListeners[] = $listenerClass;
                continue;
            }

            $this->invoker->invoke($listenerClass, $event);
        }

        if ($queuedListeners === []) {
            return;
        }

        app(Driver::class)->publish(new EventMessage(
            eventId: bin2hex(random_bytes(16)),
            createdAt: new DateTimeImmutable(),
            event: $event,
            listeners: $queuedListeners,
            traceId: $this->request->requestId,
        ));
    }
}
