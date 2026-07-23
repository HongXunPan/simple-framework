<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Command;

use Throwable;

final class ModuleStatusCommand extends Command
{
    public function run(?string $name = null): int
    {
        $packages = $name === null ? $this->registry->all() : [$this->registry->find($name)];
        $enabledClasses = $this->config->enabled();
        if ($packages === [] && $enabledClasses === []) {
            $this->output->line('没有已安装 Module。');
            return 0;
        }

        $installedClasses = [];
        foreach ($packages as $package) {
            $module = $package->module;
            $moduleClass = $module::class;
            $installedClasses[] = $moduleClass;
            $enabled = in_array($moduleClass, $enabledClasses, true);
            $missingDependencies = array_values(array_filter(
                $module->requires(),
                static fn (string $requiredClass): bool => !in_array(
                    ltrim($requiredClass, '\\'),
                    $enabledClasses,
                    true,
                ),
            ));
            $refresh = '未启用';
            if ($enabled) {
                try {
                    $changes = $this->installer($module)?->refresh($this->projectPath, true) ?? [];
                    $refresh = $changes === [] ? '无需刷新' : '待刷新';
                } catch (Throwable $throwable) {
                    $refresh = '冲突：' . $throwable->getMessage();
                }
            }
            $dependencyStatus = $missingDependencies === []
                ? '满足'
                : '缺失 ' . implode(', ', $missingDependencies);
            $this->output->line(sprintf(
                '%s  包=%s  版本=%s  状态=%s  依赖=%s  刷新=%s',
                $module->name(),
                $package->package,
                $package->version,
                $enabled ? '已启用' : '未启用',
                $dependencyStatus,
                $refresh,
            ));
        }

        if ($name === null) {
            foreach ($enabledClasses as $enabledClass) {
                if (!in_array($enabledClass, $installedClasses, true)) {
                    $this->output->line("缺失  类={$enabledClass}  状态=已配置但 Composer 包不存在");
                }
            }
        }

        return 0;
    }
}
