<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\Argument;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Input\InputParser;
use EasySwoole\Command\Input\InputBinder;
use EasySwoole\Command\Result\CommandResult;

function cacheApplication(): array
{
    $cache = new class implements CommandInterface {
        public array $calls = [];
        public function definition(): CommandDefinition {
            return CommandDefinition::create('cache')->description('Cache manager')
                ->action(ActionDefinition::create('clear')->description('Clear cache')
                    ->option(Option::string('store', 'Cache store', default: 'default')))
                ->action(ActionDefinition::create('warmup')->description('Warm up cache')
                    ->option(Option::integer('count', 'Item count', required: true)));
        }
        public function execute(Input $input, Console $console): CommandResult {
            $this->calls[] = [$input->command(), $input->action()];
            return match ($input->action()) {
                'clear' => CommandResult::success('cleared', $input->option('store')),
                'warmup' => CommandResult::success('warmed', $input->option('count')),
                default => throw new LogicException('undefined operation'),
            };
        }
    };
    $app = new Application('Test');
    $app->addCommand($cache);
    $app->addCommand(new class implements CommandInterface {
        public function definition(): CommandDefinition {
            return CommandDefinition::create('install')->description('Install application')
                ->argument(new Argument('target', default: 'default'));
        }
        public function execute(Input $input, Console $console): CommandResult {
            return CommandResult::success('installed', [$input->command(), $input->action(), $input->argument('target')]);
        }
    });
    return [$app, $cache];
}

test('one command instance handles multiple distinct actions', function () {
    [$app, $cache] = cacheApplication();
    same('redis', $app->dispatch(['cache', 'clear', '--store=redis'])->data);
    same(3, $app->dispatch(['cache', 'warmup', '--count=3'])->data);
    same([['cache', 'clear'], ['cache', 'warmup']], $cache->calls);
    same(2, count($app->registry()->definitions()));
});

test('root help lists top level commands without action details', function () {
    [$app, $cache] = cacheApplication();
    $text = $app->dispatch([])->message;
    same(true, str_contains($text, 'current support command is:'));
    same(true, str_contains($text, 'Cache manager'));
    same(true, str_contains($text, 'Install application'));
    same(false, str_contains($text, 'Clear cache'));
    same(false, str_contains($text, '--store'));
    same([], $cache->calls);
});

test('command help lists all actions and their own options', function () {
    [$app, $cache] = cacheApplication();
    foreach ([['cache'], ['cache', '--help'], ['help', 'cache']] as $argv) {
        $result = $app->dispatch($argv);
        same(0, $result->exitCode);
        foreach (['current support action is:', 'clear', 'Clear cache', '--store', 'warmup', '--count'] as $word) {
            same(true, str_contains($result->message, $word));
        }
    }
    same([], $cache->calls);
});

test('action help bypasses required input and excludes sibling actions', function () {
    [$app, $cache] = cacheApplication();
    foreach ([['cache', 'warmup', '--help'], ['help', 'cache', 'warmup']] as $argv) {
        $result = $app->dispatch($argv);
        same(0, $result->exitCode);
        same(true, str_contains($result->message, '--count'));
        same(false, str_contains($result->message, '--store'));
    }
    same([], $cache->calls);
});

test('standalone command executes without a synthetic default action', function () {
    [$app] = cacheApplication();
    same(['install', null, 'default'], $app->dispatch(['install'])->data);
    same(['install', null, 'project'], $app->dispatch(['install', 'project'])->data);
    same(true, str_contains($app->dispatch(['install', '--help'])->message, 'Install application'));
});

test('action options and case sensitive names cannot leak to siblings', function () {
    [$app, $cache] = cacheApplication();
    foreach ([['cache', 'clear', '--count=3'], ['cache', 'warmup', '--store=redis'],
        ['cache', 'warmup'], ['cache', 'Clear'], ['cache', 'missing', '--help'], ['cache', '--', 'clear']] as $argv) {
        same(2, $app->dispatch($argv)->exitCode);
    }
    same([], $cache->calls);
});

test('action definitions are isolated from later mutations and returned snapshots', function () {
    $action = ActionDefinition::create('clear')->description('Original');
    $definition = CommandDefinition::create('cache')->action($action);
    $registry = new \EasySwoole\Command\CommandRegistry();
    $registry->register($definition, fn () => throw new LogicException());
    $action->description('Changed before registry');
    $definition->actions()['clear']->description('Changed after registry');
    $snapshot = $registry->definitions()['cache'];
    same('Original', $snapshot->actions()['clear']->summary());
    $snapshot->actions()['clear']->description('Changed snapshot');
    same('Original', $registry->definitions()['cache']->actions()['clear']->summary());
});

test('duplicate actions and ambiguous standalone input are rejected', function () {
    raises(fn () => CommandDefinition::create('cache')->action(ActionDefinition::create('clear'))->action(ActionDefinition::create('clear')), LogicException::class);
    raises(fn () => CommandDefinition::create('cache')->option(Option::flag('d'))->action(ActionDefinition::create('clear')), LogicException::class);
    raises(fn () => CommandDefinition::create('cache')->action(ActionDefinition::create('clear'))->option(Option::flag('d')), LogicException::class);
    raises(fn () => CommandDefinition::create('cache')->action(ActionDefinition::create('clear'))->argument(new Argument('target')), LogicException::class);
    foreach (['cache clear', '', 'help'] as $name) { raises(fn () => CommandDefinition::create($name), InvalidArgumentException::class); }
    raises(fn () => CommandDefinition::create('cache', 'clear'), InvalidArgumentException::class);
    raises(fn () => ActionDefinition::create('clear', 'more'), InvalidArgumentException::class);
});

test('parser exposes command and action definitions without factory execution', function () {
    [$app, $cache] = cacheApplication();
    $parser = new InputParser();
    $parsed = $parser->parse(['cache', 'warmup', '--count=4'], $app->registry());
    same('cache', $parsed->command->name);
    same('warmup', $parsed->action->name);
    $input = (new InputBinder())->bind($parsed->action, $parsed->options, $parsed->words, $parsed->commandName, $parsed->actionName);
    same('warmup', $input->action());
    same(4, $input->option('count'));
    same([], $cache->calls);
});

test('group help and invalid actions never invoke lazy factories or middleware', function () {
    [$sample] = cacheApplication();
    $app = new Application();
    $created = 0;
    $initialized = 0;
    $app->registry()->register($sample->registry()->definitions()['cache'], function () use (&$created) { ++$created; throw new LogicException('must not run'); });
    $app->middleware(function () use (&$initialized) { ++$initialized; throw new LogicException('must not run'); });
    foreach ([[], ['cache'], ['cache', 'warmup', '--help']] as $argv) { same(0, $app->dispatch($argv)->exitCode); }
    foreach ([['cache', 'warmup'], ['cache', 'bad'], ['cache', 'clear', '--count=2']] as $argv) { same(2, $app->dispatch($argv)->exitCode); }
    same(0, $created); same(0, $initialized);
});

test('middleware sees both group and selected action metadata', function () {
    [$app] = cacheApplication();
    $seen = [];
    $app->middleware(function ($definition, $input, $console, $next) use (&$seen) {
        $seen = [$definition->name, $definition->actionDefinition($input->action())->summary()];
        return $next();
    });
    same(0, $app->dispatch(['cache', 'clear'])->exitCode);
    same(['cache', 'Clear cache'], $seen);
});
