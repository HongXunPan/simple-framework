<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Event\Bootstrap\EventBootstrapper;
use HongXunPan\Framework\Event\Dispatch\Dispatcher;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
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
function bootApplication(mixed $listeners = [], bool $withEventsConfig = true): array
{
    putenv('DEBUG=false');
    $loaded = (new ReflectionClass(Env::class))->getProperty('loaded');
    $loaded->setValue(null, true);

    Config::$config = [
        'app' => ['timezone' => 'Asia/Shanghai'],
        'singleton' => [],
        'boot' => [
            [EventBootstrapper::class, 'boot'],
        ],
    ];
    if ($withEventsConfig) {
        Config::$config['events'] = ['listeners' => $listeners];
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
