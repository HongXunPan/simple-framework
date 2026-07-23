<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

use HongXunPan\Framework\Module\Exception\ModuleException;

final class ModuleConfig
{
    private readonly string $projectPath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<class-string<Module>>
     */
    public function enabled(): array
    {
        return $this->configuration()['enable'];
    }

    /**
     * @param class-string<Module> $moduleClass
     */
    public function isEnabled(string $moduleClass): bool
    {
        return in_array(ltrim($moduleClass, '\\'), $this->enabled(), true);
    }

    /**
     * @param class-string<Module> $moduleClass
     */
    public function add(string $moduleClass): bool
    {
        $moduleClass = ltrim($moduleClass, '\\');
        $enabled = $this->enabled();
        if (in_array($moduleClass, $enabled, true)) {
            return false;
        }

        $enabled[] = $moduleClass;
        $this->write($enabled);

        return true;
    }

    /**
     * @param class-string<Module> $moduleClass
     */
    public function remove(string $moduleClass): bool
    {
        $moduleClass = ltrim($moduleClass, '\\');
        $enabled = $this->enabled();
        $remaining = array_values(array_filter(
            $enabled,
            static fn (string $enabledClass): bool => $enabledClass !== $moduleClass,
        ));
        if ($remaining === $enabled) {
            return false;
        }

        $this->write($remaining);

        return true;
    }

    public function file(): string
    {
        return $this->projectPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'module.php';
    }

    /**
     * @param list<class-string<Module>> $modules
     */
    private function write(array $modules): void
    {
        $directory = dirname($this->file());
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ModuleException('Module 配置目录创建失败：' . $directory);
        }

        $configuration = $this->configuration();
        $configuration['enable'] = $this->validateClassList($modules, 'module.enable');
        $content = $this->render($configuration);

        $temporaryFile = tempnam($directory, 'module-');
        if ($temporaryFile === false) {
            throw new ModuleException('Module 配置临时文件创建失败：' . $directory);
        }

        try {
            if (file_put_contents($temporaryFile, $content, LOCK_EX) === false) {
                throw new ModuleException('Module 配置写入失败：' . $temporaryFile);
            }
            if (!chmod($temporaryFile, 0644)) {
                throw new ModuleException('Module 配置权限设置失败：' . $temporaryFile);
            }
            if (!rename($temporaryFile, $this->file())) {
                throw new ModuleException('Module 配置原子替换失败：' . $this->file());
            }

            $cacheFile = $this->projectPath . DIRECTORY_SEPARATOR . 'bootstrap/cache/config.php';
            if (is_file($cacheFile) && !unlink($cacheFile)) {
                throw new ModuleException('Module 配置已更新，但配置缓存清理失败：' . $cacheFile);
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    /**
     * @return array{enable: list<class-string<Module>>, 'provider-override': list<class-string>}
     */
    private function configuration(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [
                'enable' => [],
                'provider-override' => [],
            ];
        }

        $configuration = require $file;
        if (!is_array($configuration)) {
            throw new ModuleException('config/module.php 必须返回配置数组');
        }

        $unknownKeys = array_diff(array_keys($configuration), ['enable', 'provider-override']);
        if ($unknownKeys !== []) {
            throw new ModuleException(
                'config/module.php 包含未知配置项：' . implode('、', $unknownKeys),
            );
        }

        return [
            'enable' => $this->validateClassList($configuration['enable'] ?? [], 'module.enable'),
            'provider-override' => $this->validateClassList(
                $configuration['provider-override'] ?? [],
                'module.provider-override',
            ),
        ];
    }

    /**
     * @return list<class-string>
     */
    private function validateClassList(mixed $classes, string $key): array
    {
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new ModuleException("config('{$key}') 必须是类名列表");
        }

        $validated = [];
        foreach ($classes as $class) {
            if (!is_string($class) || !$this->isClassName($class)) {
                throw new ModuleException("config('{$key}') 只能包含合法类名");
            }
            $class = ltrim($class, '\\');
            if (in_array($class, $validated, true)) {
                throw new ModuleException("config('{$key}') 存在重复类名：{$class}");
            }
            $validated[] = $class;
        }

        return $validated;
    }

    /**
     * @param array{enable: list<class-string<Module>>, 'provider-override': list<class-string>} $configuration
     */
    private function render(array $configuration): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'return [',
        ];
        foreach ($configuration as $key => $classes) {
            $lines[] = "    '{$key}' => [";
            foreach ($classes as $class) {
                $lines[] = '        \\' . $class . '::class,';
            }
            $lines[] = '    ],';
        }
        $lines[] = '];';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function isClassName(string $class): bool
    {
        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) === 1;
    }
}
