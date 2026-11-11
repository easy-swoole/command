<?php

declare(strict_types=1);

namespace EasySwoole\Command;

class Console
{
    private Terminal $terminal;
    protected mixed $output;
    protected mixed $error;
    private bool $active = false;
    public function __construct($input = null, $output = null, $error = null, ?Terminal $terminal = null)
    {
        $this->output = $output ?? STDOUT; $this->error = $error ?? STDERR;
        $this->terminal = $terminal ?? new Terminal($input, $this->output);
    }
    public function terminal(): Terminal { return $this->terminal; }
    public function interactive(): bool { return $this->terminal->interactive(); }
    public function line(string $text = '', bool $error = false): void
    {
        if ($this->active) { throw new \LogicException('return a view instead of writing during an interactive session'); }
        $stream = $error ? $this->error : $this->output;
        if (!stream_isatty($stream) || getenv('NO_COLOR') !== false || getenv('TERM') === 'dumb') {
            $text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text);
        }
        fwrite($stream, $text . PHP_EOL);
    }
    public function info(string $text): void { $this->line($text); }
    public function error(string $text): void { $this->line($text, true); }
    public function table(array $headers, array $rows): void
    { $this->line((new TableView($headers, $rows))->render()); }
    public function prompt(string $question): ?string
    {
        if ($this->active) { throw new \LogicException('an interactive view is already active'); }
        if (!$this->interactive()) { throw new \RuntimeException('interactive input requires a terminal; pass explicit parameters'); }
        fwrite($this->output, $question);
        return $this->terminal->readLine();
    }
    public function confirm(string $question, bool $default = false): bool
    {
        while (true) {
            $answer = $this->prompt($question . ($default ? ' [Y/n]: ' : ' [y/N]: '));
            if ($answer === null) { return false; }
            $answer = strtolower(trim($answer));
            if ($answer === '') { return $default; }
            if (in_array($answer, ['y', 'yes', 'n', 'no'], true)) { return in_array($answer, ['y', 'yes'], true); }
            $this->line('Please enter y or n.');
        }
    }
    private function session(callable $callback): mixed
    {
        if ($this->active) { throw new \LogicException('an interactive view is already active'); }
        $this->active = true;
        try { $this->terminal->open(); }
        catch (\Throwable $error) { $this->active = false; throw $error; }
        try { return $callback(); }
        finally { $this->terminal->close(); $this->active = false; }
    }
    /** 刷新回调返回文本或 ViewInterface 视图；返回 false 结束视图。 */
    public function live(callable $refresh, float $interval = 2): void
    {
        if (!is_finite($interval) || $interval <= 0) { throw new \InvalidArgumentException('refresh interval must be positive'); }
        $this->session(function () use ($refresh, $interval): void {
            $next = 0.0; $view = ''; $dimensions = null;
            while (true) {
                $now = $this->terminal->now();
                if ($now >= $next) {
                    $view = $refresh();
                    if ($view === false) { return; }
                    if (!is_string($view) && !$view instanceof ViewInterface) { throw new \UnexpectedValueException('live callback must return text, a view, or false'); }
                    $next = $this->terminal->now() + $interval; $dimensions = null;
                }
                $size = $this->terminal->dimensions();
                if ($dimensions !== $size) {
                    [$height, $width] = $size;
                    $text = $view instanceof ViewInterface ? $view->render($width - 1, max(1, $height - 3)) : $view;
                    $this->terminal->frame('Refreshing every ' . $interval . "s | q/Esc/Ctrl+C to exit\n\n" . $text);
                    $dimensions = $size;
                }
                if ($this->terminal->wait(min(.1, max(0, $next - $this->terminal->now())))) {
                    $key = $this->terminal->key();
                    if ($key === null || in_array($key, ['cancel', 'q', 'Q'], true)) { return; }
                }
            }
        });
    }
    /** @param list<Choice> $choices */
    public function select(string $title, array $choices, bool $multiple = false): Selection
    {
        $choices = array_values($choices); $ids = [];
        foreach ($choices as $choice) {
            if (!$choice instanceof Choice) { throw new \InvalidArgumentException('choices must be Choice objects'); }
            if (isset($ids[(string)$choice->id])) { throw new \InvalidArgumentException('duplicate choice id'); }
            $ids[(string)$choice->id] = true;
        }
        if (!$choices) { return Selection::selected([]); }
        if (!$this->interactive()) { throw new \RuntimeException('interactive selection requires a terminal; pass explicit parameters'); }
        if (!$this->terminal->supported()) { return $this->numberedSelect($title, $choices, $multiple); }
        try { return $this->session(function () use ($title, $choices, $multiple): Selection {
            $cursor = 0; $selected = []; $query = ''; $search = false; $previousFrame = null;
            while (true) {
                $visible = array_filter($choices, static fn ($c) => $query === '' || stripos($c->label . ' ' . $c->description, $query) !== false);
                $indices = array_keys($visible); $cursor = min($cursor, count($indices));
                [$rows, $columns] = $this->terminal->dimensions(); $limit = max(1, intdiv($rows - 6, 2));
                $start = max(0, min($cursor - intdiv($limit, 2), count($indices) - $limit));
                $lines = [$title, '↑/↓ Move  Space Toggle  a All/None  / Search  Enter Continue  q/Esc Cancel', 'Search: ' . $query];
                foreach (array_slice($indices, $start, $limit, true) as $position => $index) {
                    $choice = $choices[$index];
                    $lines[] = ($cursor === $position ? '> ' : '  ') . '[' . (isset($selected[$index]) ? 'x' : ' ') . '] ' . $choice->label;
                    $lines[] = '    ' . $choice->description;
                }
                $lines[] = ($cursor === count($indices) ? '> ' : '  ') . '[0] Cancel';
                $lines[] = 'Selected: ' . count($selected);
                $frame = implode("\n", $lines);
                $signature = [$frame, $rows, $columns];
                if ($signature !== $previousFrame) { $this->terminal->frame($frame); $previousFrame = $signature; }
                if (!$this->terminal->wait(.1)) { continue; }
                $key = $this->terminal->key();
                if ($key === null) { return Selection::eof(); }
                if ($key === 'cancel' || (!$search && in_array($key, ['q', 'Q'], true))) { return Selection::cancel(); }
                if ($search) {
                    if ($key === 'enter') { $search = false; }
                    elseif ($key === 'backspace') { $query = function_exists('mb_substr') ? mb_substr($query, 0, -1, 'UTF-8') : substr($query, 0, -1); }
                    elseif ($key === 'toggle') { $query .= ' '; }
                    elseif (preg_match('/^[^\\x00-\\x1f\\x7f]$/uD', $key) === 1) { $query .= $key; }
                    $cursor = 0; continue;
                }
                if ($key === '/') { $search = true; continue; }
                if ($key === 'up' || $key === 'down') {
                    $cursor = ($cursor + ($key === 'up' ? -1 : 1) + count($indices) + 1) % (count($indices) + 1);
                } elseif ($key === 'toggle' && isset($indices[$cursor])) {
                    $index = $indices[$cursor];
                    if (!$multiple) { $selected = [$index => true]; }
                    elseif (isset($selected[$index])) { unset($selected[$index]); }
                    else { $selected[$index] = true; }
                } elseif ($multiple && in_array($key, ['a', 'A'], true)) {
                    $all = $indices && count(array_intersect_key($selected, $visible)) === count($visible);
                    foreach ($indices as $index) { if ($all) { unset($selected[$index]); } else { $selected[$index] = true; } }
                } elseif ($key === 'enter') {
                    if ($cursor === count($indices)) { return Selection::cancel(); }
                    if (!$multiple || !$selected) { $selected = [$indices[$cursor] => true]; }
                    return Selection::selected(array_values(array_intersect_key($choices, $selected)));
                }
            }
        }); } catch (TerminalException $error) {
            return $this->numberedSelect($title, $choices, $multiple);
        }
    }
    private function numberedSelect(string $title, array $choices, bool $multiple): Selection
    {
        $this->line($title);
        foreach ($choices as $index => $choice) {
            $this->line('[' . ($index + 1) . '] ' . Terminal::clean($choice->label));
            if ($choice->description !== '') { $this->line('    ' . Terminal::clean($choice->description)); }
        }
        $this->line('[0] Cancel');
        while (true) {
            $answer = $this->prompt($multiple ? 'Enter indexes (comma-separated) [0]: ' : 'Enter index [0]: ');
            if ($answer === null) { return Selection::eof(); }
            $answer = trim($answer);
            if ($answer === '' || $answer === '0' || strtolower($answer) === 'q') { return Selection::cancel(); }
            $selected = [];
            foreach (preg_split('/[\s,，]+/u', $answer) as $index) {
                if (!ctype_digit($index) || (int)$index < 1 || (int)$index > count($choices)) { $selected = []; break; }
                $selected[(int)$index - 1] = $choices[(int)$index - 1];
            }
            if ($selected && ($multiple || count($selected) === 1)) { return Selection::selected(array_values($selected)); }
            $this->line('Invalid selection; enter listed indexes or 0 to cancel.');
        }
    }
}
