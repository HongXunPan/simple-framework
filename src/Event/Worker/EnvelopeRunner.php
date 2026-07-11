<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Worker;

use HongXunPan\Framework\Event\Dispatch\Envelope;
use HongXunPan\Framework\Event\Listener\ListenerCaller;
use Throwable;

final readonly class EnvelopeRunner
{
    public function __construct(private ListenerCaller $caller)
    {
    }

    public function run(Envelope $envelope): EnvelopeExecutionResult
    {
        $results = [];
        foreach ($envelope->listeners as $index => $listenerClass) {
            $startedAt = hrtime(true);

            try {
                $this->caller->call($listenerClass, $envelope->event);
                $results[] = new ListenerExecutionResult(
                    listenerClass: $listenerClass,
                    order: $index + 1,
                    elapsedMs: $this->elapsedMs($startedAt),
                    succeeded: true,
                );
            } catch (Throwable $throwable) {
                $results[] = new ListenerExecutionResult(
                    listenerClass: $listenerClass,
                    order: $index + 1,
                    elapsedMs: $this->elapsedMs($startedAt),
                    succeeded: false,
                    errorClass: $throwable::class,
                    errorMessage: $this->sanitizeErrorMessage($throwable->getMessage()),
                );
            }
        }

        return new EnvelopeExecutionResult($results);
    }

    private function elapsedMs(int $startedAt): int
    {
        return max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
    }

    public function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace('/[\r\n\t]+/', ' ', trim($message)) ?? '';
        $message = preg_replace(
            '/\b(token|password|secret|openid|cookie)\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $message,
        ) ?? $message;
        $message = preg_replace('/\b1[3-9]\d{9}\b/', '1**********', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
