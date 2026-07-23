<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Console\Output;
use HongXunPan\Framework\Module\Command\ModuleCommandRunner;
use HongXunPan\Framework\Module\Module;
use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\Framework\Module\ModuleInstaller;
use HongXunPan\Framework\Module\ModuleRegistry;
use RuntimeException;
use Throwable;

final class CommandBaseModule implements Module
{
    public static string $path = '';

    public function name(): string
    {
        return 'base';
    }

    public function basePath(): string
    {
        return self::$path;
    }

    public function requires(): array
    {
        return [];
    }

    public function installer(): ?string
    {
        return CommandModuleInstaller::class;
    }
}

final class CommandDependentModule implements Module
{
    public static string $path = '';

    public function name(): string
    {
        return 'dependent';
    }

    public function basePath(): string
    {
        return self::$path;
    }

    public function requires(): array
    {
        return [CommandBaseModule::class];
    }

    public function installer(): ?string
    {
        return null;
    }
}

final class CommandFailureModule implements Module
{
    public static string $path = '';

    public function name(): string
    {
        return 'failure';
    }

    public function basePath(): string
    {
        return self::$path;
    }

    public function requires(): array
    {
        return [];
    }

    public function installer(): ?string
    {
        return CommandFailureInstaller::class;
    }
}

final class CommandModuleInstaller implements ModuleInstaller
{
    /** @var list<string> */
    public static array $operations = [];

    public function install(string $projectPath, bool $dryRun = false): array
    {
        self::$operations[] = $dryRun ? 'install-preview' : 'install';
        return ['准备基础 Module 接入'];
    }

    public function refresh(string $projectPath, bool $dryRun = false): array
    {
        self::$operations[] = $dryRun ? 'refresh-preview' : 'refresh';
        return [];
    }

    public function upgrade(string $projectPath, string $fromVersion, bool $dryRun = false): array
    {
        self::$operations[] = $dryRun ? 'upgrade-preview' : 'upgrade';
        return [];
    }

    public function uninstall(string $projectPath, bool $dryRun = false): array
    {
        self::$operations[] = $dryRun ? 'uninstall-preview' : 'uninstall';
        return ['撤销基础 Module 接入'];
    }
}

final class CommandFailureInstaller implements ModuleInstaller
{
    public function install(string $projectPath, bool $dryRun = false): array
    {
        if (!$dryRun) {
            throw new RuntimeException('模拟 Installer 失败');
        }
        return ['模拟失败前预览'];
    }

    public function refresh(string $projectPath, bool $dryRun = false): array
    {
        return [];
    }

    public function upgrade(string $projectPath, string $fromVersion, bool $dryRun = false): array
    {
        return [];
    }

    public function uninstall(string $projectPath, bool $dryRun = false): array
    {
        return [];
    }
}

