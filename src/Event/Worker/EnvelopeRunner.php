<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Worker;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Dispatch\Envelope;
use HongXunPan\Framework\Event\Execution\EnvelopeExecutionResult;
use HongXunPan\Framework\Event\Execution\ErrorMessageSanitizer;
use HongXunPan\Framework\Event\Execution\ListenerExecutionResult;
use HongXunPan\Framework\Event\Listener\ListenerCaller;
use Throwable;

final readonly class EnvelopeRunner
{
    public function __construct(
        private ListenerCaller $caller,
        private ErrorMessageSanitizer $errors,
    ) {
    }

    public function run(Envelope $envelope): EnvelopeExecutionResult
    {
        $results = [];
        foreach ($envelope->listeners as $index => $listenerClass) {
            $startedAt = new DateTimeImmutable();
            $startedAtTick = hrtime(true);

            try {
                $this->caller->call($listenerClass, $envelope->event);
                $results[] = new ListenerExecutionResult(
                    listenerClass: $listenerClass,
                    order: $index + 1,
                    startedAt: $startedAt,
                    finishedAt: new DateTimeImmutable(),
                    elapsedMs: $this->elapsedMs($startedAtTick),
                    succeeded: true,
                );
            } catch (Throwable $throwable) {
                $results[] = new ListenerExecutionResult(
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

        return new EnvelopeExecutionResult($results);
    }

    private function elapsedMs(int $startedAt): int
    {
        return max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
