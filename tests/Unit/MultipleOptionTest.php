<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;
use EasySwoole\Command\Validation\Rules;

test('multiple options collect only declared repeated values and retain order', function () {
    $ids = Option::integer('id')->multiple()->validate(Rules::range(1, 10));
    $action = ActionDefinition::create('run')->option($ids)->option(Option::string('name'));
    $app = new Application();
    $app->addCommand(new CallbackCommand(CommandDefinition::create('demo')->action($action),
        static fn (Input $input) => CommandResult::success(data: [$input->options(), $input->provided('id')])));
    same([['id' => [1, 2, 1], 'name' => 'last'], true],
        $app->dispatch(['demo', 'run', '--id=01', '-id=2', '--id=1', '--name=first', '--name=last'])->data);
    same([['id' => [], 'name' => null], false], $app->dispatch(['demo', 'run'])->data);
    same(2, $app->dispatch(['demo', 'run', '--id=1', '--id=invalid'])->exitCode);
    same(2, $app->dispatch(['demo', 'run', '--id=1', '--id=0'])->exitCode);
    same(2, $app->dispatch(['demo', 'run', '--id=1', '--id'])->exitCode);
    same(2, $app->dispatch(['demo', 'run', '--id=1,2'])->exitCode);
});

test('multiple definitions retain immutability defaults and item validator semantics', function () {
    $base = Option::integer('id', default: 2);
    $multiple = $base->multiple();
    same(2, $base->bind(null, false));
    same([2], $multiple->bind(null, false));
    same([3, 4], $multiple->multiple([3, 4])->bind(null, false));
    same([2], $multiple->bind(null, false));
    same([], Option::string('tag')->multiple()->validate(static fn (string $value) => true)->bind(null, false));
    $calls = [];
    $option = Option::integer('id')->multiple([1, 2])->validate(function (int $value) use (&$calls) { $calls[] = $value; return true; });
    same([1, 2], $option->bind(null, false)); same([1, 2], $calls);
    same([true, false], Option::flag('enabled')->multiple([])->bind([null, 'false'], true));
    same([2.0, 3.0], Option::number('interval', implicit: 2)->multiple()->bind([null, '3'], true));
});

test('required multiple options require a value or nonempty defaults', function () {
    $option = Option::integer('id', required: true)->multiple();
    same(true, $option->isRequired());
    raises(fn () => $option->bind(null, false), InputException::class);
    raises(fn () => $option->bind([], true), InputException::class);
    same([1], $option->bind(['1'], true));
    same(false, $option->multiple([1])->isRequired());
    same([1], $option->multiple([1])->bind(null, false));
});

test('multiple options validate definitions and all default items early', function () {
    raises(fn () => Option::integer('id')->multiple(['invalid']), InvalidArgumentException::class);
    raises(fn () => Option::integer('id')->multiple([1, null]), InvalidArgumentException::class);
    raises(fn () => Option::number('id')->multiple([1, INF]), InvalidArgumentException::class);
    raises(fn () => Option::number('id')->multiple([NAN]), InvalidArgumentException::class);
    $option = Option::integer('id')->multiple([0])->validate(Rules::range(1, 5));
    raises(fn () => $option->bind(null, false), InputException::class);
});
