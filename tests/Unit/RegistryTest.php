<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Contracts\CommandProviderInterface;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Result\CommandResult;

test('registration collisions and explicit replace', function () {
    $a = app(); raises(fn () => $a->addCommand(new DemoCommand()), LogicException::class);
    $a->registry()->replace((new DemoCommand())->definition(), fn () => new DemoCommand());
    same(0, $a->dispatch(['demo', 'run'])->exitCode);
});

test('registration definition snapshot', function () {
    $a = new Application(); $d = (new DemoCommand())->definition(); $a->registry()->register($d, fn () => new DemoCommand());
    $d->actions()['run']->option(Option::flag('later'));
    same(2, $a->dispatch(['demo', 'run', '--later'])->exitCode);
});

test('provider injection', function () {
    $a = new Application();
    $a->addProvider(new class implements CommandProviderInterface {
        public function register(CommandRegistry $registry): void { $registry->add(new DemoCommand()); }
    });
    same(0, $a->dispatch(['demo', 'run'])->exitCode);
});

test('provider registration rolls back additions and replacements after a collision', function () {
    $app = new Application();
    $original = new DemoCommand();
    $app->addCommand($original);

    $provider = new class implements CommandProviderInterface {
        public function register(CommandRegistry $registry): void
        {
            $partial = new CallbackCommand(CommandDefinition::create('partial'), static fn () => CommandResult::success());
            $registry->add($partial);

            $replacement = new DemoCommand();
            $definition = $replacement->definition()->description('Replacement');
            $registry->replace($definition, static fn () => $replacement);

            $registry->add(new DemoCommand());
        }
    };

    $message = null;
    try {
        $app->addProvider($provider);
    } catch (LogicException $error) {
        $message = $error->getMessage();
    }

    same('duplicate command: demo', $message);
    same(['demo'], array_keys($app->registry()->definitions()));
    same($original->definition()->summary(), $app->registry()->definition('demo')->summary());
    same($original, $app->registry()->create('demo'));
});

test('factory mismatch', function () {
    $a = new Application(); $a->registry()->register(CommandDefinition::create('bad'), fn () => new DemoCommand());
    same(1, $a->dispatch(['bad'])->exitCode);
});
