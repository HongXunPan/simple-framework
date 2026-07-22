<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Config;

use RuntimeException;

final class Env
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(?string $filePath = null)
    {
        if ($filePath !== null) {
            $this->load($filePath);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $env = new self();
        foreach ($values as $key => $value) {
            $env->values[$env->normalizeKey((string) $key)] = $value;
        }

        return $env;
    }

    public function load(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }
        if (!is_readable($filePath)) {
            throw new RuntimeException('环境配置文件不可读：' . $filePath);
        }

        $values = parse_ini_file($filePath, false, INI_SCANNER_RAW);
        if ($values === false) {
            throw new RuntimeException('环境配置文件解析失败：' . $filePath);
        }

        foreach ($values as $key => $value) {
            $this->values[$this->normalizeKey((string) $key)] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->normalizeKey($key);
        $value = getenv($key);
        if ($value !== false) {
            return $this->normalizeValue($value);
        }
        if (array_key_exists($key, $_ENV)) {
            return $this->normalizeValue($_ENV[$key]);
        }
        if (array_key_exists($key, $_SERVER)) {
            return $this->normalizeValue($_SERVER[$key]);
        }
        if (array_key_exists($key, $this->values)) {
            return $this->normalizeValue($this->values[$key]);
        }

        return $default;
    }

    private function normalizeKey(string $key): string
    {
        return strtoupper(str_replace('.', '_', $key));
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}
