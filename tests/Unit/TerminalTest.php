<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Terminal;
use EasySwoole\Command\Console\View\TableView;

test('live refresh and clean exit', function () {
    $t = new FakeTerminal(); $n = 0;
    console($t)->live(function () use (&$n) { return ++$n === 3 ? false : new TableView(['PID'], [[$n]]); }, .2);
    same(3, $n); same(['saved'], end($t->modes)); same(true, str_contains(implode('', $t->frames), 'PID'));
});

test('live quit responds before refresh interval', function () {
    $t = new FakeTerminal(); $t->keys = ['q']; $n = 0;
    console($t)->live(function () use (&$n) { ++$n; return 'frame'; }, 60);
    same(1, $n); same(true, $t->time <= .1); same(['saved'], end($t->modes));
});

test('live error cleanup', function () {
    $t = new FakeTerminal(); raises(fn () => console($t)->live(fn () => throw new RuntimeException('offline')), RuntimeException::class);
    same(['saved'], end($t->modes));
});

test('live invalid interval', function () {
    foreach ([0, -1, INF, NAN] as $interval) { raises(fn () => console()->live(fn () => '', $interval), InvalidArgumentException::class); }
});

test('nested sessions rejected without closing outer session', function () {
    $t = new FakeTerminal(); $inner = console($t); $outer = console($t);
    $outer->live(function () use ($inner, $t) {
        raises(fn () => $inner->live(fn () => false), LogicException::class);
        same(false, in_array(['saved'], $t->modes, true)); return false;
    });
    same(['saved'], end($t->modes));
});

test('independent terminals cannot fight over raw mode', function () {
    $a = new FakeTerminal(); $b = new FakeTerminal();
    console($a)->live(function () use ($b) { raises(fn () => console($b)->live(fn () => false), LogicException::class); return false; });
});

test('frame crops and sanitizes control characters', function () {
    $t = new FakeTerminal(); $t->sizes = [[3, 12]]; $t->frame("hello\e[2Jabcdefgh\nsecond\nthird");
    same(false, str_contains(end($t->frames), 'third')); same(false, str_contains(end($t->frames), "hello\e"));
});

test('multibyte width without optional extensions', function () {
    same('你', Terminal::fit('你好A', 3)); same(5, Terminal::width('你好A'));
});

test('repeated session restores previous signal handlers', function () {
    if (!function_exists('pcntl_signal_get_handler')) { return; }
    $before = pcntl_signal_get_handler(SIGTERM); $async = pcntl_async_signals();
    $t = new FakeTerminal(); $c = console($t);
    $c->live(fn () => false); $c->live(fn () => false);
    same($before, pcntl_signal_get_handler(SIGTERM)); same($async, pcntl_async_signals());
});
