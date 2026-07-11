<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Validation\ListenerValidator;
use Throwable;

final readonly class ListenerCaller
{
    public function __construct(private ListenerValidator $validator)
    {
    }

    /**
     * @param class-string $listenerClass
     */
    public function call(string $listenerClass, Event $event): void
    {
        $this->validator->validate($listenerClass, $event::class);

        try {
            $listener = app($listenerClass);
        } catch (Throwable $throwable) {
            throw new EventConfigException(
                "事件监听器无法通过容器解析：{$listenerClass}",
                previous: $throwable,
            );
        }

        $listener->handle($event);
    }
}
