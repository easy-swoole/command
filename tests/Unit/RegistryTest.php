<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Contracts\CommandProviderInterface;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;

test('registration collisions and explicit replace', function () {
    $a = app(); raises(fn () => $a->addCommand(new DemoCommand()), LogicException::class);
    $a->registry()->replace((new DemoCommand())->definition(), fn () => new DemoCommand());
    same(0, $a->dispatch(['demo', 'run'])->exitCode);
});

test('registration definition snapshot', function () {
    $a = new Application(); $d = (new DemoCommand())->definition(); $a->registry()->register($d, fn () => new DemoCommand());
    $d->option(Option::flag('later'));
    same(2, $a->dispatch(['demo', 'run', '--later'])->exitCode);
});

test('provider injection', function () {
    $a = new Application();
    $a->addProvider(new class implements CommandProviderInterface {
        public function register(CommandRegistry $registry): void { $registry->add(new DemoCommand()); }
    });
    same(0, $a->dispatch(['demo', 'run'])->exitCode);
});

test('factory mismatch', function () {
    $a = new Application(); $a->registry()->register(CommandDefinition::named('bad'), fn () => new DemoCommand());
    same(1, $a->dispatch(['bad'])->exitCode);
});
