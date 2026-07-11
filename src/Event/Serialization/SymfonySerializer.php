<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Serialization;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Dispatch\Envelope;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Framework\Event\Validation\EventValidator;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer as SymfonyComponentSerializer;
use stdClass;
use UnexpectedValueException;

final readonly class SymfonySerializer implements Serializer
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    private SymfonyComponentSerializer $serializer;

    public function __construct(private EventValidator $events)
    {
        $this->serializer = new SymfonyComponentSerializer(
            [
                new DateTimeNormalizer([DateTimeNormalizer::FORMAT_KEY => self::DATE_FORMAT]),
                new BackedEnumNormalizer(),
                new ObjectNormalizer(),
            ],
            [new JsonEncoder()],
        );
    }

    public function serialize(Envelope $envelope): string
    {
        if ($envelope->envelopeVersion !== Envelope::CURRENT_ENVELOPE_VERSION) {
            throw new UnexpectedValueException("不支持的 Envelope 版本：{$envelope->envelopeVersion}");
        }
        if ($envelope->eventId === '') {
            throw new UnexpectedValueException('Event event_id 必须是非空字符串');
        }
        if ($envelope->traceId !== null && $envelope->traceId === '') {
            throw new UnexpectedValueException('Event trace_id 必须是非空字符串或 null');
        }

        $eventClass = $envelope->event::class;
        $this->events->validate($eventClass);
        $this->validateListeners($envelope->listeners);

        $payload = $this->serializer->normalize($envelope->event, 'json');
        if (!is_array($payload)) {
            throw new UnexpectedValueException("Event 无法规范化为 JSON 对象：{$eventClass}");
        }

        return $this->serializer->encode([
            'envelope_version' => $envelope->envelopeVersion,
            'event_id' => $envelope->eventId,
            'occurred_at' => $envelope->occurredAt->format(self::DATE_FORMAT),
            'trace_id' => $envelope->traceId,
            'event_class' => $eventClass,
            'event_version' => $this->events->versionOf($eventClass),
            'listeners' => $envelope->listeners,
            'payload' => $payload === [] ? new stdClass() : $payload,
        ], 'json', [
            JsonEncode::OPTIONS => JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ]);
    }

    public function deserialize(string $payload): Envelope
    {
        $message = $this->serializer->decode($payload, 'json');
        if (!is_array($message)) {
            throw new UnexpectedValueException('Event JSON 顶层必须是对象');
        }

        $envelopeVersion = $this->positiveInt($message, 'envelope_version');
        if ($envelopeVersion !== Envelope::CURRENT_ENVELOPE_VERSION) {
            throw new UnexpectedValueException("不支持的 Envelope 版本：{$envelopeVersion}");
        }

        $eventClass = $this->nonEmptyString($message, 'event_class');
        $this->assertSupports($eventClass);
        $eventVersion = $this->positiveInt($message, 'event_version');
        if ($eventVersion !== $this->events->versionOf($eventClass)) {
            throw new UnexpectedValueException("不支持的 Event 版本：{$eventClass} v{$eventVersion}");
        }

        $eventPayload = $message['payload'] ?? null;
        if (!is_array($eventPayload)) {
            throw new UnexpectedValueException('Event payload 必须是 JSON 对象');
        }

        $event = $this->serializer->denormalize($eventPayload, $eventClass, 'json');
        if (!$event instanceof Event) {
            throw new UnexpectedValueException("反序列化结果不是 Event：{$eventClass}");
        }

        $listeners = $message['listeners'] ?? null;
        if (!is_array($listeners) || !array_is_list($listeners)) {
            throw new UnexpectedValueException('Event listeners 必须是 JSON 列表');
        }
        $this->validateListeners($listeners);

        $traceId = $message['trace_id'] ?? null;
        if ($traceId !== null && (!is_string($traceId) || $traceId === '')) {
            throw new UnexpectedValueException('Event trace_id 必须是非空字符串或 null');
        }

        return new Envelope(
            eventId: $this->nonEmptyString($message, 'event_id'),
            occurredAt: $this->dateTime($message, 'occurred_at'),
            event: $event,
            listeners: $listeners,
            traceId: $traceId,
            envelopeVersion: $envelopeVersion,
        );
    }

    public function assertSupports(string $eventClass): void
    {
        $this->events->validate($eventClass);
    }

    /** @param array<mixed> $message */
    private function nonEmptyString(array $message, string $key): string
    {
        $value = $message[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new UnexpectedValueException("Event {$key} 必须是非空字符串");
        }

        return $value;
    }

    /** @param array<mixed> $message */
    private function positiveInt(array $message, string $key): int
    {
        $value = $message[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new UnexpectedValueException("Event {$key} 必须是正整数");
        }

        return $value;
    }

    /** @param array<mixed> $message */
    private function dateTime(array $message, string $key): DateTimeImmutable
    {
        $value = $this->nonEmptyString($message, $key);
        $dateTime = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dateTime === false || ($errors !== false
            && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $dateTime->format(self::DATE_FORMAT) !== $value) {
            throw new UnexpectedValueException("Event {$key} 不是合法的固定格式时间");
        }

        return $dateTime;
    }

    /** @param array<mixed> $listeners */
    private function validateListeners(array $listeners): void
    {
        if ($listeners === [] || !array_is_list($listeners)) {
            throw new UnexpectedValueException('Event listeners 必须是非空列表');
        }

        foreach ($listeners as $listenerClass) {
            if (!is_string($listenerClass) || !class_exists($listenerClass)
                || !is_a($listenerClass, ShouldQueue::class, true)) {
                throw new UnexpectedValueException('Event listeners 只能包含 ShouldQueue 监听器类名');
            }
        }
    }
}
