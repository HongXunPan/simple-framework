<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use HongXunPan\Framework\Event\Exception\EventConsumeException;
use HongXunPan\Framework\Event\Exception\EventException;
use HongXunPan\Framework\Event\Exception\EventPublishException;
use HongXunPan\Framework\Exceptions\ErrorLogExceptionReporter;
use HongXunPan\Framework\Exceptions\ExceptionReporter;
use HongXunPan\Tools\Config\Config;
use HongXunPan\Tools\Env\Env;
use RuntimeException;
use Throwable;

final class RecordingExceptionReporter implements ExceptionReporter
{
    /** @var list<Throwable> */
    public array $throwables = [];

    public function report(Throwable $throwable): void
    {
        $this->throwables[] = $throwable;
    }
}

/**
 * @param array<class-string, class-string> $singletons
 */
function bootHelperApplication(array $singletons = []): Application
{
    putenv('DEBUG=false');
    $loaded = (new ReflectionClass(Env::class))->getProperty('loaded');
    $loaded->setValue(null, true);

    Config::$config = [
        'app' => ['timezone' => 'Asia/Shanghai'],
        'singleton' => $singletons,
        'boot' => [],
    ];

    $application = new Application();
    Application::setInstance($application);
    $application->init('/tmp/simple-framework-helper-tests');

    return $application;
}

$helperFailures = [];

$helperAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$runHelper = static function (string $name, callable $test) use (&$helperFailures): void {
    try {
        $test();
        echo "[通过] {$name}" . PHP_EOL;
    } catch (Throwable $throwable) {
        $helperFailures[] = "[失败] {$name}：{$throwable->getMessage()}";
    }
};

$runHelper('Application 默认绑定异常上报器', static function (): void {
    bootHelperApplication();
    if (!app(ExceptionReporter::class) instanceof ErrorLogExceptionReporter) {
        throw new RuntimeException('Application 未绑定默认异常上报器');
    }
});

$runHelper('Event 异常共享可识别基类', static function (): void {
    $exceptions = [
        new EventConfigException('配置异常'),
        new EventPublishException('发布异常'),
        new EventConsumeException('消费异常'),
    ];

    foreach ($exceptions as $exception) {
        if (!$exception instanceof EventException) {
            throw new RuntimeException($exception::class . ' 未继承 EventException');
        }
    }
});

$runHelper('业务配置可以覆盖默认异常上报器', static function (): void {
    bootHelperApplication([
        ExceptionReporter::class => RecordingExceptionReporter::class,
    ]);
    if (!app(ExceptionReporter::class) instanceof RecordingExceptionReporter) {
        throw new RuntimeException('业务配置未覆盖默认异常上报器');
    }
});

$runHelper('report 委托给容器中的异常上报器', static function () use ($helperAssertSame): void {
    bootHelperApplication([
        ExceptionReporter::class => RecordingExceptionReporter::class,
    ]);
    $throwable = new RuntimeException('测试异常上报');

    report($throwable);

    /** @var RecordingExceptionReporter $reporter */
    $reporter = app(ExceptionReporter::class);
    $helperAssertSame([$throwable], $reporter->throwables, 'report 未上报原异常实例');
});

$runHelper('rescue 成功时返回原结果且不上报', static function () use ($helperAssertSame): void {
    bootHelperApplication([
        ExceptionReporter::class => RecordingExceptionReporter::class,
    ]);

    $result = rescue(static fn (): int => 42, fallback: 0);

    /** @var RecordingExceptionReporter $reporter */
    $reporter = app(ExceptionReporter::class);
    $helperAssertSame(42, $result, 'rescue 未返回 callback 结果');
    $helperAssertSame([], $reporter->throwables, 'rescue 成功时错误上报了异常');
});

$runHelper('rescue 失败时上报并返回固定 fallback', static function () use ($helperAssertSame): void {
    bootHelperApplication([
        ExceptionReporter::class => RecordingExceptionReporter::class,
    ]);
    $throwable = new RuntimeException('测试 rescue 失败');

    $result = rescue(static fn () => throw $throwable, fallback: false);

    /** @var RecordingExceptionReporter $reporter */
    $reporter = app(ExceptionReporter::class);
    $helperAssertSame(false, $result, 'rescue 未返回固定 fallback');
    $helperAssertSame([$throwable], $reporter->throwables, 'rescue 未上报原异常实例');
});

$runHelper('rescue 支持 callable fallback', static function () use ($helperAssertSame): void {
    bootHelperApplication([
        ExceptionReporter::class => RecordingExceptionReporter::class,
    ]);
    $throwable = new RuntimeException('测试 callable fallback');

    $result = rescue(
        static fn () => throw $throwable,
        fallback: static fn (Throwable $caught): string => $caught->getMessage(),
    );

    $helperAssertSame('测试 callable fallback', $result, 'rescue 未执行 callable fallback');
});

$runHelper('rescue 支持关闭或按条件上报', static function () use ($helperAssertSame): void {
    bootHelperApplication([
        ExceptionReporter::class => RecordingExceptionReporter::class,
    ]);

    rescue(static fn () => throw new RuntimeException('关闭上报'), report: false);
    rescue(
        static fn () => throw new RuntimeException('条件不上报'),
        report: static fn (Throwable $throwable): bool => $throwable instanceof LogicException,
    );

    /** @var RecordingExceptionReporter $reporter */
    $reporter = app(ExceptionReporter::class);
    $helperAssertSame([], $reporter->throwables, 'rescue 未遵守 report 参数');
});

if ($helperFailures !== []) {
    foreach ($helperFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo '全局异常上报与 rescue helper 测试通过。' . PHP_EOL;
