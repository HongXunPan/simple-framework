<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use Throwable;

interface ListenerFailureReporter
{
    /** @param class-string $listenerClass */
    public function report(string $listenerClass, Event $event, Throwable $throwable): void;
}
