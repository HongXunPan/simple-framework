<?php

declare(strict_types=1);

use HongXunPan\Framework\Core\Application as ContractWorkerApplication;
use HongXunPan\Framework\Event\Consumer\Consumer as ContractConsumer;
use HongXunPan\Framework\Event\Consumer\Failure as ContractFailure;
use HongXunPan\Framework\Event\Consumer\Message as ContractMessage;
use HongXunPan\Framework\Event\Dispatch\Envelope as ContractEnvelope;
use HongXunPan\Framework\Event\Event as ContractWorkerEvent;
use HongXunPan\Framework\Event\Listener\ListenerCaller as ContractListenerCaller;
use HongXunPan\Framework\Event\Serialization\Serializer as ContractWorkerSerializer;
use HongXunPan\Framework\Event\Validation\EventValidator as ContractEventValidator;
use HongXunPan\Framework\Event\Worker\EnvelopeRunner as ContractEnvelopeRunner;
use HongXunPan\Framework\Event\Worker\EventWorker;

final readonly class ContractWorkerOccurred implements ContractWorkerEvent
{
    public function __construct(public string $name)
    {
    }
}

final class ContractWorkerLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class ContractWorkerListener
{
    public function __construct(private ContractWorkerLog $log)
    {
    }

    public function handle(ContractWorkerOccurred $event): void
    {
        $this->log->entries[] = $event->name;
    }
}

final readonly class ContractFailingWorkerListener
{
    public function handle(ContractWorkerOccurred $event): void
    {
        throw new RuntimeException('消费失败');
    }
}

final class FakeEventConsumer implements ContractConsumer
{
    /** @var list<ContractMessage> */
    public array $messages;

    /** @var list<ContractMessage> */
    public array $acknowledged = [];

    /** @var list<array{message: ContractMessage, failure: ContractFailure}> */
    public array $failed = [];

    public int $receiveCalls = 0;

    public ?Throwable $receiveFailure = null;

    /** @param list<ContractMessage> $messages */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function receive(): iterable
    {
        ++$this->receiveCalls;
        if ($this->receiveFailure !== null) {
            throw $this->receiveFailure;
        }

        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }

    public function acknowledge(ContractMessage $message): void
    {
        $this->acknowledged[] = $message;
    }

    public function fail(ContractMessage $message, ContractFailure $failure): void
    {
        $this->failed[] = compact('message', 'failure');
    }
}

final readonly class FixedEnvelopeSerializer implements ContractWorkerSerializer
{
    public function __construct(
        private ?ContractEnvelope $envelope = null,
        private ?Throwable $failure = null,
    ) {
    }

    public function serialize(ContractEnvelope $envelope): string
    {
        return 'test-payload';
    }

    public function deserialize(string $payload): ContractEnvelope
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->envelope ?? throw new LogicException('测试 Envelope 未配置');
    }

    public function assertSupports(string $eventClass): void
    {
    }
}

/**
 * @param list<ContractMessage>|null $messages
 * @return array{worker: EventWorker, consumer: FakeEventConsumer, log: ContractWorkerLog}
 */
function createContractEventWorker(
    ContractWorkerSerializer $serializer,
    ?array $messages = null,
): array {
    $application = new ContractWorkerApplication();
    ContractWorkerApplication::setInstance($application);
    $log = new ContractWorkerLog();
    $application->instance(ContractWorkerLog::class, $log);

    $consumer = new FakeEventConsumer(
        $messages ?? [new ContractMessage('message-1', 'test-payload')],
    );
    $worker = new EventWorker(
        $consumer,
        $serializer,
        new ContractEnvelopeRunner(new ContractListenerCaller()),
        new ContractEventValidator(),
    );

    return compact('worker', 'consumer', 'log');
}

function contractEnvelope(array $listeners): ContractEnvelope
{
    return new ContractEnvelope(
        eventId: 'event-contract-1',
        occurredAt: new DateTimeImmutable(),
        event: new ContractWorkerOccurred('contract'),
        listeners: $listeners,
    );
}

$contractWorkerFailures = [];

$contractWorkerAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$runContractWorker = static function (string $name, callable $test) use (&$contractWorkerFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $contractWorkerFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runContractWorker('EventWorker 只依赖 Consumer 契约完成消息确认', static function () use (
    $contractWorkerAssertSame,
): void {
    $envelope = contractEnvelope([ContractWorkerListener::class]);
    $context = createContractEventWorker(new FixedEnvelopeSerializer($envelope));

    $contractWorkerAssertSame(1, $context['worker']->runOnce(), 'EventWorker 消费数量错误');
    $contractWorkerAssertSame(['contract'], $context['log']->entries, 'EventWorker 未执行 Envelope listener');
    $contractWorkerAssertSame(['message-1'], array_column($context['consumer']->acknowledged, 'id'), '成功消息未确认');
    $contractWorkerAssertSame([], $context['consumer']->failed, '成功消息错误进入失败流程');
    $contractWorkerAssertSame(0, $context['worker']->runOnce(), 'Consumer 已清空后仍重复消费消息');
});

$runContractWorker('EventWorker 启动前收到停止条件时不领取消息', static function () use (
    $contractWorkerAssertSame,
): void {
    $envelope = contractEnvelope([ContractWorkerListener::class]);
    $context = createContractEventWorker(new FixedEnvelopeSerializer($envelope));

    $context['worker']->run(static fn (): bool => true);

    $contractWorkerAssertSame(0, $context['consumer']->receiveCalls, '停止条件成立后仍领取消息');
    $contractWorkerAssertSame([], $context['consumer']->acknowledged, '停止条件成立后仍执行消息');
});

$runContractWorker('EventWorker 只在完整批次之间停止', static function () use (
    $contractWorkerAssertSame,
): void {
    $envelope = contractEnvelope([ContractWorkerListener::class]);
    $context = createContractEventWorker(
        new FixedEnvelopeSerializer($envelope),
        [
            new ContractMessage('message-1', 'test-payload'),
            new ContractMessage('message-2', 'test-payload'),
        ],
    );
    $checks = 0;

    $context['worker']->run(static function () use (&$checks): bool {
        return $checks++ > 0;
    });

    $contractWorkerAssertSame(1, $context['consumer']->receiveCalls, 'Worker 停止前消费批次数错误');
    $contractWorkerAssertSame(
        ['message-1', 'message-2'],
        array_column($context['consumer']->acknowledged, 'id'),
        'Worker 在批次内部中断消息执行',
    );
    $contractWorkerAssertSame(['contract', 'contract'], $context['log']->entries, '批次内 listener 未全部执行');
});

$runContractWorker('EventWorker 不吞没 Consumer 运行异常', static function () use (
    $contractWorkerAssertSame,
): void {
    $envelope = contractEnvelope([ContractWorkerListener::class]);
    $context = createContractEventWorker(new FixedEnvelopeSerializer($envelope));
    $context['consumer']->receiveFailure = new RuntimeException('Consumer 读取失败');

    try {
        $context['worker']->run(static fn (): bool => false);
    } catch (RuntimeException $throwable) {
        $contractWorkerAssertSame('Consumer 读取失败', $throwable->getMessage(), 'Consumer 异常被改写');
        return;
    }

    throw new RuntimeException('EventWorker 吞没了 Consumer 运行异常');
});

$runContractWorker('EventWorker 将 listener 失败交给 Consumer', static function () use (
    $contractWorkerAssertSame,
): void {
    $envelope = contractEnvelope([ContractFailingWorkerListener::class]);
    $context = createContractEventWorker(new FixedEnvelopeSerializer($envelope));

    $contractWorkerAssertSame(1, $context['worker']->runOnce(), '失败消息未计入消费数量');
    $contractWorkerAssertSame([], $context['consumer']->acknowledged, '失败消息被直接确认');
    $contractWorkerAssertSame(1, count($context['consumer']->failed), '失败消息未交给 Consumer');

    $failure = $context['consumer']->failed[0]['failure'];
    $contractWorkerAssertSame('message-1', $failure->messageId, 'Failure 消息 ID 错误');
    $contractWorkerAssertSame(false, $failure->listeners[0]->succeeded, 'Failure 缺少 listener 结果');
});

$runContractWorker('EventWorker 将反序列化失败交给 Consumer', static function () use (
    $contractWorkerAssertSame,
): void {
    $context = createContractEventWorker(
        new FixedEnvelopeSerializer(failure: new RuntimeException('无法解析消息')),
    );

    $contractWorkerAssertSame(1, $context['worker']->runOnce(), '反序列化失败消息未计数');
    $failure = $context['consumer']->failed[0]['failure'];
    $contractWorkerAssertSame(RuntimeException::class, $failure->errorClass, '反序列化异常类型未保留');
    $contractWorkerAssertSame('message-1', $failure->messageId, 'Failure 消息 ID 错误');
});

if ($contractWorkerFailures !== []) {
    foreach ($contractWorkerFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'EventWorker 契约测试通过。' . PHP_EOL;
