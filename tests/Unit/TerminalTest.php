<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Terminal;
use EasySwoole\Command\Console\View\TableView;
use EasySwoole\Command\Exception\TerminalException;

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

test('multibyte terminal cell widths', function () {
    same('你', Terminal::fit('你好A', 3)); same(5, Terminal::width('你好A'));
});

test('repeated session restores previous signal handlers', function () {
    if (!function_exists('pcntl_signal_get_handler')) { return; }
    $before = pcntl_signal_get_handler(SIGTERM); $async = pcntl_async_signals();
    $t = new FakeTerminal(); $c = console($t);
    $c->live(fn () => false); $c->live(fn () => false);
    same($before, pcntl_signal_get_handler(SIGTERM)); same($async, pcntl_async_signals());
});

test('terminal open failure rolls back raw mode ownership and signals', function () {
    $terminal = new class extends FakeTerminal {
        public bool $fail = true;
        public function write(string $text): void {
            if ($this->fail) { throw new RuntimeException('output failed during open'); }
            parent::write($text);
        }
    };
    $before = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGTERM) : null;
    $async = function_exists('pcntl_async_signals') ? pcntl_async_signals() : null;
    $console = console($terminal);
    try { $console->live(fn () => false); throw new LogicException('expected open failure'); }
    catch (RuntimeException $error) { same('output failed during open', $error->getMessage()); }
    same(['saved'], end($terminal->modes));
    if ($before !== null) {
        same($before, pcntl_signal_get_handler(SIGTERM));
        same($async, pcntl_async_signals());
    }
    $terminal->fail = false;
    $console->live(fn () => false);
    console(new FakeTerminal())->live(fn () => false);
});

test('raw mode exception restores the saved mode before propagating', function () {
    $terminal = new class extends FakeTerminal {
        protected function command(array $args): ?string {
            $result = parent::command($args);
            if (($args[0] ?? '') === '-icanon') { throw new RuntimeException('raw mode failed'); }
            return $result;
        }
    };
    raises(fn () => console($terminal)->live(fn () => false), RuntimeException::class);
    same(['saved'], end($terminal->modes));
    same([], $terminal->frames);
    console()->live(fn () => false);
});

test('console clears active state even if closing output fails', function () {
    $terminal = new class extends FakeTerminal {
        public bool $failClose = true;
        public function write(string $text): void {
            if ($this->failClose && str_contains($text, "\033[?25h")) { throw new RuntimeException('close failed'); }
            parent::write($text);
        }
    };
    $console = console($terminal);
    raises(fn () => $console->live(fn () => false), RuntimeException::class);
    $terminal->failClose = false;
    $console->live(fn () => false);
});

test('mode restoration failure still releases global ownership', function () {
    $terminal = new class extends FakeTerminal {
        protected function command(array $args): ?string {
            $result = parent::command($args);
            return $args === ['saved'] ? null : $result;
        }
    };
    raises(fn () => console($terminal)->live(fn () => false), TerminalException::class);
    same(true, str_contains(end($terminal->frames), "\033[?25h"));
    console()->live(fn () => false);
});

test('selection cleanup failure does not restart numbered input', function () {
    $terminal = new class extends FakeTerminal {
        protected function command(array $args): ?string {
            $result = parent::command($args);
            return $args === ['saved'] ? null : $result;
        }
    };
    $terminal->keys = ['enter'];
    $terminal->lines = ['2'];
    raises(fn () => console($terminal)->select('Choose', choices()), TerminalException::class);
    same(['2'], $terminal->lines);
    console()->live(fn () => false);
});

test('failed open rollback is not downgraded to numbered input', function () {
    $terminal = new class extends FakeTerminal {
        protected function command(array $args): ?string {
            $result = parent::command($args);
            return ($args[0] ?? '') === '-icanon' || $args === ['saved'] ? null : $result;
        }
    };
    $terminal->lines = ['2'];
    raises(fn () => console($terminal)->select('Choose', choices()), RuntimeException::class);
    same(['2'], $terminal->lines);
    console()->live(fn () => false);
});
