<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Bootstrap;

use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Core\Application;
use RuntimeException;
use Throwable;

final class BootstrapCache
{
    private const array FILES = [
        'config.php',
        'routes.php',
    ];

    private readonly string $projectPath;
    private readonly string $cachePath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, DIRECTORY_SEPARATOR);
        if ($this->projectPath === '') {
            throw new RuntimeException('项目目录不能为空');
        }
        $this->cachePath = $this->projectPath . DIRECTORY_SEPARATOR . 'bootstrap/cache';
    }

    public function clear(): void
    {
        foreach (self::FILES as $fileName) {
            $file = $this->cachePath . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($file) && !@unlink($file) && is_file($file)) {
                throw new RuntimeException('启动缓存删除失败：' . $file);
            }
        }
    }

    public function cache(): void
    {
        $this->clear();

        try {
            $app = new Application();
            $config = new Config(
                configPath: $this->projectPath . DIRECTORY_SEPARATOR . 'config',
                cachePath: $this->cachePath,
                cacheEnabled: false,
            );
            $app->instance(Config::class, $config);
            $app->init($this->projectPath);

            $config->cache();
            $app->cacheRoutes();
        } catch (Throwable $throwable) {
            $this->clear();
            throw $throwable;
        }
    }
}
