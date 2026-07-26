<?php

namespace HongXunPan\Framework\Core;

use Closure;
use HongXunPan\Framework\Config\Env;
use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Exceptions\ErrorLogExceptionReporter;
use HongXunPan\Framework\Exceptions\ErrorHandler;
use HongXunPan\Framework\Exceptions\ExceptionRenderer;
use HongXunPan\Framework\Exceptions\ExceptionReporter;
use HongXunPan\Framework\Exceptions\SafeExceptionRenderer;
use HongXunPan\Framework\Lifecycle\ApplicationLifecycle;
use HongXunPan\Framework\Lifecycle\ExceptionOccurredSnapshot;
use HongXunPan\Framework\Lifecycle\NullApplicationLifecycle;
use HongXunPan\Framework\Lifecycle\RequestHandledSnapshot;
use HongXunPan\Framework\Module\HelperLoader;
use HongXunPan\Framework\Module\ModuleLoader;
use HongXunPan\Framework\Response\Response;
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
            $this->notifyLifecycle(
                static fn (ApplicationLifecycle $lifecycle) =>
                    $lifecycle->requestHandled(new RequestHandledSnapshot()),
            );
        } catch (Throwable $throwable) {
            $this->notifyLifecycle(
                static fn (ApplicationLifecycle $lifecycle) =>
                    $lifecycle->exceptionOccurred(new ExceptionOccurredSnapshot($throwable)),
            );
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
        $this->bindDefaults();
        if (!$this->bound(Env::class)) {
            $this->instance(Env::class, new Env($this->getPath('base') . '.env'));
        }
        $env = $this->make(Env::class);
        $this->environment = (string) $env->get('APP_ENV', 'production');
        $this->isDebug = (bool) $env->get('APP_DEBUG', false);
        $this->loadConfig($this, !$this->isDebug);
        $this->environment = (string) config('app.env', $this->environment);
        $this->isDebug = (bool) config('app.debug', $this->isDebug);
        ini_set(
            'display_errors',
            $this->environment === 'local' && $this->isDebug ? 'On' : 'Off',
        );
        $this->bootProviders();
        $this->initialized = true;
        return self::setInstance($this);
    }

    private function bindDefaults(): void
    {
        $this->singletonIf(ResponseContract::class, Response::class);
        $this->singletonIf(ExceptionReporter::class, ErrorLogExceptionReporter::class);
        $this->singletonIf(ExceptionRenderer::class, SafeExceptionRenderer::class);
        $this->singletonIf(ApplicationLifecycle::class, NullApplicationLifecycle::class);
        $this->singletonIf(Request::class);
    }

    private function notifyLifecycle(Closure $notification): void
    {
        if (!$this->bound(ApplicationLifecycle::class)) {
            return;
        }

        try {
            $notification($this->make(ApplicationLifecycle::class));
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function bootProviders(): void
    {
        $this->singletonIf(HelperLoader::class);
        $this->singletonIf(
            ModuleLoader::class,
            static fn (Application $app): ModuleLoader =>
                new ModuleLoader(
                    $app,
                    $app->make(Config::class),
                    $app->make(HelperLoader::class),
                ),
        );

        /** @var ModuleLoader $loader */
        $loader = $this->make(ModuleLoader::class);
        $loader->registerModules();

        $projectProviders = config('module.provider-override', []);
        if (!is_array($projectProviders) || !array_is_list($projectProviders)) {
            throw new \RuntimeException("config('module.provider-override') 必须是 Provider 类名列表");
        }
        $loader->registerProjectProviders($projectProviders);
        $loader->boot();
    }

    public function loadRoute(): void
    {
        $cacheFile = $this->getPath('base', 'bootstrap/cache') . 'routes.php';
        if (!$this->isDebug && is_file($cacheFile)) {
            Route::loadCache($cacheFile);
            return;
        }

        $this->loadRouteFiles();
    }

    public function cacheRoutes(): void
    {
        $this->loadRouteFiles();
        Route::cache($this->getPath('base', 'bootstrap/cache'), 'routes.php');
    }

    private function loadRouteFiles(): void
    {
        Route::clear();
        Route::loadAllRouteByFile($this->getPath('base', 'routes'));
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