function moduleCommandTemporaryProject(): string
{
    $directory = sys_get_temp_dir() . '/simple-framework-module-command-' . bin2hex(random_bytes(8));
    mkdir($directory . '/config', 0755, true);
    mkdir($directory . '/bootstrap/cache', 0755, true);
    mkdir($directory . '/vendor/composer', 0755, true);
    file_put_contents($directory . '/config/module.php', "<?php return ['enable' => [], 'provider-override' => []];\n");

    $packages = [
        'integration/base-module' => [CommandBaseModule::class, '1.0.0'],
        'integration/dependent-module' => [CommandDependentModule::class, '1.1.0'],
        'integration/failure-module' => [CommandFailureModule::class, '1.0.0'],
    ];
    $versions = [];
    foreach ($packages as $packageName => [$moduleClass, $version]) {
        $packagePath = $directory . '/vendor/' . $packageName;
        mkdir($packagePath, 0755, true);
        file_put_contents(
            $packagePath . '/composer.json',
            json_encode([
                'name' => $packageName,
                'type' => 'simple-module',
                'autoload' => ['psr-4' => []],
                'extra' => ['simple' => ['module' => $moduleClass]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $versions[$packageName] = [
            'pretty_version' => $version,
            'version' => $version . '.0',
            'type' => 'simple-module',
            'install_path' => $packagePath,
        ];

        match ($moduleClass) {
            CommandBaseModule::class => CommandBaseModule::$path = $packagePath,
            CommandDependentModule::class => CommandDependentModule::$path = $packagePath,
            CommandFailureModule::class => CommandFailureModule::$path = $packagePath,
        };
    }
    file_put_contents(
        $directory . '/vendor/composer/installed.php',
        '<?php return ' . var_export(['versions' => $versions], true) . ";\n",
    );

    return $directory;
}

function removeModuleCommandDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

/**
 * @return array{ModuleCommandRunner, resource, resource}
 */
function moduleCommandRunner(string $projectPath): array
{
    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');
    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('无法创建命令输出内存流');
    }
    return [new ModuleCommandRunner($projectPath, new Output($stdout, $stderr)), $stdout, $stderr];
}

function moduleCommandStream(mixed $stream): string
{
    rewind($stream);
    return (string) stream_get_contents($stream);
}

$moduleCommandFailures = [];

$moduleCommandAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$moduleCommandAssertTrue = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};

$runModuleCommand = static function (string $name, callable $test) use (&$moduleCommandFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $moduleCommandFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runModuleCommand('ModuleRegistry 只发现声明 simple-module 元数据的 Composer 包', static function () use (
    $moduleCommandAssertSame,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        $registry = new ModuleRegistry($directory);
        $moduleCommandAssertSame(
            ['base', 'dependent', 'failure'],
            array_map(static fn ($package): string => $package->module->name(), $registry->all()),
            'Module 发现结果错误',
        );
        $moduleCommandAssertSame(CommandBaseModule::class, $registry->find('base')->module::class, '名称查找失败');
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModuleCommand('enable 支持预览、幂等启用、刷新、状态和禁用', static function () use (
    $moduleCommandAssertSame,
    $moduleCommandAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        CommandModuleInstaller::$operations = [];
        [$console, $stdout] = moduleCommandRunner($directory);
        $config = new ModuleConfig($directory);

        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base', '--dry-run']), '启用预览失败');
        $moduleCommandAssertSame([], $config->enabled(), '预览模式写入了启用状态');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base']), '首次启用失败');
        $moduleCommandAssertSame([CommandBaseModule::class], $config->enabled(), '启用状态未写入');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base']), '重复启用失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:refresh']), '刷新失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:status']), '状态查询失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:disable', 'base']), '禁用失败');
        $moduleCommandAssertSame([], $config->enabled(), '禁用状态未移除');
        $moduleCommandAssertTrue(
            str_contains(moduleCommandStream($stdout), '已启用 Module：base'),
            '命令输出缺少启用结果',
        );
        $moduleCommandAssertSame(
            [
                'install-preview',
                'install-preview',
                'install',
                'refresh-preview',
                'refresh',
                'refresh-preview',
                'uninstall-preview',
                'uninstall',
            ],
            CommandModuleInstaller::$operations,
            'Installer 执行顺序或幂等行为错误',
        );
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModuleCommand('依赖未启用时阻止 enable 且被依赖 Module 不能 disable', static function () use (
    $moduleCommandAssertSame,
    $moduleCommandAssertTrue,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        [$console, , $stderr] = moduleCommandRunner($directory);
        $moduleCommandAssertSame(1, $console->run(['bin/simple', 'module:enable', 'dependent']), '缺少依赖时未失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'base']), '基础 Module 启用失败');
        $moduleCommandAssertSame(0, $console->run(['bin/simple', 'module:enable', 'dependent']), '依赖 Module 启用失败');
        $moduleCommandAssertSame(1, $console->run(['bin/simple', 'module:disable', 'base']), '被依赖 Module 可以直接禁用');
        $moduleCommandAssertTrue(
            str_contains(moduleCommandStream($stderr), '正被 dependent 依赖'),
            '依赖阻断信息不明确',
        );
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

$runModuleCommand('Installer 失败时不写入 Module 启用状态', static function () use (
    $moduleCommandAssertSame,
): void {
    $directory = moduleCommandTemporaryProject();
    try {
        [$console] = moduleCommandRunner($directory);
        $moduleCommandAssertSame(1, $console->run(['bin/simple', 'module:enable', 'failure']), 'Installer 失败未返回错误');
        $moduleCommandAssertSame([], (new ModuleConfig($directory))->enabled(), 'Installer 失败污染了启用状态');
    } finally {
        removeModuleCommandDirectory($directory);
    }
});

if ($moduleCommandFailures !== []) {
    foreach ($moduleCommandFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Module 发现与命令测试通过。' . PHP_EOL;
