<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Module\Exception\HelperConflictException;
use HongXunPan\Framework\Module\Exception\ModuleException;
use HongXunPan\Framework\Module\Module;
use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\Framework\Provider\ServiceProvider;
use RuntimeException;
use Throwable;

final class ModuleRuntimeState
{
    /** @var list<string> */
    public static array $steps = [];

    public static function reset(): void
    {
        self::$steps = [];
    }

    public static function legacyBoot(): void
    {
        self::$steps[] = 'legacy-boot';
    }
}

final class RuntimeModule implements Module
{
    public static string $path = '';

    public function name(): string
    {
        return 'runtime';
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
        return null;
    }
}

final class DependentRuntimeModule implements Module
{
    public static string $path = '';

    public function name(): string
    {
        return 'dependent-runtime';
    }

    public function basePath(): string
    {
        return self::$path;
    }

    public function requires(): array
    {
        return [RuntimeModule::class];
    }

    public function installer(): ?string
    {
        return null;
    }
}

final class ConflictRuntimeModule implements Module
{
    public static string $path = '';

    public function name(): string
    {
        return 'conflict-runtime';
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
        return null;
    }
}

final class RuntimeModuleServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        ModuleRuntimeState::$steps[] = 'module-register';
        $app->instance('module.runtime.value', 'module');
    }

    public function boot(Application $app): void
    {
        ModuleRuntimeState::$steps[] = 'module-boot';
    }
}

final class RuntimeProjectServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        ModuleRuntimeState::$steps[] = 'project-register';
        $app->instance('module.runtime.value', 'project');
    }

    public function boot(Application $app): void
    {
        ModuleRuntimeState::$steps[] = 'project-boot';
    }
}

function moduleRuntimeTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir() . '/simple-framework-module-runtime-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Module 测试临时目录创建失败');
    }

    return $directory;
}

function removeModuleRuntimeDirectory(string $directory): void
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

function writeModuleRuntimeProject(string $projectPath, array $providers = []): void
{
    mkdir($projectPath . '/config', 0755, true);
    mkdir($projectPath . '/bootstrap/cache', 0755, true);
    file_put_contents(
        $projectPath . '/config/app.php',
        "<?php return ['env' => 'production', 'debug' => false, 'timezone' => 'Asia/Shanghai'];\n",
    );
    file_put_contents($projectPath . '/config/singleton.php', "<?php return [];\n");
    file_put_contents(
        $projectPath . '/config/boot.php',
        "<?php return [[ModuleRuntimeState::class, 'legacyBoot']];\n",
    );
    file_put_contents(
        $projectPath . '/config/module.php',
        "<?php return ['enable' => [], 'provider-override' => "
        . var_export($providers, true) . "];\n",
    );
}

function writeRuntimeModule(
    string $modulePath,
    array $providers = [],
    array $helpers = [],
): void {
    mkdir($modulePath . '/config', 0755, true);
    file_put_contents(
        $modulePath . '/config/providers.php',
        '<?php return ' . var_export($providers, true) . ";\n",
    );
    file_put_contents(
        $modulePath . '/config/helpers.php',
        '<?php return ' . var_export($helpers, true) . ";\n",
    );
}

$moduleRuntimeFailures = [];

$moduleRuntimeAssertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '；期望：' . var_export($expected, true) . '；实际：' . var_export($actual, true),
        );
    }
};

$moduleRuntimeAssertTrue = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};

$runModuleRuntime = static function (string $name, callable $test) use (&$moduleRuntimeFailures): void {
    try {
        $test();
        echo '[通过] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        $moduleRuntimeFailures[] = $name . '：' . $throwable::class . '：' . $throwable->getMessage();
        echo '[失败] ' . $name . PHP_EOL;
    }
};

$runModuleRuntime('ModuleConfig 原子维护启用状态、保留项目覆盖并清理配置缓存', static function () use (
    $moduleRuntimeAssertSame,
    $moduleRuntimeAssertTrue,
): void {
    $directory = moduleRuntimeTemporaryDirectory();
    try {
        writeModuleRuntimeProject($directory, [RuntimeProjectServiceProvider::class]);
        file_put_contents($directory . '/bootstrap/cache/config.php', '<?php return [];');
        $config = new ModuleConfig($directory);

        $moduleRuntimeAssertTrue($config->add(RuntimeModule::class), '首次启用没有写入配置');
        $moduleRuntimeAssertSame([RuntimeModule::class], $config->enabled(), 'Module 启用状态错误');
        $moduleConfiguration = require $directory . '/config/module.php';
        $moduleRuntimeAssertSame([RuntimeProjectServiceProvider::class], $moduleConfiguration['provider-override'], '更新启用状态时丢失了项目 Provider 覆盖配置');
        $moduleRuntimeAssertTrue(!is_file($directory . '/bootstrap/cache/config.php'), '配置缓存未清理');
        $moduleRuntimeAssertSame(false, $config->add(RuntimeModule::class), '重复启用不幂等');
        $moduleRuntimeAssertTrue($config->remove(RuntimeModule::class), 'Module 禁用没有更新配置');
        $moduleRuntimeAssertSame([], $config->enabled(), 'Module 禁用状态错误');
        $moduleRuntimeAssertSame(false, $config->remove(RuntimeModule::class), '重复禁用不幂等');
    } finally {
        removeModuleRuntimeDirectory($directory);
    }
});

