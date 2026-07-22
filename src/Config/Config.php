<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Config;

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

        $files = glob(rtrim($this->configPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $this->items[pathinfo($file, PATHINFO_FILENAME)] = $this->requireArray($file, '配置文件');
        }

        if ($this->cacheEnabled && $cacheFile !== '') {
            $this->writeCache($cacheFile);
        } elseif ($cacheFile !== '' && is_file($cacheFile)) {
            @unlink($cacheFile);
        }

        $this->loaded = true;
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

        $temporaryFile = tempnam($cacheDirectory, 'config-');
        if ($temporaryFile === false) {
            throw new RuntimeException('配置缓存临时文件创建失败：' . $cacheDirectory);
        }

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->items, true) . ";\n";
        try {
            if (file_put_contents($temporaryFile, $content, LOCK_EX) === false) {
                throw new RuntimeException('配置缓存写入失败：' . $temporaryFile);
            }
            if (!rename($temporaryFile, $cacheFile)) {
                throw new RuntimeException('配置缓存替换失败：' . $cacheFile);
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }
}
