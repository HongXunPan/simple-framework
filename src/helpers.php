<?php

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Config\Env;
use HongXunPan\Framework\Exceptions\ExceptionReporter;
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
    function env(string $key, mixed $default = null): mixed
    {
        return Application::getInstance()->make(Env::class)->get($key, $default);
    }
}

if (!function_exists('config')) {
    /**
     * @param string $key
     * @param bool|array|string $default
     * @return array|bool|mixed|string|null
     */
    function config(string $key = '', mixed $default = ''): mixed
    {
        return Application::getInstance()->make(Config::class)->get($key, $default);
    }
}

if (!function_exists('report')) {
    function report(\Throwable $throwable): void
    {
        try {
            app(ExceptionReporter::class)->report($throwable);
        } catch (\Throwable $reporterFailure) {
            error_log(sprintf(
                '[simple-framework:report] reporter failure: %s; original: %s',
                $reporterFailure::class,
                $throwable::class,
            ));
        }
    }
}

if (!function_exists('rescue')) {
    /**
     * @template TValue
     * @template TFallback
     * @param callable(): TValue $callback
     * @param TFallback|callable(\Throwable): TFallback $fallback
     * @param bool|callable(\Throwable): bool $report
     * @return TValue|TFallback
     */
    function rescue(
        callable $callback,
        mixed $fallback = null,
        bool|callable $report = true,
    ): mixed {
        try {
            return $callback();
        } catch (\Throwable $throwable) {
            $shouldReport = is_callable($report) ? $report($throwable) : $report;
            if ($shouldReport) {
                report($throwable);
            }

            return is_callable($fallback) ? $fallback($throwable) : $fallback;
        }
    }
}
