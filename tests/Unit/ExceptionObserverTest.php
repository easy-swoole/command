<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Event\ExceptionEvent;
use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Exception\InterruptedException;
use EasySwoole\Command\Result\CommandResult;

test('observer receives the original exception chain and bound action context', function () {
    $cause = new LogicException('root cause');
    $error = new RuntimeException('failed', 0, $cause);
    $definition = CommandDefinition::create('cache')->action(ActionDefinition::create('clear')->option(Option::integer('count', default: 3)));
    $app = new Application();
    $app->addCommand(new CallbackCommand($definition, static fn () => throw $error));
    $seen = [];
    $app->onException(function (ExceptionEvent $event) use (&$seen) { $seen[] = $event; });
    $result = $app->dispatch(['cache', 'clear']);
    same(1, $result->exitCode); same('failed', $result->message);
    same(1, count($seen)); same($error, $seen[0]->error); same($cause, $seen[0]->error->getPrevious());
    same('cache', $seen[0]->command); same('clear', $seen[0]->action);
    same(3, $seen[0]->input->option('count'));
    same(true, $seen[0]->error->getTrace() !== []);
});

test('observer failure neither replaces the result nor skips other observers', function () {
    $app = new Application();
    $error = new RuntimeException('original');
    $app->registry()->register(CommandDefinition::create('run'), static fn () => throw $error);
    $seen = [];
    $app->onException(static fn () => throw new LogicException('observer failed'));
    $app->onException(function ($event) use (&$seen) { $seen[] = $event->error; });
    $result = $app->dispatch(['run']);
    same('original', $result->message); same(1, $result->exitCode); same([$error], $seen);
});

test('observer distinguishes validation failure from early parse failure', function () {
    $definition = CommandDefinition::create('run')->option(Option::integer('count', required: true));
    $app = new Application();
    $app->addCommand(new CallbackCommand($definition, static fn () => CommandResult::success()));
    $seen = [];
    $app->onException(function ($event) use (&$seen) { $seen[] = $event; });
    same(2, $app->dispatch(['run', '--count=invalid'])->exitCode);
    same('run', $seen[0]->command); same(null, $seen[0]->input);
    same(true, $seen[0]->error instanceof InputException);
    same(2, $app->dispatch([123])->exitCode);
    same(null, $seen[1]->command); same(null, $seen[1]->action); same(null, $seen[1]->input);
});

test('success and help do not notify exception observers', function () {
    $app = app(); $seen = [];
    $app->onException(function ($event) use (&$seen) { $seen[] = $event; });
    same(0, $app->dispatch([])->exitCode);
    same(0, $app->dispatch(['demo', 'run', '--help'])->exitCode);
    same(0, $app->dispatch(['demo', 'run'])->exitCode);
    same([], $seen);
});

test('interruptions preserve their exit code and original throwable', function () {
    $app = new Application();
    $error = new InterruptedException(15);
    $app->registry()->register(CommandDefinition::create('run'), static fn () => throw $error);
    $seen = [];
    $app->onException(function ($event) use (&$seen) { $seen[] = $event->error; });
    same(143, $app->dispatch(['run'])->exitCode);
    same([$error], $seen);
});
