# simple-framework

`hongxunpan/simple-framework` 是面向 simple-php 项目的轻量框架内核，当前 `php85 / 0.2.x` 版本线要求 PHP `^8.5`。

## 安装

```bash
composer require hongxunpan/simple-framework
```

## Config 与 Env

框架核心提供容器单例 `Config`、`Env` 以及全局 `config()`、`env()`：

- 进程环境变量优先于项目 `.env`；
- `.env` 不存在时使用调用方默认值，不阻断应用启动；
- 配置支持点号路径读取；
- 非调试环境可以生成原子配置缓存；
- `APP_ENV`、`APP_DEBUG` 是推荐环境键，兼容期继续读取 `ENV_NAME`、`DEBUG`。

正常应用启动始终使用 framework Config / Env。当前版本仅在核心实例尚未绑定时回退到 `php-tools` 旧入口，用于已有脚本迁移；新代码不得继续依赖该回退路径。

## 默认异常处理

默认 `ErrorHandler` 先调用 `ExceptionReporter`，再调用 `ExceptionRenderer`。完整异常只进入 Reporter，默认 `SafeExceptionRenderer` 对 HTTP 请求返回状态码 500 和 `Internal Server Error`，不输出异常消息、绝对路径或堆栈。

业务项目可以在 `config/singleton.php` 分别覆盖 Reporter 和 Renderer。现有 `Application::run($closure, ErrorHandler::class)` 静态处理器入口在兼容期继续有效。

## Module 运行机制

Module 契约和运行时加载机制属于 framework core。项目使用 `config('module.enable')` 记录已启用 Module，使用 `config('module.provider-override')` 登记项目级覆盖 Provider；Module 自己在包内维护 `config/providers.php` 和可选的 `config/helpers.php`。

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

`extra.simple.module` 指向实现 `HongXunPan\Framework\Module\Module` 的无参入口类。入口类的 `basePath()` 必须返回当前 Composer 包根目录。

项目通过以下命令管理启用状态：

```bash
php bin/simple module:enable <name>
php bin/simple module:refresh [name]
php bin/simple module:disable <name>
php bin/simple module:status [name]
```

`ModuleCommandRunner` 只负责解析和分发上述 `module:*` 命令；通用输出继续由 `Console\Output` 承接，不提前建立面向所有 CLI 能力的总控 Console。

前三个写入类命令支持 `--dry-run`。CLI 使用不执行 Composer `autoload.files` 的受限自动加载，因此 Installer 不得依赖全局帮助函数；运行时只读取 `config/module.php`，不会扫描 Composer 包、执行 Installer 或修改项目文件。Module 命令读取完整配置、替换 `module.enable` 后原子规范化写回，并保留 `module.provider-override` 的类名列表；写入成功后清理可能存在的配置缓存。

Installer 的 `dryRun=true` 调用只能返回差异，不得写项目文件；正式 `install()`、`refresh()`、`upgrade()`、`uninstall()` 必须各自保证幂等和操作内失败回滚。命令层会先完成全量预检，并且只在 `install()` 成功后写入 Module 启用状态。

Provider 注册顺序为 Module Provider、旧 `config/singleton.php` 兼容绑定、`module.provider-override` 项目 Provider；随后依次执行 Provider `boot()`，最后执行旧 `config/boot.php`。项目 Provider 因此保留最终容器覆盖权，不替换或跳过 Module Provider。

## 全局异常上报与 rescue

框架提供只上报、不生成响应的 `report()`，以及用于显式容错的通用 `rescue()`：

```php
$result = rescue(
    static fn () => $service->runOptionalOperation(),
    fallback: false,
);
```

callback 成功时返回原结果；失败时默认通过 `ExceptionReporter` 上报原异常，再返回固定 fallback 或执行 fallback callable。也可以使用 `report: false` 或判断 callable 控制是否上报。

业务仓可以在 `config/singleton.php` 覆盖默认上报器：

```php
use App\Exceptions\BusinessExceptionReporter;
use HongXunPan\Framework\Exceptions\ExceptionReporter;

return [
    ExceptionReporter::class => BusinessExceptionReporter::class,
];
```

`rescue()` 会捕获 `Throwable`，只应包裹业务已经明确允许失败的旁路操作。关键写入、配置校验和默认 Event 发布仍应让异常向上传播。

Event 配置、发布与消费异常统一继承 `HongXunPan\Framework\Event\Exception\EventException`，业务仓可以基于该公共异常族设置独立日志 Channel 或告警策略，不需要逐个枚举具体异常类。

## 业务事件 MVP

当前 Event 内核支持：

- 同步 listener；
- Redis Streams 异步 listener；
- 显式 best-effort listener 失败策略；
- Symfony JSON 持久化协议；
- Consumer Group、pending 回收和 failed stream；
- 一个 Event 对应一条异步消息，消息内冻结全部异步 listener。

