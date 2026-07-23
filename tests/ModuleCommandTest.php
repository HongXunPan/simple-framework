<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/ModuleCommandFixture.php';

use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\Framework\Module\ModuleRegistry;
use RuntimeException;
use Throwable;

$moduleCommandFailures = [];

$moduleCommandAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$moduleCommandAssertTrue = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};

$runModuleCommand = static function (string $name, callable $test) use (&$moduleCommandFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $moduleCommandFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runModuleCommand('ModuleRegistry 只发现声明 simple-module 元数据的 Composer 包', static function () use (
    $moduleCommandAssertSame,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        $registry = new ModuleRegistry($directory);
        $moduleCommandAssertSame(
            ['base', 'dependent', 'failure'],
            array_map(static fn ($package): string => $package->module->name(), $registry->all()),
            'Module 发现结果错误',
        );
        $moduleCommandAssertSame(CommandBaseModule::class, $registry->find('base')->module::class, '名称查找失败');
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModuleCommand('enable 支持预览、幂等启用、刷新、状态和禁用', static function () use (
    $moduleCommandAssertSame,
    $moduleCommandAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        CommandModuleInstaller::$operations = [];
        [$console, $stdout] = moduleCommandRunner($directory);
        $config = new ModuleConfig($directory);

        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base', '--dry-run']), '启用预览失败');
        $moduleCommandAssertSame([], $config->enabled(), '预览模式写入了启用状态');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base']), '首次启用失败');
        $moduleCommandAssertSame([CommandBaseModule::class], $config->enabled(), '启用状态未写入');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base']), '重复启用失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:refresh']), '刷新失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:status']), '状态查询失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:disable', 'base']), '禁用失败');
        $moduleCommandAssertSame([], $config->enabled(), '禁用状态未移除');
        $moduleCommandAssertTrue(
            str_contains(moduleCommandStream($stdout), '已启用 Module：base'),
            '命令输出缺少启用结果',
        );
        $moduleCommandAssertSame(
            [
                'install-preview',
                'install-preview',
                'install',
                'refresh-preview',
                'refresh',
                'refresh-preview',
                'uninstall-preview',
                'uninstall',
            ],
            CommandModuleInstaller::$operations,
            'Installer 执行顺序或幂等行为错误',
        );
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModuleCommand('依赖未启用时阻止 enable 且被依赖 Module 不能 disable', static function () use (
    $moduleCommandAssertSame,
    $moduleCommandAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        [$console, , $stderr] = moduleCommandRunner($directory);
        $moduleCommandAssertSame(1, $console->run(['bin/simple', 'module:enable', 'dependent']), '缺少依赖时未失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base']), '基础 Module 启用失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'dependent']), '依赖 Module 启用失败');
        $moduleCommandAssertSame(1, $console->run(['bin/simple', 'module:disable', 'base']), '被依赖 Module 可以直接禁用');
        $moduleCommandAssertTrue(
            str_contains(moduleCommandStream($stderr), '正被 dependent 依赖'),
            '依赖阻断信息不明确',
        );
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModuleCommand('Installer 失败时不写入 Module 启用状态', static function () use (
    $moduleCommandAssertSame,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        [$console] = moduleCommandRunner($directory);
        $moduleCommandAssertSame(1, $console->run(['bin/simple', 'module:enable', 'failure']), 'Installer 失败未返回错误');
        $moduleCommandAssertSame([], (new ModuleConfig($directory))->enabled(), 'Installer 失败污染了启用状态');
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

if ($moduleCommandFailures !== []) {
    foreach ($moduleCommandFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Module 发现与命令测试通过。' . PHP_EOL;
