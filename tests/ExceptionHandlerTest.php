<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Exceptions\ErrorHandler;
use HongXunPan\Framework\Exceptions\ExceptionRenderer;
use HongXunPan\Framework\Exceptions\ExceptionReporter;
use RuntimeException;
use Throwable;

final class RecordingExceptionHandlerReporter implements ExceptionReporter
{
    /** @var list<Throwable> */
    public array $throwables = [];

    public function report(Throwable $throwable): void
    {
        $this->throwables[] = $throwable;
    }
}

final class ThrowingExceptionHandlerReporter implements ExceptionReporter
{
    public function report(Throwable $throwable): void
    {
        throw new RuntimeException('模拟 Reporter 失败');
    }
}

final class RecordingExceptionRenderer implements ExceptionRenderer
{
    /** @var list<Throwable> */
    public array $throwables = [];

    public function render(Throwable $throwable): void
    {
        $this->throwables[] = $throwable;
    }
}

/**
 * @return array{RecordingExceptionHandlerReporter|ThrowingExceptionHandlerReporter, RecordingExceptionRenderer}
 */
function bootExceptionHandler(
    ExceptionReporter $reporter = new RecordingExceptionHandlerReporter(),
): array {
    $application = new Application();
    Application::setInstance($application);
    $renderer = new RecordingExceptionRenderer();
    $application->instance(ExceptionReporter::class, $reporter);
    $application->instance(ExceptionRenderer::class, $renderer);

    return [$reporter, $renderer];
}

$exceptionHandlerFailures = [];

$exceptionHandlerAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$runExceptionHandler = static function (string $name, callable $test) use (&$exceptionHandlerFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $exceptionHandlerFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runExceptionHandler('ErrorHandler 先上报再渲染同一个异常', static function () use (
    $exceptionHandlerAssertSame,
): void {
    [$reporter, $renderer] = bootExceptionHandler();
    $throwable = new RuntimeException('不应直接输出的异常');

    ErrorHandler::handle($throwable);

    $exceptionHandlerAssertSame([$throwable], $reporter->throwables, 'Reporter 未收到原异常');
    $exceptionHandlerAssertSame([$throwable], $renderer->throwables, 'Renderer 未收到原异常');
});

$runExceptionHandler('Reporter 失败不阻断安全渲染', static function () use ($exceptionHandlerAssertSame): void {
    [, $renderer] = bootExceptionHandler(new ThrowingExceptionHandlerReporter());
    $throwable = new RuntimeException('Reporter 失败时的原异常');

    ErrorHandler::handle($throwable);

    $exceptionHandlerAssertSame([$throwable], $renderer->throwables, 'Reporter 失败后未继续调用 Renderer');
});

$runExceptionHandler('Application run 保留自定义静态 ErrorHandler 入口', static function () use (
    $exceptionHandlerAssertSame,
): void {
    LegacyStaticErrorHandler::$throwables = [];
    $application = new Application();
    $throwable = new RuntimeException('兼容入口测试');

    $application->run(static fn () => throw $throwable, LegacyStaticErrorHandler::class);

    $exceptionHandlerAssertSame([$throwable], LegacyStaticErrorHandler::$throwables, '旧 ErrorHandler 入口失效');
});

if ($exceptionHandlerFailures !== []) {
    foreach ($exceptionHandlerFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo '安全异常处理测试通过。' . PHP_EOL;

final class LegacyStaticErrorHandler
{
    /** @var list<Throwable> */
    public static array $throwables = [];

    public static function handle(Throwable $throwable): void
    {
        self::$throwables[] = $throwable;
    }
}
