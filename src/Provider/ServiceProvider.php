<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Provider;

use HongXunPan\Framework\Core\Application;

abstract class ServiceProvider
{
    abstract public function register(Application $app): void;

    public function boot(Application $app): void
    {
    }
}
