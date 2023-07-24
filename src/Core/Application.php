<?php

namespace HongXunPan\Framework\Core;

use Closure;
use HongXunPan\Framework\Response\ErrorHandler;
use HongXunPan\Framework\Route\Route;
use Illuminate\Container\Container;
use Throwable;

class Application extends Container
{
    use PathTrait;

    private bool $isDebug;
    private bool $initialized = false;

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
        $this->initialized = true;
        return self::setInstance($this);
    }

    public function run(Closure $closure): void
    {
        try {
            $closure($this);
        } catch (Throwable $throwable) {
            ErrorHandler::handle($throwable);
        }
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
}
