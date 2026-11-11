<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Selection;

/** 区分确认、主动取消与输入结束；values 返回原始业务值，不要求调用方反查展示序号。 */
final class Selection
{
    private function __construct(public readonly string $state, public readonly array $choices = []) {}
    public static function selected(array $choices): self { return new self('selected', $choices); }
    public static function cancel(): self { return new self('cancelled'); }
    public static function eof(): self { return new self('eof'); }
    public function cancelled(): bool { return $this->state !== 'selected'; }
    public function values(): array { return array_map(static fn (Choice $c) => $c->value, $this->choices); }
}
