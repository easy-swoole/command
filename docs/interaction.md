# 输出、交互和自动化脚本

除特别说明外，本页片段放在命令的 `execute(Input $input, Console $console)` 内，替换原来直接返回问候的主体。`$console` 就是 execute 的第二个参数，无需自己创建。

可先运行仓库里的完整交互示例：

```sh
php examples/console.php demo console
php examples/console.php demo console --live
```

## 文字、颜色和表格

普通输出：

```php
$console->line('开始处理');
$console->info('已读取配置');
$console->error('这里是一条诊断信息');
return CommandResult::success('处理完成');
```

line 和 info 默认写 stdout，error 写 stderr；error 只输出文字，不会自行终止命令或设置失败退出码。需要失败时返回 `CommandResult::failure(...)`。

需要颜色时，在命令文件顶部引入 Color：

```php
use EasySwoole\Command\Console\Color;
```

在 execute 中使用：

```php
$console->line(Color::green('运行正常'));
$console->line(Color::red('连接失败'));
$console->line(Color::yellow('等待处理'));
return CommandResult::success();
```

表格直接传表头和每一行的单元格：

```php
$console->table(['名称', '状态'], [
    ['worker-1', 'running'],
    ['worker-2', 'waiting'],
]);
return CommandResult::success();
```

颜色可用于单元格。列宽按可见文字计算，支持中文，窄视口会截断内容以保留其他列。`NO_COLOR`、`TERM=dumb` 或输出重定向时移除颜色；内容中的清屏、移动光标等控制序列不会被执行。

## 提问和确认

提问返回输入的字符串；输入流结束时返回 null：

```php
$name = $console->prompt('请输入称呼：');
if ($name === null) {
    return CommandResult::failure('输入已关闭');
}
return CommandResult::success('你好，' . trim($name));
```

确认返回布尔值，下面示例默认不执行：

```php
if (!$console->confirm('确认继续？', default: false)) {
    return CommandResult::success('已取消');
}
return CommandResult::success('已确认');
```

空输入使用 default，输入流关闭返回 false。不合法的回答会继续询问。没有可用交互终端或显式禁止交互时会报告错误，不会因为设置 default: true 就自动确认。

## 单选、多选和搜索

在命令文件顶部引入 Choice：

```php
use EasySwoole\Command\Console\Selection\Choice;
```

先定义候选，再选择和读取业务值：

```php
$choices = [
    new Choice('dev', '开发环境', ['mode' => 'dev'], '本机调试'),
    new Choice('prod', '生产环境', ['mode' => 'produce'], '正式服务'),
];

$result = $console->select('选择环境', $choices);
if ($result->cancelled()) {
    return CommandResult::success('已取消或输入已结束');
}
$target = $result->values()[0];
return CommandResult::success('已选择 ' . $target['mode']);
```

Choice 的四个构造参数依次是：稳定 ID、展示名称、原始业务值、可选说明。ID 必须唯一；业务值可以是字符串、数组或对象，不需要按展示序号重新查找。

需要多选时，只改选择这一行：

```php
$result = $console->select('选择环境', $choices, multiple: true);
```

values() 返回已选业务值的列表，单选也返回列表。choices 属性返回已选 Choice 对象，可以读取其 id 或 label。

如果业务要区分用户取消与输入流关闭，检查 state：

```php
if ($result->state === 'eof') {
    return CommandResult::failure('输入流已关闭');
}
if ($result->state === 'cancelled') {
    return CommandResult::success('用户取消');
}
// state 为 selected，继续处理 $result->values()。
```

| 按键 | 行为 |
| --- | --- |
| ↑ / ↓ | 移动光标 |
| Home / End | 首项 / 最后的取消项 |
| PageUp / PageDown | 按可见页移动 |
| 空格 | 切换当前项；单选时只保留当前项 |
| a | 多选模式中切换所有可见项 |
| / | 进入搜索，支持中文；回车回到导航模式 |
| 回车 | 确认；光标停在取消项时取消 |
| q / Esc / Ctrl+C | 取消；搜索时 q 可作为搜索文字 |

