<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Core\Request;
use HongXunPan\Framework\Event\Bootstrap\EventBootstrapper;
use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Consumer\ReceivedMessage;
use HongXunPan\Framework\Event\Execution\Failure;
use HongXunPan\Framework\Event\Dispatch\Dispatcher;
use HongXunPan\Framework\Event\Message\EventMessage;
use HongXunPan\Framework\Event\Driver\Driver;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ShouldQueue;
use HongXunPan\Tools\Config\Config;
use HongXunPan\Tools\Env\Env;

final readonly class DemoOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final readonly class OtherOccurred implements Event
{
}

final class InvocationLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class FirstListener
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function handle(DemoOccurred $event): void
    {
        $this->log->entries[] = 'first:' . $event->name;
    }
}

final readonly class SecondListener
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function handle(DemoOccurred $event): void
    {
        $this->log->entries[] = 'second:' . $event->name;
    }
}

final readonly class ThrowingListener
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function handle(DemoOccurred $event): void
    {
        $this->log->entries[] = 'throwing:' . $event->name;
        throw new \RuntimeException('监听器执行失败');
    }
}

final readonly class FirstQueuedListener implements ShouldQueue
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function handle(DemoOccurred $event): void
    {
        $this->log->entries[] = 'queued-first:' . $event->name;
    }
}

final readonly class SecondQueuedListener implements ShouldQueue
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function handle(DemoOccurred $event): void
    {
        $this->log->entries[] = 'queued-second:' . $event->name;
    }
}

final class FakeDriver implements Driver
{
    /** @var list<EventMessage> */
    public array $messages = [];

    public static function validateConfig(array $config): void
    {
    }

    public static function consumerClass(): string
    {
        return SyncFakeConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
        $this->messages[] = $message;
    }
}

final class SyncFakeConsumer implements Consumer
{
    public function receive(): iterable
    {
        return [];
    }

    public function acknowledge(ReceivedMessage $message): void
    {
    }

    public function fail(ReceivedMessage $message, Failure $failure): void
    {
    }
}

final class InvalidConsumerDriver implements Driver
{
    public static function validateConfig(array $config): void
    {
    }

    public static function consumerClass(): string
    {
        return InvalidDriver::class;
    }

    public function publish(EventMessage $message): void
    {
    }
}

final class RejectingConfigDriver implements Driver
{
    public static function validateConfig(array $config): void
    {
        throw new EventConfigException('Fake Driver 配置无效');
    }

    public static function consumerClass(): string
    {
        return SyncFakeConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
    }
}

final class InvalidDriver
{
}

final class MissingHandleListener
{
}

final class WrongEventListener
{
    public function handle(OtherOccurred $event): void
    {
    }
}

final class InvalidReturnListener
{
    public function handle(DemoOccurred $event): int
    {
        return 1;
    }
}

/**
 * @return array{Application, InvocationLog}
 */
function bootApplication(
    mixed $listeners = [],
    bool $withEventsConfig = true,
    mixed $driverClass = null,
): array
{
    putenv('DEBUG=false');
    $loaded = (new ReflectionClass(Env::class))->getProperty('loaded');
    $loaded->setValue(null, true);

    Config::$config = [
        'app' => ['timezone' => 'Asia/Shanghai'],
        'singleton' => [Request::class],
        'boot' => [
            [EventBootstrapper::class, 'boot'],
        ],
    ];
    if ($withEventsConfig) {
        Config::$config['events'] = ['listeners' => $listeners];
        if ($driverClass !== null) {
            Config::$config['events']['driver'] = ['class' => $driverClass];
        }
    }

    $application = new Application();
    Application::setInstance($application);
    $application->init('/tmp/simple-framework-event-tests');

    $log = new InvocationLog();
    $application->instance(InvocationLog::class, $log);

    return [$application, $log];
}

$failures = [];

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$assertThrows = static function (
    string $exceptionClass,
    callable $callback,
    string $message,
): \Throwable {
    try {
        $callback();
    } catch (\Throwable $throwable) {
        if (!$throwable instanceof $exceptionClass) {
            throw new \RuntimeException(
                $message . '；期望异常：' . $exceptionClass . '；实际异常：' . $throwable::class,
            );
        }

        return $throwable;
    }

    throw new \RuntimeException($message . '；未抛出预期异常：' . $exceptionClass);
};

