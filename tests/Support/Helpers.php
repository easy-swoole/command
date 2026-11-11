<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Console\Terminal;
use EasySwoole\Command\Console\Selection\Choice;

function console(?FakeTerminal $terminal = null): Console {
    return new Console(output: fopen('php://temp', 'w+'), error: fopen('php://temp', 'w+'), terminal: $terminal ?? new FakeTerminal());
}

function app(): Application { $app = new Application(); $app->addCommand(new DemoCommand()); return $app; }

function choices(): array {
    return [new Choice('a', 'Alpha', ['pid' => 1], 'first'), new Choice('b', 'Beta', ['pid' => 2], 'second')];
}

/** 使用真实流验证解码，避免只验证已经转换过的模拟按键名称。 */
function decodeKeys(string $bytes, int $count): array
{
    $input = tmpfile();
    try {
        fwrite($input, $bytes); rewind($input);
        $terminal = new Terminal(input: $input);
        $keys = [];
        for ($i = 0; $i < $count; ++$i) { $keys[] = $terminal->key(); }
        return $keys;
    } finally { fclose($input); }
}
