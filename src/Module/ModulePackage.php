<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

final readonly class ModulePackage
{
    public function __construct(
        public string $package,
        public string $version,
        public string $path,
        public Module $module,
    ) {
    }
}
