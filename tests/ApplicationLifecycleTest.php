<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Config\Env;
use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Exceptions\ExceptionRenderer;
use HongXunPan\Framework\Exceptions\ExceptionReporter;
use HongXunPan\Framework\Lifecycle\ApplicationLifecycle;
use HongXunPan\Framework\Lifecycle\ExceptionOccurredSnapshot;
use HongXunPan\Framework\Lifecycle\NullApplicationLifecycle;
use HongXunPan\Framework\Lifecycle\RequestHandledSnapshot;
use RuntimeException;
use Throwable;

final class RecordingApplicationLifecycle implements ApplicationLifecycle
{
    /** @var list<RequestHandledSnapshot> */
    public array $handled = [];

    /** @var list<ExceptionOccurredSnapshot> */
    public array $exceptions = [];

    public ?Throwable $requestFailure = null;
    public ?Throwable $exceptionFailure = null;

    public function requestHandled(RequestHandledSnapshot $snapshot): void
    {
        $this->handled[] = $snapshot;
        if ($this->requestFailure !== null) {
            throw $this->requestFailure;
        }
    }

    public function exceptionOccurred(ExceptionOccurredSnapshot $snapshot): void
    {
        $this->exceptions[] = $snapshot;
        if ($this->exceptionFailure !== null) {
            throw $this->exceptionFailure;
        }
    }
}

final class LifecycleExceptionReporter implements ExceptionReporter
{
    /** @var list<Throwable> */
    public array $throwables = [];

    public function report(Throwable $throwable): void
    {
        $this->throwables[] = $throwable;
    }
}

final class LifecycleExceptionRenderer implements ExceptionRenderer
{
    /** @var list<Throwable> */
    public array $throwables = [];

    public function render(Throwable $throwable): void
    {
        $this->throwables[] = $throwable;
    }
}

/**
 * @return array{Application, LifecycleExceptionReporter, LifecycleExceptionRenderer}
 */
function bootApplicationLifecycle(?ApplicationLifecycle $lifecycle = null): array
{
    $application = new Application();
    Application::setInstance($application);
    $reporter = new LifecycleExceptionReporter();
    $renderer = new LifecycleExceptionRenderer();
    $application->instance(Env::class, Env::fromArray(['APP_DEBUG' => false]));
    $application->instance(Config::class, Config::fromArray([
        'app' => ['timezone' => 'Asia/Shanghai'],
        'singleton' => [],
        'boot' => [],
        'module' => [
            'enable' => [],
            'provider-override' => [],
        ],
    ]));
    $application->instance(ExceptionReporter::class, $reporter);
    $application->instance(ExceptionRenderer::class, $renderer);
    if ($lifecycle !== null) {
        $application->instance(ApplicationLifecycle::class, $lifecycle);
    }
    $application->init('/tmp/simple-framework-lifecycle-tests');

    return [$application, $reporter, $renderer];
}

$lifecycleFailures = [];

$lifecycleAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$runLifecycle = static function (string $name, callable $test) use (&$lifecycleFailures): void {
    try {
        $test();
        echo "[通过] {$name}" . PHP_EOL;
    } catch (Throwable $throwable) {
        $lifecycleFailures[] = "[失败] {$name}：{$throwable->getMessage()}";
    }
};

$runLifecycle('Application 默认绑定空生命周期实现', static function (): void {
    bootApplicationLifecycle();
    if (!app(ApplicationLifecycle::class) instanceof NullApplicationLifecycle) {
        throw new RuntimeException('Application 未绑定默认空生命周期实现');
    }
});

$runLifecycle('run 正常完成后触发 requestHandled', static function () use ($lifecycleAssertSame): void {
    $lifecycle = new RecordingApplicationLifecycle();
    [$application, $reporter, $renderer] = bootApplicationLifecycle($lifecycle);
    $completed = false;

    $application->run(static function () use (&$completed): void {
        $completed = true;
    });

    $lifecycleAssertSame(true, $completed, '业务闭包未正常完成');
    $lifecycleAssertSame(1, count($lifecycle->handled), 'requestHandled 触发次数错误');
    $lifecycleAssertSame([], $lifecycle->exceptions, '成功路径错误触发 exceptionOccurred');
    $lifecycleAssertSame([], $reporter->throwables, '成功路径错误上报异常');
    $lifecycleAssertSame([], $renderer->throwables, '成功路径错误渲染异常');
});

$runLifecycle('业务异常先进入生命周期再保留原异常处理', static function () use (
    $lifecycleAssertSame,
): void {
    $lifecycle = new RecordingApplicationLifecycle();
    [$application, $reporter, $renderer] = bootApplicationLifecycle($lifecycle);
    $original = new RuntimeException('原业务异常');

    $application->run(static fn () => throw $original);

    $lifecycleAssertSame([], $lifecycle->handled, '异常路径错误触发 requestHandled');
    $lifecycleAssertSame(1, count($lifecycle->exceptions), 'exceptionOccurred 触发次数错误');
    $lifecycleAssertSame(
        $original,
        $lifecycle->exceptions[0]->throwable,
        'Exception Snapshot 未保留原异常实例',
    );
    $lifecycleAssertSame([$original], $reporter->throwables, 'Reporter 未收到原业务异常');
    $lifecycleAssertSame([$original], $renderer->throwables, 'Renderer 未收到原业务异常');
});

$runLifecycle('requestHandled 失败只上报且不改写成功结果', static function () use (
    $lifecycleAssertSame,
): void {
    $failure = new RuntimeException('完成回调失败');
    $lifecycle = new RecordingApplicationLifecycle();
    $lifecycle->requestFailure = $failure;
    [$application, $reporter, $renderer] = bootApplicationLifecycle($lifecycle);

    $application->run(static function (): void {
    });

    $lifecycleAssertSame([$failure], $reporter->throwables, '生命周期失败未进入 Reporter');
    $lifecycleAssertSame([], $renderer->throwables, '生命周期失败不应生成错误响应');
    $lifecycleAssertSame([], $lifecycle->exceptions, '生命周期失败不应转为业务异常');
});

$runLifecycle('exceptionOccurred 失败不覆盖原业务异常', static function () use (
    $lifecycleAssertSame,
): void {
    $original = new RuntimeException('原业务异常');
    $failure = new RuntimeException('异常回调失败');
    $lifecycle = new RecordingApplicationLifecycle();
    $lifecycle->exceptionFailure = $failure;
    [$application, $reporter, $renderer] = bootApplicationLifecycle($lifecycle);

    $application->run(static fn () => throw $original);

    $lifecycleAssertSame(
        [$failure, $original],
        $reporter->throwables,
        '生命周期失败与原业务异常上报顺序错误',
    );
    $lifecycleAssertSame([$original], $renderer->throwables, 'Renderer 未保留原业务异常');
});

if ($lifecycleFailures !== []) {
    foreach ($lifecycleFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'Application 生命周期测试通过。' . PHP_EOL;
