# Command 3.x

需要 PHP 8.1+ 和 mbstring 扩展的独立命令执行与终端交互组件，无需依赖 EasySwoole 主框架、Swoole 扩展或依赖注入容器。3.x 使用全新 PHP 接口，不兼容 2.x 接口。

## 命令与操作

一个 CommandDefinition 描述一个顶层命令，例如 cache、server 或 install；通过 ActionDefinition 声明该命令的多个操作。

- 不带命令：列出顶层命令及其描述。
- 仅输入 cache：列出 cache 的操作、说明和参数。
- 输入 cache clear：校验 clear 的参数，然后执行。
- 输入 cache clear --help 或 help cache clear：只显示 clear 的帮助。
- 输入 install：直接执行没有操作列表的独立命令。

create 只接收一个名称，不再接收完整路径或多个路径段。命令和操作名称均不包含空格；根名称 help 为保留入口，但操作可以命名为 help。

包含操作的命令，选项和位置参数必须定义在各个 action 上；独立命令则直接在 CommandDefinition 上声明输入。两种模式不能混用，操作之间的参数互不影响。未知操作会返回输入错误，不会回退执行其他方法。

## 定义一个支持多个操作的命令

先定义带校验的选项，再分别定义操作，最后组装命令，避免把多层定义嵌套在同一条链中：

```php
use EasySwoole\Command\Definition\{ActionDefinition, CommandDefinition, Option};
use EasySwoole\Command\Validation\Rules;

$store = Option::string('store', '缓存存储名称', required: true);
$store = $store->validate(Rules::notBlank('--store 不能为空'));

$clear = ActionDefinition::create('clear');
$clear->description('清理缓存');
$clear->option($store);

$warmup = ActionDefinition::create('warmup');
$warmup->description('预热缓存');
$warmup->option($store);

$command = CommandDefinition::create('cache');
$command->description('缓存管理');
$command->action($clear);
$command->action($warmup);
```

- [CacheCommand](examples/Cache/CacheCommand.php)：完整命令定义，分发及各 action 的独立方法。
- [MemoryCache](examples/Cache/MemoryCache.php)：演示用缓存依赖。
- [cache.php](examples/cache.php)：仅负责依赖组装、注册和执行的入口。

没有操作列表的命令直接使用 CommandDefinition::create('install') 定义，可在其上添加选项和位置参数。帮助从注册的定义读取，不调用工厂和 execute；execute 中的 Input::action() 为 null，无需伪造 default 操作。

## 目录结构

```text
src/
├── Application.php          # 应用入口与执行流程
├── CommandRegistry.php      # 命令注册与延迟创建
├── Contracts/               # 命令与组件注册契约
├── Definition/              # 命令、操作与共用输入规则
├── Input/                   # 解析结果、执行上下文与参数绑定
├── Help/                    # 顶层、命令及操作帮助
├── Validation/              # 共用类型转换与可复用校验规则
├── Event/                   # 提供给宿主的异常上下文
├── Console/
│   ├── Console.php          # 终端输出与交互入口
│   ├── Terminal.php         # 终端驱动及会话管理
│   ├── Color.php            # 颜色工具
│   ├── Live/                # 实时刷新状态与终端事件循环
│   ├── Selection/           # 选择状态、按键循环、选择项与结果
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

## 输入定义

- `Option::string()`、`integer()`、`number()` 和 `flag()` 将输入转换为对应类型。
- `->validate(fn ($value) => true)` 在类型转换后追加校验；返回错误字符串或 false 表示校验不通过。
- `Input::provided()` 区分用户未传入选项与显式传入选项。
- 支持 `--name=value` 和原有的 `-name=value`。带值选项使用 =，不支持通过空格分隔选项和值，也不支持合并多个短选项。
- 布尔开关支持 `--force`、`--force=true` 和 `--force=false`。
- 数值选项可声明省略值时使用的隐式值，例如 `Option::number('watch', implicit: 2)`。
- 重复选项取最后一次传入的值。未知选项和多余参数会返回输入错误。
- `Argument::string()`、`integer()`、`number()` 和 `boolean()` 声明带类型的位置参数，也支持原有的 `name=value` 命名参数形式。`--` 之后的内容按字面值作为位置参数处理。
- 命令和选项名称区分大小写，`help` 和 `--help` 为保留名称。

参数是否必传和参数值是否为空需要分别判断：

- required: true 且没有默认值：未传 --store 时返回输入错误。
- 只传 --store、不提供值：字符串选项会提示必须提供值。
- 传 --store= 或 --store="   "：通过 validate 拒绝空字符串和纯空白（包括全角空格）。
- validate 返回 true 表示通过；返回错误字符串或 false 表示失败。
- 字符串 "0" 是合法的非空值，示例不使用 empty() 判断。
- 校验在业务执行前完成，失败时不会清理或预热缓存。

输入校验和帮助处理早于命令工厂与执行中间件。读取命令定义时不应初始化应用或产生其他副作用。

### 组合校验规则

Option 和 Argument 的 validate 都返回新定义，必须接收返回值。多次调用会按顺序追加规则，首个失败立即停止；原定义可以独立复用。规则接收类型转换后的值，返回 true 表示通过，返回 false 或错误字符串表示失败。

```php
use EasySwoole\Command\Definition\{ActionDefinition, Argument, Option};
use EasySwoole\Command\Validation\Rules;

