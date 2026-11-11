<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;

test('unknown action does not run default', fn () => same(2, app()->dispatch(['demo', 'typo'])->exitCode));

test('help bypasses factory, validation and middleware', function () {
    $a = new Application(); $made = 0; $initialized = 0;
    $d = CommandDefinition::named('needs')->option(Option::string('secret', required: true));
    $a->registry()->register($d, function () use (&$made) { ++$made; throw new RuntimeException(); });
    $a->middleware(function () use (&$initialized) { ++$initialized; });
    same(0, $a->dispatch(['needs', '--help'])->exitCode);
    same(0, $a->dispatch(['help', 'needs'])->exitCode); same(0, $made); same(0, $initialized);
    same(2, $a->dispatch(['needs'])->exitCode); same(0, $initialized);
});

test('group and root help', function () {
    same(0, app()->dispatch([])->exitCode); same(0, app()->dispatch(['demo'])->exitCode);
    same(true, str_contains(app()->dispatch(['demo'])->message, 'demo run'));
    same(2, app()->dispatch(['help', 'demo', 'typo'])->exitCode);
});

test('middleware order', function () {
    $a = app(); $order = [];
    foreach ([1, 2] as $id) { $a->middleware(function ($d, $i, $c, $next) use (&$order, $id) { $order[] = $id; $r = $next(); $order[] = -$id; return $r; }); }
    same(0, $a->dispatch(['demo', 'run'])->exitCode); same([1, 2, -2, -1], $order);
});

test('factory exception boundary', function () {
    $a = new Application(); $a->registry()->register(CommandDefinition::named('bad'), fn () => throw new RuntimeException('offline'));
    same(1, $a->dispatch(['bad'])->exitCode); same('offline', $a->dispatch(['bad'])->message);
});

test('stdout stderr and exit codes', function () {
    $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+'); $c = new Console(output: $out, error: $err);
    same(0, app()->run(['demo', 'run'], $c)); same(2, app()->run(['missing'], $c));
    rewind($out); rewind($err); same("done\n", stream_get_contents($out)); same(true, str_contains(stream_get_contents($err), 'unknown command'));
});

test('redirect strips ANSI', function () {
    $out = fopen('php://temp', 'w+'); $c = new Console(output: $out); $c->line("\e[31mred\e[0m");
    rewind($out); same("red\n", stream_get_contents($out));
});

test('interrupted result has signal exit code', function () {
    $a = new Application(); $a->registry()->register(CommandDefinition::named('interrupt'), fn () => throw new \EasySwoole\Command\Exception\InterruptedException(15));
    same(143, $a->dispatch(['interrupt'])->exitCode);
});

test('repeated middleware calls construct once per dispatch', function () {
    $a = new Application(); $created = 0;
    $a->registry()->register((new DemoCommand())->definition(), function () use (&$created) { ++$created; return new DemoCommand(); });
    $a->middleware(function ($d, $i, $c, $next) { $next(); return $next(); });
    same(0, $a->dispatch(['demo', 'run'])->exitCode); same(1, $created);
    same(0, $a->dispatch(['demo', 'run'])->exitCode); same(2, $created);
});
