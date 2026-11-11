<?php

declare(strict_types=1);

if (!extension_loaded('mbstring')) { throw new RuntimeException('command requires ext-mbstring'); }

// 测试入口独立注册自动加载，无需 Composer 或 Swoole。
spl_autoload_register(static function (string $class): void {
    $prefix = 'EasySwoole\\Command\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

require __DIR__ . '/Support/Assertions.php';
require __DIR__ . '/Support/DemoCommand.php';
require __DIR__ . '/Support/FakeTerminal.php';
require __DIR__ . '/Support/Helpers.php';

require __DIR__ . '/Support/CallbackCommand.php';