$run = static function (string $name, callable $test) use (&$failures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (\Throwable $throwable) {
        $failures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$run('无事件配置时启动并安全空运行', static function () use ($assertSame): void {
    [, $log] = bootApplication(withEventsConfig: false);

    event(new DemoOccurred('empty'));

    $assertSame([], $log->entries, '无配置时不应调用监听器');
});

$run('启动时按配置顺序注册监听器', static function () use ($assertSame): void {
    [, $log] = bootApplication([
        DemoOccurred::class => [FirstListener::class, SecondListener::class],
    ]);

    event(new DemoOccurred('ordered'));

    $assertSame(
        ['first:ordered', 'second:ordered'],
        $log->entries,
        '启动注册后的同步监听器顺序错误',
    );
});

$run('Dispatcher 在进程内保持单例', static function () use ($assertSame): void {
    bootApplication();

    $assertSame(
        app(Dispatcher::class),
        app(Dispatcher::class),
        'Dispatcher 未保持进程级单例',
    );
});

$run('Dispatcher addListener 可用于启动注册', static function () use ($assertSame): void {
    [, $log] = bootApplication();
    app(Dispatcher::class)->addListener(DemoOccurred::class, FirstListener::class);

    event(new DemoOccurred('manual'));

    $assertSame(['first:manual'], $log->entries, 'addListener 注册结果未被 dispatch 使用');
});

$run('手动注册异步监听器时复用已配置 Driver', static function () use ($assertSame): void {
    bootApplication(driverClass: FakeDriver::class);
    app(Dispatcher::class)->addListener(DemoOccurred::class, FirstQueuedListener::class);

    event(new DemoOccurred('manual-queued'));

    /** @var FakeDriver $driver */
    $driver = app(Driver::class);
    $assertSame(1, count($driver->messages), '手动异步注册未发布唯一事件总消息');
});

$run('Driver 声明的 Consumer 由启动器自动绑定', static function () use ($assertSame): void {
    bootApplication(driverClass: FakeDriver::class);

    $assertSame(SyncFakeConsumer::class, app(Consumer::class)::class, 'Driver Consumer 未自动绑定');
    $assertSame(app(Consumer::class), app(Consumer::class), 'Consumer 未保持进程级单例');
});

$run('手动注册异步监听器缺少 Driver 时立即失败', static function () use ($assertThrows): void {
    bootApplication();

    $assertThrows(
        EventConfigException::class,
        static fn () => app(Dispatcher::class)->addListener(
            DemoOccurred::class,
            FirstQueuedListener::class,
        ),
        '手动异步注册绕过了 Driver 门禁',
    );
});

$run('同步异常原样传播并停止后续监听器', static function () use ($assertSame, $assertThrows): void {
    [, $log] = bootApplication([
        DemoOccurred::class => [FirstListener::class, ThrowingListener::class, SecondListener::class],
    ]);

    $throwable = $assertThrows(
        \RuntimeException::class,
        static fn () => event(new DemoOccurred('failed')),
        '同步监听器异常未向上传播',
    );

    $assertSame('监听器执行失败', $throwable->getMessage(), '同步异常消息被框架改写');
    $assertSame(
        ['first:failed', 'throwing:failed'],
        $log->entries,
        '异常后仍执行了后续监听器',
    );
});

$run('单个异步监听器只发布一个事件总消息', static function () use ($assertSame): void {
    [, $log] = bootApplication(
        [DemoOccurred::class => [FirstQueuedListener::class]],
        driverClass: FakeDriver::class,
    );
    $event = new DemoOccurred('single-queued');

    event($event);

    /** @var FakeDriver $driver */
    $driver = app(Driver::class);
    $assertSame([], $log->entries, '异步监听器不应在 dispatch 进程内执行');
    $assertSame(1, count($driver->messages), '单个异步监听器应只发布一次');
    $assertSame($event, $driver->messages[0]->event, 'EventMessage 未保留当前事件实例');
    $assertSame(
        app(Request::class)->requestId,
        $driver->messages[0]->traceId,
        'EventMessage 未继承当前请求 requestId',
    );
    $assertSame(
        [FirstQueuedListener::class],
        $driver->messages[0]->listeners,
        'EventMessage 的异步监听器列表错误',
    );
});

$run('多个异步监听器合并为一个有序事件总消息', static function () use ($assertSame): void {
    [, $log] = bootApplication(
        [DemoOccurred::class => [FirstQueuedListener::class, SecondQueuedListener::class]],
        driverClass: FakeDriver::class,
    );

    event(new DemoOccurred('multi-queued'));

    /** @var FakeDriver $driver */
    $driver = app(Driver::class);
    $assertSame([], $log->entries, '异步监听器不应被本地执行');
    $assertSame(1, count($driver->messages), '多个异步监听器不应拆成多条消息');
    $assertSame(
        [FirstQueuedListener::class, SecondQueuedListener::class],
        $driver->messages[0]->listeners,
        '异步监听器声明顺序未被保留',
    );
});

$run('同步监听器执行完成后再发布异步事件总消息', static function () use ($assertSame): void {
    [, $log] = bootApplication(
        [DemoOccurred::class => [FirstQueuedListener::class, FirstListener::class, SecondQueuedListener::class, SecondListener::class]],
        driverClass: FakeDriver::class,
    );

    event(new DemoOccurred('mixed'));

    /** @var FakeDriver $driver */
    $driver = app(Driver::class);
    $assertSame(['first:mixed', 'second:mixed'], $log->entries, '同步监听器执行结果错误');
    $assertSame(1, count($driver->messages), '混合分发未生成唯一异步消息');
    $assertSame(
        [FirstQueuedListener::class, SecondQueuedListener::class],
        $driver->messages[0]->listeners,
        '混合分发未保持异步监听器声明顺序',
    );
});

$run('同步监听器失败时不发布异步消息', static function () use ($assertSame, $assertThrows): void {
    bootApplication(
        [DemoOccurred::class => [FirstQueuedListener::class, ThrowingListener::class]],
        driverClass: FakeDriver::class,
    );

    $assertThrows(
        \RuntimeException::class,
        static fn () => event(new DemoOccurred('sync-failed')),
        '同步监听器异常未向上传播',
    );

    /** @var FakeDriver $driver */
    $driver = app(Driver::class);
    $assertSame([], $driver->messages, '同步失败后仍发布了异步消息');
});

$run('异步监听器缺少 Driver 配置时启动失败', static function () use ($assertThrows): void {
    $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication([DemoOccurred::class => [FirstQueuedListener::class]]),
        '异步监听器缺少 Driver 时未在启动期失败',
    );
});

$run('非法 Driver 配置时启动失败', static function () use ($assertThrows): void {
    $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication(
            [DemoOccurred::class => [FirstQueuedListener::class]],
            driverClass: InvalidDriver::class,
        ),
        '未实现 Driver 的类未在启动期失败',
    );
});

