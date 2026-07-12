<?php

declare(strict_types=1);

use HongXunPan\Framework\Event\Message\EventMessage;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Framework\Event\Serialization\Serializer;
use HongXunPan\Framework\Event\Serialization\SymfonySerializer;
use HongXunPan\Framework\Event\Validation\EventValidator;
use HongXunPan\Framework\Event\Validation\ListenerValidator;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

enum SerializationStatus: string
{
    case Approved = 'approved';
}

final readonly class SerializableOccurred implements Event
{
    public const int VERSION = 2;

    public function __construct(
        public int $id,
        public string $name,
        public ?string $note,
        public bool $enabled,
        public float $score,
        public SerializationStatus $status,
        public DateTimeImmutable $approvedAt,
    ) {
    }
}

final readonly class SerializationQueuedListener implements ShouldQueue
{
    public function handle(SerializableOccurred $event): void
    {
    }
}

final readonly class EmptyEventQueuedListener implements ShouldQueue
{
    public function handle(OtherOccurred $event): void
    {
    }
}

final readonly class InvalidArrayOccurred implements Event
{
    /** @param list<int> $ids */
    public function __construct(public array $ids)
    {
    }
}

final readonly class InvalidArrayListener
{
    public function handle(InvalidArrayOccurred $event): void
    {
    }
}

final readonly class InvalidMixedOccurred implements Event
{
    public function __construct(public mixed $value)
    {
    }
}

final readonly class InvalidObjectOccurred implements Event
{
    public function __construct(public object $value)
    {
    }
}

final class FakeOrmModel
{
}

final readonly class InvalidModelOccurred implements Event
{
    public function __construct(public FakeOrmModel $model)
    {
    }
}

final readonly class InvalidUnionOccurred implements Event
{
    public function __construct(public int|string $value)
    {
    }
}

final readonly class InvalidPrivateOccurred implements Event
{
    public function __construct(private string $value)
    {
    }
}

final class InvalidMutableOccurred implements Event
{
    public function __construct(public string $value)
    {
    }
}

readonly class InvalidNonFinalOccurred implements Event
{
    public function __construct(public string $value)
    {
    }
}

final readonly class InvalidVersionOccurred implements Event
{
    public const int VERSION = 0;
}

$serializationFailures = [];

$serializationAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$serializationAssertThrows = static function (
    string $exceptionClass,
    callable $callback,
    string $message,
): Throwable {
    try {
        $callback();
    } catch (Throwable $throwable) {
        if (!$throwable instanceof $exceptionClass) {
            throw new RuntimeException(
                $message . '；期望异常：' . $exceptionClass . '；实际异常：' . $throwable::class,
            );
        }

        return $throwable;
    }

    throw new RuntimeException($message . '；未抛出预期异常：' . $exceptionClass);
};

$runSerialization = static function (string $name, callable $test) use (&$serializationFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $serializationFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runSerialization('Event Serializer 在进程内保持单例', static function () use ($serializationAssertSame): void {
    bootApplication();

    $serializationAssertSame(
        app(Serializer::class),
        app(Serializer::class),
        'Serializer 未保持进程级单例',
    );
});

$runSerialization('Symfony JSON 完整往返标量枚举与时间', static function () use ($serializationAssertSame): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $event = new SerializableOccurred(
        id: 7,
        name: '校友卡审核通过',
        note: null,
        enabled: true,
        score: 9.5,
        status: SerializationStatus::Approved,
        approvedAt: new DateTimeImmutable('2026-07-11T12:34:56.123456+08:00'),
    );
    $eventMessage = new EventMessage(
        eventId: 'event-20260711-0001',
        createdAt: new DateTimeImmutable('2026-07-11T12:35:00.654321+08:00'),
        event: $event,
        listeners: [SerializationQueuedListener::class],
        traceId: 'trace-0759',
    );

    $json = $serializer->serialize($eventMessage);
    $message = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($json);

    $serializationAssertSame(2, $message['message_version'], 'EventMessage 版本未写入 JSON');
    $serializationAssertSame(SerializableOccurred::class, $message['event_class'], 'Event class 未写入 JSON');
    $serializationAssertSame(2, $message['event_version'], 'Event version 未写入 JSON');
    $serializationAssertSame('approved', $message['payload']['status'], '枚举未规范化为 backing value');
    $serializationAssertSame('event-20260711-0001', $restored->eventId, 'eventId 往返错误');
    $serializationAssertSame('trace-0759', $restored->traceId, 'traceId 往返错误');
    $serializationAssertSame(
        '2026-07-11T12:35:00.654321+08:00',
        $restored->createdAt->format('Y-m-d\TH:i:s.uP'),
        'createdAt 往返错误',
    );
    $serializationAssertSame([SerializationQueuedListener::class], $restored->listeners, 'listener 顺序往返错误');
    $serializationAssertSame(SerializableOccurred::class, $restored->event::class, 'Event 类型恢复错误');
    $serializationAssertSame(7, $restored->event->id, '标量字段往返错误');
    $serializationAssertSame(null, $restored->event->note, 'nullable 字段往返错误');
    $serializationAssertSame(SerializationStatus::Approved, $restored->event->status, '枚举字段往返错误');
    $serializationAssertSame(
        '2026-07-11T12:34:56.123456+08:00',
        $restored->event->approvedAt->format('Y-m-d\TH:i:s.uP'),
        'Event 时间字段往返错误',
    );
});

