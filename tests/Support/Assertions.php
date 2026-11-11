<?php

declare(strict_types=1);



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
