# 定义、读取和校验参数

本页沿用 [README 的 GreetCommand](../README.md#五分钟写出第一个命令)。**每个小节的示例独立使用**：定义片段放在 `definition()` 中、`return $definition` 之前；读取片段放在 `execute()` 中。

如果使用多个 Action，把 `$definition->option(...)`、`argument(...)`、`validateInput(...)` 改成对应 Action 的调用，例如 `$clear->option(...)`。

示例涉及以下类，按实际使用添加到命令文件顶部的 use 区域：

```php
use EasySwoole\Command\Definition\Argument;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Validation\Rules;
```

README 已经引入的类不用再次引入。

## 选项的类型

选项以横线开头，带值时使用 `=`。声明决定了 `$input->option()` 取得的 PHP 类型：

| 定义示例 | 命令行输入 | 读取结果 |
| --- | --- | --- |
| `Option::string('name')` | `--name=张三` | 字符串 `'张三'` |
| `Option::integer('count')` | `--count=12` | 整数 `12` |
| `Option::number('ratio')` | `--ratio=0.5` | 浮点数 `0.5` |
| `Option::flag('force')` | `--force` | 布尔值 `true` |
| 同一个 flag | `--force=false` | 布尔值 `false` |

例如添加处理数量和强制开关：

```php
$definition->option(Option::integer('count', '处理数量', default: 10));
$definition->option(Option::flag('force', '是否强制执行'));
```

执行：

```sh
php console.php greet --count=3 --force=false
```

在 `execute()` 中读取：

```php
$count = $input->option('count'); // 整数 3
$force = $input->option('force'); // false

if ($force) {
    $console->line('强制执行');
}
```

是否开启开关应检查其布尔值，不能只用 `provided('force')`：用户传 `--force=false` 时，确实提供了选项，但没有开启功能。

未传时，有默认值的选项使用默认值；无默认值的可选选项返回 null。flag 默认是 false。

## 必填不等于不能为空

`required: true` 检查是否提供参数；拒绝空字符串需要再增加值校验：

```php
$store = Option::string('store', '缓存存储名称', required: true);
$store = $store->validate(Rules::notBlank('--store 不能为空'));
$definition->option($store);
```

| 调用参数 | 结果 |
| --- | --- |
| 没有 `--store` | 缺少必填参数，退出码 2 |
| `--store` | 缺少值，退出码 2 |
| `--store=` | 非空校验失败，退出码 2 |
| `--store="   "` | 非空校验失败，退出码 2 |
| `--store=0` | 合法字符串 `'0'` |
| `--store=redis` | 合法字符串 `'redis'` |

校验失败时不会执行命令业务。建议必填参数不再同时配置默认值；如果配置了默认值，缺省输入会使用该默认值。

## 枚举、范围和自定义校验

`validate()` 返回新定义，**必须接收返回值**。规则在类型转换之后执行，多次添加按顺序执行，首条失败立即停止。

限制可选值：

```php
$mode = Option::string('mode', '运行环境', default: 'dev');
$mode = $mode->validate(Rules::oneOf(['dev', 'test', 'produce'], '不支持的环境'));
$definition->option($mode);
```

```sh
php console.php greet --mode=test
# 通过，$input->option('mode') 为 'test'

php console.php greet --mode=unknown
# 不支持的环境，退出码 2
```

限制数值范围，包含两个端点：

```php
$count = Option::integer('count', '处理数量', default: 10);
$count = $count->validate(Rules::range(1, 100, '处理数量应在 1 到 100 之间'));
$definition->option($count);
```

也可以提供自己的规则。将 README 中创建 name 的那一行替换为下面代码，其余代码（包括后面的 option($name)）保持不变：

```php
$name = Option::string('name', '称呼', default: '世界');
$name = $name->validate(static function (string $value): bool|string {
    return mb_strlen($value) <= 20 ? true : '称呼最多 20 个字符';
});
```

校验器接收到转换后的字符串，返回错误字符串时业务不会执行。

| 规则 | 说明 |
| --- | --- |
| `Rules::notBlank()` | 拒绝空字符串和 Unicode 纯空白，保留数字 0 和布尔值 false |
| `Rules::oneOf([...])` | 严格比较值和类型；整数参数的候选列表也应使用整数 |
| `Rules::range($min, $max)` | 数值闭区间，定义时检查上下界 |
| 自定义回调 | 返回 true 通过；返回 false 或错误字符串失败 |

默认值和隐式值同样执行值校验。没有提供、没有默认值的可选字段绑定为 null，并跳过值校验器，因此可选字段的回调也可以声明 `string`、`int` 等类型。

## 默认值与隐式值

默认值用于“没有传这个选项”；隐式值用于“传了选项，但省略了值”。数值选项可以声明隐式值：

```php
$interval = Option::number('interval', '刷新间隔', default: null, implicit: 2);
$interval = $interval->validate(Rules::range(0.1, 60, '间隔应在 0.1 到 60 秒之间'));
$definition->option($interval);
```

| 调用参数 | `$input->option('interval')` |
| --- | --- |
| 不传 | null |
| `--interval` | 浮点数 2.0 |
| `--interval=0.5` | 浮点数 0.5 |
| `--interval=` | 转换失败；空字符串不表示省略值 |

没有隐式值的选项，例如普通字符串选项，不允许只写 `--name`。默认值和隐式值在定义时检查类型，数值不允许 INF、-INF 或 NAN。

## 位置参数

位置参数不带横线，按定义顺序绑定，也支持 `name=value` 形式：

```php
$count = Argument::integer('count', '处理数量', required: true);
$count = $count->validate(Rules::range(1, 100, '数量应在 1 到 100 之间'));
$definition->argument($count);
```

下面两种调用都让 `$input->argument('count')` 返回整数 3：

```sh
php console.php greet 3
php console.php greet count=3
```

Argument 提供 `string()`、`integer()`、`number()`、`boolean()`，和 Option 一样支持 default、required 与 validate。布尔位置参数需要明确写出 true 或 false，没有“裸开关”。

多个位置参数中，实际必填的参数必须排在可选参数之前。有默认值的参数属于可选参数，即使同时写了 required: true。

如果希望接收以横线开头或包含等号的字面文本，先声明字符串位置参数，再使用 `--`：

```php
$definition->argument(Argument::string('path', '目标路径', required: true));
```

```sh
php console.php greet -- -cache=tmp
# $input->argument('path') 是 '-cache=tmp'
```

此处按本小节的 path 定义运行，不要同时保留前面 count 小节的定义。`--` 后面的内容不会再识别成选项或 name=value。

## 多值选项

需要接受多个目标时，显式开启 `multiple()`：

```php
$ids = Option::integer('id', '目标编号', required: true);
$ids = $ids->multiple();
$ids = $ids->validate(Rules::range(1, 1000, '编号应在 1 到 1000 之间'));
$definition->option($ids);
```

```sh
php console.php greet --id=1 --id=2 --id=1
# $input->option('id') 是 [1, 2, 1]
```

每次出现都要通过类型转换和校验，保持顺序及重复值。`--id=1,2` 不会自动拆成两个整数。

普通选项未开启 multiple 时，重复输入仍然取最后一次值。`multiple()` 和 `validate()` 一样返回新定义，原定义不变。

多值默认值的规则：

- 原定义无默认值：未传时返回 []。
- 原定义默认值为 3：调用 multiple() 后，未传时返回 [3]。
- 调用 multiple([1, 2])：将该列表作为默认值。
- required 且默认列表为空：至少提供一个值。
- flag 原本默认 false，因此 multiple() 保留为 [false]；需要空列表时显式使用 multiple([])。

规则对每个元素执行。要检查整个列表的数量或与其他字段的关系，使用下面的整组校验。

## 多个字段之间的校验

例如 `--all` 和 `--store` 不能同时使用。放在定义方法中：

```php
$definition->option(Option::flag('all', '处理全部存储'));
$definition->option(Option::string('store', '指定存储'));

$definition->validateInput(static function (Input $input): bool|string {
    if ($input->option('all') && $input->provided('store')) {
        return '--all 与 --store 不能同时使用';
    }
    return true;
});
```

```sh
php console.php greet --all
# 通过

php console.php greet --all --store=redis
# --all 与 --store 不能同时使用，退出码 2
```

整组规则在所有字段完成绑定、类型转换和单字段校验后执行，早于工厂和执行中间件。可多次追加，首条失败停止。帮助请求不会执行规则。

Option 和 Argument 的 validate 返回新对象；CommandDefinition 和 ActionDefinition 的 validateInput 则修改当前定义，不必重新赋值。

## 区分默认值和用户输入

下面代码放在 `execute()` 中，沿用 README 默认的 name 选项：

```php
$name = $input->option('name');
$provided = $input->provided('name');
```

| 调用 | name | provided |
| --- | --- | --- |
| `php console.php greet` | `'世界'` | false |
| `php console.php greet --name=世界` | `'世界'` | true |

`provided()` 与 `optionProvided()` 都只判断选项。位置参数使用 `argumentProvided()`，位置形式和 name=value 形式都会记录。可用 `options()`、`arguments()` 取得全部绑定值。

## 输入格式速查

- 支持 `--name=value` 和旧式 `-name=value`；不支持 `--name value`。
- 值包含空格时使用 Shell 引号，例如 `--name="张 三"`。
- flag 支持裸开关及显式 true/false；不支持把多个短选项合并。
- 选项可以出现在命令名称前，但建议按“命令、Action、参数”的顺序书写。
- 未知选项、未知命名参数、多余位置参数、通过 name=value 重复赋值同一个位置参数会报输入错误。
- `---name` 等非法选项前缀会报错。普通重复选项最后一次生效，多值选项收集全部值。
- 参数绑定与校验失败时退出码为 2，不会创建延迟业务实例。
