<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use Throwable;

final readonly class ListenerInvoker
{
    /**
     * @param class-string $listenerClass
     */
    public function invoke(string $listenerClass, Event $event): void
    {
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
