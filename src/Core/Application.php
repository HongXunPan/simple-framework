<?php

namespace HongXunPan\Framework\Core;

use Closure;
use HongXunPan\Framework\Config\Env;
use HongXunPan\Framework\Exceptions\ErrorLogExceptionReporter;
use HongXunPan\Framework\Exceptions\ErrorHandler;
use HongXunPan\Framework\Exceptions\ExceptionRenderer;
use HongXunPan\Framework\Exceptions\ExceptionReporter;
use HongXunPan\Framework\Exceptions\SafeExceptionRenderer;
use HongXunPan\Framework\Module\HelperLoader;
use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\Framework\Module\ModuleLoader;
use HongXunPan\Framework\Response\ResponseContract;
use HongXunPan\Framework\Route\Route;
use Illuminate\Container\Container;
use Throwable;

class Application extends Container
{
    use PathTrait, ConfigTrait;

    public bool $isDebug;
    public bool $isCli;
    public string $environment;
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
        self::setInstance($this);
        $this->setPath('base', $basePath);
        $this->isCli = in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
        error_reporting(E_ALL);
        ini_set('display_errors', 'Off');
        $this->bindExceptionHandling();
        if (!$this->bound(Env::class)) {
            $this->instance(Env::class, new Env($this->getPath('base') . '.env'));
        }
        $env = $this->make(Env::class);
        $this->environment = (string) $env->get('APP_ENV', $env->get('ENV_NAME', 'production'));
        $this->isDebug = (bool) $env->get('APP_DEBUG', $env->get('DEBUG', false));
        $this->loadConfig($this, !$this->isDebug);
        $this->environment = (string) config('app.env', $this->environment);
        $this->isDebug = (bool) config('app.debug', config('app.is_debug', $this->isDebug));
        ini_set(
            'display_errors',
            $this->environment === 'local' && $this->isDebug ? 'On' : 'Off',
        );
        $this->bootProviders();
        $this->initialized = true;
        return self::setInstance($this);
    }

    private function bindExceptionHandling(): void
    {
        if (!$this->bound(ExceptionReporter::class)) {
            $this->singleton(ExceptionReporter::class, ErrorLogExceptionReporter::class);
        }
        if (!$this->bound(ExceptionRenderer::class)) {
            $this->singleton(ExceptionRenderer::class, SafeExceptionRenderer::class);
        }
    }

    private function bootProviders(): void
    {
        if (!$this->bound(ModuleConfig::class)) {
            $this->instance(ModuleConfig::class, new ModuleConfig($this->getPath('base')));
        }
        if (!$this->bound(HelperLoader::class)) {
            $this->instance(HelperLoader::class, new HelperLoader());
        }
        if (!$this->bound(ModuleLoader::class)) {
            $this->instance(ModuleLoader::class, new ModuleLoader(
                $this,
                $this->make(ModuleConfig::class),
                $this->make(HelperLoader::class),
            ));
        }

        /** @var ModuleLoader $loader */
        $loader = $this->make(ModuleLoader::class);
        $loader->registerModules();
        $this->loadSingleton();

        $projectProviders = config('module.provider-override', []);
        if (!is_array($projectProviders) || !array_is_list($projectProviders)) {
            throw new \RuntimeException("config('module.provider-override') 必须是 Provider 类名列表");
        }
        $loader->registerProjectProviders($projectProviders);
        $loader->boot();
        $this->loadBoot();
    }

    public function loadRoute(): void
    {
        $cachePath = $this->getPath('base', 'bootstrap/cache');
        $cacheFile = 'routes.php';
        if (file_exists($cachePath . $cacheFile)) {
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