$store = Option::string('store', '缓存存储名称', required: true);
$store = $store->validate(Rules::notBlank('--store 不能为空'));
$store = $store->validate(Rules::oneOf(['default', 'redis'], '不支持的缓存存储'));

$count = Argument::integer('count', '处理数量', default: 100);
$count = $count->validate(Rules::range(1, 1000, '处理数量应在 1 到 1000 之间'));

$clear = ActionDefinition::create('clear');
$clear->option($store);
$clear->argument($count);
```

- notBlank 拒绝空字符串和 Unicode 纯空白，接受数字 0 和布尔值 false。
- oneOf 严格比较值和类型，例如整数参数应传入整数枚举列表。
- range 校验数值闭区间；定义时会检查上下界是否有效。
- 没有提供、没有默认值的可选字段绑定为 null，不执行值校验器。因此可选字段可以使用带 string 或 int 类型声明的回调。
- 默认值和省略选项值时使用的隐式值仍会执行类型转换与全部规则。布尔开关默认的 false 也会校验。
- required 判断是否必须提供值；要拒绝空字符串，应再添加 notBlank。

位置参数也会在执行前转换和校验。例如 count=3 或位置参数 3 都会绑定为整数；多余参数和转换失败返回退出码 2。

### 分层帮助

顶层帮助列出命令，命令分组帮助列出操作与参数。具体操作和独立命令的详细帮助增加 Usage、类型、实际必填标记、默认值、隐式值与示例。

交互终端中，命令和操作名称使用红色，标题使用绿色，参数名称使用黄色。输出重定向、设置 NO_COLOR 或 TERM=dumb 时自动使用纯文本；对齐按未着色文字计算。

```php
$clear->example('php examples/cache.php cache clear --store=redis', '清理 Redis 缓存');
```

example 保存完整调用形式，不参与解析。用 php examples/cache.php cache clear --help 查看详情；读取帮助不会创建业务实例或校验业务参数。

## 输入解析与执行结果

传给 run 和 dispatch 的参数不包含脚本名称。InputParser 返回 ParsedInput，分别保存命令定义、操作定义、原始名称和剩余参数，不创建业务实例。

dispatch 返回 CommandResult，不自动输出结果消息；run 将成功消息写到标准输出、错误消息写到标准错误，并返回退出码。命令也可通过 Console 主动输出进度。退出码：0 成功、1 执行错误、2 输入错误；pcntl 可用时，外部信号中断返回 128 + 信号编号。

## 组件注册与延迟工厂

组件实现 `CommandProviderInterface::register(CommandRegistry $registry): void`，在其中调用 `add()` 或 `register()` 注册命令。宿主应用通过 `$app->addProvider($provider)` 接入。依赖通过构造函数或工厂传入，组件无需了解宿主框架。

```php
$app->registry()->register(
    CacheCommand::createDefinition(), // 在命令类中实现这个静态定义工厂。
    fn () => new CacheCommand(new MemoryCache()),
);
```

按名称读取定义可使用 $app->registry()->definition('cache')，不存在时返回 null。返回值是隔离的定义快照，修改它不会影响注册内容；普通命令解析只复制匹配的定义，顶层列表才读取全部定义。

使用延迟注册时，让 `definition()` 调用同一个静态定义工厂。重复注册同名命令会立即报错，替换已有命令必须显式调用 `registry()->replace($definition, $factory)`。

执行中间件接收 `(CommandDefinition $definition, Input $input, Console $console, callable $next)`，返回 `CommandResult`。宿主应用可依据命令定义中的元数据初始化运行环境。中间件按注册顺序包裹命令执行，帮助请求和输入错误不会进入执行中间件。


一个顶层命令只需注册一次，其多个操作随定义注册。execute 通过 Input::action() 获取已经解析并校验的操作名称；独立命令的操作为 null。Input::command() 返回顶层名称。

中间件接收顶层 CommandDefinition，通过 actionDefinition($input->action()) 获取操作定义。宿主可以优先读取操作元数据，再回退到命令元数据。同一次分发中只创建一个命令实例，实时刷新可重复执行它。

## 观察执行异常

宿主可以通过 onException 接入自己的日志系统，无需让组件依赖日志库。观察器接收 ExceptionEvent，其中 error 是原始 Throwable，保留堆栈和 previous 异常链；command、action 与 input 表示已经成功解析或绑定的上下文。

```php
use EasySwoole\Command\Event\ExceptionEvent;

