<?php

declare(strict_types=1);

use EasySwoole\Command\Application;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Console\Selection\Choice;

function console(?FakeTerminal $terminal = null): Console {
    return new Console(output: fopen('php://temp', 'w+'), error: fopen('php://temp', 'w+'), terminal: $terminal ?? new FakeTerminal());
}

function app(): Application { $app = new Application(); $app->addCommand(new DemoCommand()); return $app; }

function choices(): array {
    return [new Choice('a', 'Alpha', ['pid' => 1], 'first'), new Choice('b', 'Beta', ['pid' => 2], 'second')];
}
