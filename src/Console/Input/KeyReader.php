<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Input;

/** 读取完整 UTF-8 字符及 CSI/SS3 按键，未知序列也必须消费完毕，不能泄漏为搜索文本。 */
final class KeyReader
{
    private ?string $pending = null;
    private bool $discardSequence = false;
    private \Closure $wait;

    public function __construct(private mixed $input, callable $wait)
    { $this->wait = \Closure::fromCallable($wait); }

    /** 事件循环必须先处理暂存字节，不能继续等待底层流出现新输入。 */
    public function hasBufferedInput(): bool { return $this->pending !== null; }

    private function byte(): ?string
    {
        if ($this->pending !== null) { $byte = $this->pending; $this->pending = null; return $byte; }
        $byte = fgetc($this->input);
        return $byte === false ? null : $byte;
    }

    private function following(float $deadline): ?string
    {
        if ($this->pending !== null) { return $this->byte(); }
        $remaining = $deadline - hrtime(true) / 1e9;
        return $remaining > 0 && ($this->wait)($remaining) ? $this->byte() : null;
    }

    public function read(): ?string
    {
        $char = $this->byte();
        if ($char === null) { return null; }
        if ($this->discardSequence) { return $this->sequence($char, true); }
        $byte = ord($char);
        if ($byte >= 0xc2 && $byte <= 0xf4) {
            $length = $byte < 0xe0 ? 2 : ($byte < 0xf0 ? 3 : 4);
            $character = $char;
            $deadline = hrtime(true) / 1e9 + .1;
            for ($index = 1; $index < $length; ++$index) {
                $next = $this->following($deadline);
                if ($next === null) { return 'ignore'; }
                if ((ord($next) & 0xc0) !== 0x80) {
                    $this->pending = $next; // 无效字符不能吞掉紧随其后的正常按键。
                    return 'ignore';
                }
                $character .= $next;
            }
            return preg_match('//u', $character) === 1 ? $character : 'ignore';
        }
        if ($char === "\033") {
            $prefix = $this->following(hrtime(true) / 1e9 + .05);
            if ($prefix === null) { return 'cancel'; }
            if ($prefix !== '[' && $prefix !== 'O') { return 'ignore'; }
            return $this->sequence();
        }
        if ($byte >= 0x80) { return 'ignore'; }
        return match ($char) {
            "\r", "\n" => 'enter', ' ' => 'toggle', "\177", "\010" => 'backspace',
            "\003", "\004", "\032" => 'cancel', default => $char,
        };
    }

    /** 有界等待避免半条序列阻塞界面；超时后丢弃后续字节直到终止符。 */
    private function sequence(?string $first = null, bool $discard = false): string
    {
        $sequence = '';
        $deadline = hrtime(true) / 1e9 + .1;
        for ($length = 0; $length < 256; ++$length) {
            $char = $first ?? $this->following($deadline);
            $first = null;
            if ($char === null) { $this->discardSequence = true; return 'ignore'; }
            $byte = ord($char);
            if ($byte < 0x20 || $byte > 0x7e) {
                $this->pending = $char;
                $this->discardSequence = false;
                return 'ignore';
            }
            if ($byte >= 0x40) {
                $this->discardSequence = false;
                if ($discard) { return 'ignore'; }
                return match ($char) {
                    'A' => 'up', 'B' => 'down', 'C' => 'right', 'D' => 'left',
                    'H' => 'home', 'F' => 'end',
                    '~' => match (explode(';', $sequence, 2)[0]) {
                        '1', '7' => 'home', '4', '8' => 'end', '5' => 'pageUp', '6' => 'pageDown',
                        '2' => 'insert', '3' => 'delete', default => 'ignore',
                    },
                    default => 'ignore',
                };
            }
            $sequence .= $char;
        }
        $this->discardSequence = true;
        return 'ignore';
    }
}
