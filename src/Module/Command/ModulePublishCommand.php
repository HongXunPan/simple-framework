<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Command;

use HongXunPan\Framework\Module\ModulePublisher;

final class ModulePublishCommand extends Command
{
    public function run(string $name, string $resource, bool $dryRun = false): int
    {
        $package = $this->registry->find($name);
        $changes = new ModulePublisher($this->projectPath)->publish(
            $package,
            $resource,
            $dryRun,
        );
        $prefix = $dryRun ? '[预览]' : '[完成]';
        $this->output->line(
            "{$prefix} 发布 Module 资源：{$package->module->name()}/{$resource}",
        );
        if ($changes === []) {
            $this->output->line('  - 无变更');
        } else {
            $this->printChanges($changes);
        }

        return 0;
    }
}
