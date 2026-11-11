<?php

declare(strict_types=1);

// 不依赖第三方库的回归测试，也可运行 `php -n tests/run.php`.
spl_autoload_register(static function (string $class): void {
    $prefix = 'EasySwoole\\Command\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use EasySwoole\Command\{Application, Argument, Choice, CommandDefinition, CommandInterface,
    CommandProviderInterface, CommandRegistry, CommandResult, Console, Input, InputException,
    InputParser, Option, Selection, TableView, Terminal};

$passed = 0;
function same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function raises(callable $callback, string $class): void {
    try { $callback(); } catch (Throwable $e) { if ($e instanceof $class) { return; } throw $e; }
    throw new RuntimeException('Expected exception ' . $class);
}
function test(string $name, callable $callback): void {
    global $passed;
    try { $callback(); ++$passed; echo '.'; }
    catch (Throwable $e) { fwrite(STDERR, "\nFAIL {$name}: {$e}\n"); exit(1); }
}
final class DemoCommand implements CommandInterface {
    public int $executions = 0;
    public function definition(): CommandDefinition {
        return CommandDefinition::named('demo', 'run')->description('Run demo')
            ->option(Option::flag('d'))->option(Option::flag('force'))
            ->option(Option::string('mode', default: 'dev'))
            ->option(Option::integer('pid')->validate(static fn ($v) => $v === null || $v > 0))
            ->option(Option::number('watch', implicit: 2)->validate(static fn ($v) => $v === null || $v > 0))
            ->argument(new Argument('name'));
    }
    public function execute(Input $input, Console $console): CommandResult {
        ++$this->executions;
        return CommandResult::success('done', [$input->options(), $input->argument('name'), $input->provided('force')]);
    }
}
class FakeTerminal extends Terminal {
    public array $keys = [];
    public array $lines = [];
    public array $frames = [];
    public array $modes = [];
    public bool $tty = true;
    public bool $raw = true;
    public bool $throwOnRead = false;
    public bool $failRawMode = false;
    public array $sizes = [];
    public float $time = 0;
    public function interactive(): bool { return $this->tty; }
    public function supported(): bool { return $this->tty && $this->raw; }
    protected function command(array $args): ?string {
        $this->modes[] = $args;
        if ($args === ['-g']) { return 'saved'; }
        if ($args === ['size']) { return '24 80'; }
        return $this->failRawMode && ($args[0] ?? '') === '-icanon' ? null : '';
    }
    public function write(string $text): void { $this->frames[] = $text; }
    public function dimensions(): array { return $this->sizes ? array_shift($this->sizes) : [24, 80]; }
    public function wait(float $seconds): bool { $this->time += $seconds; return count($this->keys) > 0; }
    public function key(): ?string {
        if ($this->throwOnRead) { throw new RuntimeException('read failed'); }
        return array_shift($this->keys);
    }
    public function now(): float { return $this->time; }
    public function readLine(): ?string { return array_shift($this->lines); }
}
function console(?FakeTerminal $terminal = null): Console {
    return new Console(output: fopen('php://temp', 'w+'), error: fopen('php://temp', 'w+'), terminal: $terminal ?? new FakeTerminal());
}
function app(): Application { $app = new Application(); $app->addCommand(new DemoCommand()); return $app; }
function choices(): array {
    return [new Choice('a', 'Alpha', ['pid' => 1], 'first'), new Choice('b', 'Beta', ['pid' => 2], 'second')];
}

test('typed input and legacy single dash', function () {
    $r = app()->dispatch(['demo', 'run', '-d', '-force=false', '--mode=produce', '--pid=0012', 'name=hello']);
    same(0, $r->exitCode); same(true, $r->data[0]['d']); same(false, $r->data[0]['force']);
    same(12, $r->data[0]['pid']); same('hello', $r->data[1]); same(true, $r->data[2]);
});
test('option presence and defaults', function () {
    $r = app()->dispatch(['demo', 'run']); same(false, $r->data[2]); same('dev', $r->data[0]['mode']);
    same(true, app()->dispatch(['demo', 'run', '--force'])->data[0]['force']);
});
test('watch optional value', function () {
    same(2.0, app()->dispatch(['demo', 'run', '--watch'])->data[0]['watch']);
    same(.25, app()->dispatch(['demo', 'run', '--watch=0.25'])->data[0]['watch']);
});
test('last repeated option wins', fn () => same('test', app()->dispatch(['demo', 'run', '--mode=dev', '--mode=test'])->data[0]['mode']));
test('options before command', fn () => same('prod', app()->dispatch(['--mode=prod', 'demo', 'run'])->data[0]['mode']));
test('equals preserved', fn () => same('a=b', app()->dispatch(['demo', 'run', '--mode=a=b'])->data[0]['mode']));
test('positional argument', fn () => same('hello', app()->dispatch(['demo', 'run', 'hello'])->data[1]));
test('literal terminator', fn () => same('-a=b', app()->dispatch(['demo', 'run', '--', '-a=b'])->data[1]));
foreach ([['--unknown'], ['--mode'], ['--pid=0'], ['--pid=-1'], ['--pid=1.5'], ['--pid=99999999999999999999999'], ['--force=bad'], ['--watch=0'], ['--watch=INF'], ['--watch=NaN']] as $options) {
    test('invalid ' . implode(' ', $options), fn () => same(2, app()->dispatch(array_merge(['demo', 'run'], $options))->exitCode));
}
test('unexpected argument', fn () => same(2, app()->dispatch(['demo', 'run', 'a', 'b'])->exitCode));
test('unknown named argument', fn () => same(2, app()->dispatch(['demo', 'run', 'unknown=x'])->exitCode));
test('duplicate named argument', fn () => same(2, app()->dispatch(['demo', 'run', 'name=x', 'name=y'])->exitCode));
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
test('required argument and option', function () {
    $p = new InputParser(); $d = CommandDefinition::named('a')->argument(new Argument('x', required: true))->option(Option::string('y', required: true));
    raises(fn () => $p->bind($d, []), InputException::class);
    raises(fn () => $p->bind($d, ['y' => 'yes']), InputException::class);
    same('ok', $p->bind($d, ['y' => 'yes'], [['ok', false]])->argument('x'));
});
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
test('middleware order', function () {
    $a = app(); $order = [];
    foreach ([1, 2] as $id) { $a->middleware(function ($d, $i, $c, $next) use (&$order, $id) { $order[] = $id; $r = $next(); $order[] = -$id; return $r; }); }
    same(0, $a->dispatch(['demo', 'run'])->exitCode); same([1, 2, -2, -1], $order);
});
test('factory exception boundary', function () {
    $a = new Application(); $a->registry()->register(CommandDefinition::named('bad'), fn () => throw new RuntimeException('offline'));
    same(1, $a->dispatch(['bad'])->exitCode); same('offline', $a->dispatch(['bad'])->message);
});
test('factory mismatch', function () {
    $a = new Application(); $a->registry()->register(CommandDefinition::named('bad'), fn () => new DemoCommand());
    same(1, $a->dispatch(['bad'])->exitCode);
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
test('selection deselect and all toggle off', function () {
    $t = new FakeTerminal(); $t->keys = ['toggle', 'down', 'toggle', 'up', 'toggle', 'enter'];
    same([['pid'=>2]], console($t)->select('Choose', choices(), true)->values());
    $t = new FakeTerminal(); $t->keys = ['a', 'a', 'down', 'enter'];
    same([['pid'=>2]], console($t)->select('Choose', choices(), true)->values());
});
test('repeated session restores previous signal handlers', function () {
    if (!function_exists('pcntl_signal_get_handler')) { return; }
    $before = pcntl_signal_get_handler(SIGTERM); $async = pcntl_async_signals();
    $t = new FakeTerminal(); $c = console($t);
    $c->live(fn () => false); $c->live(fn () => false);
    same($before, pcntl_signal_get_handler(SIGTERM)); same($async, pcntl_async_signals());
});
test('interrupted result has signal exit code', function () {
    $a = new Application(); $a->registry()->register(CommandDefinition::named('interrupt'), fn () => throw new \EasySwoole\Command\InterruptedException(15));
    same(143, $a->dispatch(['interrupt'])->exitCode);
});

test('repeated middleware calls construct once per dispatch', function () {
    $a = new Application(); $created = 0;
    $a->registry()->register((new DemoCommand())->definition(), function () use (&$created) { ++$created; return new DemoCommand(); });
    $a->middleware(function ($d, $i, $c, $next) { $next(); return $next(); });
    same(0, $a->dispatch(['demo', 'run'])->exitCode); same(1, $created);
    same(0, $a->dispatch(['demo', 'run'])->exitCode); same(2, $created);
});

test('unicode search and backspace', function () {
    $t = new FakeTerminal(); $t->keys = ['/', '测', '试', 'backspace', 'enter', 'enter'];
    $r = console($t)->select('选择', [new Choice('a', '开发', 1), new Choice('b', '测试', 2)]);
    same([2], $r->values());
});
echo "\n{$passed} tests passed\n";