$runModuleRuntime('Module Provider 先于项目 Provider 且项目保留覆盖权', static function () use (
    $moduleRuntimeAssertSame,
): void {
    $directory = moduleRuntimeTemporaryDirectory();
    try {
        ModuleRuntimeState::reset();
        writeModuleRuntimeProject($directory, [RuntimeProjectServiceProvider::class]);
        $modulePath = $directory . '/modules/runtime';
        $helperFile = $modulePath . '/helpers.php';
        writeRuntimeModule(
            $modulePath,
            [RuntimeModuleServiceProvider::class],
            ['p1_runtime_module_value' => $helperFile],
        );
        file_put_contents(
            $helperFile,
            "<?php function p1_runtime_module_value(): string { return 'helper'; }\n",
        );
        RuntimeModule::$path = $modulePath;
        (new ModuleConfig($directory))->add(RuntimeModule::class);

        $application = new Application();
        $application->init($directory);

        $moduleRuntimeAssertSame('project', $application->make('module.runtime.value'), '项目 Provider 未覆盖 Module');
        $moduleRuntimeAssertSame('helper', p1_runtime_module_value(), 'Module Helper 未加载');
        $moduleRuntimeAssertSame(
            ['module-register', 'project-register', 'module-boot', 'project-boot', 'legacy-boot'],
            ModuleRuntimeState::$steps,
            'Provider 与旧 boot 执行顺序错误',
        );
    } finally {
        removeModuleRuntimeDirectory($directory);
    }
});

$runModuleRuntime('运行时缺少已启用依赖时立即失败', static function () use ($moduleRuntimeAssertTrue): void {
    $directory = moduleRuntimeTemporaryDirectory();
    try {
        writeModuleRuntimeProject($directory);
        $modulePath = $directory . '/modules/dependent';
        writeRuntimeModule($modulePath);
        DependentRuntimeModule::$path = $modulePath;
        (new ModuleConfig($directory))->add(DependentRuntimeModule::class);

        try {
            (new Application())->init($directory);
        } catch (ModuleException $exception) {
            $moduleRuntimeAssertTrue(
                str_contains($exception->getMessage(), RuntimeModule::class),
                '依赖失败信息没有包含缺失 Module',
            );
            return;
        }
        throw new RuntimeException('缺少依赖时 Application 仍然启动成功');
    } finally {
        removeModuleRuntimeDirectory($directory);
    }
});

$runModuleRuntime('两个已启用 Module 声明同名 Helper 时启动失败', static function () use (
    $moduleRuntimeAssertTrue,
): void {
    $directory = moduleRuntimeTemporaryDirectory();
    try {
        writeModuleRuntimeProject($directory);
        $firstPath = $directory . '/modules/first';
        $secondPath = $directory . '/modules/second';
        $firstHelper = $firstPath . '/helpers.php';
        $secondHelper = $secondPath . '/helpers.php';
        writeRuntimeModule($firstPath, [], ['p1_duplicate_module_helper' => $firstHelper]);
        writeRuntimeModule($secondPath, [], ['p1_duplicate_module_helper' => $secondHelper]);
        file_put_contents($firstHelper, "<?php function p1_duplicate_module_helper(): string { return 'first'; }\n");
        file_put_contents($secondHelper, "<?php function p1_duplicate_module_helper(): string { return 'second'; }\n");
        RuntimeModule::$path = $firstPath;
        ConflictRuntimeModule::$path = $secondPath;
        $config = new ModuleConfig($directory);
        $config->add(RuntimeModule::class);
        $config->add(ConflictRuntimeModule::class);

        try {
            (new Application())->init($directory);
        } catch (HelperConflictException $exception) {
            $moduleRuntimeAssertTrue(
                str_contains($exception->getMessage(), 'p1_duplicate_module_helper'),
                'Helper 冲突信息缺少函数名',
            );
            return;
        }
        throw new RuntimeException('Helper 同名时 Application 仍然启动成功');
    } finally {
        removeModuleRuntimeDirectory($directory);
    }
});

if ($moduleRuntimeFailures !== []) {
    foreach ($moduleRuntimeFailures as $failure) {
        fwrite(STDERR, $failure . PHP_EOL);
    }
    exit(1);
}

echo 'Module 配置、Provider、Helper 与兼容启动链测试通过。' . PHP_EOL;