$run('Driver 声明非法 Consumer 时启动失败', static function () use ($assertThrows): void {
    $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication(driverClass: InvalidConsumerDriver::class),
        'Driver 返回非 Consumer 类时未在启动期失败',
    );
});

$run('Driver 配置校验失败时启动失败', static function () use ($assertThrows): void {
    $throwable = $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication(driverClass: RejectingConfigDriver::class),
        'Driver 自身配置校验未在启动期执行',
    );

    if ($throwable->getMessage() !== 'Fake Driver 配置无效') {
        throw new \RuntimeException('Driver 配置异常被通用校验器错误改写');
    }
});

$run('显式空 Driver 配置时启动失败', static function () use ($assertThrows): void {
    $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication(driverClass: ''),
        '显式声明但未填写 Driver 类名时未在启动期失败',
    );
});

$run('非法监听器在启动时失败', static function () use ($assertThrows): void {
    foreach ([MissingHandleListener::class, WrongEventListener::class, InvalidReturnListener::class] as $listener) {
        $assertThrows(
            EventConfigException::class,
            static fn () => bootApplication([DemoOccurred::class => [$listener]]),
            '非法监听器未在启动时失败：' . $listener,
        );
    }
});

$run('非法事件类在启动时失败', static function () use ($assertThrows): void {
    $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication([\stdClass::class => [FirstListener::class]]),
        '未实现 Event 的类未在启动时失败',
    );
});

$run('重复监听器在启动时失败', static function () use ($assertThrows): void {
    $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication([
            DemoOccurred::class => [FirstListener::class, FirstListener::class],
        ]),
        '重复监听器未在启动时失败',
    );
});

$run('非法监听器配置结构在启动时失败', static function () use ($assertThrows): void {
    $assertThrows(
        EventConfigException::class,
        static fn () => bootApplication('invalid-listeners'),
        '非法监听器配置结构未在启动时失败',
    );
});

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo '同步 Event 启动注册与分发测试通过。' . PHP_EOL;
