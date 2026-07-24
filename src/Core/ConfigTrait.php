<?php

namespace HongXunPan\Framework\Core;

use HongXunPan\Framework\Config\Config;

trait ConfigTrait
{
    private function loadConfig(Application $app, bool $cacheEnabled): static
    {
        if (!$app->bound(Config::class)) {
            $app->instance(Config::class, new Config(
                configPath: $app->getPath('base', 'config'),
                cachePath: $app->getPath('base', 'bootstrap/cache'),
                cacheEnabled: $cacheEnabled,
            ));
        }
        app(Config::class)->load();
        date_default_timezone_set((string) config('app.timezone', 'UTC'));

        return $this;
    }
}
