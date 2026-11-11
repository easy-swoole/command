# 命令、Action、帮助和结果

先完成 [README 的 GreetCommand](../README.md#五分钟写出第一个命令)。本页介绍独立命令以外的用法；缓存示例的完整文件已经放在 [examples/Cache](../examples/Cache/CacheCommand.php)。

## 独立命令

README 中的 `greet` 是独立命令：

```sh
php console.php greet --name=张三
```

它没有 Action，选项和位置参数直接添加到 `CommandDefinition`。执行时：

```php
$input->command(); // 'greet'
$input->action();  // null
```

定义中的名称只写 `greet`，不包含 `php`、脚本名称或完整命令路径。

## 一个命令支持多个 Action

希望支持下面两种调用时，用一个 CommandDefinition 加两个 ActionDefinition：

```sh
php examples/cache.php cache clear --store=default
php examples/cache.php cache warmup --store=redis
```

定义逻辑放在命令类中。以下是 [CacheCommand::createDefinition()](../examples/Cache/CacheCommand.php) 的基本组织方式；文件顶部引入这些类：

```php
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
```

先建立各个 Action，再组装命令，避免多层嵌套：

```php
$store = Option::string('store', '缓存存储名称', required: true);

$clear = ActionDefinition::create('clear');
$clear->description('清理缓存');
$clear->option($store);

$warmup = ActionDefinition::create('warmup');
$warmup->description('预热缓存');
$warmup->option($store);

$definition = CommandDefinition::create('cache');
$definition->description('缓存管理');
$definition->action($clear);
$definition->action($warmup);
return $definition;
```

`create()` 只接收一个名称：写 `create('cache')` 和 `create('clear')`，不要写 `create('cache clear')`。

命令类仍然只实现一个 `execute()`，用 `$input->action()` 分发。下面是该方法的主体；`clear()` 和 `warmup()` 是同一命令类中的私有业务方法：

```php
return match ($input->action()) {
    'clear' => $this->clear($input),
    'warmup' => $this->warmup($input),
    default => throw new LogicException('未定义的缓存操作'),
};
```

完整的分发及业务方法见 [CacheCommand](../examples/Cache/CacheCommand.php)，入口和依赖组装见 [cache.php](../examples/cache.php)。

一个顶层命令只注册一次，它的 Action 随定义一起注册。Action 的名称、选项及校验相互独立，未知 Action 返回退出码 2。

**有 Action 的命令，参数和整组校验都放到 Action 上；独立命令才直接放到 CommandDefinition 上。** 两种模式不能混用。

## 帮助自动生成

不需要单独实现 help 命令。组件从注册的定义读取名称、描述、参数类型及默认值：

```sh
php examples/cache.php
php examples/cache.php cache
php examples/cache.php cache clear --help
php examples/cache.php help cache clear
```

四种形式分别显示命令列表、Action 列表、指定 Action 的详细帮助，以及同一份详细帮助。

可以在定义中增加使用示例。例如在缓存命令的 `createDefinition()` 中，创建 `$clear` 之后、加入命令之前添加：

```php
$clear->example(
    'php examples/cache.php cache clear --store=redis',
    '清理 Redis 缓存',
);
```

详细帮助会显示 Usage、类型、实际必填标记、默认值、隐式值和示例。分组列表保持简洁。帮助请求不创建延迟业务实例，也不执行输入校验或中间件。

终端中的命令及 Action 名称为红色，标题为绿色，参数名称为黄色。重定向输出、设置 `NO_COLOR` 或 `TERM=dumb` 时使用纯文本。

命令、Action 和参数名称区分大小写。名称以英文字母开头，可包含字母、数字、下划线和横线。顶层名称 `help`、选项名称 `help` 为保留入口；Action 可以命名为 `help`。

## 返回结果和退出码

`execute()` 返回 `CommandResult`。下面这些写法相互独立，放在业务方法结束的位置：

```php
return CommandResult::success('操作完成');
```

```php
return CommandResult::success('已处理', data: ['count' => 3]);
```

```php
return CommandResult::failure('目标不存在');
```

```php
return CommandResult::failure('参数不合法', exitCode: 2);
```

`message` 用于展示，`data` 保存结构化业务结果。普通文本模式只自动输出 message；程序调用或 JSON 模式可以读取 data。

| 退出码 | 含义 |
| --- | --- |
| `0` | 成功 |
| `1` | 执行异常，或默认的失败结果 |
| `2` | 输入解析、类型转换或参数校验失败 |
| `128 + 信号编号` | 原始终端会话中捕获到的外部信号，例如 SIGTERM 对应 143 |

业务也可以通过 `exitCode` 返回自己的退出码。实时视图中的键盘 q、Esc、Ctrl+C 表示正常退出视图，与外部信号中断不同。

## 在 PHP 中调用命令

入口脚本一般用 `run()`，它输出结果并返回退出码。如果另一个 PHP 组件只想取得结果，使用 `dispatch()`。

下面代码放在 README 的 `console.php` 中、注册命令之后，替换原来的 `exit(...)`：

```php
$result = $app->dispatch(['greet', '--name=张三']);

if ($result->exitCode === 0) {
    echo $result->message; // 你好，张三！
}
```

`dispatch()` 不自动输出返回结果的 message，但业务主动调用 Console 的输出仍会发生。两种入口传入的数组都不含脚本名称。

需要由调用方决定输出流和格式时，可将同一个 Console 传入，再显式输出结果：

```php
use EasySwoole\Command\Console\Console;

$console = new Console(json: true);
$result = $app->dispatch(['greet', '--name=张三'], $console);
$console->writeResult($result);
```

JSON 模式的流分离和数据要求见[自动化输出](interaction.md#json-输出)。