多选时若尚未勾选任何项，直接回车会选择当前项；若已有勾选项则返回勾选结果。过滤搜索不会丢失已经勾选但暂时隐藏的项。小终端使用紧凑布局，无法使用原始按键模式时会退回编号选择。

## 实时输出

在命令文件顶部引入 TableView；需要彩色状态时同时引入 Color：

```php
use EasySwoole\Command\Console\View\TableView;
use EasySwoole\Command\Console\Color;
```

下面示例每半秒生成一张新表，五次后结束，也可以按 q 提前退出：

```php
$frame = 0;
$console->live(function () use (&$frame) {
    if ($frame >= 5) {
        return false;
    }
    $row = [++$frame, Color::green('运行中'), date('H:i:s')];
    return new TableView(['刷新次数', '状态', '时间'], [$row]);
}, interval: 0.5);

return CommandResult::success('视图已结束');
```

刷新回调返回以下三种结果之一：

| 返回值 | 行为 |
| --- | --- |
| 字符串 | 绘制文本，也支持跨行颜色 |
| `ViewInterface` 实例，例如 TableView | 按当前视口渲染 |
| `false` | 结束实时视图 |

interval 必须是大于 0 的有限数值。按 q、Esc、Ctrl+C 结束视图。窗口缩放会重绘已有快照，不会额外调用业务查询。

回调应返回内容，不能在回调里调用 line、table、prompt、select 或另一个 live。需要下一步选择或确认时，先结束实时视图，再调用对应方法。业务查询是同步调用，慢请求的超时由业务自己控制。

实时视图要求输入、输出都支持终端交互。POSIX 下需要 stty；`TERM=dumb` 不支持实时视图。`NO_COLOR=1` 只禁用颜色，不禁止动态刷新。

## 禁止交互

本节改的是 README 的入口文件 console.php。保留加载和注册代码，在文件顶部引入 Console，再替换最后的 exit 调用：

```php
use EasySwoole\Command\Console\Console;

$console = new Console(allowInteraction: false);
exit($app->run(array_slice($argv, 1), $console));
```

即使 stdin 是 TTY，调用 prompt、confirm、select 等需要交互的方法也会报告错误；live 不会启动，也不会调用刷新回调。普通文字和表格输出不受影响。

适用于 CI、定时任务以及希望完全由参数驱动的调用。`allowInteraction` 是 PHP 配置，不会自动增加 `--no-interaction` 命令行选项。

## JSON 输出

仍然只修改入口最后的 Console 配置：

```php
use EasySwoole\Command\Console\Console;

$console = new Console(json: true);
exit($app->run(array_slice($argv, 1), $console));
```

现在执行：

```sh
php console.php greet --name=张三
```

stdout 中是一份 JSON：

```json
{"exitCode":0,"message":"你好，张三！","data":null}
```

命令返回 `CommandResult::success('已处理', data: ['count' => 3])` 时，data 就是对象 `{"count":3}`。失败、输入错误和帮助同样使用 JSON 输出，并保持对应退出码。

命令中通过 Console 的 line、info、error 输出的进度和诊断会写到 stderr，不混入结果。可以分别保存两个输出流：

```sh
php console.php greet --name=张三 > result.json 2> progress.log
```

JSON 模式不输出颜色，并禁止交互。data 应能直接 JSON 编码；编码失败时 stdout 不写部分 JSON，错误写 stderr，退出码为 1。不通过 Console 的直接 echo 不受流分离控制。

json 也是 PHP 配置，不是组件预留的命令行参数；是否向用户提供 `--json`，由宿主决定。

## 取消、异常与终端恢复

普通业务使用 Console 即可，原始终端会话由组件自动管理。正常结束、异常和已处理的外部信号都会进入清理流程，恢复输入模式、光标和屏幕。

如果自己扩展 Terminal 并直接操作它，使用 try/finally 配对 open 与 close。输入模式恢复失败会抛出 TerminalException，并保留原始模式；修复原因后可以再次 close。恢复成功前不能重新 open 同一个 Terminal。退出清理最多再尝试一次，不循环重试。

pcntl 为可选扩展，用于外部信号的清理与退出码。键盘取消和普通命令不依赖 pcntl。输入输出流和 Terminal 注入方式见[开发说明](development.md#在测试中替换输入输出)。
