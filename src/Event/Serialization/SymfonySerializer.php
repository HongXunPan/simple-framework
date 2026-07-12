<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Serialization;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Framework\Event\Message\EventMessage;
use HongXunPan\Framework\Event\Validation\EventValidator;
use HongXunPan\Framework\Event\Validation\ListenerValidator;
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

    /** @var list<string> */
    private const array MESSAGE_FIELDS = [
        'message_version',
        'event_id',
        'created_at',
        'trace_id',
        'event_class',
        'event_version',
        'listeners',
        'payload',
    ];

    private SymfonyComponentSerializer $serializer;

    public function __construct(
        private EventValidator $events,
        private ListenerValidator $listeners,
    ) {
        $this->serializer = new SymfonyComponentSerializer(
            [
                new DateTimeNormalizer([DateTimeNormalizer::FORMAT_KEY => self::DATE_FORMAT]),
                new BackedEnumNormalizer(),
                new ObjectNormalizer(),
            ],
            [new JsonEncoder()],
        );
    }

    public function serialize(EventMessage $message): string
    {
        if ($message->messageVersion !== EventMessage::VERSION) {
            throw new UnexpectedValueException("不支持的 EventMessage 版本：{$message->messageVersion}");
        }
        if ($message->eventId === '') {
            throw new UnexpectedValueException('Event event_id 必须是非空字符串');
        }
        if ($message->traceId !== null && $message->traceId === '') {
            throw new UnexpectedValueException('Event trace_id 必须是非空字符串或 null');
        }

        $eventClass = $message->event::class;
        $this->events->validate($eventClass);
        $this->validateListeners($message->listeners, $eventClass);

        $payload = $this->serializer->normalize($message->event, 'json');
        if (!is_array($payload)) {
            throw new UnexpectedValueException("Event 无法规范化为 JSON 对象：{$eventClass}");
        }

        return $this->serializer->encode([
            'message_version' => $message->messageVersion,
            'event_id' => $message->eventId,
            'created_at' => $message->createdAt->format(self::DATE_FORMAT),
            'trace_id' => $message->traceId,
            'event_class' => $eventClass,
            'event_version' => $this->events->versionOf($eventClass),
            'listeners' => $message->listeners,
            'payload' => $payload === [] ? new stdClass() : $payload,
        ], 'json', [
            JsonEncode::OPTIONS => JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ]);
    }

    public function deserialize(string $payload): EventMessage
    {
        $data = $this->serializer->decode($payload, 'json');
        if (!is_array($data)) {
            throw new UnexpectedValueException('Event JSON 顶层必须是对象');
        }
        $this->assertExactFields($data, self::MESSAGE_FIELDS, 'EventMessage');

        $messageVersion = $this->positiveInt($data, 'message_version');
        if ($messageVersion !== EventMessage::VERSION) {
            throw new UnexpectedValueException("不支持的 EventMessage 版本：{$messageVersion}");
        }

        $eventClass = $this->nonEmptyString($data, 'event_class');
        $this->events->validate($eventClass);
        $eventVersion = $this->positiveInt($data, 'event_version');
        if ($eventVersion !== $this->events->versionOf($eventClass)) {
            throw new UnexpectedValueException("不支持的 Event 版本：{$eventClass} v{$eventVersion}");
        }

        $listeners = $data['listeners'];
        if (!is_array($listeners) || !array_is_list($listeners)) {
            throw new UnexpectedValueException('Event listeners 必须是 JSON 列表');
        }
        $this->validateListeners($listeners, $eventClass);

        $eventPayload = $data['payload'];
        if (!is_array($eventPayload)) {
            throw new UnexpectedValueException('Event payload 必须是 JSON 对象');
        }
        $this->assertExactFields(
            $eventPayload,
            $this->events->snapshotFieldsOf($eventClass),
            "Event payload ({$eventClass})",
        );

        $event = $this->serializer->denormalize($eventPayload, $eventClass, 'json');
        if (!$event instanceof Event) {
            throw new UnexpectedValueException("反序列化结果不是 Event：{$eventClass}");
        }

        $traceId = $data['trace_id'];
        if ($traceId !== null && (!is_string($traceId) || $traceId === '')) {
            throw new UnexpectedValueException('Event trace_id 必须是非空字符串或 null');
        }

        return new EventMessage(
            eventId: $this->nonEmptyString($data, 'event_id'),
            createdAt: $this->dateTime($data, 'created_at'),
            event: $event,
            listeners: $listeners,
            traceId: $traceId,
            messageVersion: $messageVersion,
        );
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

    /**
     * @param array<mixed> $listeners
     * @param class-string<Event> $eventClass
     */
    private function validateListeners(array $listeners, string $eventClass): void
    {
        if ($listeners === [] || !array_is_list($listeners)) {
            throw new UnexpectedValueException('Event listeners 必须是非空列表');
        }

        $validated = [];
        foreach ($listeners as $listenerClass) {
            if (!is_string($listenerClass) || !class_exists($listenerClass)
                || !is_a($listenerClass, ShouldQueue::class, true)) {
                throw new UnexpectedValueException('Event listeners 只能包含 ShouldQueue 监听器类名');
            }
            if (isset($validated[$listenerClass])) {
                throw new UnexpectedValueException("Event listener 不得重复：{$listenerClass}");
            }

            try {
                $this->listeners->validate($listenerClass, $eventClass);
            } catch (EventConfigException $exception) {
                throw new UnexpectedValueException(
                    "Event listener 与事件契约不匹配：{$eventClass} -> {$listenerClass}",
                    previous: $exception,
                );
            }

            $validated[$listenerClass] = true;
        }
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $expectedFields
     */
    private function assertExactFields(array $payload, array $expectedFields, string $subject): void
    {
        $actualFields = array_keys($payload);
        $missing = array_values(array_diff($expectedFields, $actualFields));
        $extra = array_values(array_diff($actualFields, $expectedFields));
        if ($missing === [] && $extra === []) {
            return;
        }

        $details = [];
        if ($missing !== []) {
            $details[] = '缺少：' . implode(', ', $missing);
        }
        if ($extra !== []) {
            $details[] = '多余：' . implode(', ', $extra);
        }

        throw new UnexpectedValueException("{$subject} 字段必须精确匹配（" . implode('；', $details) . '）');
    }
}
