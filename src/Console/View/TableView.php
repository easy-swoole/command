<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\View;

use EasySwoole\Command\Console\Terminal;

final class TableView implements ViewInterface
{
    public function __construct(private array $headers, private array $rows) {}

    public function render(int $width = 80, int $height = PHP_INT_MAX): string
    {
        if ($width <= 0 || $height <= 0) { return ''; }
        // 先截取可视行，隐藏数据不参与单元格转换与列宽测量。
        $visible = [$this->headers];
        foreach ($this->rows as $row) {
            if (count($visible) >= $height) { break; }
            $visible[] = $row;
        }
        $rows = [];
        $desired = [];
        foreach ($visible as $row) {
            $cells = [];
            foreach (array_values($row) as $column => $value) {
                $cell = Terminal::clean((string)$value);
                $cells[] = $cell;
                $desired[$column] = max($desired[$column] ?? 1, Terminal::width($cell));
            }
            $rows[] = $cells;
        }
        if (!$desired) { return ''; }

        $columns = count($desired);
        $gap = $width >= $columns + 2 * ($columns - 1) ? 2 : ($width >= 2 * $columns - 1 ? 1 : 0);
        $widths = $this->allocate($desired, max(0, $width - $gap * ($columns - 1)));
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($widths as $column => $size) {
                if ($size === 0) { continue; }
                $value = Terminal::fit($row[$column] ?? '', $size);
                $cells[] = $value . str_repeat(' ', max(0, $size - Terminal::width($value)));
            }
            $lines[] = rtrim(implode(str_repeat(' ', $gap), $cells));
        }
        return implode(PHP_EOL, $lines);
    }

    /** 给长列设置共同宽度上限，保留短状态列，避免第一列吃掉整个视口。 */
    private function allocate(array $desired, int $budget): array
    {
        if (array_sum($desired) <= $budget) { return $desired; }
        if ($budget < count($desired)) {
            return array_map(static fn ($index) => $index < $budget ? 1 : 0, array_keys($desired));
        }
        $low = 1;
        $high = min(max($desired), $budget);
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $needed = array_sum(array_map(static fn ($size) => min($size, $middle), $desired));
            if ($needed <= $budget) { $low = $middle; }
            else { $high = $middle - 1; }
        }
        $widths = array_map(static fn ($size) => min($size, $low), $desired);
        $remaining = $budget - array_sum($widths);
        foreach ($widths as $column => &$size) {
            if ($remaining > 0 && $size < $desired[$column]) { ++$size; --$remaining; }
        }
        unset($size);
        return $widths;
    }
}
