<?php

namespace HongXunPan\Framework\Core;

use Exception;
use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Response\Response;
use HongXunPan\Framework\Response\ResponseContract;

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

        return $this
            ->loadSingleton()
            ->loadBoot();
    }

    private function loadSingleton(): static
    {
        //singleton
        $singletons = config('singleton', [
            ResponseContract::class => Response::class,
        ]);
        foreach ($singletons as $key => $value) {
            if (is_int($key)) {
                app()->singleton($value);
            } else {
                app()->singleton($key, $value);
            }
        }
        return $this;
    }

    private function loadBoot()
    {
        $booters = config('boot');
        if ($booters) {
            foreach ($booters as $booter) {
                if ($booter instanceof \Closure) {
                    $booter();
                    continue;
                }
                if (is_array($booter) && count($booter) == 2) {
                    $class = $booter[0];
                    $method = $booter[1];
                    if (!method_exists($class, $method)) {
                        throw new Exception("method not exit, $class::$method");
                    }
                    $class::$method();
                    continue;
                }
            }
        }
        return $this;
    }
}
