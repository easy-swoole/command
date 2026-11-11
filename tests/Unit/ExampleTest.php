<?php

declare(strict_types=1);

require_once __DIR__ . '/../../examples/Cache/MemoryCache.php';
require_once __DIR__ . '/../../examples/Cache/CacheCommand.php';

use EasySwoole\Command\Application;
use EasySwoole\Command\Examples\Cache\CacheCommand;
use EasySwoole\Command\Examples\Cache\MemoryCache;

test('cache example rejects omitted bare empty and blank store before execution', function () {
    $cache = new MemoryCache();
    $app = new Application();
    $app->addCommand(new CacheCommand($cache));
    foreach (['clear', 'warmup'] as $action) {
        foreach ([[], ['--store'], ['--store='], ['--store=   '], ['--store=　'], ["--store=\u{00a0}"]] as $options) {
            $result = $app->dispatch(array_merge(['cache', $action], $options));
            same(2, $result->exitCode);
            same(true, str_contains($result->message, '--store'));
        }
    }
    same(1, $cache->clear('default'));
});

test('cache example reports a custom validation message', function () {
    $app = new Application();
    $app->addCommand(new CacheCommand(new MemoryCache()));
    same('--store 不能为空', $app->dispatch(['cache', 'clear', '--store='])->message);
    same('--store 不能为空', $app->dispatch(['cache', 'warmup', '--store=   '])->message);
});

test('cache example routes valid actions and accepts zero as a store name', function () {
    $app = new Application();
    $app->addCommand(new CacheCommand(new MemoryCache()));
    same('已清理 1 项缓存', $app->dispatch(['cache', 'clear', '--store=default'])->message);
    same('已预热 2 项缓存', $app->dispatch(['cache', 'warmup', '--store=redis'])->message);
    same(0, $app->dispatch(['cache', 'clear', '--store=0'])->exitCode);
});

test('cache example help does not require the required store', function () {
    $app = new Application();
    $app->addCommand(new CacheCommand(new MemoryCache()));
    foreach ([['cache'], ['cache', 'clear', '--help'], ['help', 'cache', 'warmup']] as $argv) {
        $result = $app->dispatch($argv);
        same(0, $result->exitCode);
        same(true, str_contains($result->message, '--store'));
        same(true, str_contains($result->message, '(required)'));
    }
});
