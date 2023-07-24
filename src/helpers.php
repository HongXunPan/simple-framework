<?php

use HongXunPan\Framework\Core\Application;
use HongXunPan\Tools\Env\Env;
use Illuminate\Contracts\Container\BindingResolutionException;

if (!function_exists('app')) {
    /**
     * @param string|null $make
     * @param array $parameters
     * @return Closure|Application|mixed|object|null
     * @throws BindingResolutionException
     * @author HongXunPan <me@kangxuanpeng.com>
     * @date 2023-07-24 14:22
     */
    function app(?string $make = null, array $parameters = []): mixed
    {
        if (!$make) {
            return Application::getInstance();
        }
        return Application::getInstance()->make($make, $parameters);
    }

    if (!function_exists('env')) {
        /**
         * @param $key
         * @param $default
         * @return bool|array|string|null
         * @throws Exception
         * @author HongXunPan <me@kangxuanpeng.com>
         * @date 2023-07-24 14:50
         */
        function env($key, $default = null): bool|array|string|null
        {
            return Env::get($key, $default);
        }
    }
}