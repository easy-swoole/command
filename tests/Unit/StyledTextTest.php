<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Color;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Console\Text;
use EasySwoole\Command\Console\Terminal;
use EasySwoole\Command\Console\View\TableView;

test('styled text measures visible unicode cells and resets clipped colors', function () {
    $text = Color::green('中文AB');
    same(6, Text::width($text));
    $clipped = Text::fit($text, 3);
    same('中', Text::clean($clipped, false));
    same(true, str_ends_with($clipped, "\033[0m"));
    same(2, Text::width($clipped));
    same('', Text::fit($text, 0));
    same('中', Text::fit($text, 3, false));
    same('中', Text::clean(Text::fit(Text::fit($text, 5), 3), false));
    same("\033[38;2;1;2;3mx\033[0m", Text::fit("\033[38;2;1;2;3mx", 1));
});

test('text removes terminal commands while retaining only permitted styles', function () {
    $unsafe = "a\033[2Jb\033[Hc\033]0;title\007d\033]8;;https://example.test\033\\e\033]8;;\033\\f\033Ppayload\033\\g";
    same('abcdefg', Text::clean($unsafe));
    same('abcdefg', Text::clean($unsafe, false));
    same('a?b', Text::clean("a\tb"));
    same('a?b', Text::clean("a\nb"));
    same("a\nb", Text::clean("a\nb", multiline: true));
    same("\033[32mx\033[0m", Text::clean("\033[32mx"));
});

test('live frames preserve supported styles without leaking styles to other rows', function () {
    $terminal = new class extends FakeTerminal {
        public function supportsColor(): bool { return true; }
    };
    $terminal->sizes = [[5, 8]];
    $terminal->frame(Color::green('中文ABCD') . "\nnext\033[2J");
    $frame = end($terminal->frames);
    same(true, str_contains($frame, "\033[0;32m"));
    same(false, str_contains($frame, '?[0;32m'));
    same(false, str_contains($frame, "\033[2J"));
    same(true, str_contains($frame, "\033[0m\nnext"));
    $text = Text::clean($frame, false, true);
    foreach (explode("\n", $text) as $line) { same(true, Terminal::width($line) <= 7); }
    $plain = new class extends FakeTerminal { public function supportsColor(): bool { return false; } };
    $plain->frame(Color::green('running'));
    same("\033[H\033[Jrunning", end($plain->frames));
});

test('styled tables retain identical cell alignment and obey narrow viewports', function () {
    $plain = new TableView(['名称', '状态'], [['中文服务名称', 'running']]);
    $colored = new TableView([Color::green('名称'), '状态'], [[Color::red('中文服务名称'), Color::green('running')]]);
    foreach (range(1, 35) as $width) {
        $text = $colored->render($width);
        same($plain->render($width), Text::clean($text, false, true));
        foreach (explode("\n", $text) as $line) { same(true, Terminal::width($line) <= $width); }
    }
});

test('redirected ordinary output strips styles and terminal control payloads', function () {
    $out = fopen('php://temp', 'w+');
    try {
        $console = new Console(output: $out);
        $console->line(Color::green("one\ntwo") . "\033]0;title\007\033[2J");
        rewind($out); same("one\ntwo\n", stream_get_contents($out));
    } finally { fclose($out); }
});


test('empty clipped colored cells do not retain invisible trailing column padding', function () {
    $plain = new TableView(['A', 'B'], [['x', '中']]);
    $colored = new TableView(['A', 'B'], [['x', Color::red('中')]]);
    foreach (range(1, 10) as $width) {
        same($plain->render($width), Text::clean($colored->render($width), false, true));
    }
});


test('selection search matches visible labels instead of their color codes', function () {
    $choices = [new \EasySwoole\Command\Console\Selection\Choice('a', Color::green('开发'), 1)];
    $state = new \EasySwoole\Command\Console\Selection\SelectionState($choices, false);
    foreach (['/', '开', 'enter'] as $key) { $state->handle($key); }
    same([1], $state->handle('enter')->values());
    $state = new \EasySwoole\Command\Console\Selection\SelectionState($choices, false);
    foreach (['/', '3', '2', 'enter'] as $key) { $state->handle($key); }
    same('cancelled', $state->handle('enter')->state);
});


test('prompt uses the same color and control sequence policy as ordinary output', function () {
    $out = fopen('php://temp', 'w+');
    try {
        $terminal = new FakeTerminal(); $terminal->lines = ['answer'];
        $console = new Console(output: $out, terminal: $terminal);
        same('answer', $console->prompt(Color::green('Question: ') . "\033[2J"));
        rewind($out); same('Question: ', stream_get_contents($out));
    } finally { fclose($out); }
});


test('multiline frames carry original colors across lines and reset every visible line', function () {
    $terminal = new class extends FakeTerminal { public function supportsColor(): bool { return true; } };
    $terminal->frame(Color::green("one\ntwo"));
    same("\033[H\033[J\033[0;32mone\033[0m\n\033[0;32mtwo\033[0m", end($terminal->frames));
    same("one\ntwo", Text::fitLines(Color::green("one\ntwo"), 20, 2, false));
    same('', Text::fitLines('one', 20, 0));
});

test('multiline clipping respects hidden resets and partial style changes', function () {
    $text = "\033[32m中文ABCDE\033[0m\nplain";
    $lines = explode("\n", Text::fitLines($text, 3, 2));
    same('中', Text::clean($lines[0], false));
    same('pla', $lines[1]);
    $lines = explode("\n", Text::fitLines("\033[32mone\n\033[39mtwo\nthree", 20, 3));
    same("\033[32m\033[39mthree\033[0m", $lines[2]);
    same("\033[32mx\033[0m\n\033[32my\033[0m", Text::fitLines("\033[32mx\ny", 20, 2));
});
