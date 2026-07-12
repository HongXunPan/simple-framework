<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Worker;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Consumer\ReceivedMessage;
use HongXunPan\Framework\Event\Dispatch\EventMessage;
use HongXunPan\Framework\Event\Exception\EventConsumeException;
use HongXunPan\Framework\Event\Execution\ErrorMessageSanitizer;
use HongXunPan\Framework\Event\Execution\EventResult;
use HongXunPan\Framework\Event\Execution\Failure;
use HongXunPan\Framework\Event\Serialization\Serializer;
use HongXunPan\Framework\Event\Validation\EventValidator;
use Throwable;

final readonly class EventWorker
{
    public function __construct(
        private Consumer $consumer,
        private Serializer $serializer,
        private EventMessageExecutor $executor,
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

    private function process(ReceivedMessage $message): void
    {
        if ($message->body === '') {
            $this->fail($message, null, null, new EventConsumeException('消息缺少 message 字段'));
            return;
        }

        try {
            $eventMessage = $this->serializer->deserialize($message->body);
        } catch (Throwable $throwable) {
            $this->fail($message, null, null, $throwable);
            return;
        }

        $result = $this->executor->run($eventMessage);
        if (!$result->succeeded()) {
            $this->fail($message, $eventMessage, $result);
            return;
        }

        $this->consumer->acknowledge($message);
    }

    private function fail(
        ReceivedMessage $message,
        ?EventMessage $eventMessage,
        ?EventResult $result,
        ?Throwable $throwable = null,
    ): void {
        $this->consumer->fail(
            $message,
            new Failure(
                messageId: $message->id,
                eventId: $eventMessage?->eventId,
                eventClass: $eventMessage === null ? null : $eventMessage->event::class,
                eventVersion: $eventMessage === null
                    ? null
                    : $this->events->versionOf($eventMessage->event::class),
                traceId: $eventMessage?->traceId,
                queuedAt: $eventMessage?->occurredAt,
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
