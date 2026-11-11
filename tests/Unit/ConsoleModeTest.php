<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Console\Color;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

test('explicit interaction policy blocks every interactive entry even with a TTY', function () {
    $terminal = new FakeTerminal(); $terminal->lines = ['yes'];
    $console = new Console(output: fopen('php://temp', 'w+'), terminal: $terminal, allowInteraction: false);
    same(false, $console->interactive());
    raises(fn () => $console->prompt('Question'), RuntimeException::class);
    raises(fn () => $console->confirm('Continue?', true), RuntimeException::class);
    raises(fn () => $console->select('Choose', choices()), RuntimeException::class);
    raises(fn () => $console->live(static fn () => throw new LogicException('must not run')), RuntimeException::class);
    same(['yes'], $terminal->lines); same([], $terminal->modes); same([], $terminal->frames);
});

test('JSON results own stdout while command progress and diagnostics go to stderr', function () {
    $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
    try {
        $console = new Console(output: $out, error: $err, json: true);
        $app = new Application();
        $app->addCommand(new CallbackCommand(CommandDefinition::create('run'), static function (Input $input, Console $console) {
            $console->info(Color::green('progress'));
            $console->error('diagnostic');
            return CommandResult::success(Color::green('done'), ['name' => '测试', 'url' => 'https://example.test']);
        }));
        same(0, $app->run(['run'], $console));
        rewind($out); $output = stream_get_contents($out);
        same(['exitCode' => 0, 'message' => 'done', 'data' => ['name' => '测试', 'url' => 'https://example.test']], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        same(true, str_contains($output, '测试')); same(true, str_contains($output, 'https://example.test'));
        rewind($err); same("progress\ndiagnostic\n", stream_get_contents($err));
    } finally { fclose($out); fclose($err); }
});

test('JSON includes input failures execution failures and help with correct exit codes', function () {
    foreach ([['missing'], ['demo', 'run', '--help']] as $argv) {
        $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
        try {
            $console = new Console(output: $out, error: $err, json: true);
            $code = app()->run($argv, $console);
            rewind($out); $data = json_decode(stream_get_contents($out), true, flags: JSON_THROW_ON_ERROR);
            same($argv[0] === 'missing' ? 2 : 0, $code); same($code, $data['exitCode']);
            same(null, $data['data']); same(false, str_contains($data['message'], "\033["));
            rewind($err); same('', stream_get_contents($err));
        } finally { fclose($out); fclose($err); }
    }
    $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+');
    try {
        $app = new Application();
        $app->registry()->register(CommandDefinition::create('fail'), static fn () => throw new RuntimeException('failed'));
        same(1, $app->run(['fail'], new Console(output: $out, error: $err, json: true)));
        rewind($out); same(['exitCode' => 1, 'message' => 'failed', 'data' => null], json_decode(stream_get_contents($out), true));
    } finally { fclose($out); fclose($err); }
});

test('JSON disables interaction without entering a terminal session', function () {
    $terminal = new FakeTerminal();
    $console = new Console(output: fopen('php://temp', 'w+'), terminal: $terminal, json: true);
    same(false, $console->interactive()); same(false, $console->supportsColor());
    raises(fn () => $console->confirm('Continue?', true), RuntimeException::class);
    raises(fn () => $console->select('Choose', choices()), RuntimeException::class);
    raises(fn () => $console->live(static fn () => ''), RuntimeException::class);
    same([], $terminal->modes);
});

test('JSON encoding failure reports an error without writing partial JSON', function () {
    $out = fopen('php://temp', 'w+'); $err = fopen('php://temp', 'w+'); $resource = fopen('php://temp', 'w+');
    try {
        $app = new Application();
        $app->addCommand(new CallbackCommand(CommandDefinition::create('run'), static fn () => CommandResult::success(data: $resource)));
        same(1, $app->run(['run'], new Console(output: $out, error: $err, json: true)));
        rewind($out); same('', stream_get_contents($out));
        rewind($err); same(true, str_contains(stream_get_contents($err), 'cannot encode command result:'));
    } finally { fclose($out); fclose($err); fclose($resource); }
});
