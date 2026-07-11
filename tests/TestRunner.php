<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Event\Dispatch\Dispatcher;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Listener\ListenerCaller;
use HongXunPan\Framework\Event\Listener\ListenerMap;
use HongXunPan\Framework\Event\Validation\ListenerValidator;
use HongXunPan\Tools\Config\Config;

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

final class MissingHandleListener
{
}

final class UntypedListener
{
    public function handle($event): void
    {
    }
}

final class WrongEventListener
{
    public function handle(OtherOccurred $event): void
    {
    }
}

final class BaseEventListener
{
    public function handle(Event $event): void
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
function resetApplication(): array
{
    $application = new Application();
    Application::setInstance($application);

    $log = new InvocationLog();
    $application->instance(InvocationLog::class, $log);

    return [$application, $log];
}

function makeDispatcher(array $listeners): Dispatcher
{
    return new Dispatcher(
        new ListenerMap($listeners),
        new ListenerCaller(new ListenerValidator()),
    );
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

$run('无事件配置时安全空运行', static function () use ($assertSame): void {
    [, $log] = resetApplication();
    Config::$config = [];

    event(new DemoOccurred('empty'));

    $assertSame([], $log->entries, '无配置时不应调用监听器');
});

$run('同步监听器按声明顺序并由容器解析', static function () use ($assertSame): void {
    [, $log] = resetApplication();
    $dispatcher = makeDispatcher([
        DemoOccurred::class => [FirstListener::class, SecondListener::class],
    ]);

    $dispatcher->dispatch(new DemoOccurred('ordered'));

    $assertSame(
        ['first:ordered', 'second:ordered'],
        $log->entries,
        '同步监听器调用顺序错误',
    );
});

$run('event helper 使用容器中的 Dispatcher', static function () use ($assertSame): void {
    [$application, $log] = resetApplication();
    $application->instance(Dispatcher::class, makeDispatcher([
        DemoOccurred::class => [FirstListener::class],
    ]));

    event(new DemoOccurred('helper'));

    $assertSame(['first:helper'], $log->entries, 'event helper 未使用容器 Dispatcher');
});

$run('同步异常原样传播并停止后续监听器', static function () use ($assertSame, $assertThrows): void {
    [, $log] = resetApplication();
    $dispatcher = makeDispatcher([
        DemoOccurred::class => [FirstListener::class, ThrowingListener::class, SecondListener::class],
    ]);

    $throwable = $assertThrows(
        \RuntimeException::class,
        static fn () => $dispatcher->dispatch(new DemoOccurred('failed')),
        '同步监听器异常未向上传播',
    );

    $assertSame('监听器执行失败', $throwable->getMessage(), '同步异常消息被框架改写');
    $assertSame(
        ['first:failed', 'throwing:failed'],
        $log->entries,
        '异常后仍执行了后续监听器',
    );
});

$run('非法监听器签名快速失败', static function () use ($assertThrows): void {
    resetApplication();
    $validator = new ListenerValidator();

    foreach ([
        MissingHandleListener::class,
        UntypedListener::class,
        WrongEventListener::class,
        BaseEventListener::class,
        InvalidReturnListener::class,
    ] as $listenerClass) {
        $assertThrows(
            EventConfigException::class,
            static fn () => $validator->validate($listenerClass, DemoOccurred::class),
            '非法监听器签名未被拒绝：' . $listenerClass,
        );
    }
});

$run('非法监听器映射快速失败', static function () use ($assertThrows): void {
    resetApplication();
    $map = new ListenerMap([
        DemoOccurred::class => [''],
    ]);

    $assertThrows(
        EventConfigException::class,
        static fn () => $map->listenersFor(new DemoOccurred('invalid-map')),
        '空监听器类名未被拒绝',
    );
});

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo '同步 Event 内核测试通过。' . PHP_EOL;
