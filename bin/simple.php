<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use HongXunPan\Framework\Module\Command\ModuleCommandRunner;

$projectPath = isset($projectPath) && is_string($projectPath)
    ? rtrim($projectPath, DIRECTORY_SEPARATOR)
    : getcwd();
if (!is_string($projectPath) || $projectPath === '') {
    fwrite(STDERR, '[错误] 无法确定项目目录' . PHP_EOL);
    return 1;
}
if (PHP_VERSION_ID < 80500) {
    fwrite(STDERR, '[错误] Simple Module 命令要求 PHP 8.5 或更高版本' . PHP_EOL);
    return 1;
}

$composerPath = $projectPath . '/vendor/composer';
$classLoaderFile = $composerPath . '/ClassLoader.php';
if (!is_file($classLoaderFile)) {
    fwrite(STDERR, '[错误] 缺少 Composer 自动加载文件，请先执行 composer install' . PHP_EOL);
    return 1;
}

require_once $classLoaderFile;
$loader = new ClassLoader($projectPath . '/vendor');
foreach (require $composerPath . '/autoload_psr4.php' as $prefix => $paths) {
    $loader->setPsr4($prefix, $paths);
}
foreach (require $composerPath . '/autoload_namespaces.php' as $prefix => $paths) {
    $loader->set($prefix, $paths);
}
$loader->addClassMap(require $composerPath . '/autoload_classmap.php');
$loader->register(true);

// Module 命令故意不加载 autoload_files.php，给 Installer 保留自动加载前处理窗口。
return (new ModuleCommandRunner($projectPath))->run($argv ?? []);
