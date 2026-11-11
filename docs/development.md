# 开发与测试

普通使用先阅读 [README](../README.md)。本页介绍维护组件、编写集成测试和扩展底层终端时的约定。

## 目录与职责

| 位置 | 职责 |
| --- | --- |
| `src/Application.php` | 串联帮助、参数处理、中间件和命令执行 |
| `src/CommandRegistry.php` | 保存定义快照，注册及延迟创建命令 |
| `src/Contracts/` | CommandInterface、CommandProviderInterface |
| `src/Definition/` | 命令、Action、选项、位置参数及说明 |
| `src/Input/` | 词元解析、字段绑定、类型转换和输入来源 |
| `src/Validation/` | 不可变 ValidationChain 与通用 Rules |
| `src/Help/` | 分层帮助 |
| `src/Result/`、`src/Event/`、`src/Exception/` | 结果、异常上下文及异常类型 |
| `src/Console/Console.php` | 输出、交互入口和会话管理 |
| `src/Console/Terminal.php` | 原始终端模式、信号、键盘和视口 |
| `src/Console/Text.php`、`Color.php` | 文本清理、样式与可见宽度 |
| `src/Console/Input/` | 完整按键序列及 UTF-8 字符读取 |
| `src/Console/Selection/` | 选择状态、布局和事件循环 |
| `src/Console/Live/` | 实时刷新状态和事件循环 |
| `src/Console/View/` | 视图接口及表格 |

应用流程：InputParser 定位命令和 Action，InputBinder 绑定并校验输入，随后进入中间件和业务命令。帮助在绑定之前返回；参数错误在执行工厂之前返回。

Option、Argument 及 ValidationChain 通过新对象追加规则；CommandDefinition、ActionDefinition 是构建器。注册及读取时复制定义，Action 和校验链不会因外部继续修改而污染注册快照。

SelectionState 处理状态转换，SelectionRenderer 只负责布局，SelectionRunner 显式同步可见页大小并处理终端事件。空闲时不反复生成视图。实时视图同样把状态与事件循环分开。

## 在测试中替换输入输出

不需要启动子进程也可以测试命令。下面在完成注册的 Application 上调用，输入输出均由调用方提供：

```php
use EasySwoole\Command\Console\Console;

$output = fopen('php://temp', 'w+');
$error = fopen('php://temp', 'w+');
$console = new Console(output: $output, error: $error, allowInteraction: false);

$result = $app->dispatch(['greet', '--name=测试'], $console);
// 检查 $result->exitCode、message、data；dispatch 不自动输出返回消息。
```

需要检查 run 的结果输出时，对同一 Console 调用 run，然后 rewind 并读取对应流。交互测试可以注入 Terminal 的子类，替换按键、行输入、尺寸和时钟；同一进程只允许一个活动原始终端会话。

## 运行现有测试

在 command 仓库根目录执行：

```sh
composer install
composer test
python3 tests/terminal_test.py
```

PHP 回归测试使用自己的断言与自动加载，覆盖参数、校验、注册、帮助、输出和交互状态，无需 PHPUnit 或 Swoole。

真实终端测试需要 Python 3、POSIX PTY 和 stty。安装 Composer 依赖用于加载组件，pcntl 可用时额外覆盖外部信号。测试会检查原始输入模式、屏幕和光标恢复，也检查小视口、按键序列、跨行颜色及恢复重试。

| 位置 | 测试内容 |
| --- | --- |
| `tests/Support/` | 断言、模拟终端、命令和流辅助 |
| `tests/Unit/InputTest.php`、`MultipleOptionTest.php` | 输入格式、来源与多值绑定 |
| `tests/Unit/ValidationTest.php` | 字段及整组规则 |
| `tests/Unit/KeyboardTest.php`、`SelectionTest.php` | 字节解码、选择与布局 |
| `tests/Unit/StyledTextTest.php`、`ConsoleModeTest.php` | 样式、JSON 与交互策略 |
| `tests/terminal_test.py` | 真实 PTY 交互和终端恢复 |

## 扩展范围

组件可以扩展 ViewInterface 展示自己的业务数据，宿主可以用 Provider 和中间件组合执行流程。业务初始化、服务查询和管理动作放在宿主或提供命令的组件中。

需要自定义视图时，在类文件中引入并实现这个接口：

```php
use EasySwoole\Command\Console\View\ViewInterface;

final class SummaryView implements ViewInterface
{
    public function __construct(private int $count) {}

    public function render(int $width = 80, int $height = PHP_INT_MAX): string
    {
        return '当前任务数：' . $this->count;
    }
}
```

live 的 refresh 返回这个对象即可。width 和 height 是当前可用空间；外层 Terminal 仍会裁剪可见区域。视图只返回文字，不操作输入模式、不直接输出。
