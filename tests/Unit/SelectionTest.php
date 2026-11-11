<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Selection\Choice;
use EasySwoole\Command\Console\Selection\SelectionState;

foreach ([['enter'], ['down', 'enter'], ['toggle', 'down', 'toggle', 'enter'], ['a', 'enter']] as $keys) {
    test('keyboard selection ' . implode(',', $keys), function () use ($keys) {
        $t = new FakeTerminal(); $t->keys = $keys; $r = console($t)->select('Choose', choices(), true);
        $expected = in_array('a', $keys, true) || in_array('toggle', $keys, true) ? [['pid'=>1], ['pid'=>2]] : (in_array('down', $keys, true) ? [['pid'=>2]] : [['pid'=>1]]);
        same($expected, $r->values()); same('selected', $r->state); same(['saved'], end($t->modes));
    });
}

test('single selection ignores multi toggle', function () {
    $t = new FakeTerminal(); $t->keys = ['toggle', 'down', 'toggle', 'enter'];
    same([['pid'=>2]], console($t)->select('Choose', choices())->values());
});

test('search returns stable business value', function () {
    $t = new FakeTerminal(); $t->keys = ['/', 'B', 'e', 't', 'a', 'enter', 'enter'];
    $r = console($t)->select('Choose', choices(), true); same('b', $r->choices[0]->id); same([['pid'=>2]], $r->values());
});

test('selection cancel and EOF differ', function () {
    foreach ([['q', 'cancelled'], [null, 'eof'], ['cancel', 'cancelled']] as [$key, $state]) {
        $t = new FakeTerminal(); $t->keys = [$key]; $r = console($t)->select('Choose', choices());
        same($state, $r->state); same([], $r->values()); same(['saved'], end($t->modes));
    }
});

test('cancel row and wrapping', function () {
    $t = new FakeTerminal(); $t->keys = ['up', 'enter']; same(true, console($t)->select('Choose', choices())->cancelled());
});

test('read exception restores terminal', function () {
    $t = new FakeTerminal(); $t->keys = ['enter']; $t->throwOnRead = true;
    raises(fn () => console($t)->select('Choose', choices()), RuntimeException::class); same(['saved'], end($t->modes));
    same(true, str_contains(end($t->frames), "\e[?25h"));
});

test('raw mode failure restores mode', function () {
    $t = new FakeTerminal(); $t->failRawMode = true;
    $t->lines = ['2'];
    same([['pid'=>2]], console($t)->select('Choose', choices())->values()); same(['saved'], end($t->modes));
});

test('numbered fallback retries and deduplicates', function () {
    $t = new FakeTerminal(); $t->raw = false; $t->lines = ['bad', '9', '1,2,1'];
    same([['pid'=>1], ['pid'=>2]], console($t)->select('Choose', choices(), true)->values());
});

test('numbered fallback EOF', function () {
    $t = new FakeTerminal(); $t->raw = false; same('eof', console($t)->select('Choose', choices())->state);
});

test('noninteractive input never auto selects', function () {
    $t = new FakeTerminal(); $t->tty = false;
    raises(fn () => console($t)->select('Choose', choices()), RuntimeException::class);
    raises(fn () => console($t)->confirm('Sure?'), RuntimeException::class);
});

test('confirmation retries and defaults', function () {
    $t = new FakeTerminal(); $t->lines = ['bad', '', 'yes', null]; $c = console($t);
    same(false, $c->confirm('Sure?')); same(true, $c->confirm('Sure?')); same(false, $c->confirm('Sure?', true));
});

test('duplicate choice identity rejected', fn () => raises(fn () => console()->select('Choose', [new Choice('x','x',1), new Choice('x','y',2)]), InvalidArgumentException::class));

test('selection deselect and all toggle off', function () {
    $t = new FakeTerminal(); $t->keys = ['toggle', 'down', 'toggle', 'up', 'toggle', 'enter'];
    same([['pid'=>2]], console($t)->select('Choose', choices(), true)->values());
    $t = new FakeTerminal(); $t->keys = ['a', 'a', 'down', 'enter'];
    same([['pid'=>2]], console($t)->select('Choose', choices(), true)->values());
});

test('unicode search and backspace', function () {
    $t = new FakeTerminal(); $t->keys = ['/', '测', '试', 'backspace', 'enter', 'enter'];
    $r = console($t)->select('选择', [new Choice('a', '开发', 1), new Choice('b', '测试', 2)]);
    same([2], $r->values());
});

test('selection unicode backspace exposes all matching choices', function () {
    $state = new SelectionState([new Choice('a', '测试', 2), new Choice('b', '测验', 3)], false);
    foreach (['/', '测', '试', 'backspace', 'enter', 'down'] as $key) { same(null, $state->handle($key)); }
    same([3], $state->handle('enter')->values());
});

test('selection search keeps hidden selections and toggles only visible choices', function () {
    $state = new SelectionState([new Choice('a', 'Alpha', 1), new Choice('b', 'Beta', 2)], true);
    foreach (['toggle', '/', 'B', 'enter', 'a'] as $key) { $state->handle($key); }
    same([1, 2], $state->handle('enter')->values());
});

test('selection empty search and EOF have distinct results', function () {
    $state = new SelectionState([new Choice('a', 'Alpha', 1)], true);
    foreach (['/', 'Z', 'enter'] as $key) { $state->handle($key); }
    same('cancelled', $state->handle('enter')->state);
    same('eof', (new SelectionState([], false))->handle(null)->state);
});
