<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Terminal;
use EasySwoole\Command\Console\View\TableView;

test('table keeps status columns when names are long', function () {
    $text = (new TableView(['Name', 'Status'], [[str_repeat('x', 100), 'running']]))->render(30, 5);
    same(true, str_contains($text, 'Status'));
    same(true, str_contains($text, 'running'));
    foreach (explode("\n", $text) as $line) { same(true, Terminal::width($line) <= 30); }
});

test('table never converts cells outside the visible rows', function () {
    $hidden = new class {
        public function __toString(): string { throw new RuntimeException('hidden cell must not be inspected'); }
    };
    $table = new TableView(['Name', 'Status'], [['visible', 'running'], [$hidden, 'hidden']]);
    same(true, str_contains($table->render(30, 2), 'visible'));
    same("Name  Status", $table->render(30, 1));
});

test('table obeys narrow unicode widths and empty viewport', function () {
    $table = new TableView(['名称', '状态', '数量'], [['中文测试名称', '正常', 10]]);
    foreach (range(1, 30) as $width) {
        $text = $table->render($width, 2);
        foreach (explode("\n", $text) as $line) {
            same(true, mb_check_encoding($line, 'UTF-8'));
            same(true, mb_strwidth($line, 'UTF-8') <= $width);
        }
    }
    same('', $table->render(0, 2));
    same('', $table->render(10, 0));
    same('', (new TableView([], []))->render());
});
