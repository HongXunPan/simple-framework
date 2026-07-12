<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Worker;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Execution\ErrorMessageSanitizer;
use HongXunPan\Framework\Event\Execution\EventResult;
use HongXunPan\Framework\Event\Execution\ListenerResult;
use HongXunPan\Framework\Event\Listener\ListenerInvoker;
use HongXunPan\Framework\Event\Message\EventMessage;
use Throwable;

final readonly class EventMessageExecutor
{
    public function __construct(
        private ListenerInvoker $invoker,
        private ErrorMessageSanitizer $errors,
    ) {
    }

    public function run(EventMessage $message): EventResult
    {
        $results = [];
        foreach ($message->listeners as $index => $listenerClass) {
            $startedAt = new DateTimeImmutable();
            $startedAtTick = hrtime(true);

            try {
                $this->invoker->invoke($listenerClass, $message->event);
                $results[] = new ListenerResult(
                    listenerClass: $listenerClass,
                    order: $index + 1,
                    startedAt: $startedAt,
                    finishedAt: new DateTimeImmutable(),
                    elapsedMs: $this->elapsedMs($startedAtTick),
                    succeeded: true,
                );
            } catch (Throwable $throwable) {
                $results[] = new ListenerResult(
                    listenerClass: $listenerClass,
                    order: $index + 1,
                    startedAt: $startedAt,
                    finishedAt: new DateTimeImmutable(),
                    elapsedMs: $this->elapsedMs($startedAtTick),
                    succeeded: false,
                    errorClass: $throwable::class,
                    errorMessage: $this->errors->sanitize($throwable->getMessage()),
                );
            }
        }

        return new EventResult($results);
    }

    private function elapsedMs(int $startedAt): int
    {
        return max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