当前不提供 Database Outbox、自动重试、延迟任务、多 Driver 选择、长期执行历史或 exactly-once。

### 1. 定义 Event

Event 是已经发生的业务事实快照，不携带 ORM Model、Request、Service 或基础设施配置。

```php
<?php

use DateTimeImmutable;
use HongXunPan\Framework\Event\Event;

final readonly class AlumniCardApproved implements Event
{
    public const int VERSION = 1;

    public function __construct(
        public int $alumniCardId,
        public int $userId,
        public DateTimeImmutable $approvedAt,
    ) {
    }
}
```

MVP 快照字段只允许标量、`null`、`BackedEnum` 和 `DateTimeImmutable`。Event 类必须声明为 `final readonly`，构造参数必须与公开属性一一对应。

### 2. 定义 listener

普通 listener 同步执行：

```php
final class WriteApprovalAuditLog
{
    public function handle(AlumniCardApproved $event): void
    {
        // 写入审计事实。
    }
}
```

实现 `ShouldQueue` 的 listener 进入全局异步 Driver：

```php
use HongXunPan\Framework\Event\Listener\ShouldQueue;

final class SendApprovalNotification implements ShouldQueue
{
    public function handle(AlumniCardApproved $event): void
    {
        // 异步副作用必须按业务唯一事实保证幂等。
    }
}
```

listener 必须声明公开实例方法 `handle(具体 Event $event): void`。单个 Event 和 listener 均不配置 driver、channel、stream 或重试参数。

对不应污染业务调用链的非关键副作用，显式实现 `ShouldHandleBestEffort`：

```php
use HongXunPan\Framework\Event\Listener\ShouldHandleBestEffort;
use HongXunPan\Framework\Event\Listener\ShouldQueue;

final class SendApprovalNotification implements ShouldQueue, ShouldHandleBestEffort
{
    public function handle(AlumniCardApproved $event): void
    {
        // 异常会被上报，但不会让同步调用失败或让异步消息进入失败流。
    }
}
```

普通同步 listener 仍保持异常向上传播；普通异步 listener 失败仍进入 failed stream。best-effort 只改变显式 marker listener 的失败策略：同步阶段继续后续 listener，异步阶段完成上报后 ACK。

框架默认使用 `ErrorLogListenerFailureReporter` 输出已清洗的 listener、Event 和异常摘要。业务仓可以在 `config/singleton.php` 绑定自己的 `ListenerFailureReporter` 实现；失败上报器自身异常也不会污染业务调用链。

### 3. 配置 Event

`config/events.php`：

```php
<?php

use HongXunPan\Framework\Event\Driver\RedisStreamDriver;

return [
    'driver' => [
        'class' => RedisStreamDriver::class,
        'connection' => 'default',
        'stream' => 'simple-framework:business-events',
        'group' => 'simple-framework',
        'failed_stream' => 'simple-framework:business-events:failed',
        'block_ms' => 5000,
        'batch_size' => 10,
        'claim_idle_ms' => 60000,
        'failed_max_length' => 10000,
    ],
    'listeners' => [
        AlumniCardApproved::class => [
            WriteApprovalAuditLog::class,
            SendApprovalNotification::class,
        ],
    ],
];
```

没有任何 `ShouldQueue` listener 时可以省略 `driver`。一旦存在异步 listener，driver 配置缺失或不合法会在启动期失败。

### 4. 启动 Event

在业务仓 `config/boot.php` 中显式启动，并保证 Redis connection 先完成初始化：

```php
<?php

use HongXunPan\Framework\Event\Bootstrap\EventBootstrapper;

return [
    [App\BootConfigService::class, 'setRedisConnection'],
    [EventBootstrapper::class, 'boot'],
];
```

### 5. 触发 Event

```php
event(new AlumniCardApproved(
    alumniCardId: 1,
    userId: 10001,
    approvedAt: new DateTimeImmutable(),
));
```

触发端不区分同步或异步。同步 listener 全部成功后，Dispatcher 才发布包含全部异步 listener 的唯一 EventMessage。

MVP 没有事务协调器。涉及数据库事务时，应在事务成功返回后调用 `event(...)`；数据库提交后、Redis 发布前仍存在已接受的丢失窗口。

### 6. 运行 Worker

框架提供：

```php
$processed = app(HongXunPan\Framework\Event\Worker\EventWorker::class)->runOnce();
```

持续运行时使用 `EventWorker::run(callable $shouldStop)`。信号注册、命令退出码和 Supervisor/systemd 配置由业务仓负责，框架不硬依赖 `pcntl`。

Redis Streams 消费语义为 at-least-once。Worker 崩溃或 ACK 前退出时，整条 Event 消息可能重新执行，因此所有异步 listener 必须幂等。

## 验证

```bash
composer test
```

共享工作区中的 `php85 / 0.2.x` 版本线应使用 PHP 8.5 对应容器执行验证。
