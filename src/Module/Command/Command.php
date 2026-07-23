<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Command;

use HongXunPan\Framework\Console\Output;
use HongXunPan\Framework\Module\Exception\ModuleException;
use HongXunPan\Framework\Module\Module;
use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\Framework\Module\ModuleInstaller;
use HongXunPan\Framework\Module\ModulePackage;
use HongXunPan\Framework\Module\ModuleRegistry;
use HongXunPan\Framework\Module\HelperLoader;

abstract class Command
{
    public function __construct(
        protected readonly string $projectPath,
        protected readonly ModuleRegistry $registry,
        protected readonly ModuleConfig $config,
        protected readonly Output $output,
    ) {
    }

    protected function installer(Module $module): ?ModuleInstaller
    {
        $installerClass = $module->installer();
        if ($installerClass === null) {
            return null;
        }
        if (!class_exists($installerClass)) {
            throw new ModuleException("Module Installer 无法加载：{$installerClass}");
        }
        $installer = new $installerClass();
        if (!$installer instanceof ModuleInstaller) {
            throw new ModuleException("Module Installer 未实现契约：{$installerClass}");
        }

        return $installer;
    }

    /**
     * @return list<ModulePackage>
     */
    protected function enabledPackages(): array
    {
        $packages = [];
        foreach ($this->config->enabled() as $moduleClass) {
            $packages[] = $this->registry->findByClass($moduleClass);
        }

        return $packages;
    }

    /**
     * @param list<ModulePackage> $packages
     */
    protected function validatePackages(array $packages): void
    {
        $enabledClasses = array_map(
            static fn (ModulePackage $package): string => $package->module::class,
            $packages,
        );
        foreach ($packages as $package) {
            foreach ($package->module->requires() as $requiredClass) {
                if (!is_string($requiredClass) || $requiredClass === '') {
                    throw new ModuleException("Module {$package->module->name()} 声明了非法依赖类名");
                }
                $this->registry->findByClass($requiredClass);
                if (!in_array(ltrim($requiredClass, '\\'), $enabledClasses, true)) {
                    throw new ModuleException(
                        "Module {$package->module->name()} 依赖尚未启用：{$requiredClass}",
                    );
                }
            }
        }

        (new HelperLoader())->validate(array_map(
            static fn (ModulePackage $package): Module => $package->module,
            $packages,
        ));
    }

    /**
     * @param list<string> $changes
     */
    protected function printChanges(array $changes): void
    {
        foreach ($changes as $change) {
            $this->output->line('  - ' . $change);
        }
    }
}
