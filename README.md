# Command 3.x

一个独立的 PHP 命令行组件，提供命令及 Action 管理、参数校验、分层帮助、表格、实时输出和交互选择。可用于自己的 CLI 工具，也可由其他组件提供命令，再交给宿主应用注册。

需要 **PHP 8.1+、mbstring 扩展和 Composer**。不依赖 Swoole 或 EasySwoole 主框架。3.x 的 PHP 接口与 2.x 不兼容。

## 安装

在自己的项目中引入 3.x 开发分支：

```sh
composer require easyswoole/command:3.x-dev
```

本地开发时可使用相邻的 command 仓库，见[本地依赖配置](docs/integration.md#本地依赖配置)。

如果已经下载本仓库，在仓库根目录运行下面的命令即可先体验现成示例：

```sh
composer install
php examples/cache.php
php examples/cache.php cache
php examples/cache.php cache clear --store=default
php examples/cache.php cache clear --help
```

前两条 PHP 命令分别显示命令列表、Action 列表；第三条执行清理；第四条显示该 Action 的用法和参数。

## 五分钟：写出第一个命令

在自己的项目根目录创建两个文件，和 `vendor/` 放在同一级目录：

```text
my-cli/
├── vendor/
├── GreetCommand.php
└── console.php
```

### 1. 创建 GreetCommand.php

一个命令实现 `CommandInterface` 的两个方法：`definition()` 描述用法，`execute()` 执行业务。下面是可以直接复制的完整文件：

```php
<?php

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

final class GreetCommand implements CommandInterface
{
    public function definition(): CommandDefinition
    {
        $name = Option::string('name', '如何称呼你', default: '世界');

        $definition = CommandDefinition::create('greet');
        $definition->description('输出一条问候');
        $definition->option($name);
        return $definition;
    }

    public function execute(Input $input, Console $console): CommandResult
    {
        return CommandResult::success('你好，' . $input->option('name') . '！');
    }
}
```

`Option::string()` 把 `--name` 声明为字符串选项。用户没有提供时，`$input->option('name')` 返回默认值 `世界`。

### 2. 创建 console.php

入口只负责加载依赖、注册命令和执行：

```php
<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/GreetCommand.php';

use EasySwoole\Command\Application;

$app = new Application('我的命令行');
$app->addCommand(new GreetCommand());

exit($app->run(array_slice($argv, 1)));
```

`run()` 接收的数组不包含脚本名称，所以这里使用 `array_slice($argv, 1)`。它负责输出结果并返回退出码，入口再用 `exit()` 交给 Shell。

### 3. 运行

```sh
php console.php greet
# 你好，世界！

php console.php greet --name=张三
# 你好，张三！

php console.php
# 显示已注册的命令

php console.php greet --help
# 显示 greet 的描述、参数和默认值
```

带值选项使用 `--name=张三`，也支持旧式的 `-name=张三`。**目前不支持 `--name 张三` 这种空格分隔的写法。**

## 接下来想实现什么？

每份指南都说明代码放在哪里、如何调用以及会得到什么结果；不必先理解内部解析器或状态机。

| 需求 | 从哪里开始 |
| --- | --- |
| 一个 `cache` 命令支持 `clear`、`warmup` 等 Action | [命令与 Action](docs/commands.md#一个命令支持多个-action) |
| 字符串、整数、布尔开关、位置参数 | [定义和读取参数](docs/input.md#选项的类型) |
| 必填、不能为空、枚举、数值范围、自定义校验 | [校验参数](docs/input.md#必填不等于不能为空) |
| 两个参数互斥、至少提供一个参数 | [整组输入校验](docs/input.md#多个字段之间的校验) |
| 多次传入 `--id=1 --id=2` | [多值选项](docs/input.md#多值选项) |
| 判断用户是否主动传入某个参数 | [输入来源](docs/input.md#区分默认值和用户输入) |
| 输出文字、颜色、表格 | [普通输出](docs/interaction.md#文字颜色和表格) |
| 提问、确认、单选、多选和搜索 | [交互输入](docs/interaction.md#提问和确认) |
| 实时刷新状态表格 | [实时输出](docs/interaction.md#实时输出) |
| 禁止交互，或输出 JSON 供脚本读取 | [自动化脚本](docs/interaction.md#禁止交互) |
| 注入命令依赖、Provider、延迟工厂、中间件 | [组件接入](docs/integration.md) |
| 返回结果、设置退出码、捕获异常和写日志 | [结果](docs/commands.md#返回结果和退出码)、[异常观察](docs/integration.md#记录执行异常) |

## 命令与 Action 的调用形式

独立命令直接执行，有 Action 的命令先选择操作。以仓库里的缓存示例为例：

| 调用 | 行为 |
| --- | --- |
| `php examples/cache.php` | 列出顶层命令 |
| `php examples/cache.php cache` | 列出 cache 下的 Action 及各自参数 |
| `php examples/cache.php cache clear --store=default` | 校验参数，然后执行 clear |
| `php examples/cache.php cache clear --help` | 只显示 clear 的帮助 |
| `php examples/cache.php help cache clear` | 同样显示 clear 的帮助 |

帮助和参数校验早于业务工厂、中间件。定义命令时应只描述用法，数据库连接、框架初始化等工作放到执行阶段。

## 可运行的完整示例

| 示例 | 运行方式 | 可以学到什么 |
| --- | --- | --- |
| [缓存入口](examples/cache.php)、[CacheCommand](examples/Cache/CacheCommand.php)、[MemoryCache](examples/Cache/MemoryCache.php) | `php examples/cache.php cache clear --store=default` | 构造函数注入、多个 Action、必填与非空校验、帮助示例 |
| [交互入口](examples/console.php)、[DemoProvider](examples/Console/DemoProvider.php)、[DemoCommand](examples/Console/DemoCommand.php) | `php examples/console.php demo console` | Provider、搜索、多选、确认 |
| 同一个交互示例 | `php examples/console.php demo console --live` | 彩色状态表格、定时刷新、键盘退出 |

选择和实时视图请在终端中运行。原始按键交互需要 POSIX 终端和 `stty`；无法使用原始模式时，选择器可退回编号输入。输入不是终端时，需要显式提供业务参数，组件不会自动选择或确认。

## 开发与测试

```sh
composer test
python3 tests/terminal_test.py
```

测试分工、内部目录与执行流程见[开发说明](docs/development.md)。server、process 等业务命令由宿主框架或其他组件实现。
