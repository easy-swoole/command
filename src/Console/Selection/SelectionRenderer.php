<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Selection;

use EasySwoole\Command\Console\Text;

/** 只负责选择列表的布局与样式；渲染不改变游标、选中项和翻页步长。 */
final class SelectionRenderer
{
    /** 事件循环显式同步可见页大小，翻页行为不依赖是否执行过绘制。 */
    public function pageSize(int $rows, int $columns): int
    {
        $available = max(0, $rows - 1);
        if (!$this->compact($available, $columns)) { return max(1, intdiv($available - 5, 2)); }
        $header = $available >= 3 ? 1 : 0;
        return max(1, $available - $header - 1);
    }

    public function render(string $title, SelectionState $state, int $rows, int $columns = 80): string
    {
        $available = max(0, $rows - 1);
        if ($available === 0 || $columns <= 1) { return ''; }
        $indices = $state->visibleIndices();
        $pageSize = $this->pageSize($rows, $columns);
        if ($this->compact($available, $columns)) {
            $lines = $this->compactLines($title, $state, $available, $indices, $pageSize);
        } else {
            $start = $this->windowStart($state->cursor(), count($indices), $pageSize);
            $lines = [$title, '↑/↓ Move  Space Toggle  a All/None  / Search  Enter Continue  q/Esc Cancel', 'Search: ' . $state->query()];
            foreach (array_slice($indices, $start, $pageSize, true) as $position => $index) {
                $lines[] = $this->choiceLine($state, $position, $index);
                $lines[] = '    ' . $state->choice($index)->description;
            }
            $lines[] = ($state->cursor() === count($indices) ? '> ' : '  ') . '[0] Cancel';
            $lines[] = 'Selected: ' . $state->selectedCount();
        }
        return implode("\n", array_map(static fn ($line) => Text::fit($line, $columns - 1), $lines));
    }

    private function compact(int $available, int $columns): bool
    { return $available < 7 || $columns < 50; }

    /** 小视口省略描述并保留取消入口，仅剩一行时先展示取消键。 */
    private function compactLines(string $title, SelectionState $state, int $available, array $indices, int $pageSize): array
    {
        $cursor = $state->cursor();
        if ($available === 1) {
            return ['q Cancel | ' . (isset($indices[$cursor])
                ? $this->choiceLine($state, $cursor, $indices[$cursor]) : '> Cancel')];
        }
        $lines = $available >= 3 ? [$state->searching() || $state->query() !== '' ? 'Search: ' . $state->query() : $title] : [];
        $start = $this->windowStart($cursor, count($indices), $pageSize);
        foreach (array_slice($indices, $start, $pageSize, true) as $position => $index) {
            $lines[] = $this->choiceLine($state, $position, $index);
        }
        $lines[] = ($cursor === count($indices) ? '> ' : '  ') . 'q Cancel | Enter Select';
        return $lines;
    }

    private function windowStart(int $cursor, int $count, int $limit): int
    { return max(0, min($cursor - intdiv($limit, 2), $count - $limit)); }

    private function choiceLine(SelectionState $state, int $position, int $index): string
    {
        return ($state->cursor() === $position ? '> ' : '  ')
            . '[' . ($state->isSelected($index) ? 'x' : ' ') . '] ' . $state->choice($index)->label;
    }
}
