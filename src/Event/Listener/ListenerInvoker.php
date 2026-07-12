<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use Throwable;

final readonly class ListenerInvoker
{
    public function __construct(private ListenerFailureReporter $failureReporter)
    {
    }

    /**
     * @param class-string $listenerClass
     */
    public function invoke(string $listenerClass, Event $event): void
    {
        try {
            $listener = app($listenerClass);
        } catch (Throwable $throwable) {
            $this->handleFailure($listenerClass, $event, new EventConfigException(
                "事件监听器无法通过容器解析：{$listenerClass}",
                previous: $throwable,
            ));
            return;
        }

        try {
            $listener->handle($event);
        } catch (Throwable $throwable) {
            $this->handleFailure($listenerClass, $event, $throwable);
        }
    }

    /** @param class-string $listenerClass */
    private function handleFailure(
        string $listenerClass,
        Event $event,
        Throwable $throwable,
    ): void {
        if (!is_a($listenerClass, ShouldHandleBestEffort::class, true)) {
            throw $throwable;
        }

        try {
            $this->failureReporter->report($listenerClass, $event, $throwable);
        } catch (Throwable $reporterFailure) {
            error_log(sprintf(
                '[simple-framework:event:best-effort] failure reporter error: %s',
                $reporterFailure::class,
            ));
        }
    }
}
