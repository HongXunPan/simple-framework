<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Module\Exception\ModuleException;
use HongXunPan\Framework\Provider\ServiceProvider;

final class ModuleLoader
{
    /** @var list<ServiceProvider> */
    private array $providers = [];

    /** @var array<class-string<ServiceProvider>, true> */
    private array $providerClasses = [];

    private bool $modulesRegistered = false;
    private bool $projectProvidersRegistered = false;
    private bool $booted = false;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly HelperLoader $helpers,
    ) {
    }

    public function registerModules(): void
    {
        if ($this->modulesRegistered) {
            return;
        }

        $modules = [];
        foreach ($this->enabledClasses() as $moduleClass) {
            if (!class_exists($moduleClass)) {
                throw new ModuleException("已启用 Module 不存在，请先恢复 Composer 依赖：{$moduleClass}");
            }
            $module = $this->app->make($moduleClass);
            if (!$module instanceof Module) {
                throw new ModuleException("已启用类未实现 Module 契约：{$moduleClass}");
            }
            if (!is_dir($module->basePath())) {
                throw new ModuleException("Module 包目录不存在：{$module->basePath()}");
            }
            $modules[$moduleClass] = $module;
        }

        $this->validateDependencies($modules);
        $this->helpers->load(array_values($modules));
        foreach ($modules as $module) {
            foreach ($this->providerClasses($module) as $providerClass) {
                $this->registerProvider($providerClass, "Module {$module->name()}");
            }
        }

        $this->modulesRegistered = true;
    }

    /**
     * @return list<class-string<Module>>
     */
    private function enabledClasses(): array
    {
        $classes = $this->config->get('module.enable', []);
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new ModuleException("config('module.enable') 必须是 Module 类名列表");
        }

        $enabled = [];
        foreach ($classes as $class) {
            if (!is_string($class) || $class === '') {
                throw new ModuleException("config('module.enable') 只能包含 Module 类名");
            }
            $class = ltrim($class, '\\');
            if (isset($enabled[$class])) {
                throw new ModuleException("config('module.enable') 存在重复类名：{$class}");
            }
            $enabled[$class] = true;
        }

        return array_keys($enabled);
    }

    /**
     * @param list<class-string<ServiceProvider>> $providerClasses
     */
    public function registerProjectProviders(array $providerClasses): void
    {
        if ($this->projectProvidersRegistered) {
            return;
        }
        if (!$this->modulesRegistered) {
            throw new ModuleException('必须先注册 Module Provider，再注册项目 Provider');
        }

        foreach ($providerClasses as $providerClass) {
            if (!is_string($providerClass)) {
                throw new ModuleException("config('module.provider-override') 只能包含 Provider 类名");
            }
            $this->registerProvider($providerClass, '项目');
        }
        $this->projectProvidersRegistered = true;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        if (!$this->projectProvidersRegistered) {
            throw new ModuleException('Provider 注册尚未完成，不能执行 boot');
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this->app);
        }
        $this->booted = true;
    }

    /**
     * @param array<class-string<Module>, Module> $modules
     */
    private function validateDependencies(array $modules): void
    {
        foreach ($modules as $module) {
            foreach ($module->requires() as $requiredClass) {
                if (!is_string($requiredClass) || $requiredClass === '') {
                    throw new ModuleException("Module {$module->name()} 声明了非法依赖类名");
                }
                $requiredClass = ltrim($requiredClass, '\\');
                if (!isset($modules[$requiredClass])) {
                    throw new ModuleException(
                        "Module {$module->name()} 缺少已启用依赖：{$requiredClass}",
                    );
                }
            }
        }
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    private function providerClasses(Module $module): array
    {
        $configFile = rtrim($module->basePath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'config/providers.php';
        if (!is_file($configFile)) {
            return [];
        }

        $providers = require $configFile;
        if (!is_array($providers) || !array_is_list($providers)) {
            throw new ModuleException("Module {$module->name()} 的 config/providers.php 必须返回 Provider 列表");
        }

        foreach ($providers as $providerClass) {
            if (!is_string($providerClass) || $providerClass === '') {
                throw new ModuleException("Module {$module->name()} 声明了非法 Provider 类名");
            }
        }

        return $providers;
    }

    /**
     * @param class-string<ServiceProvider> $providerClass
     */
    private function registerProvider(string $providerClass, string $owner): void
    {
        if (!class_exists($providerClass) || !is_subclass_of($providerClass, ServiceProvider::class)) {
            throw new ModuleException("{$owner} Provider 未继承 ServiceProvider：{$providerClass}");
        }
        if (isset($this->providerClasses[$providerClass])) {
            throw new ModuleException("Provider 被重复登记：{$providerClass}");
        }

        $provider = $this->app->make($providerClass);
        if (!$provider instanceof ServiceProvider) {
            throw new ModuleException("Provider 无法实例化：{$providerClass}");
        }
        $provider->register($this->app);
        $this->providers[] = $provider;
        $this->providerClasses[$providerClass] = true;
    }
}
