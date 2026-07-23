<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module;

use HongXunPan\Framework\Module\Exception\HelperConflictException;
use HongXunPan\Framework\Module\Exception\HelperLoadException;

final class HelperLoader
{
    /**
     * P1 兼容期仍由 framework 自动加载的全局函数。
     *
     * @var list<string>
     */
    private const array CORE_FUNCTIONS = [
        'app',
        'config',
        'env',
        'event',
        'report',
        'rescue',
    ];

    /**
     * @param list<Module> $modules
     */
    public function validate(array $modules): void
    {
        $this->validateDeclarations($this->declarations($modules));
    }

    /**
     * @param list<Module> $modules
     */
    public function load(array $modules): void
    {
        $declarations = $this->declarations($modules);
        $this->validateDeclarations($declarations);
        $files = [];
        foreach ($declarations as $declaration) {
            $files[$declaration['file']] = true;
        }
        foreach (array_keys($files) as $file) {
            require_once $file;
        }
        foreach ($declarations as $declaration) {
            $function = $declaration['function'];
            if (!function_exists($function)) {
                throw new HelperLoadException(
                    "Module {$declaration['module']->name()} 的帮助函数未定义：{$function}",
                );
            }
        }
    }

    /**
     * @param list<array{function: string, file: string, module: Module}> $declarations
     */
    private function validateDeclarations(array $declarations): void
    {
        $owners = array_fill_keys(self::CORE_FUNCTIONS, 'simple-framework core');
        foreach ($declarations as $declaration) {
            $function = $declaration['function'];
            if (isset($owners[$function])) {
                throw new HelperConflictException($function, $owners[$function]);
            }
            if (function_exists($function)) {
                throw new HelperConflictException($function, '当前全局作用域');
            }
            $owners[$function] = $declaration['module']->name();
        }
    }

    /**
     * @param list<Module> $modules
     * @return list<array{function: string, file: string, module: Module}>
     */
    private function declarations(array $modules): array
    {
        $declarations = [];
        foreach ($modules as $module) {
            $configFile = rtrim($module->basePath(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'config/helpers.php';
            if (!is_file($configFile)) {
                continue;
            }

            $helpers = require $configFile;
            if (!is_array($helpers)) {
                throw new HelperLoadException("Module {$module->name()} 的 config/helpers.php 必须返回数组");
            }
            foreach ($helpers as $function => $file) {
                if (!is_string($function)
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $function) !== 1) {
                    throw new HelperLoadException("Module {$module->name()} 声明了非法全局函数名");
                }
                if (!is_string($file) || !is_file($file)) {
                    throw new HelperLoadException(
                        "Module {$module->name()} 的帮助函数文件不存在：" . (string) $file,
                    );
                }
                $declarations[] = [
                    'function' => $function,
                    'file' => $file,
                    'module' => $module,
                ];
            }
        }

        return $declarations;
    }
}
