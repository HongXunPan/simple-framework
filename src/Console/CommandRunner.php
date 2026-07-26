<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Console;

use HongXunPan\Framework\Bootstrap\Command\BootstrapCommandRunner;
use HongXunPan\Framework\Module\Command\ModuleCommandRunner;

final class CommandRunner
{
    private readonly Output $output;

    public function __construct(private readonly string $projectPath, ?Output $output = null)
    {
        $this->output = $output ?? new Output();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';
        if (str_starts_with($name, 'module:')) {
            return (new ModuleCommandRunner($this->projectPath, $this->output))->run($argv);
        }
        if (str_starts_with($name, 'bootstrap:')) {
            return (new BootstrapCommandRunner($this->projectPath, $this->output))->run($argv);
        }
        if (in_array($name, ['help', '--help', '-h'], true)) {
            return $this->help();
        }

        $this->output->error('[错误] 未知命令：' . $name);
        return 1;
    }

    private function help(): int
    {
        $this->output->line('Simple 命令：');
        $this->output->line('  bootstrap:clear');
        $this->output->line('  bootstrap:cache');
        $this->output->line('  module:enable <name> [--dry-run]');
        $this->output->line('  module:refresh [name] [--dry-run]');
        $this->output->line('  module:disable <name> [--dry-run]');
        $this->output->line('  module:status [name]');
        $this->output->line('  module:publish <name> <resource> [--dry-run]');
        return 0;
    }
}
