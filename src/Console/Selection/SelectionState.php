<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Selection;

/** 选择状态机：解释按键、搜索和多选，不直接读取终端，便于独立验证状态转换。 */
final class SelectionState
{
    private int $cursor = 0;
    private array $selected = [];
    private string $query = '';
    private bool $search = false;
    private ?array $visible = null;

    /** @param list<Choice> $choices 已由 Console 校验并连续编号的选项。 */
    public function __construct(private readonly array $choices, private readonly bool $multiple) {}

    /** 仅搜索条件变化时重建索引；原始索引保持不变，隐藏的已选项不会丢失。 */
    private function indices(): array
    {
        return $this->visible ??= array_keys(array_filter($this->choices, fn (Choice $choice) =>
            $this->query === '' || mb_stripos($choice->label . ' ' . $choice->description, $this->query, 0, 'UTF-8') !== false));
    }

    public function handle(?string $key): ?Selection
    {
        if ($key === null) { return Selection::eof(); }
        if ($key === 'cancel' || (!$this->search && in_array($key, ['q', 'Q'], true))) {
            return Selection::cancel();
        }
        if ($this->search) {
            if ($key === 'enter') { $this->search = false; }
            elseif ($key === 'backspace') { $this->query = mb_substr($this->query, 0, -1, 'UTF-8'); }
            elseif ($key === 'toggle') { $this->query .= ' '; }
            elseif (preg_match('/^[^\x00-\x1f\x7f]$/uD', $key) === 1) { $this->query .= $key; }
            $this->visible = null;
            $this->cursor = 0;
            return null;
        }
        if ($key === '/') { $this->search = true; return null; }

        $indices = $this->indices();
        if ($key === 'up' || $key === 'down') {
            // 取消行也参与导航，空搜索结果仍然可以取消。
            $this->cursor = ($this->cursor + ($key === 'up' ? -1 : 1) + count($indices) + 1) % (count($indices) + 1);
        } elseif ($key === 'toggle' && isset($indices[$this->cursor])) {
            $index = $indices[$this->cursor];
            if (!$this->multiple) { $this->selected = [$index => true]; }
            elseif (isset($this->selected[$index])) { unset($this->selected[$index]); }
            else { $this->selected[$index] = true; }
        } elseif ($this->multiple && in_array($key, ['a', 'A'], true)) {
            $all = $indices && count(array_intersect_key($this->selected, array_flip($indices))) === count($indices);
            foreach ($indices as $index) {
                if ($all) { unset($this->selected[$index]); }
                else { $this->selected[$index] = true; }
            }
        } elseif ($key === 'enter') {
            if ($this->cursor === count($indices)) { return Selection::cancel(); }
            if (!$this->multiple || !$this->selected) { $this->selected = [$indices[$this->cursor] => true]; }
            return Selection::selected(array_values(array_intersect_key($this->choices, $this->selected)));
        }
        return null;
    }

    public function render(string $title, int $rows): string
    {
        $indices = $this->indices();
        $limit = max(1, intdiv($rows - 6, 2));
        $start = max(0, min($this->cursor - intdiv($limit, 2), count($indices) - $limit));
        $lines = [$title, '↑/↓ Move  Space Toggle  a All/None  / Search  Enter Continue  q/Esc Cancel', 'Search: ' . $this->query];
        foreach (array_slice($indices, $start, $limit, true) as $position => $index) {
            $choice = $this->choices[$index];
            $lines[] = ($this->cursor === $position ? '> ' : '  ') . '[' . (isset($this->selected[$index]) ? 'x' : ' ') . '] ' . $choice->label;
            $lines[] = '    ' . $choice->description;
        }
        $lines[] = ($this->cursor === count($indices) ? '> ' : '  ') . '[0] Cancel';
        $lines[] = 'Selected: ' . count($this->selected);
        return implode("\n", $lines);
    }
}
