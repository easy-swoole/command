<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Console/DemoCommand.php';
require __DIR__ . '/Console/DemoProvider.php';

use EasySwoole\Command\Application;
use EasySwoole\Command\Examples\Console\DemoProvider;

$app = new Application('Command 3.x demo');
$app->addProvider(new DemoProvider());

exit($app->run(array_slice($argv, 1)));
