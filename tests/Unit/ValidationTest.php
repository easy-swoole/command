<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Definition\Argument;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Input\InputBinder;
use EasySwoole\Command\Result\CommandResult;
use EasySwoole\Command\Validation\Rules;

test('option rules accumulate in order and stop at first failure', function () {
    $calls = [];
    $base = Option::string('name');
    $first = $base->validate(function (string $value) use (&$calls) { $calls[] = 1; return $value !== '' ? true : 'empty'; });
    $second = $first->validate(function (string $value) use (&$calls) { $calls[] = 2; return strlen($value) < 4 ? true : 'long'; });
    same('', $base->bind('', true));
    try { $second->bind('', true); } catch (InputException $error) { same('empty', $error->getMessage()); }
    same([1], $calls);
    $calls = [];
    same('abc', $second->bind('abc', true)); same([1, 2], $calls);
    $calls = [];
    same('abcde', $first->bind('abcde', true)); same([1], $calls);
    raises(fn () => $second->bind('abcde', true), InputException::class);
});

test('optional null skips typed rules while defaults are validated', function () {
    $calls = 0;
    $rule = function (int $value) use (&$calls): bool|string { ++$calls; return $value > 0 ? true : 'positive only'; };
    same(null, Option::integer('count')->validate($rule)->bind(null, false)); same(0, $calls);
    same(3, Option::integer('count', default: 3)->validate($rule)->bind(null, false)); same(1, $calls);
    raises(fn () => Option::integer('count', default: 0)->validate($rule)->bind(null, false), InputException::class);
    same(2, $calls);
    raises(fn () => Option::integer('count')->validate($rule)->bind(null, true), InputException::class);
    same(2, $calls);
    same(false, Option::flag('force')->validate(static fn (bool $value) => true)->bind(null, false));
});

test('required options do not invoke value rules when absent', function () {
    $calls = 0;
    $option = Option::string('name', required: true)->validate(function (string $value) use (&$calls) { ++$calls; return true; });
    raises(fn () => $option->bind(null, false), InputException::class);
    same(0, $calls);
    same('', $option->bind('', true));
    same(1, $calls);
    same(5.0, Option::number('timeout', default: 5, required: true)->validate(Rules::range(1, 10))->bind(null, false));
});

test('not blank rule rejects Unicode whitespace without rejecting zero or false', function () {
    $rule = Rules::notBlank('不能为空');
    foreach ([null, '', ' ', '　', "\u{00a0}", "\xff"] as $value) { same('不能为空', $rule($value)); }
    foreach (['0', 0, false, '正常', '  x  '] as $value) { same(true, $rule($value)); }
});

test('choice rules compare converted values using strict types', function () {
    $option = Option::integer('level')->validate(Rules::oneOf([1, 2], '非法级别'));
    same(1, $option->bind('01', true));
    raises(fn () => $option->bind('3', true), InputException::class);
    same('invalid', Rules::oneOf([1], 'invalid')('1'));
    same('invalid', Rules::oneOf(['Dev'], 'invalid')('dev'));
});

test('range includes endpoints and rejects invalid definitions', function () {
    $rule = Rules::range(-2, 2.5, '超出范围');
    foreach ([-2, 0, 2.5] as $value) { same(true, $rule($value)); }
    foreach ([-3, 3, '1', false, NAN, INF] as $value) { same('超出范围', $rule($value)); }
    raises(fn () => Rules::range(3, 1), InvalidArgumentException::class);
    raises(fn () => Rules::range(NAN, 1), InvalidArgumentException::class);
    raises(fn () => Rules::range(0, INF), InvalidArgumentException::class);
});

test('positional arguments use the same typed converters', function () {
    $definition = CommandDefinition::create('convert');
    $definition->argument(Argument::integer('count', required: true));
    $definition->argument(Argument::number('ratio', required: true));
    $definition->argument(Argument::boolean('enabled', required: true));
    $definition->argument(Argument::string('name', required: true));
    $app = new Application();
    $app->addCommand(new CallbackCommand($definition, static fn ($input) => CommandResult::success(data: [
        $input->argument('count'), $input->argument('ratio'), $input->argument('enabled'), $input->argument('name'),
    ])));
    same([-12, 1.25, false, '测试'], $app->dispatch(['convert', '--', '-0012', '1.25', 'false', '测试'])->data);
    same([2, 1.0, true, 'x'], $app->dispatch(['convert', 'count=2', 'ratio=1', 'enabled=true', 'name=x'])->data);
    same(2, $app->dispatch(['convert', '--', 'invalid', '1', 'true', 'x'])->exitCode);
});

