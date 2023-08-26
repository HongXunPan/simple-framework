<?php

namespace HongXunPan\Framework\Core;

use Exception;
use HongXunPan\DB\Mysql\Pdo\Pdo;
use HongXunPan\Framework\Response\Response;
use HongXunPan\Framework\Response\ResponseContract;
use HongXunPan\Tools\Config\Config;

trait ConfigTrait
{
    private function loadConfig(Application $app): static
    {
        Config::getInstance()->setConfigPath(
            $app->getPath('base', 'config'),
            $app->getPath('base', 'bootstrap/cache'),
            !$app->isDebug
        );
        ini_set('date.timezone', config('app.timezone'));
        return $this
            ->loadSingleton()
            ->loadDB()
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

    private function loadDB(): static
    {
        //database
        $databases = config('database.mysql');
        foreach ($databases as $name => $config) {
            Pdo::setConfig($config, $name);
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
