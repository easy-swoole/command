<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Terminal;
use EasySwoole\Command\Console\Selection\Choice;
use EasySwoole\Command\Console\Selection\SelectionState;

test('keyboard consumes whole sequences without leaking trailing search characters', function () {
    foreach (["\033[3~" => 'delete', "\033[2~" => 'insert', "\033[99~" => 'ignore',
        "\033[1;5A" => 'up', "\033[?1;2x" => 'ignore', "\033OP" => 'ignore'] as $bytes => $expected) {
        same([$expected, 'X', null], decodeKeys($bytes . 'X', 3));
    }
    $state = new SelectionState([new Choice('a', 'Alpha', 1)], false);
    $state->handle('/');
    foreach (decodeKeys("\033[3~Alpha", 6) as $key) { $state->handle($key); }
    $state->handle('enter');
    same([1], $state->handle('enter')->values());
});

test('keyboard recognizes navigation variants and preserves normal UTF8 input', function () {
    foreach (["\033[H" => 'home', "\033OH" => 'home', "\033[1~" => 'home', "\033[7~" => 'home',
        "\033[F" => 'end', "\033OF" => 'end', "\033[4~" => 'end', "\033[8~" => 'end',
        "\033[5~" => 'pageUp', "\033[6~" => 'pageDown', "\033[C" => 'right', "\033[D" => 'left'] as $bytes => $key) {
        same([$key, '测', 'q'], decodeKeys($bytes . '测q', 3));
    }
    same(['ignore', 'q', null], decodeKeys("\xc2q", 3));
    same(['ignore', 'q', null], decodeKeys("\xffq", 3));
    same(['cancel'], decodeKeys("\033", 1));
});

test('truncated and excessive key sequences are discarded until their terminator', function () {
    same(['ignore', 'ignore', 'X'], decodeKeys("\033[" . str_repeat('1', 300) . '~X', 3));
    $input = tmpfile();
    try {
        fwrite($input, "\033[3"); rewind($input);
        $terminal = new Terminal(input: $input);
        same('ignore', $terminal->key());
        fwrite($input, '~X'); fseek($input, 3);
        same('ignore', $terminal->key()); same('X', $terminal->key());
    } finally { fclose($input); }
});

test('terminal wait reports buffered bytes before waiting for more stream input', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    try {
        $terminal = new Terminal(input: $pair[0]);
        fwrite($pair[1], "\xc2q");
        same(true, $terminal->wait(.01));
        same('ignore', $terminal->key());
        same(true, $terminal->wait(.01));
        same('q', $terminal->key());
        same(false, $terminal->wait(.01));
    } finally { fclose($pair[0]); fclose($pair[1]); }
});
