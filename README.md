# Command 3.x

独立的 PHP 8.1+ 命令执行与终端交互组件，无需依赖 EasySwoole 主框架、Swoole 扩展或依赖注入容器。3.x 使用全新的 PHP 接口，不兼容 2.x 接口。

## 目录结构

```text
src/
├── Application.php          # 应用入口与执行流程
├── CommandRegistry.php      # 命令注册与延迟创建
├── Contracts/               # 命令与组件注册契约
├── Definition/              # 命令、选项和参数定义
├── Input/                   # 输入解析与参数绑定
├── Console/
│   ├── Console.php          # 终端输出与交互入口
│   ├── Terminal.php         # 终端驱动及会话管理
│   ├── Color.php            # 颜色工具
│   ├── Selection/           # 选择项和选择结果
│   └── View/                # 视图契约与表格视图
├── Exception/               # 输入、终端及中断异常
└── Result/                  # 命令执行结果

tests/
├── bootstrap.php            # 独立自动加载与测试准备
├── run.php                  # 回归测试入口
├── Support/                 # 断言、测试命令和模拟终端
├── Unit/                    # 按职责划分的回归用例
└── terminal_test.py         # 真实终端交互测试
```

## 定义与注入命令

```php
use EasySwoole\Command\Application;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

final class CacheClearCommand implements CommandInterface
{
    public function __construct(private CacheManager $cache) {}

    public function definition(): CommandDefinition
    {
        return CommandDefinition::named('cache', 'clear')
            ->description('清理缓存数据')
            ->option(Option::string('store', default: 'default'));
    }

    public function execute(Input $input, Console $console): CommandResult
    {
        $this->cache->clear($input->option('store'));
        return CommandResult::success('缓存已清理');
    }
}

$app = new Application('我的应用');
$app->addCommand(new CacheClearCommand($cache));
exit($app->run(array_slice($argv, 1)));
```

```sh
php console.php cache clear --store=redis
php console.php cache clear --help
php console.php help cache
```

传给 `run()` 和 `dispatch()` 的参数不包含脚本名称。命令路径可以包含一个或多个单词。仅输入命令组时显示帮助，未知操作不会回退执行其他命令。

`run()` 将结果消息输出到标准输出或标准错误，并返回退出码；`dispatch()` 返回 `CommandResult`，不自动输出其中的消息。执行期间仍可通过控制台输出进度或读取交互输入。退出码：0 成功，1 执行失败，2 输入错误；安装 pcntl 扩展时，被外部信号中断的会话返回 128 + 信号编号。

## 输入定义

- `Option::string()`、`integer()`、`number()` 和 `flag()` 将输入转换为对应类型。
- `->validate(fn ($value) => true)` 在类型转换后追加校验；返回错误字符串或 false 表示校验不通过。
- `Input::provided()` 区分用户未传入选项与显式传入选项。
- 支持 `--name=value` 和原有的 `-name=value`。带值选项使用 =，不支持通过空格分隔选项和值，也不支持合并多个短选项。
- 布尔开关支持 `--force`、`--force=true` 和 `--force=false`。
- 数值选项可声明省略值时使用的隐式值，例如 `Option::number('watch', implicit: 2)`。
- 重复选项取最后一次传入的值。未知选项和多余参数会返回输入错误。
- `Argument` 声明位置参数，也支持原有的 `name=value` 命名参数形式。`--` 之后的内容按字面值作为位置参数处理。
- 命令和选项名称区分大小写，`help` 和 `--help` 为保留名称。

输入校验和帮助处理早于命令工厂与执行中间件。读取命令定义时不应初始化应用或产生其他副作用。

## 组件注册与延迟工厂

组件实现 `CommandProviderInterface::register(CommandRegistry $registry): void`，在其中调用 `add()` 或 `register()` 注册命令。宿主应用通过 `$app->addProvider($provider)` 接入。依赖通过构造函数或工厂传入，组件无需了解宿主框架。

```php
$app->registry()->register(
    CacheClearCommand::createDefinition(), // 在命令类中实现这个静态定义工厂。
    fn () => new CacheClearCommand($container->get(CacheManager::class)),
);
```

使用延迟注册时，让 `definition()` 调用同一个静态定义工厂。重复注册相同命令路径会立即报错，替换已有命令必须显式调用 `registry()->replace($definition, $factory)`。

执行中间件接收 `(CommandDefinition $definition, Input $input, Console $console, callable $next)`，返回 `CommandResult`。宿主应用可依据命令定义中的元数据初始化运行环境。中间件按注册顺序包裹命令执行，帮助请求和输入错误不会进入执行中间件。

## 终端交互

`Console` 提供 `line()`、`info()`、`error()`、`table()`、`prompt()`、`confirm()`、`select()`、`live()`。

```php
use EasySwoole\Command\Console\Selection\Choice;

$result = $console->select('选择操作目标', [
    new Choice('primary', '主实例', $primary, '生产环境'),
    new Choice('secondary', '备用实例', $secondary, '待命'),
], multiple: true);

if (!$result->cancelled() && $console->confirm('是否继续？', default: false)) {
    foreach ($result->values() as $target) {
        // 直接操作原始业务对象，无需根据展示序号重新查找。
    }
}
```

每个选项具有稳定且唯一的 ID。选择结果区分 selected（已选择）、cancelled（已取消）和 eof（输入流关闭）。方向键移动，空格切换选中状态，a 切换全部可见选项，/ 进入搜索，回车确认，q、Esc 或 Ctrl+C 取消。搜索模式下可输入文字，按回车返回导航模式。长列表会在终端可视区域内滚动。

```php
use EasySwoole\Command\Console\View\TableView;

$console->live(
    refresh: fn () => new TableView(['PID', '内存'], $service->status()),
    interval: .5,
);
```

实时刷新回调返回文本、`ViewInterface` 视图或 false；返回 false 表示结束视图。按 q、Esc 或 Ctrl+C 退出。刷新与窗口尺寸变化共用键盘事件循环。回调应返回视图内容，不要直接向控制台输出。需要提示输入或进行选择时，先退出实时视图，再进入下一次交互。

原始键盘会话需要 POSIX 终端及 stty。其他交互终端使用编号选择；非交互输入要求显式传入参数。实时视图要求输入和输出均支持终端交互。重定向后的普通输出不包含 ANSI 颜色控制序列。会话会在退出、异常及已处理的信号中恢复终端输入模式、光标和屏幕。可选的 pcntl 扩展用于处理外部信号清理，普通命令和键盘取消操作不依赖该扩展。

输入输出流和 `Terminal` 均可注入，便于测试。`Terminal::now()` 提供可替换的单调时钟。同一时间只允许一个原始终端会话处于活动状态。

## 运行示例与测试

```sh
composer install
php examples/console.php demo console
php examples/console.php demo console --live
composer test
php -n tests/run.php
python3 tests/terminal_test.py # 可选：运行 POSIX 真实终端回归测试。
```

`tests/run.php` 无需第三方测试库或 Swoole 即可运行。server、process 和 Bridge 等业务管理逻辑由宿主应用或其组件实现。