$app->onException(static function (ExceptionEvent $event) use ($logger): void {
    $logger->error($event->error->getMessage(), [
        'exception' => $event->error,
        'command' => $event->command,
        'action' => $event->action,
    ]);
});
```

解析、校验、工厂、中间件与命令执行中抛出的异常都会通知观察器。早期解析失败时 command 和 action 可能为 null；绑定未完成时 input 为 null。成功、帮助请求以及主动返回 CommandResult::failure 的结果不会产生异常事件。

可以注册多个观察器，按注册顺序调用。观察器自身抛出异常时继续通知其他观察器，保留原始错误消息与退出码；观察器不接管执行结果，也不用于恢复执行。

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

实时刷新由 LiveState 管理快照、刷新期限和重绘条件，由 LiveRunner 处理终端事件。选择交互由 SelectionState 管理搜索和多选，由 SelectionRunner 处理绘制与按键。Console 统一负责会话与降级，调用方式保持不变。业务查询仍是同步回调，调用方应为网络请求配置超时。

表格只处理可视区域内的行，并依据终端宽度分配列空间。长列会截断以保留其他列；极窄视口无法显示全部列时，仍保证输出不超过宽度。

输入输出流和 `Terminal` 均可注入，便于测试。`Terminal::now()` 提供可替换的单调时钟。同一时间只允许一个原始终端会话处于活动状态。

## 运行示例与测试

```sh
composer install
php examples/cache.php
php examples/cache.php cache
php examples/cache.php cache clear --store=default
php examples/cache.php cache warmup --store=redis
php examples/console.php demo console
php examples/console.php demo console --live
composer test
python3 tests/terminal_test.py
```

examples/cache.php 和 examples/console.php 只保留入口代码；命令和依赖分别放在 Cache/ 与 Console/ 目录，一份文件只定义一个类。缓存示例要求显式提供非空的 --store，可将演示内存缓存替换为实际业务依赖。测试需要 mbstring，无需第三方测试库或 Swoole。server、process 和 Bridge 等业务管理逻辑由宿主应用或其组件实现。
