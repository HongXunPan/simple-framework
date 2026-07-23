<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Filesystem\AtomicFile;
use RuntimeException;
use Throwable;

function filesystemTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir() . '/simple-framework-filesystem-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('文件测试临时目录创建失败');
    }

    return $directory;
}

function removeFilesystemDirectory(string $directory): void
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

$filesystemFailures = [];

$filesystemAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$filesystemAssertTrue = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};

$runFilesystem = static function (string $name, callable $test) use (&$filesystemFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $filesystemFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runFilesystem('AtomicFile 原子替换并清理临时文件', static function () use (
    $filesystemAssertSame,
): void {
    $directory = filesystemTemporaryDirectory();
    try {
        $file = $directory . '/config.php';
        AtomicFile::replace($file, 'first', 0644);
        AtomicFile::replace($file, 'second', 0644);

        $filesystemAssertSame('second', AtomicFile::read($file), '原子替换内容错误');
        $filesystemAssertSame(0644, fileperms($file) & 0777, '原子替换权限错误');
        $filesystemAssertSame([], glob($directory . '/.atomic-*') ?: [], '原子替换遗留临时文件');
    } finally {
        removeFilesystemDirectory($directory);
    }
});

$runFilesystem('AtomicFile 原子创建且不覆盖已有文件', static function () use (
    $filesystemAssertSame,
): void {
    $directory = filesystemTemporaryDirectory();
    try {
        $file = $directory . '/published.php';
        $filesystemAssertSame(true, AtomicFile::create($file, 'module', 0644), '首次原子创建失败');
        $filesystemAssertSame(false, AtomicFile::create($file, 'project', 0644), '重复创建未返回冲突');
        $filesystemAssertSame('module', AtomicFile::read($file), '重复创建覆盖了已有文件');
        $filesystemAssertSame([], glob($directory . '/.atomic-*') ?: [], '原子创建遗留临时文件');
    } finally {
        removeFilesystemDirectory($directory);
    }
});

$runFilesystem('AtomicFile 不隐式创建目录且读取失败明确抛错', static function () use (
    $filesystemAssertTrue,
): void {
    $directory = filesystemTemporaryDirectory();
    try {
        $replaceFailed = false;
        try {
            AtomicFile::replace($directory . '/missing/file.php', 'content');
        } catch (RuntimeException $exception) {
            $replaceFailed = str_contains($exception->getMessage(), '文件目录不存在');
        }
        $filesystemAssertTrue($replaceFailed, '原子替换隐式创建了目录或错误不明确');

        $readFailed = false;
        try {
            AtomicFile::read($directory . '/missing.php');
        } catch (RuntimeException $exception) {
            $readFailed = str_contains($exception->getMessage(), '文件读取失败');
        }
        $filesystemAssertTrue($readFailed, '缺失文件读取未明确失败');
    } finally {
        removeFilesystemDirectory($directory);
    }
});

if ($filesystemFailures !== []) {
    foreach ($filesystemFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Filesystem 原子文件测试通过。' . PHP_EOL;
