<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\View;

use EasySwoole\Command\Console\Terminal;

final class TableView implements ViewInterface
{
    public function __construct(private array $headers, private array $rows) {}
    public function render(int $width = 80, int $height = PHP_INT_MAX): string
    {
        $rows = array_merge([$this->headers], $this->rows); $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $column => $value) {
                $widths[$column] = max($widths[$column] ?? 0, Terminal::width(Terminal::clean((string)$value)));
            }
        }
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $column => $value) {
                $value = Terminal::clean((string)$value);
                $cells[] = $value . str_repeat(' ', max(0, $widths[$column] - Terminal::width($value)));
            }
            $lines[] = Terminal::fit(rtrim(implode('  ', $cells)), $width);
        }
        return implode(PHP_EOL, array_slice($lines, 0, $height));
    }
}
