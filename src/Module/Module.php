<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

interface Module
{
    public function name(): string;

    public function basePath(): string;

    /**
     * @return list<class-string<Module>>
     */
    public function requires(): array;

    /**
     * @return class-string<ModuleInstaller>|null
     */
    public function installer(): ?string;
}
