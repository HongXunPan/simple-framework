<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Config;

use HongXunPan\Framework\Filesystem\AtomicFile;
use RuntimeException;

final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];
    private bool $loaded = false;

    public function __construct(
        private readonly string $configPath = '',
        private readonly string $cachePath = '',
        private readonly bool $cacheEnabled = false,
    ) {
    }

    /**
     * @param array<string, mixed> $items
     */
    public static function fromArray(array $items): self
    {
        $config = new self();
        $config->items = $items;
        $config->loaded = true;

        return $config;
    }

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        if ($this->configPath === '') {
            $this->loaded = true;

            return;
        }

        $cacheFile = $this->cacheFile();
        if ($this->cacheEnabled && $cacheFile !== '' && is_file($cacheFile)) {
            $this->items = $this->requireArray($cacheFile, '配置缓存');
            $this->loaded = true;

            return;
        }

        $this->items = $this->loadFiles();
        $this->loaded = true;
    }

    public function cache(): void
    {
        $cacheFile = $this->cacheFile();
        if ($this->configPath === '' || $cacheFile === '') {
            throw new RuntimeException('配置目录和缓存目录不能为空');
        }

        $this->items = $this->loadFiles();
        $this->loaded = true;
        $this->writeCache($cacheFile);
    }

    public function get(string $key = '', mixed $default = ''): mixed
    {
        $this->load();
        if ($key === '') {
            return $this->items;
        }

        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireArray(string $file, string $type): array
    {
        $value = require $file;
        if (!is_array($value)) {
            throw new RuntimeException($type . '必须返回数组：' . $file);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFiles(): array
    {
        $items = [];
        $files = glob(rtrim($this->configPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $items[pathinfo($file, PATHINFO_FILENAME)] = $this->requireArray($file, '配置文件');
        }

        return $items;
    }

    private function cacheFile(): string
    {
        if ($this->cachePath === '') {
            return '';
        }

        return rtrim($this->cachePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.php';
    }

    private function writeCache(string $cacheFile): void
    {
        $cacheDirectory = dirname($cacheFile);
        if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0755, true) && !is_dir($cacheDirectory)) {
            throw new RuntimeException('配置缓存目录创建失败：' . $cacheDirectory);
        }

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->items, true) . ";\n";
        AtomicFile::replace($cacheFile, $content, 0644);
    }
}
