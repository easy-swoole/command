<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$passed = 0;
foreach (glob(__DIR__ . '/Unit/*Test.php') as $file) {
    require $file;
}
echo "\n{$passed} tests passed\n";
