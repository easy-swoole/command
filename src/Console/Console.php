<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console;

use EasySwoole\Command\Console\Live\LiveRunner;
use EasySwoole\Command\Console\Live\LiveState;
use EasySwoole\Command\Console\Selection\Choice;
use EasySwoole\Command\Console\Selection\NumberedSelectionRunner;
use EasySwoole\Command\Console\Selection\Selection;
use EasySwoole\Command\Console\Selection\SelectionRunner;
use EasySwoole\Command\Console\View\TableView;
use EasySwoole\Command\Exception\TerminalException;
use EasySwoole\Command\Result\CommandResult;
use EasySwoole\Command\Result\ExitCode;

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
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
        $this->terminal = $terminal ?? new Terminal($input, $this->textStream());
    }
    public function terminal(): Terminal { return $this->terminal; }
    public function interactive(): bool
    {
        return $this->allowInteraction && !$this->json && $this->terminal->interactive()
            && (stream_isatty($this->output) || stream_isatty($this->error));
    }
    /** 按实际输出流判断颜色能力，重定向、NO_COLOR 和简易终端统一使用纯文本。 */
    public function supportsColor(bool $error = false): bool
    {
        return !$this->json && Text::supportsColor($this->textStream($error));
    }
    public function line(string $text = '', bool $error = false): void
    {
        // 活动视图独占屏幕；普通输出会破坏光标位置，因此要求回调返回待渲染内容。
        if ($this->active) { throw new \LogicException('return a view instead of writing during an interactive session'); }
        $this->writeText($text, $error, newline: true);
    }

    /** JSON 模式的普通文字走 stderr，完整 JSON 结果直接写入 stdout。 */
    private function textStream(bool $error = false): mixed
    {
        return $error || $this->json ? $this->error : $this->output;
    }

    private function writeText(string $text, bool $error, bool $newline = false): void
    {
        $text = Text::clean($text, $this->supportsColor($error || $this->json), multiline: true);
        $this->write($this->textStream($error), $newline ? $text . PHP_EOL : $text);
    }

    /** 检查写入字节数，避免管道断开或只写出部分内容时仍报告成功。 */
    private function write(mixed $stream, string $text): void
    {
        if (@fwrite($stream, $text) !== strlen($text)) {
            throw new TerminalException('cannot write console output');
        }
    }

    public function writeResult(CommandResult $result): void
    {
        if ($this->json) {
            $this->write($this->output, $this->encodeResult($result) . PHP_EOL);
            return;
        }

        if ($result->message !== null && $result->message !== '') {
            $this->line($result->message, $result->exitCode !== ExitCode::SUCCESS->value);
        }
    }

    private function encodeResult(CommandResult $result): string
    {
        $data = [
            'exitCode' => $result->exitCode,
            'message' => $result->message === null ? null : Text::clean($result->message, false, multiline: true),
            'data' => $result->data,
        ];
        try {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw $error;
        } catch (\Throwable $error) {
            // jsonSerialize() 内抛出的异常会直接传出，统一按编码失败处理。
            throw new \JsonException($error->getMessage(), 0, $error);
        }
    }

    public function info(string $text): void { $this->line($text); }
    public function error(string $text): void { $this->line($text, true); }
    public function table(array $headers, array $rows): void
    {
        $width = stream_isatty($this->textStream()) ? $this->terminal->dimensions()[1] : PHP_INT_MAX;
        $this->line((new TableView($headers, $rows))->render($width));
    }

    /** 交互文字与提示使用同一个输出流，避免菜单随 stdout 一起重定向。 */
    public function interactionLine(string $text): void
    {
        $this->line($text, $this->interactionUsesStderr());
    }

    private function interactionUsesStderr(): bool
    {
        return !stream_isatty($this->output) && stream_isatty($this->error);
    }

    public function prompt(string $question): ?string
    {
        if ($this->active) { throw new \LogicException('an interactive view is already active'); }
        $this->requireInteraction('input');
        $this->writeText($question, $this->interactionUsesStderr());
        return $this->terminal->readLine();
    }

    private function requireInteraction(string $operation): void
    {
        if (!$this->interactive()) {
            throw new \RuntimeException(
                'interactive ' . $operation . ' requires a terminal input and visible output; enable interaction or pass explicit parameters',
            );
        }
    }

    public function confirm(string $question, bool $default = false): bool
    {
        while (true) {
            $answer = $this->prompt($question . ($default ? ' [Y/n]: ' : ' [y/N]: '));
            if ($answer === null) { return false; }
            $answer = strtolower(trim($answer));
            if ($answer === '') { return $default; }
            if (in_array($answer, ['y', 'yes', 'n', 'no'], true)) { return in_array($answer, ['y', 'yes'], true); }
            $this->interactionLine('Please enter y or n.');
        }
    }
    /** 会话成功打开后统一在 finally 中恢复终端；打开失败时不关闭其他会话持有的终端。 */
    private function session(callable $callback, ?callable $onUnavailable = null): mixed
    {
        if ($this->active) { throw new \LogicException('an interactive view is already active'); }
        $this->active = true;
        try {
            try {
                $this->terminal->open();
            } catch (TerminalException $error) {
                // 只有打开失败时才能降级，编号菜单写入前先释放 Console 的独占状态。
                if ($onUnavailable === null) {
                    throw $error;
                }
                $this->active = false;
                return $onUnavailable();
            }

            try {
                return $callback();
            } finally {
                $this->terminal->close();
            }
        } finally {
            $this->active = false;
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
        $this->requireInteraction('selection');
        $numbered = fn () => (new NumberedSelectionRunner($this))->run($title, $choices, $multiple);
        if (!$this->terminal->supported()) {
            return $numbered();
        }
        return $this->session(
            fn () => (new SelectionRunner($this->terminal))->run($title, $choices, $multiple),
            $numbered,
        );
    }
}
