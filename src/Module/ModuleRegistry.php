<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

use HongXunPan\Framework\Module\Exception\ModuleException;
use JsonException;

final class ModuleRegistry
{
    /** @var list<ModulePackage>|null */
    private ?array $packages = null;

    private readonly string $projectPath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<ModulePackage>
     */
    public function all(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $installedFile = $this->projectPath . '/vendor/composer/installed.php';
        if (!is_file($installedFile)) {
            throw new ModuleException('缺少 Composer 安装清单，请先执行 composer install');
        }
        $installed = require $installedFile;
        if (!is_array($installed)) {
            throw new ModuleException('Composer 安装清单格式错误：' . $installedFile);
        }

        $datasets = isset($installed['versions']) ? [$installed] : $installed;
        $packages = [];
        $names = [];
        $classes = [];
        foreach ($datasets as $dataset) {
            if (!is_array($dataset) || !isset($dataset['versions']) || !is_array($dataset['versions'])) {
                continue;
            }
            foreach ($dataset['versions'] as $packageName => $packageData) {
                if (!is_string($packageName)
                    || !is_array($packageData)
                    || ($packageData['type'] ?? null) !== 'simple-module') {
                    continue;
                }
                $package = $this->loadPackage($packageName, $packageData);
                $moduleName = $package->module->name();
                $moduleClass = $package->module::class;
                if (isset($names[$moduleName])) {
                    throw new ModuleException(
                        "Module 名称重复：{$moduleName} 同时来自 {$names[$moduleName]} 和 {$packageName}",
                    );
                }
                if (isset($classes[$moduleClass])) {
                    throw new ModuleException("Module 入口类被多个包重复声明：{$moduleClass}");
                }
                $names[$moduleName] = $packageName;
                $classes[$moduleClass] = true;
                $packages[] = $package;
            }
        }

        usort(
            $packages,
            static fn (ModulePackage $left, ModulePackage $right): int => $left->module->name() <=> $right->module->name(),
        );
        $this->packages = $packages;

        return $this->packages;
    }

    public function find(string $name): ModulePackage
    {
        foreach ($this->all() as $package) {
            if ($package->module->name() === $name || $package->package === $name) {
                return $package;
            }
        }

        throw new ModuleException("未找到已安装 Module：{$name}");
    }

    /**
     * @param class-string<Module> $moduleClass
     */
    public function findByClass(string $moduleClass): ModulePackage
    {
        $moduleClass = ltrim($moduleClass, '\\');
        foreach ($this->all() as $package) {
            if ($package->module::class === $moduleClass) {
                return $package;
            }
        }

        throw new ModuleException("已启用 Module 的 Composer 包不存在：{$moduleClass}");
    }

    /**
     * @param array<string, mixed> $packageData
     */
    private function loadPackage(string $packageName, array $packageData): ModulePackage
    {
        $installPath = $packageData['install_path'] ?? null;
        if (!is_string($installPath) || !is_dir($installPath)) {
            throw new ModuleException("Module 包目录不存在：{$packageName}");
        }
        $composerFile = rtrim($installPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerFile)) {
            throw new ModuleException("Module 缺少 composer.json：{$packageName}");
        }

        try {
            $composer = json_decode(
                (string) file_get_contents($composerFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new ModuleException("Module composer.json 解析失败：{$packageName}", 0, $exception);
        }
        $moduleClass = $composer['extra']['simple']['module'] ?? null;
        if (!is_string($moduleClass) || $moduleClass === '') {
            throw new ModuleException("Module 缺少 extra.simple.module：{$packageName}");
        }
        if (!class_exists($moduleClass)) {
            throw new ModuleException("Module 入口类无法加载：{$moduleClass}");
        }

        $module = new $moduleClass();
        if (!$module instanceof Module) {
            throw new ModuleException("Module 入口类未实现契约：{$moduleClass}");
        }
        if (preg_match('/^[a-z][a-z0-9-]*$/', $module->name()) !== 1) {
            throw new ModuleException("Module 名称只能使用小写字母、数字和连字符：{$module->name()}");
        }
        $realInstallPath = realpath($installPath);
        $realModulePath = realpath($module->basePath());
        if ($realInstallPath === false || $realModulePath === false || $realInstallPath !== $realModulePath) {
            throw new ModuleException("Module basePath 必须指向自身 Composer 包目录：{$packageName}");
        }

        return new ModulePackage(
            package: $packageName,
            version: (string) ($packageData['pretty_version'] ?? $packageData['version'] ?? 'unknown'),
            path: $realInstallPath,
            module: $module,
        );
    }
}
