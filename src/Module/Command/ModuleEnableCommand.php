<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Command;

use Throwable;

final class ModuleEnableCommand extends Command
{
    public function run(string $name, bool $dryRun = false): int
    {
        $package = $this->registry->find($name);
        $module = $package->module;
        $moduleClass = $module::class;
        if ($this->config->isEnabled($moduleClass)) {
            $this->output->line("Module 已启用，无需重复处理：{$module->name()}");
            return 0;
        }

        $enabledPackages = $this->enabledPackages();
        $this->validatePackages([...$enabledPackages, $package]);

        $installer = $this->installer($module);
        if ($dryRun) {
            $this->output->line("[预览] 启用 Module：{$module->name()}");
            $this->printChanges($installer?->install($this->projectPath, true) ?? []);
            $this->output->line("  - 写入 config('module.enable')");
            return 0;
        }

        $installed = false;
        try {
            $installer?->install($this->projectPath, true);
            $changes = $installer?->install($this->projectPath) ?? [];
            $installed = true;
            $this->config->add($moduleClass);
        } catch (Throwable $throwable) {
            if ($installed && $installer !== null) {
                try {
                    $installer->uninstall($this->projectPath);
                } catch (Throwable $rollbackFailure) {
                    throw new ModuleException(
                        "Module 启用失败且回滚失败：{$throwable->getMessage()}；回滚：{$rollbackFailure->getMessage()}",
                        0,
                        $throwable,
                    );
                }
            }
            throw $throwable;
        }

        $this->printChanges($changes);
        $this->output->line("[完成] 已启用 Module：{$module->name()}");
        return 0;
    }
}
