<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/ModuleCommandFixture.php';

use RuntimeException;
use Throwable;

$modulePublishFailures = [];

$modulePublishAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$modulePublishAssertTrue = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};

$runModulePublish = static function (string $name, callable $test) use (&$modulePublishFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $modulePublishFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runModulePublish('publish 支持预览、原子创建和同内容幂等', static function () use (
    $modulePublishAssertSame,
    $modulePublishAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        [$console, $stdout] = moduleCommandRunner($directory);
        $target = $directory . '/app/Generated/module.php';

        $modulePublishAssertSame(
            0,
            $console->run(['bin/simple', 'module:publish', 'base', 'config', '--dry-run']),
            '资源发布预览失败',
        );
        $modulePublishAssertTrue(!file_exists($target), '预览模式创建了目标文件');
        $modulePublishAssertSame(
            0,
            $console->run(['bin/simple', 'module:publish', 'base', 'config']),
            '资源首次发布失败',
        );
        $modulePublishAssertSame(
            moduleCommandResourceContent(),
            file_get_contents($target),
            '发布内容与 Module 源文件不一致',
        );
        $modulePublishAssertSame(
            0,
            $console->run(['bin/simple', 'module:publish', 'base', 'config']),
            '相同内容重复发布失败',
        );
        $modulePublishAssertSame(
            [],
            glob(dirname($target) . '/.simple-publish-*') ?: [],
            '发布后残留临时文件',
        );

        $output = moduleCommandStream($stdout);
        $modulePublishAssertTrue(str_contains($output, '[预览] 发布 Module 资源：base/config'), '缺少预览输出');
        $modulePublishAssertTrue(str_contains($output, '[完成] 发布 Module 资源：base/config'), '缺少完成输出');
        $modulePublishAssertTrue(str_contains($output, '  - 无变更'), '缺少幂等无变更输出');
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModulePublish('publish 遇到项目已有定制时停止且不覆盖', static function () use (
    $modulePublishAssertSame,
    $modulePublishAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        $target = $directory . '/app/Generated/module.php';
        mkdir(dirname($target), 0755, true);
        file_put_contents($target, "<?php\n\nreturn ['project' => true];\n");
        [$console, , $stderr] = moduleCommandRunner($directory);

        $modulePublishAssertSame(
            1,
            $console->run(['bin/simple', 'module:publish', 'base', 'config']),
            '不同内容的项目文件未阻止发布',
        );
        $modulePublishAssertSame(
            "<?php\n\nreturn ['project' => true];\n",
            file_get_contents($target),
            '项目已有文件被覆盖',
        );
        $modulePublishAssertTrue(
            str_contains(moduleCommandStream($stderr), '发布目标已存在且内容不同'),
            '发布冲突信息不明确',
        );
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModulePublish('publish 阻止未知资源、源文件越界和目标路径越界', static function () use (
    $modulePublishAssertSame,
    $modulePublishAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        [$console, , $stderr] = moduleCommandRunner($directory);

        $modulePublishAssertSame(
            1,
            $console->run(['bin/simple', 'module:publish', 'base', 'missing']),
            '未知资源未被阻止',
        );
        $modulePublishAssertSame(
            1,
            $console->run(['bin/simple', 'module:publish', 'dependent', 'config']),
            '未声明资源的 Module 仍可发布',
        );
        $modulePublishAssertSame(
            1,
            $console->run(['bin/simple', 'module:publish', 'base', 'outside-source']),
            'Module 包外源文件未被阻止',
        );
        $modulePublishAssertSame(
            1,
            $console->run(['bin/simple', 'module:publish', 'base', 'escape-target']),
            '项目外目标路径未被阻止',
        );

        $errors = moduleCommandStream($stderr);
        $modulePublishAssertTrue(str_contains($errors, 'Module 未声明资源：base/missing'), '未知资源错误不明确');
        $modulePublishAssertTrue(str_contains($errors, 'Module 未声明可发布资源：dependent'), '缺少声明错误不明确');
        $modulePublishAssertTrue(str_contains($errors, 'Module 资源源文件越界'), '源文件越界错误不明确');
        $modulePublishAssertTrue(str_contains($errors, 'Module 资源目标路径不合法'), '目标路径越界错误不明确');
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModulePublish('publish 阻止经符号链接写出项目目录', static function () use (
    $modulePublishAssertSame,
    $modulePublishAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    $outside = sys_get_temp_dir() . '/simple-framework-module-publish-outside-' . bin2hex(random_bytes(8));
    try {
        mkdir($outside, 0755, true);
        if (!symlink($outside, $directory . '/app')) {
            throw new RuntimeException('无法创建符号链接测试夹具');
        }
        [$console, , $stderr] = moduleCommandRunner($directory);

        $modulePublishAssertSame(
            1,
            $console->run(['bin/simple', 'module:publish', 'base', 'config']),
            '项目外符号链接目标未被阻止',
        );
        $modulePublishAssertTrue(
            !file_exists($outside . '/Generated/module.php'),
            '资源经符号链接写出了项目目录',
        );
        $modulePublishAssertTrue(
            str_contains(moduleCommandStream($stderr), 'Module 资源目标目录越界'),
            '符号链接越界错误不明确',
        );
    } finally {
        if (is_link($directory . '/app')) {
            unlink($directory . '/app');
        }
        if (is_dir($outside)) {
            rmdir($outside);
        }
        removeModuleCommandDirectory($directory);
    }
});

if ($modulePublishFailures !== []) {
    foreach ($modulePublishFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Module 资源发布测试通过。' . PHP_EOL;
