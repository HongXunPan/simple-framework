<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

interface ModuleInstaller
{
    /**
     * @return list<string>
     */
    public function install(string $projectPath, bool $dryRun = false): array;

    /**
     * @return list<string>
     */
    public function refresh(string $projectPath, bool $dryRun = false): array;

    /**
     * @return list<string>
     */
    public function upgrade(string $projectPath, string $fromVersion, bool $dryRun = false): array;

    /**
     * @return list<string>
     */
    public function uninstall(string $projectPath, bool $dryRun = false): array;
}
