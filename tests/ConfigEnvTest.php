<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Config\Env;
use HongXunPan\Framework\Core\Application;
use RuntimeException;
use Throwable;

function configEnvTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir() . '/simple-framework-config-env-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('测试临时目录创建失败');
    }

    return $directory;
}

function removeConfigEnvDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

$configEnvFailures = [];

$configEnvAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$configEnvAssertTrue = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};

$runConfigEnv = static function (string $name, callable $test) use (&$configEnvFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $configEnvFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runConfigEnv('Env 缺少文件时返回默认值', static function () use ($configEnvAssertSame): void {
    $env = new Env('/tmp/simple-framework-not-found-' . bin2hex(random_bytes(6)) . '/.env');

    $configEnvAssertSame('default', $env->get('MISSING_VALUE', 'default'), 'Env 未返回默认值');
});

$runConfigEnv('进程环境变量优先于 env 文件', static function () use ($configEnvAssertSame): void {
    $directory = configEnvTemporaryDirectory();
    $key = 'SIMPLE_FRAMEWORK_ENV_PRIORITY';
    try {
        file_put_contents($directory . '/.env', $key . "=file\nBOOL_VALUE=false\n");
        putenv($key . '=process');
        $env = new Env($directory . '/.env');

        $configEnvAssertSame('process', $env->get($key), '进程环境变量未覆盖文件值');
        $configEnvAssertSame(false, $env->get('BOOL_VALUE'), '布尔环境变量未正确归一化');
    } finally {
        putenv($key);
        removeConfigEnvDirectory($directory);
    }
});

$runConfigEnv('Config 支持点号读取与默认值', static function () use ($configEnvAssertSame): void {
    $config = Config::fromArray([
        'app' => ['timezone' => 'Asia/Shanghai'],
    ]);

    $configEnvAssertSame('Asia/Shanghai', $config->get('app.timezone'), 'Config 点号读取失败');
    $configEnvAssertSame('fallback', $config->get('app.missing.value', 'fallback'), 'Config 默认值失败');
});

$runConfigEnv('Config 使用原子缓存并复用缓存内容', static function () use (
    $configEnvAssertSame,
    $configEnvAssertTrue,
): void {
    $directory = configEnvTemporaryDirectory();
    $configPath = $directory . '/config';
    $cachePath = $directory . '/bootstrap/cache';
    try {
        mkdir($configPath, 0755, true);
        file_put_contents($configPath . '/app.php', "<?php return ['name' => 'first'];\n");

        $config = new Config($configPath, $cachePath, true);
        $configEnvAssertSame('first', $config->get('app.name'), '首次配置加载失败');
        $configEnvAssertTrue(is_file($cachePath . '/config.php'), '配置缓存未生成');

        file_put_contents($configPath . '/app.php', "<?php return ['name' => 'second'];\n");
        $cached = new Config($configPath, $cachePath, true);
        $configEnvAssertSame('first', $cached->get('app.name'), '未优先使用已有配置缓存');
        $configEnvAssertSame([], glob($cachePath . '/.atomic-*') ?: [], '遗留配置缓存临时文件');
    } finally {
        removeConfigEnvDirectory($directory);
    }
});

$runConfigEnv('Application 无 env 文件也能初始化', static function () use ($configEnvAssertSame): void {
    $directory = configEnvTemporaryDirectory();
    try {
        mkdir($directory . '/config', 0755, true);
        file_put_contents(
            $directory . '/config/app.php',
            "<?php return ['env' => env('APP_ENV', 'production'), 'debug' => env('APP_DEBUG', false), 'timezone' => 'Asia/Shanghai'];\n",
        );
        file_put_contents($directory . '/config/singleton.php', "<?php return [];\n");
        file_put_contents($directory . '/config/boot.php', "<?php return [];\n");

        $application = new Application();
        $application->init($directory);

        $configEnvAssertSame('production', $application->environment, '默认运行环境错误');
        $configEnvAssertSame(false, $application->isDebug, '默认调试状态错误');
        $configEnvAssertSame('Asia/Shanghai', config('app.timezone'), 'Application 配置未加载');
    } finally {
        removeConfigEnvDirectory($directory);
    }
});

if ($configEnvFailures !== []) {
    foreach ($configEnvFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }

    exit(1);
}

echo 'Config 与 Env 核心能力测试通过。' . PHP_EOL;
