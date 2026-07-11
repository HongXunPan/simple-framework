<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Worker;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Consumer\Message;
use HongXunPan\Framework\Event\Dispatch\Envelope;
use HongXunPan\Framework\Event\Exception\EventConsumeException;
use HongXunPan\Framework\Event\Serialization\Serializer;
use HongXunPan\Framework\Event\Validation\EventValidator;
use JsonException;
use Throwable;

final readonly class EventWorker
{
    public function __construct(
        private Consumer $consumer,
        private Serializer $serializer,
        private EnvelopeRunner $runner,
        private EventValidator $events,
    ) {
    }

    public function run(): never
    {
        while (true) {
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
            $this->failurePayload($message, $envelope, $result, $throwable),
        );
    }

    private function failurePayload(
        Message $message,
        ?Envelope $envelope,
        ?EnvelopeExecutionResult $result,
        ?Throwable $throwable,
    ): string {
        try {
            return json_encode([
                'message_id' => $message->id,
                'event_id' => $envelope?->eventId,
                'event_class' => $envelope === null ? null : $envelope->event::class,
                'event_version' => $envelope === null ? null : $this->events->versionOf($envelope->event::class),
                'listener_total' => $envelope === null ? 0 : count($envelope->listeners),
                'listeners' => $result?->toArray() ?? [],
                'error_class' => $throwable === null ? null : $throwable::class,
                'error_message' => $throwable === null
                    ? null
                    : $this->runner->sanitizeErrorMessage($throwable->getMessage()),
                'failed_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new EventConsumeException('失败消息摘要编码失败', previous: $exception);
        }
    }
}