$runSerialization('未声明 VERSION 的 Event 默认使用版本 1', static function () use ($serializationAssertSame): void {
    $validator = new EventValidator();

    $serializationAssertSame(1, $validator->versionOf(DemoOccurred::class), 'Event 默认版本不是 1');
});

$runSerialization('无属性 Event 使用空 JSON 对象并可往返', static function () use ($serializationAssertSame): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $json = $serializer->serialize(new EventMessage(
        eventId: 'event-empty-payload',
        createdAt: new DateTimeImmutable('2026-07-11T12:35:00+08:00'),
        event: new OtherOccurred(),
        listeners: [EmptyEventQueuedListener::class],
    ));
    $restored = $serializer->deserialize($json);

    $serializationAssertSame(true, str_contains($json, '"payload":{}'), '空 Event payload 不是 JSON 对象');
    $serializationAssertSame(OtherOccurred::class, $restored->event::class, '空 Event 未正确恢复');
});

$runSerialization('非法 Event 快照在启动期失败', static function () use ($serializationAssertThrows): void {
    $serializationAssertThrows(
        EventConfigException::class,
        static fn () => bootApplication([
            InvalidArrayOccurred::class => [InvalidArrayListener::class],
        ]),
        '数组 Event 未在启动期失败',
    );
});

$runSerialization('Event Validator 拒绝非白名单结构', static function () use ($serializationAssertThrows): void {
    $validator = new EventValidator();
    foreach ([
        InvalidArrayOccurred::class,
        InvalidMixedOccurred::class,
        InvalidObjectOccurred::class,
        InvalidModelOccurred::class,
        InvalidUnionOccurred::class,
        InvalidPrivateOccurred::class,
        InvalidMutableOccurred::class,
        InvalidNonFinalOccurred::class,
        InvalidVersionOccurred::class,
    ] as $eventClass) {
        $serializationAssertThrows(
            EventConfigException::class,
            static fn () => $validator->validate($eventClass),
            '非法 Event 未被拒绝：' . $eventClass,
        );
    }
});

$runSerialization('反序列化拒绝未知 EventMessage 版本与 Event 版本', static function () use ($serializationAssertThrows): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $json = $serializer->serialize(new EventMessage(
        eventId: 'event-version-test',
        createdAt: new DateTimeImmutable('2026-07-11T12:35:00+08:00'),
        event: new SerializableOccurred(
            7,
            '版本测试',
            null,
            true,
            1.0,
            SerializationStatus::Approved,
            new DateTimeImmutable('2026-07-11T12:34:56+08:00'),
        ),
        listeners: [SerializationQueuedListener::class],
    ));
    $message = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    $message['message_version'] = 3;
    $serializationAssertThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        '未知 EventMessage 版本未被拒绝',
    );

    $message['message_version'] = 2;
    $message['event_version'] = 3;
    $serializationAssertThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        '未知 Event 版本未被拒绝',
    );
});

$runSerialization('反序列化拒绝非法 JSON', static function () use ($serializationAssertThrows): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());

    $serializationAssertThrows(
        NotEncodableValueException::class,
        static fn () => $serializer->deserialize('{invalid-json'),
        '非法 JSON 未被拒绝',
    );
});

$runSerialization('序列化拒绝非法 EventMessage 元数据', static function () use ($serializationAssertThrows): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $event = new SerializableOccurred(
        7,
        '元数据测试',
        null,
        true,
        1.0,
        SerializationStatus::Approved,
        new DateTimeImmutable('2026-07-11T12:34:56+08:00'),
    );

    foreach ([
        new EventMessage('', new DateTimeImmutable(), $event, [SerializationQueuedListener::class]),
        new EventMessage('event-empty-trace', new DateTimeImmutable(), $event, [SerializationQueuedListener::class], ''),
        new EventMessage('event-version', new DateTimeImmutable(), $event, [SerializationQueuedListener::class], null, 3),
    ] as $eventMessage) {
        $serializationAssertThrows(
            UnexpectedValueException::class,
            static fn () => $serializer->serialize($eventMessage),
            '非法 EventMessage 元数据未被拒绝',
        );
    }
});

$runSerialization('反序列化拒绝宽松时间格式', static function () use ($serializationAssertThrows): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $json = $serializer->serialize(new EventMessage(
        eventId: 'event-time-format',
        createdAt: new DateTimeImmutable('2026-07-11T12:35:00+08:00'),
        event: new SerializableOccurred(
            7,
            '时间测试',
            null,
            true,
            1.0,
            SerializationStatus::Approved,
            new DateTimeImmutable('2026-07-11T12:34:56+08:00'),
        ),
        listeners: [SerializationQueuedListener::class],
    ));
    $message = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $message['created_at'] = '2026-07-11 12:35:00';

    $serializationAssertThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        '宽松时间格式未被拒绝',
    );
});

if ($serializationFailures !== []) {
    foreach ($serializationFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'Event 结构校验与 Symfony JSON 测试通过。' . PHP_EOL;
