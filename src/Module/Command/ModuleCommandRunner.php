<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Command;

use HongXunPan\Framework\Console\Output;
use HongXunPan\Framework\Module\Exception\ModuleException;
use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\Framework\Module\ModuleRegistry;
use Throwable;

final class ModuleCommandRunner
{
    private readonly ModuleRegistry $registry;
    private readonly ModuleConfig $config;
    private readonly Output $output;

    public function __construct(private readonly string $projectPath, ?Output $output = null)
    {
        $this->registry = new ModuleRegistry($projectPath);
        $this->config = new ModuleConfig($projectPath);
        $this->output = $output ?? new Output();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            $arguments = array_slice($argv, 1);
            $name = array_shift($arguments) ?? 'help';
            $dryRun = false;
            $positionals = [];
            foreach ($arguments as $argument) {
                if ($argument === '--dry-run') {
                    $dryRun = true;
                    continue;
                }
                if (str_starts_with($argument, '--')) {
                    throw new ModuleException("未知选项：{$argument}");
                }
                $positionals[] = $argument;
            }

            return match ($name) {
                'module:enable' => $this->enable($positionals, $dryRun),
                'module:refresh' => $this->refresh($positionals, $dryRun),
                'module:disable' => $this->disable($positionals, $dryRun),
                'module:status' => $this->status($positionals, $dryRun),
                'module:publish' => $this->publish($positionals, $dryRun),
                'help', '--help', '-h' => $this->help(),
                default => throw new ModuleException("未知命令：{$name}"),
            };
        } catch (Throwable $throwable) {
            $this->output->error('[错误] ' . $throwable->getMessage());
            return 1;
        }
    }

    /** @param list<string> $arguments */
    private function enable(array $arguments, bool $dryRun): int
    {
        $name = $this->requiredName($arguments, 'module:enable');
        return (new ModuleEnableCommand(
            $this->projectPath,
            $this->registry,
            $this->config,
            $this->output,
        ))->run($name, $dryRun);
    }

    /** @param list<string> $arguments */
    private function refresh(array $arguments, bool $dryRun): int
    {
        $name = $this->optionalName($arguments, 'module:refresh');
        return (new ModuleRefreshCommand(
            $this->projectPath,
            $this->registry,
            $this->config,
            $this->output,
        ))->run($name, $dryRun);
    }

    /** @param list<string> $arguments */
    private function disable(array $arguments, bool $dryRun): int
    {
        $name = $this->requiredName($arguments, 'module:disable');
        return (new ModuleDisableCommand(
            $this->projectPath,
            $this->registry,
            $this->config,
            $this->output,
        ))->run($name, $dryRun);
    }

    /** @param list<string> $arguments */
    private function status(array $arguments, bool $dryRun): int
    {
        if ($dryRun) {
            throw new ModuleException('module:status 不支持 --dry-run');
        }
        $name = $this->optionalName($arguments, 'module:status');
        return (new ModuleStatusCommand(
            $this->projectPath,
            $this->registry,
            $this->config,
            $this->output,
        ))->run($name);
    }

    /** @param list<string> $arguments */
    private function publish(array $arguments, bool $dryRun): int
    {
        if (count($arguments) !== 2) {
            throw new ModuleException('用法：module:publish <name> <resource>');
        }
        return (new ModulePublishCommand(
            $this->projectPath,
            $this->registry,
            $this->config,
            $this->output,
        ))->run($arguments[0], $arguments[1], $dryRun);
    }

    private function help(): int
    {
        $this->output->line('Simple Module 命令：');
        $this->output->line('  module:enable <name> [--dry-run]');
        $this->output->line('  module:refresh [name] [--dry-run]');
        $this->output->line('  module:disable <name> [--dry-run]');
        $this->output->line('  module:status [name]');
        $this->output->line('  module:publish <name> <resource> [--dry-run]');
        return 0;
    }

    /** @param list<string> $arguments */
    private function requiredName(array $arguments, string $command): string
    {
        if (count($arguments) !== 1) {
            throw new ModuleException("用法：{$command} <name>");
        }
        return $arguments[0];
    }

    /** @param list<string> $arguments */
    private function optionalName(array $arguments, string $command): ?string
    {
        if (count($arguments) > 1) {
            throw new ModuleException("用法：{$command} [name]");
        }
        return $arguments[0] ?? null;
    }
}
