<?php

declare(strict_types=1);

use HongXunPan\Framework\Event\Message\EventMessage;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Framework\Event\Serialization\SymfonySerializer;
use HongXunPan\Framework\Event\Validation\EventValidator;
use HongXunPan\Framework\Event\Validation\ListenerValidator;

final readonly class WrongEventSerializationQueuedListener implements ShouldQueue
{
    public function handle(OtherOccurred $event): void
    {
    }
}

/** @return array{SymfonySerializer, array<string, mixed>} */
function strictSerializationMessage(): array
{
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $json = $serializer->serialize(new EventMessage(
        eventId: 'event-strict-protocol',
        createdAt: new DateTimeImmutable('2026-07-11T12:35:00+08:00'),
        event: new SerializableOccurred(
            7,
            '严格协议测试',
            null,
            true,
            1.0,
            SerializationStatus::Approved,
            new DateTimeImmutable('2026-07-11T12:34:56+08:00'),
        ),
        listeners: [SerializationQueuedListener::class],
        traceId: 'trace-strict-protocol',
    ));

    return [$serializer, json_decode($json, true, flags: JSON_THROW_ON_ERROR)];
}

$protocolFailures = [];

$protocolAssertThrows = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (UnexpectedValueException) {
        return;
    }

    throw new RuntimeException($message);
};

$runProtocol = static function (string $name, callable $test) use (&$protocolFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $protocolFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runProtocol('反序列化拒绝 EventMessage 顶层字段漂移', static function () use ($protocolAssertThrows): void {
    [$serializer, $message] = strictSerializationMessage();
    $message['unexpected'] = true;
    $protocolAssertThrows(
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        'EventMessage 多余字段未被拒绝',
    );

    unset($message['unexpected'], $message['trace_id']);
    $protocolAssertThrows(
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        'EventMessage 缺失字段未被拒绝',
    );
});

$runProtocol('反序列化拒绝旧版 EventMessage 结构', static function () use ($protocolAssertThrows): void {
    [$serializer, $message] = strictSerializationMessage();
    $message['message_version'] = 1;
    $message['occurred_at'] = $message['created_at'];
    unset($message['created_at']);

    $protocolAssertThrows(
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        '版本 1 的 occurred_at 旧结构未被拒绝',
    );
});

$runProtocol('反序列化拒绝 Event payload 字段漂移', static function () use ($protocolAssertThrows): void {
    [$serializer, $message] = strictSerializationMessage();
    $message['payload']['unexpected'] = true;
    $protocolAssertThrows(
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        'Event payload 多余字段未被拒绝',
    );

    unset($message['payload']['unexpected'], $message['payload']['name']);
    $protocolAssertThrows(
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        'Event payload 缺失字段未被拒绝',
    );
});

$runProtocol('反序列化拒绝重复 listener', static function () use ($protocolAssertThrows): void {
    [$serializer, $message] = strictSerializationMessage();
    $message['listeners'][] = SerializationQueuedListener::class;

    $protocolAssertThrows(
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        '重复 listener 未被拒绝',
    );
});

$runProtocol('反序列化拒绝与 Event 不匹配的 listener 签名', static function () use ($protocolAssertThrows): void {
    [$serializer, $message] = strictSerializationMessage();
    $message['listeners'] = [WrongEventSerializationQueuedListener::class];

    $protocolAssertThrows(
        static fn () => $serializer->deserialize(json_encode($message, JSON_THROW_ON_ERROR)),
        'listener 与 Event 签名不匹配时未被拒绝',
    );
});

if ($protocolFailures !== []) {
    foreach ($protocolFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'Event 持久化消息严格协议测试通过。' . PHP_EOL;
