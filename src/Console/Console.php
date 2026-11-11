<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console;

use EasySwoole\Command\Console\Live\LiveRunner;
use EasySwoole\Command\Console\Live\LiveState;
use EasySwoole\Command\Console\Selection\Choice;
use EasySwoole\Command\Console\Selection\Selection;
use EasySwoole\Command\Console\Selection\SelectionRunner;
use EasySwoole\Command\Console\View\TableView;
use EasySwoole\Command\Exception\TerminalException;
use EasySwoole\Command\Result\CommandResult;

/** 面向命令的统一交互入口，集中协调输出、选择和实时视图对终端的占用。 */
class Console
{
    private Terminal $terminal;
    protected mixed $output;
    protected mixed $error;
    private bool $active = false;
    public function __construct(
        $input = null, $output = null, $error = null, ?Terminal $terminal = null,
        private bool $allowInteraction = true, private bool $json = false,
    )
    {
        $this->output = $output ?? STDOUT; $this->error = $error ?? STDERR;
        $this->terminal = $terminal ?? new Terminal($input, $this->output);
    }
    public function terminal(): Terminal { return $this->terminal; }
    public function interactive(): bool { return $this->allowInteraction && !$this->json && $this->terminal->interactive(); }
    /** 按实际输出流判断颜色能力，重定向、NO_COLOR 和简易终端统一使用纯文本。 */
    public function supportsColor(bool $error = false): bool
    {
        return !$this->json && Text::supportsColor($error ? $this->error : $this->output);
    }
    public function line(string $text = '', bool $error = false): void
    {
        // 活动视图独占屏幕；普通输出会破坏光标位置，因此要求回调返回待渲染内容。
        if ($this->active) { throw new \LogicException('return a view instead of writing during an interactive session'); }
        $error = $error || $this->json;
        $stream = $error ? $this->error : $this->output;
        $text = Text::clean($text, $this->supportsColor($error), multiline: true);
        fwrite($stream, $text . PHP_EOL);
    }
    /** JSON 结果独占标准输出，普通 line/info/error 在该模式下都写入标准错误。 */
    public function writeResult(CommandResult $result): void
    {
        if ($this->json) {
            $payload = json_encode([
                'exitCode' => $result->exitCode,
                'message' => $result->message === null ? null : Text::clean($result->message, false, multiline: true),
                'data' => $result->data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            fwrite($this->output, $payload . PHP_EOL);
        } elseif ($result->message !== null && $result->message !== '') {
            $this->line($result->message, $result->exitCode !== 0);
        }
    }

    public function info(string $text): void { $this->line($text); }
    public function error(string $text): void { $this->line($text, true); }
    public function table(array $headers, array $rows): void
    { $this->line((new TableView($headers, $rows))->render()); }
    public function prompt(string $question): ?string
    {
        if ($this->active) { throw new \LogicException('an interactive view is already active'); }
        if (!$this->interactive()) { throw new \RuntimeException('interactive input is unavailable or disabled; pass explicit parameters'); }
        fwrite($this->output, Text::clean($question, $this->supportsColor(), multiline: true));
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
    /** 会话成功打开后统一在 finally 中恢复终端；打开失败时不关闭其他会话持有的终端。 */
    private function session(callable $callback, ?callable $onUnavailable = null): mixed
    {
        if ($this->active) { throw new \LogicException('an interactive view is already active'); }
        $this->active = true;
        try { $this->terminal->open(); }
        catch (TerminalException $error) {
            $this->active = false;
            // 只有打开阶段的能力不足才允许降级；执行或清理期间的故障必须上抛。
            if ($onUnavailable !== null) { return $onUnavailable(); }
            throw $error;
        }
        catch (\Throwable $error) { $this->active = false; throw $error; }
        try { return $callback(); }
        finally {
            try { $this->terminal->close(); }
            finally { $this->active = false; }
        }
    }
    /** 刷新回调返回文本或 ViewInterface 视图；返回 false 结束视图。 */
    public function live(callable $refresh, float $interval = 2): void
    {
        if (!$this->allowInteraction || $this->json) { throw new \RuntimeException('interactive output is disabled'); }
        $state = new LiveState($interval);
        $this->session(fn () => (new LiveRunner($this->terminal))->run($refresh, $state));
    }

    /**
     * 按稳定的选项 ID 校验唯一性，返回原始业务值；展示序号只用于交互，不作为业务标识。
     * @param list<Choice> $choices
     */
    public function select(string $title, array $choices, bool $multiple = false): Selection
    {
        $choices = array_values($choices); $ids = [];
        foreach ($choices as $choice) {
            if (!$choice instanceof Choice) { throw new \InvalidArgumentException('choices must be Choice objects'); }
            if (isset($ids[(string)$choice->id])) { throw new \InvalidArgumentException('duplicate choice id'); }
            $ids[(string)$choice->id] = true;
        }
        if (!$choices) { return Selection::selected([]); }
        // 无交互输入时明确失败，不自动选择；有交互输入但无原始模式时降级为编号选择。
        if (!$this->interactive()) { throw new \RuntimeException('interactive selection is unavailable or disabled; pass explicit parameters'); }
        if (!$this->terminal->supported()) { return $this->numberedSelect($title, $choices, $multiple); }
        return $this->session(
            fn () => (new SelectionRunner($this->terminal))->run($title, $choices, $multiple),
            fn () => $this->numberedSelect($title, $choices, $multiple),
        );
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
