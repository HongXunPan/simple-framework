# simple-framework

`hongxunpan/simple-framework` 是 simple-php 的轻量内核。当前 `0.3.x` 开发线要求
PHP `^8.5`。

框架只承载所有项目都需要的稳定能力：

- 应用容器与启动流程；
- Config / Env；
- 路由、响应与异常处理；
- 应用生命周期契约；
- Module 发现、启用、禁用、刷新与资源发布；
- 原子文件操作；
- 核心全局函数 `app()`、`config()`、`env()`、`report()`、`rescue()`。
- Request 核心单例。

数据库、Redis、Eloquent、Event 等可选基础设施不属于 framework core。

## 安装

```bash
composer require hongxunpan/simple-framework
```

## Config 与 Env

框架提供容器单例：

- `HongXunPan\Framework\Config\Config`
- `HongXunPan\Framework\Config\Env`

以及全局函数：

```php
$environment = env('APP_ENV', 'production');
$timezone = config('app.timezone', 'UTC');
```

行为约定：

- 进程环境变量优先于项目 `.env`；
- `.env` 不存在时使用调用方默认值；
- 配置支持点号路径；
- 非调试环境可以生成原子配置缓存；
- 运行环境只读取 `APP_ENV`、`APP_DEBUG`；
- 旧键 `ENV_NAME`、`DEBUG` 与旧配置键 `app.is_debug` 不再支持；
- 不再回退到 `hongxunpan/php-tools` 的旧 Config / Env。

`config()` 与 `env()` 依赖当前 `Application` 中的核心绑定。脱离应用启动流程的脚本应显式创建
并绑定 `Config`、`Env`，不能依赖隐藏的全局配置状态。

## 异常处理

默认 `ErrorHandler` 先调用 `ExceptionReporter`，再调用 `ExceptionRenderer`。
完整异常只进入 Reporter；默认 `SafeExceptionRenderer` 对 HTTP 请求返回状态码 500 和
`Internal Server Error`，不会输出异常消息、绝对路径或堆栈。

项目通过 `module.provider-override` 中的 Provider 覆盖：

```php
use App\Exceptions\BusinessExceptionReporter;
use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Exceptions\ExceptionReporter;
use HongXunPan\Framework\Provider\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $app->singleton(ExceptionReporter::class, BusinessExceptionReporter::class);
    }
}
```

框架还提供：

```php
report($throwable);

$result = rescue(
    static fn () => $service->runOptionalOperation(),
    fallback: false,
);
```

`rescue()` 只适合业务已经明确允许失败的旁路操作。关键写入、配置校验和 Module 发布仍应让
异常向上传播。

## 应用生命周期

`ApplicationLifecycle` 提供两个稳定触发点：

- `requestHandled(RequestHandledSnapshot)`：业务闭包正常完成后触发；
- `exceptionOccurred(ExceptionOccurredSnapshot)`：业务闭包抛出异常后、进入原异常处理链前触发。

框架默认绑定 `NullApplicationLifecycle`。生命周期实现自身失败只会上报，不覆盖原业务异常，
也不会把成功请求改写成错误响应。

可选 Module 可以覆盖生命周期契约；framework core 不依赖具体事件包。

## Module 运行机制

Module Composer 包必须声明：

```json
{
    "type": "simple-module",
    "extra": {
        "simple": {
            "module": "Vendor\\Package\\ExampleModule"
        }
    }
}
```

项目通过 `config/module.php` 维护：

```php
return [
    'enable' => [
        Vendor\Package\ExampleModule::class,
    ],
    'provider-override' => [
        App\Providers\ProjectServiceProvider::class,
    ],
];
```

Module 自己在包内维护：

- `config/providers.php`
- 可选的 `config/helpers.php`
- 可选的 `config/resources.php`

常用命令：

```bash
php bin/simple module:enable <name>
php bin/simple module:disable <name>
php bin/simple module:refresh [name]
php bin/simple module:status [name]
php bin/simple module:publish <name> <resource>
```

所有变更命令支持 `--dry-run`。Module 命令使用不执行 Composer `autoload.files` 的受限类加载，
因此 Installer 和资源声明不得依赖全局帮助函数。

Provider 顺序为：

1. framework 默认绑定；
2. Module Provider；
3. `module.provider-override` 项目 Provider；
4. Provider `boot()`。

项目 Provider 因此保留最终容器覆盖权。

### Module Helper

framework 只保留核心函数：

- `app`
- `config`
- `env`
- `report`
- `rescue`

其他全局函数由已启用 Module 的 `config/helpers.php` 声明。`HelperLoader` 负责所有权校验、
冲突检测和文件加载；未启用 Module 的 helper 不进入全局命名空间。

例如 `event()` 归 `hongxunpan/simple-event` 所有，只有安装并启用 Event Module 后才存在。

### 可选基础设施

按项目需要分别安装：

```bash
composer require hongxunpan/simple-redis
composer require hongxunpan/simple-event
composer require hongxunpan/simple-eloquent
```

安装 Composer 包不会自动启用 Module。安装后使用：

```bash
php bin/simple module:enable redis
php bin/simple module:enable event
php bin/simple module:enable eloquent
```

具体配置、公开契约和 Worker 使用方式由对应包 README 维护。

## 原子文件操作

`HongXunPan\Framework\Filesystem\AtomicFile` 提供：

- `read()`：读取文件并将失败转为明确异常；
- `replace()`：在目标目录内原子替换文件；
- `create()`：原子创建且不覆盖已有文件。

调用方负责目录创建、路径边界和上层幂等策略。

## 验证

```bash
composer test
```

共享工作区中的 `0.3.x` 开发线必须使用 `gplus-php-fpm-8.5` 执行验证。
