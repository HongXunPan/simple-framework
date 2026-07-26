<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Bootstrap\BootstrapCache;
use HongXunPan\Framework\Bootstrap\Command\BootstrapCommandRunner;
use HongXunPan\Framework\Console\CommandRunner;
use HongXunPan\Framework\Console\Output;
use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Route\Route;
use RuntimeException;
use Throwable;

function bootstrapCacheTemporaryProject(): string
{
    $directory = sys_get_temp_dir() . '/simple-framework-bootstrap-cache-' . bin2hex(random_bytes(8));
    mkdir($directory . '/config', 0755, true);
    mkdir($directory . '/routes', 0755, true);
    mkdir($directory . '/bootstrap/cache', 0755, true);
    file_put_contents(
        $directory . '/config/app.php',
        "<?php return ['env' => 'production', 'debug' => false, 'timezone' => 'Asia/Shanghai', 'name' => 'cached'];\n",
    );
    file_put_contents(
        $directory . '/config/module.php',
        "<?php return ['enable' => [], 'provider-override' => []];\n",
    );
    file_put_contents(
        $directory . '/routes/web.php',
        "<?php \\HongXunPan\\Framework\\Route\\Route::get('/cached', 'CachedController@index');\n",
    );

    return $directory;
}

function removeBootstrapCacheProject(string $directory): void
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

$bootstrapCacheFailures = [];

$bootstrapCacheAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$bootstrapCacheAssertTrue = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};

$runBootstrapCache = static function (string $name, callable $test) use (&$bootstrapCacheFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $bootstrapCacheFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runBootstrapCache('Bootstrap Cache 显式生成、权限一致且清理幂等', static function () use (
    $bootstrapCacheAssertSame,
    $bootstrapCacheAssertTrue,
): void {
    $directory = bootstrapCacheTemporaryProject();
    try {
        $cache = new BootstrapCache($directory);
        $cache->cache();
        foreach (['config.php', 'routes.php'] as $fileName) {
            $file = $directory . '/bootstrap/cache/' . $fileName;
            $bootstrapCacheAssertTrue(is_file($file), '启动缓存未生成：' . $fileName);
            $bootstrapCacheAssertSame(0644, fileperms($file) & 0777, '启动缓存权限错误：' . $fileName);
        }

        $cache->cache();
        $cache->clear();
        $cache->clear();
        $bootstrapCacheAssertTrue(!is_file($directory . '/bootstrap/cache/config.php'), '配置缓存未清理');
        $bootstrapCacheAssertTrue(!is_file($directory . '/bootstrap/cache/routes.php'), '路由缓存未清理');
    } finally {
        removeBootstrapCacheProject($directory);
    }
});

$runBootstrapCache('运行时缺少缓存时读取源码且不补写', static function () use (
    $bootstrapCacheAssertSame,
    $bootstrapCacheAssertTrue,
): void {
    $directory = bootstrapCacheTemporaryProject();
    try {
        $application = new Application();
        $application->init($directory);
        $application->loadRoute();

        $bootstrapCacheAssertSame('cached', config('app.name'), '运行时未读取源码配置');
        $bootstrapCacheAssertTrue(Route::getRouteByUri('/cached') !== null, '运行时未读取源码路由');
        $bootstrapCacheAssertTrue(!is_file($directory . '/bootstrap/cache/config.php'), '运行时补写了配置缓存');
        $bootstrapCacheAssertTrue(!is_file($directory . '/bootstrap/cache/routes.php'), '运行时补写了路由缓存');
    } finally {
        removeBootstrapCacheProject($directory);
    }
});

$runBootstrapCache('显式构建失败时不遗留半套缓存', static function () use (
    $bootstrapCacheAssertTrue,
): void {
    $directory = bootstrapCacheTemporaryProject();
    try {
        file_put_contents(
            $directory . '/routes/web.php',
            "<?php mkdir(dirname(__DIR__) . '/bootstrap/cache/routes.php');\n",
        );

        $failed = false;
        try {
            (new BootstrapCache($directory))->cache();
        } catch (RuntimeException) {
            $failed = true;
        }

        $bootstrapCacheAssertTrue($failed, '路由缓存写入冲突时构建未失败');
        $bootstrapCacheAssertTrue(
            !is_file($directory . '/bootstrap/cache/config.php'),
            '构建失败后遗留了配置缓存',
        );
        $bootstrapCacheAssertTrue(
            !is_file($directory . '/bootstrap/cache/routes.php'),
            '构建失败后遗留了路由缓存文件',
        );
    } finally {
        removeBootstrapCacheProject($directory);
    }
});

$runBootstrapCache('通用命令入口分发 Bootstrap 命令并拒绝额外参数', static function () use (
    $bootstrapCacheAssertSame,
    $bootstrapCacheAssertTrue,
): void {
    $directory = bootstrapCacheTemporaryProject();
    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');
    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('无法创建命令输出内存流');
    }
    try {
        $output = new Output($stdout, $stderr);
        $runner = new CommandRunner($directory, $output);
        $bootstrapCacheAssertSame(0, $runner->run(['bin/simple', 'bootstrap:cache']), '启动缓存命令失败');
        $bootstrapCacheAssertSame(0, $runner->run(['bin/simple', 'bootstrap:clear']), '启动缓存清理命令失败');
        $bootstrapCacheAssertSame(
            1,
            (new BootstrapCommandRunner($directory, $output))->run(['bin/simple', 'bootstrap:cache', 'extra']),
            'Bootstrap 命令接受了额外参数',
        );
        rewind($stdout);
        $bootstrapCacheAssertTrue(
            str_contains((string) stream_get_contents($stdout), '启动缓存已生成'),
            'Bootstrap 命令缺少成功输出',
        );
    } finally {
        fclose($stdout);
        fclose($stderr);
        removeBootstrapCacheProject($directory);
    }
});

if ($bootstrapCacheFailures !== []) {
    foreach ($bootstrapCacheFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Bootstrap Cache 与通用命令入口测试通过。' . PHP_EOL;
