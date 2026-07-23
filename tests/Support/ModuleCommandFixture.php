<?php

declare(strict_types=1);

use HongXunPan\Framework\Console\Output;
use HongXunPan\Framework\Module\Command\ModuleCommandRunner;
use HongXunPan\Framework\Module\Module;
use HongXunPan\Framework\Module\ModuleInstaller;
use RuntimeException;

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

function moduleCommandResourceContent(): string
{
    return "<?php\n\nreturn ['published' => true];\n";
}

function moduleCommandTemporaryProject(): string
{
    $directory = sys_get_temp_dir() . '/simple-framework-module-command-' . bin2hex(random_bytes(8));
    mkdir($directory . '/config', 0755, true);
    mkdir($directory . '/bootstrap/cache', 0755, true);
    mkdir($directory . '/vendor/composer', 0755, true);
    file_put_contents($directory . '/config/module.php', "<?php return ['enable' => [], 'provider-override' => []];\n");
    file_put_contents($directory . '/outside-resource.php', moduleCommandResourceContent());

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

        if ($moduleClass === CommandBaseModule::class) {
            mkdir($packagePath . '/config', 0755, true);
            mkdir($packagePath . '/resources', 0755, true);
            $source = $packagePath . '/resources/module.php';
            file_put_contents($source, moduleCommandResourceContent());
            file_put_contents(
                $packagePath . '/config/resources.php',
                '<?php return ' . var_export([
                    'config' => [
                        'source' => $source,
                        'target' => 'app/Generated/module.php',
                    ],
                    'escape-target' => [
                        'source' => $source,
                        'target' => '../escape.php',
                    ],
                    'outside-source' => [
                        'source' => $directory . '/outside-resource.php',
                        'target' => 'app/Generated/outside.php',
                    ],
                ], true) . ";\n",
            );
        }
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
