<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Cache/MemoryCache.php';
require __DIR__ . '/Cache/CacheCommand.php';

use EasySwoole\Command\Application;
use EasySwoole\Command\Examples\Cache\CacheCommand;
use EasySwoole\Command\Examples\Cache\MemoryCache;

$app = new Application('缓存管理示例');
$app->addCommand(new CacheCommand(new MemoryCache()));

exit($app->run(array_slice($argv, 1)));
