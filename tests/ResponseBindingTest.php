<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Config\Env;
use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Response\Response;
use HongXunPan\Framework\Response\ResponseContract;
use RuntimeException;
use Throwable;

final class FrameworkProjectResponse implements ResponseContract
{
    public function __construct(private readonly mixed $content)
    {
    }

    public function send(): void
    {
        echo 'project:' . $this->content;
    }
}

/**
 * @param array<class-string, class-string>|null $singletons
 */
function bootResponseBindingApplication(?array $singletons = null): Application
{
    $config = [
        'app' => ['timezone' => 'Asia/Shanghai'],
        'boot' => [],
        'module' => [
            'enable' => [],
            'provider-override' => [],
        ],
    ];
    if ($singletons !== null) {
        $config['singleton'] = $singletons;
    }

    $application = new Application();
    Application::setInstance($application);
    $application->instance(Env::class, Env::fromArray(['APP_DEBUG' => false]));
    $application->instance(Config::class, Config::fromArray($config));
    $application->init('/tmp/simple-framework-response-binding-tests');

    return $application;
}

function captureFrameworkResponse(callable $operation): string
{
    ob_start();
    try {
        $operation();

        return (string) ob_get_clean();
    } catch (Throwable $throwable) {
        ob_end_clean();
        throw $throwable;
    }
}

$responseBindingFailures = [];

$runResponseBinding = static function (
    string $name,
    callable $test,
) use (&$responseBindingFailures): void {
    try {
        $test();
        echo "[通过] {$name}" . PHP_EOL;
    } catch (Throwable $throwable) {
        $responseBindingFailures[] = "[失败] {$name}：{$throwable->getMessage()}";
    }
};

$runResponseBinding('Application 在项目未声明 singleton 时提供默认响应', static function (): void {
    $application = bootResponseBindingApplication();
    $response = $application->make(ResponseContract::class, ['content' => 'framework']);

    if (!$response instanceof Response) {
        throw new RuntimeException('Application 未绑定 framework 默认 Response');
    }
    if (captureFrameworkResponse(static fn () => $response->send()) !== 'framework') {
        throw new RuntimeException('framework 默认 Response 未发送原始内容');
    }
});

$runResponseBinding('Application 在空 singleton 配置下仍提供默认响应', static function (): void {
    $application = bootResponseBindingApplication([]);

    if (!$application->make(ResponseContract::class, ['content' => []]) instanceof Response) {
        throw new RuntimeException('空 singleton 配置覆盖了 framework 默认 Response');
    }
});

$runResponseBinding('项目 singleton 配置可以覆盖 framework 默认响应', static function (): void {
    $application = bootResponseBindingApplication([
        ResponseContract::class => FrameworkProjectResponse::class,
    ]);
    $response = $application->make(ResponseContract::class, ['content' => 'custom']);

    if (!$response instanceof FrameworkProjectResponse) {
        throw new RuntimeException('项目 singleton 配置未覆盖 framework 默认 Response');
    }
    if (captureFrameworkResponse(static fn () => $response->send()) !== 'project:custom') {
        throw new RuntimeException('项目 Response 未收到运行时 content');
    }
});

if ($responseBindingFailures !== []) {
    foreach ($responseBindingFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'Application 响应绑定测试通过。' . PHP_EOL;
