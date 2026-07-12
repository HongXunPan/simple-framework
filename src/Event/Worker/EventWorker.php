<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Worker;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Consumer\Message;
use HongXunPan\Framework\Event\Dispatch\Envelope;
use HongXunPan\Framework\Event\Exception\EventConsumeException;
use HongXunPan\Framework\Event\Execution\EnvelopeExecutionResult;
use HongXunPan\Framework\Event\Execution\ErrorMessageSanitizer;
use HongXunPan\Framework\Event\Execution\Failure;
use HongXunPan\Framework\Event\Serialization\Serializer;
use HongXunPan\Framework\Event\Validation\EventValidator;
use Throwable;

final readonly class EventWorker
{
    public function __construct(
        private Consumer $consumer,
        private Serializer $serializer,
        private EnvelopeRunner $runner,
        private EventValidator $events,
        private ErrorMessageSanitizer $errors,
    ) {
    }

    /** @param callable(): bool $shouldStop */
    public function run(callable $shouldStop): void
    {
        while (!$shouldStop()) {
            $this->runOnce();
        }
    }

    public function runOnce(): int
    {
        $processed = 0;
        foreach ($this->consumer->receive() as $message) {
            $this->process($message);
            ++$processed;
        }

        return $processed;
    }

    private function process(Message $message): void
    {
        if ($message->body === '') {
            $this->fail($message, null, null, new EventConsumeException('消息缺少 message 字段'));
            return;
        }

        try {
            $envelope = $this->serializer->deserialize($message->body);
        } catch (Throwable $throwable) {
            $this->fail($message, null, null, $throwable);
            return;
        }

        $result = $this->runner->run($envelope);
        if (!$result->succeeded()) {
            $this->fail($message, $envelope, $result);
            return;
        }

        $this->consumer->acknowledge($message);
    }

    private function fail(
        Message $message,
        ?Envelope $envelope,
        ?EnvelopeExecutionResult $result,
        ?Throwable $throwable = null,
    ): void {
        $this->consumer->fail(
            $message,
            new Failure(
                messageId: $message->id,
                eventId: $envelope?->eventId,
                eventClass: $envelope === null ? null : $envelope->event::class,
                eventVersion: $envelope === null ? null : $this->events->versionOf($envelope->event::class),
                traceId: $envelope?->traceId,
                queuedAt: $envelope?->occurredAt,
                listeners: $result?->listeners ?? [],
                errorClass: $throwable === null ? null : $throwable::class,
                errorMessage: $throwable === null
                    ? null
                    : $this->errors->sanitize($throwable->getMessage()),
                failedAt: new DateTimeImmutable(),
            ),
        );
    }
}
