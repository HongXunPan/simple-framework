<?php

namespace HongXunPan\Framework\Core;

use Closure;
use HongXunPan\Framework\Exceptions\ErrorHandler;
use HongXunPan\Framework\Response\ResponseContract;
use HongXunPan\Framework\Route\Route;
use Illuminate\Container\Container;
use Throwable;

class Application extends Container
{
    use PathTrait, ConfigTrait;

    public bool $isDebug;
    public bool $isCli;
    private bool $initialized = false;
    /** @var ResponseContract $response*/
    private mixed $response;

    public function run(Closure $closure, string $errHandlerClass = ''): void
    {
        try {
            $closure($this);
        } catch (Throwable $throwable) {
            if ($errHandlerClass && class_exists($errHandlerClass)) {
                call_user_func([$errHandlerClass, 'handle'], $throwable);
                return;
            }
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
        $this->isCli = str_contains(php_sapi_name(), 'cli');
        if ($this->isDebug) {
            ini_set('display_errors', 'On');
            error_reporting(E_ALL);
        } else {
            error_reporting(E_ERROR);
            ini_set('display_errors', 'Off');
        }
        $this->loadConfig($this);
        $this->initialized = true;
        return self::setInstance($this);
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
            $content = app(ResponseContract::class, compact('content'));
        }
        $this->response = $content;
        return $this;
    }

    public function send()
    {
        return $this->response->send();
    }
}
