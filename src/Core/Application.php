<?php

namespace HongXunPan\Framework\Core;

use Closure;
use HongXunPan\Framework\Response\ErrorHandler;
use HongXunPan\Framework\Response\Response;
use HongXunPan\Framework\Response\ResponseContract;
use HongXunPan\Framework\Route\Route;
use HongXunPan\Tools\Config\Config;
use Illuminate\Container\Container;
use Throwable;

class Application extends Container
{
    use PathTrait;

    private bool $isDebug;
    private bool $initialized = false;
    /**
     * @var ResponseContract
     */
    private mixed $response;

    public function run(Closure $closure): void
    {
        try {
            $closure($this);
        } catch (Throwable $throwable) {
            ErrorHandler::handle($throwable);
        }
    }

    public function init($basePath = ''): Application|\Illuminate\Contracts\Container\Container|null
    {
        if ($this->initialized) {
            return $this;
        }
        if (!$basePath) {
            $basePath = dirname(__DIR__, 5);
        }
        $this->setPath('base', $basePath);
        $this->isDebug = (bool)env('debug', false);
        $this->setConfig();
        $this->initialized = true;
        return self::setInstance($this);
    }

    private function setConfig()
    {
        Config::getInstance()->setConfigPath($this->getPath('base', 'config'), $this->getPath('base', 'boostrap/cache'), !$this->isDebug);
//        app()->singleton(ResponseContract::class, config('app.response_class', Response::class));
        $res=app()->singleton(ResponseContract::class, config('app.response_class', Response::class));
//        dd(1, $this->bindings, $res);
//        app()->bind(ResponseContract::class, Response::class);
    }

    public function loadRoute(): void
    {
        $cachePath = $this->getPath('base', 'bootstrap/cache');
        $cacheFile = 'routes.php';
        if (file_exists($cacheFile . $cacheFile)) {
            if (!$this->isDebug) {
                Route::loadCache($cachePath . $cacheFile);
                return;
            }
        }

        $routeFiles = glob($this->getPath('base', 'routes') . '*.php');
        foreach ($routeFiles as $file) {
            require_once $file;
            if (!app()->isDebug) {
                Route::cache($cachePath, 'routes.php');
            }
        }
    }

    public function setResponse($content): static
    {
        if (!$content instanceof ResponseContract) {
            $content = app(ResponseContract::class, [$content]);
        }
        $this->response = $content;
        return $this;
    }

    public function send()
    {
        return $this->response->send();
    }
}
