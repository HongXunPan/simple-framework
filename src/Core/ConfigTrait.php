<?php

namespace HongXunPan\Framework\Core;

use HongXunPan\Framework\Response\Response;
use HongXunPan\Framework\Response\ResponseContract;
use HongXunPan\Tools\Config\Config;

trait ConfigTrait
{
    private function loadConfig(Application $app): static
    {
        Config::getInstance()->setConfigPath(
            $app->getPath('base', 'config'),
            $app->getPath('base', 'boostrap/cache'),
            !$app->isDebug
        );
        ini_set('date.timezone', config('app.timezone'));
        return $this
            ->loadSingleton()
            ->loadDB();
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

    private function loadDB(): static
    {
        //database
        return $this;
    }
}
