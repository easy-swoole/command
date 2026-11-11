# 组件注册、依赖与执行扩展

本页入口沿用 [README 的 console.php](../README.md#2-创建-consolephp)。注册 Provider、中间件和异常观察器的代码，放在最终的 run 调用之前。

## 通过构造函数注入依赖

组件不会替你创建业务服务，也不要求使用某种依赖注入容器。先组装依赖，再把命令交给 Application。

仓库的 [cache.php](../examples/cache.php) 是可直接运行的示例，其中已经加载并引入 CacheCommand 和 MemoryCache：

```php
$app = new Application('缓存管理示例');
$app->addCommand(new CacheCommand(new MemoryCache()));
```

CacheCommand 构造函数接收 MemoryCache，execute 内调用这个依赖。实际项目可以使用自己的缓存接口和服务实现，server、process 等管理逻辑也留在提供命令的框架或组件中。

## 使用 Provider 注册一组命令

一个组件有多个命令时，可以实现 CommandProviderInterface。先在 README 的项目根目录新建 `MyCommandProvider.php`，这是完整文件：

```php
<?php

use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Contracts\CommandProviderInterface;

final class MyCommandProvider implements CommandProviderInterface
{
    public function __construct(private GreetCommand $command) {}

    public function register(CommandRegistry $registry): void
    {
        $registry->add($this->command);
    }
}
```

然后修改 console.php：在已有的 require 后加载 Provider，并用 addProvider 替换 addCommand：

```php
require __DIR__ . '/MyCommandProvider.php';

$app->addProvider(new MyCommandProvider(new GreetCommand()));
```

不要同时保留原来的 `addCommand(new GreetCommand())`，否则会因为同名重复注册而报错。调用方式仍然是：

```sh
php console.php greet --name=张三
```

Provider 可以多次调用 registry 的 add 或 register 注册不同名称。现成的 Provider 示例见 [DemoProvider](../examples/Console/DemoProvider.php)。

## 延迟创建命令

如果创建命令会连接服务、读取配置，或者构造业务依赖比较昂贵，先注册定义与工厂，在真正执行时再创建实例。

下面代码替换 [examples/cache.php](../examples/cache.php) 的 addCommand 那一行，其余 require、use 和入口代码保持不变：

```php
$app->registry()->register(
    CacheCommand::createDefinition(),
    static fn () => new CacheCommand(new MemoryCache()),
);
```

此时：

```sh
php examples/cache.php cache
php examples/cache.php cache clear --help
# 只读取定义，不调用工厂

php examples/cache.php cache clear --store=default
# 参数通过校验后才调用工厂、执行命令
```

`createDefinition()` 是示例自行提供的静态方法，不是 CommandInterface 强制要求的方法。使用这种模式时，命令的 definition 应返回同一份定义逻辑：

```php
public function definition(): CommandDefinition
{
    return self::createDefinition();
}
```

上面的片段是命令类中的方法，完整实现见 [CacheCommand](../examples/Cache/CacheCommand.php)。定义阶段不要初始化框架、创建网络连接或执行其他业务。

同一次 dispatch 只创建一个命令实例。若中间件为实时刷新多次调用 next，会复用这个实例；下一次 dispatch 是否复用，由注册的对象或工厂决定。

## 命令冲突与定义快照

重复注册同名顶层命令会立即报错。确实要替换已有命令时显式使用 replace。仍以缓存入口为例，把原注册之后的替换写为：

```php
$app->registry()->replace(
    CacheCommand::createDefinition(),
    static fn () => new CacheCommand(new MemoryCache()),
);
```

只能替换已经注册的名称。读取定义用于检查时：

```php
$definition = $app->registry()->definition('cache'); // 不存在时为 null
$all = $app->registry()->definitions();             // 名称 => 定义
```

注册和读取都会隔离定义，修改读取出来的快照不会改变已注册命令，也不会创建业务实例。

## 执行中间件

中间件适合执行计时、权限检查和宿主初始化。下面例子放在 console.php 中，在 run 之前注册。先在该文件顶部引入这些类：

```php
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;
```

然后注册：

```php
$app->middleware(static function (
    CommandDefinition $definition,
    Input $input,
    Console $console,
    callable $next,
): CommandResult {
    $started = hrtime(true);
    $result = $next();
    $elapsed = (hrtime(true) - $started) / 1e6;
    $console->info(sprintf('执行耗时 %.2fms', $elapsed));
    return $result;
});
```

`$next()` 执行下一层中间件或命令。返回值必须是 CommandResult。按注册顺序包裹执行，帮助请求和参数校验失败不会进入中间件。

中间件接收顶层 CommandDefinition，通过 Input 读取当前命令和 Action：

```php
$input->command(); // 顶层名称
$input->action();  // Action 名称；独立命令为 null
```

### 使用元数据传递宿主配置

元数据是供宿主读取的自定义配置，不会自动增加命令行参数，也不自动触发框架初始化。在定义中添加：

```php
$definition->metadata('profile', true);
```

如果某个 Action 配置不同，则在 Action 上添加同名元数据。中间件可以优先读取 Action，再回退到顶层定义：

```php
$action = $definition->actionDefinition($input->action());
$profile = $action?->meta('profile') ?? $definition->meta('profile');
```

如何使用这个值由宿主决定。EasySwoole 主框架使用类似方式声明运行环境需求，command 组件本身不认识 Core、Bridge 或进程管理服务。

## 记录执行异常

需要拿到原始异常和堆栈时，注册 onException。下面是不依赖第三方日志库的完整观察器：

```php
use EasySwoole\Command\Event\ExceptionEvent;

$app->onException(static function (ExceptionEvent $event): void {
    error_log((string) $event->error);
});
```

在宿主已经提供 logger 时，也可以在这里调用宿主的日志接口。

| 事件字段 | 内容 |
| --- | --- |
| error | 原始 Throwable，保留堆栈和 previous 异常链 |
| command | 已解析的顶层名称，早期解析失败时可能为 null |
| action | 已解析的 Action 名称，独立命令或早期失败时为 null |
| input | 已绑定的 Input，字段绑定失败时为 null |

整组规则返回 false、错误字符串或抛出 InputException 时，input 保留完整类型化输入；原异常对象不会被替换。成功、帮助请求以及业务主动返回 failure 结果都不触发异常事件。

可以注册多个观察器，按顺序通知。观察器用于记录，不接管返回值或恢复执行；它自己抛出的异常不会覆盖原始错误，也不会阻止其他观察器。

## 本地依赖配置

假设两个仓库相邻：

```text
work/
├── my-cli/
└── command/    # 检出 3.x 分支
```

在 my-cli 中执行：

```sh
composer config repositories.command path ../command
composer require easyswoole/command:3.x-dev
```

Composer 会优先从这个本地 path 仓库解析组件；查看安装提示确认链接到 ../command。路径只适用于本机开发，是否提交仓库配置由项目自己的依赖管理方式决定。

在 command 仓库内体验 examples 则只需要 composer install，无需先创建宿主项目。EasySwoole 主框架的版本依赖和业务命令管理由主框架仓库维护。
