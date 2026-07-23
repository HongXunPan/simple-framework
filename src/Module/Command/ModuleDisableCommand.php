<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Command;

use HongXunPan\Framework\Module\Exception\ModuleException;
use Throwable;

final class ModuleDisableCommand extends Command
{
    public function run(string $name, bool $dryRun = false): int
    {
        $package = $this->registry->find($name);
        $module = $package->module;
        $moduleClass = $module::class;
        if (!$this->config->isEnabled($moduleClass)) {
            $this->output->line("Module 未启用，无需处理：{$module->name()}");
            return 0;
        }

        $enabledPackages = $this->enabledPackages();
        foreach ($enabledPackages as $enabledPackage) {
            if ($enabledPackage->module::class === $moduleClass) {
                continue;
            }
            foreach ($enabledPackage->module->requires() as $requiredClass) {
                if (ltrim($requiredClass, '\\') === $moduleClass) {
                    throw new ModuleException(
                        "Module {$module->name()} 正被 {$enabledPackage->module->name()} 依赖，不能禁用",
                    );
                }
            }
        }

        $installer = $this->installer($module);
        if ($dryRun) {
            $this->output->line("[预览] 禁用 Module：{$module->name()}");
            $this->printChanges($installer?->uninstall($this->projectPath, true) ?? []);
            $this->output->line("  - 从 config('module.enable') 移除");
            return 0;
        }

        $uninstalled = false;
        try {
            $installer?->uninstall($this->projectPath, true);
            $changes = $installer?->uninstall($this->projectPath) ?? [];
            $uninstalled = true;
            $this->config->remove($moduleClass);
        } catch (Throwable $throwable) {
            if ($uninstalled && $installer !== null) {
                try {
                    $installer->install($this->projectPath);
                } catch (Throwable $rollbackFailure) {
                    throw new ModuleException(
                        "Module 禁用失败且回滚失败：{$throwable->getMessage()}；回滚：{$rollbackFailure->getMessage()}",
                        0,
                        $throwable,
                    );
                }
            }
            throw $throwable;
        }

        $this->printChanges($changes);
        $this->output->line("[完成] 已禁用 Module：{$module->name()}");
        return 0;
    }
}
