<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Command;

use HongXunPan\Framework\Module\Exception\ModuleException;

final class ModuleRefreshCommand extends Command
{
    public function run(?string $name = null, bool $dryRun = false): int
    {
        $packages = $this->enabledPackages();
        $this->validatePackages($packages);
        if ($name !== null) {
            $target = $this->registry->find($name);
            if (!$this->config->isEnabled($target->module::class)) {
                throw new ModuleException("Module 尚未启用：{$target->module->name()}");
            }
            $packages = [$target];
        }
        if ($packages === []) {
            $this->output->line('没有已启用 Module，刷新完成。');
            return 0;
        }

        if (!$dryRun) {
            foreach ($packages as $package) {
                $this->installer($package->module)?->refresh($this->projectPath, true);
            }
        }

        foreach ($packages as $package) {
            $module = $package->module;
            $installer = $this->installer($module);
            $changes = $installer?->refresh($this->projectPath, $dryRun) ?? [];
            $prefix = $dryRun ? '[预览]' : '[完成]';
            $this->output->line("{$prefix} 刷新 Module：{$module->name()}");
            if ($changes === []) {
                $this->output->line('  - 无变更');
            } else {
                $this->printChanges($changes);
            }
        }

        return 0;
    }
}
