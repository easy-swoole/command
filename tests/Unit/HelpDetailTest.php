<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\Argument;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Result\CommandResult;

test('detailed action help includes usage types defaults and examples without constructing commands', function () {
    $action = ActionDefinition::create('run');
    $action->option(Option::string('store', required: true));
    $action->option(Option::integer('count', default: 0));
    $action->option(Option::flag('force'));
    $action->option(Option::number('watch', implicit: 2));
    $action->argument(Argument::number('ratio', default: 1.5));
    $action->example('demo run --store=redis', '运行示例');
    $definition = CommandDefinition::create('demo')->action($action);
    $app = new Application();
    $app->registry()->register($definition, static fn () => throw new LogicException('factory must not run'));
    $result = $app->dispatch(['demo', 'run', '--help']);
    same(0, $result->exitCode);
    foreach (['Usage: demo run', '--store=<string>', '(required)', '--count=<int>', '[default: 0]',
        '--force[=bool]', '[default: false]', '--watch[=<float>]', '[implicit: 2.0]',
        '<ratio:float>', '[default: 1.5]', 'Examples:', 'demo run --store=redis', '运行示例'] as $text) {
        same(true, str_contains($result->message, $text));
    }
    $group = $app->dispatch(['demo'])->message;
    same(false, str_contains($group, 'Usage:'));
    same(false, str_contains($group, '[default:'));
});

test('standalone help includes typed positional usage and quoted empty defaults', function () {
    $definition = CommandDefinition::create('install');
    $definition->option(Option::string('prefix', default: ''));
    $definition->argument(Argument::integer('workers', required: true));
    $app = new Application();
    $app->addCommand(new CallbackCommand($definition, static fn () => CommandResult::success()));
    $result = $app->dispatch(['install', '--help']);
    same(0, $result->exitCode);
    same(true, str_contains($result->message, '<workers:int>'));
    same(true, str_contains($result->message, '[default: ""]'));
});

test('single command lookup returns isolated action and example snapshots', function () {
    $definition = CommandDefinition::create('demo')->action(ActionDefinition::create('run')->example('demo run', 'original'));
    $app = new Application();
    $app->registry()->register($definition, static fn () => throw new LogicException('factory must not run'));
    $snapshot = $app->registry()->definition('demo');
    $snapshot->actions()['run']->example('demo run --other');
    same(1, count($app->registry()->definition('demo')->actions()['run']->examples()));
    same(null, $app->registry()->definition('missing'));
    raises(fn () => $definition->example('   '), InvalidArgumentException::class);
});

test('help renders repeated input and substitutes invalid UTF8 defaults', function () {
    $definition = CommandDefinition::create('demo')->option(Option::integer('id', required: true)->multiple())
        ->option(Option::string('prefix', default: "\xff"));
    $app = new Application();
    $app->registry()->register($definition, static fn () => throw new LogicException('help must not create a command'));
    $result = $app->dispatch(['demo', '--help']);
    same(0, $result->exitCode);
    same(true, str_contains($result->message, '--id=<int>...'));
    same(true, str_contains($result->message, '[multiple] (required)'));
    same(true, str_contains($result->message, '�'));
});
