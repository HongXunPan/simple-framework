# simple-framework

`hongxunpan/simple-framework` 是面向 simple-php 项目的轻量框架内核，当前 `php85 / 0.2.x` 版本线要求 PHP `^8.5`。

## 安装

```bash
composer require hongxunpan/simple-framework
```

## 业务事件 MVP

当前 Event 内核支持：

- 同步 listener；
- Redis Streams 异步 listener；
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

触发端不区分同步或异步。同步 listener 全部成功后，Dispatcher 才发布包含全部异步 listener 的唯一 Envelope。

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
