<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Filesystem;

use RuntimeException;

final class AtomicFile
{
    public static function read(string $file): string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("文件读取失败：{$file}");
        }

        return $content;
    }

    public static function replace(
        string $file,
        string $content,
        ?int $permissions = null,
    ): void {
        $temporaryFile = self::temporaryFile($file, $content, $permissions);
        try {
            if (!@rename($temporaryFile, $file)) {
                throw new RuntimeException("文件原子替换失败：{$file}");
            }
        } finally {
            self::removeTemporaryFile($temporaryFile);
        }
    }

    public static function create(
        string $file,
        string $content,
        ?int $permissions = null,
    ): bool {
        $temporaryFile = self::temporaryFile($file, $content, $permissions);
        try {
            if (@link($temporaryFile, $file)) {
                return true;
            }
            if (is_link($file) || file_exists($file)) {
                return false;
            }

            throw new RuntimeException("文件原子创建失败：{$file}");
        } finally {
            self::removeTemporaryFile($temporaryFile);
        }
    }

    private static function temporaryFile(
        string $file,
        string $content,
        ?int $permissions,
    ): string {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            throw new RuntimeException("文件目录不存在：{$directory}");
        }
        if ($permissions !== null && ($permissions < 0 || $permissions > 0777)) {
            throw new RuntimeException("文件权限不合法：{$permissions}");
        }

        $temporaryFile = @tempnam($directory, '.atomic-');
        if ($temporaryFile === false) {
            throw new RuntimeException("临时文件创建失败：{$directory}");
        }

        try {
            if (@file_put_contents($temporaryFile, $content, LOCK_EX) === false) {
                throw new RuntimeException("临时文件写入失败：{$temporaryFile}");
            }
            if ($permissions !== null && !@chmod($temporaryFile, $permissions)) {
                throw new RuntimeException("临时文件权限设置失败：{$temporaryFile}");
            }
        } catch (\Throwable $throwable) {
            self::removeTemporaryFile($temporaryFile);
            throw $throwable;
        }

        return $temporaryFile;
    }

    private static function removeTemporaryFile(string $temporaryFile): void
    {
        if (is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }
}