test('argument rules accumulate and optional null is skipped', function () {
    $arg = Argument::string('name')->validate(Rules::notBlank('空值'))->validate(Rules::oneOf(['dev', 'test'], '非法名称'));
    same(null, $arg->bind(null, false));
    same('dev', $arg->bind('dev', true));
    raises(fn () => $arg->bind('', true), InputException::class);
    raises(fn () => $arg->bind('prod', true), InputException::class);
    same(3, Argument::integer('count', default: 3)->validate(Rules::range(1, 5))->bind(null, false));
    raises(fn () => Argument::integer('count', default: 0)->validate(Rules::range(1, 5))->bind(null, false), InputException::class);
    raises(fn () => Argument::string('name', required: true)->bind(null, false), InputException::class);
});

test('argument validation fails before command execution', function () {
    $definition = CommandDefinition::create('run');
    $definition->argument(Argument::integer('count', required: true)->validate(Rules::range(1, 5)));
    $executions = 0;
    $app = new Application();
    $app->addCommand(new CallbackCommand($definition, function ($input) use (&$executions) { ++$executions; return CommandResult::success(); }));
    foreach ([[], ['0'], ['6'], ['abc'], ['count=']] as $values) {
        same(2, $app->dispatch(array_merge(['run'], $values))->exitCode);
    }
    same(0, $executions);
    same(0, $app->dispatch(['run', 'count=3'])->exitCode);
    same(1, $executions);
});

test('argument definition rejects unsupported types', function () {
    raises(fn () => new Argument('x', type: 'unknown'), InvalidArgumentException::class);
});

test('whole input rules see bound typed inputs and stop before factory or middleware', function () {
    $action = ActionDefinition::create('run')->option(Option::integer('count', default: 2))
        ->argument(Argument::integer('limit', default: 1));
    $calls = [];
    $action->validateInput(function (Input $input) use (&$calls): bool|string {
        $calls[] = [$input->command(), $input->action(), $input->option('count'), $input->argument('limit')];
        return $input->option('count') <= $input->argument('limit') ? true : 'count exceeds limit';
    });
    $action->validateInput(function (Input $input) use (&$calls) { $calls[] = 'second'; return true; });
    $app = new Application(); $created = 0; $initialized = 0;
    $definition = CommandDefinition::create('demo')->action($action);
    $app->registry()->register($definition, function () use (&$created, $definition) {
        ++$created; return new CallbackCommand($definition, static fn () => CommandResult::success());
    });
    $app->middleware(function ($d, $i, $c, $next) use (&$initialized) { ++$initialized; return $next(); });
    same(2, $app->dispatch(['demo', 'run'])->exitCode);
    same([['demo', 'run', 2, 1]], $calls); same(0, $created); same(0, $initialized);
    $calls = [];
    same(0, $app->dispatch(['demo', 'run', 'limit=3'])->exitCode);
    same([['demo', 'run', 2, 3], 'second'], $calls); same(1, $created); same(1, $initialized);
    $calls = [];
    same(0, $app->dispatch(['demo', 'run', '--help'])->exitCode); same([], $calls);
    same(2, $app->dispatch(['demo', 'run', '--count=invalid'])->exitCode); same([], $calls);
});

test('whole rules support standalone commands and are isolated in snapshots', function () {
    $definition = CommandDefinition::create('demo')->validateInput(static fn (Input $input) => true);
    $app = new Application();
    $app->addCommand(new CallbackCommand($definition, static fn () => CommandResult::success()));
    $definition->validateInput(static fn () => 'must not leak');
    $snapshot = $app->registry()->definition('demo');
    $snapshot->validateInput(static fn () => 'must not leak either');
    same(0, $app->dispatch(['demo'])->exitCode);
    $invalid = CommandDefinition::create('invalid')->validateInput(static fn () => false);
    raises(fn () => (new InputBinder())->bind($invalid, []), InputException::class);
    raises(fn () => CommandDefinition::create('group')->action(ActionDefinition::create('run'))->validateInput(static fn () => true), LogicException::class);
    raises(fn () => CommandDefinition::create('group')->validateInput(static fn () => true)->action(ActionDefinition::create('run')), LogicException::class);
});

test('numeric defaults and implicit values reject nonfinite values during definition', function () {
    foreach ([INF, -INF, NAN] as $value) {
        raises(fn () => Option::number('n', default: $value), InvalidArgumentException::class);
        raises(fn () => Option::number('n', implicit: $value), InvalidArgumentException::class);
        raises(fn () => Argument::number('n', default: $value), InvalidArgumentException::class);
        raises(fn () => new Argument('n', default: $value, type: 'float'), InvalidArgumentException::class);
    }
    same(2.0, (new Argument('n', default: '2', type: 'float'))->default);
    same('3', (new Argument('n', default: 3))->default);
    raises(fn () => new Argument('n', default: []), InvalidArgumentException::class);
    // 自定义规则仍在分发时执行，避免读取定义产生业务副作用。
    $calls = 0;
    Option::integer('n', default: 0)->validate(function () use (&$calls) { ++$calls; return false; });
    same(0, $calls);
});

test('effective optional defaults cannot precede required positional arguments', function () {
    raises(fn () => CommandDefinition::create('demo')->argument(Argument::string('first', default: 'x', required: true))
        ->argument(Argument::string('last', required: true)), LogicException::class);
});
