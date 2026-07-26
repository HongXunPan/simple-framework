<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Bootstrap\Command;

use HongXunPan\Framework\Bootstrap\BootstrapCache;
use HongXunPan\Framework\Console\Output;
use RuntimeException;
use Throwable;

final class BootstrapCommandRunner
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
        try {
            $name = $argv[1] ?? '';
            if (count($argv) !== 2) {
                throw new RuntimeException("用法：{$name}");
            }

            return match ($name) {
                'bootstrap:clear' => $this->clear(),
                'bootstrap:cache' => $this->cache(),
                default => throw new RuntimeException("未知命令：{$name}"),
            };
        } catch (Throwable $throwable) {
            $this->output->error('[错误] ' . $throwable->getMessage());
            return 1;
        }
    }

    private function clear(): int
    {
        (new BootstrapCache($this->projectPath))->clear();
        $this->output->line('[完成] 启动缓存已清理');
        return 0;
    }

    private function cache(): int
    {
        (new BootstrapCache($this->projectPath))->cache();
        $this->output->line('[完成] 启动缓存已生成');
        return 0;
    }
}
