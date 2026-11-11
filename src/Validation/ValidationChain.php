<?php

declare(strict_types=1);

namespace EasySwoole\Command\Validation;

use EasySwoole\Command\Exception\InputException;

/** 不可变校验链：统一字段和整组输入规则的追加顺序、失败短路及错误消息。 */
final class ValidationChain
{
    /** @param list<\Closure> $rules */
    public function __construct(private readonly array $rules = [])
    {
        foreach ($rules as $rule) {
            if (!$rule instanceof \Closure) { throw new \InvalidArgumentException('invalid input validator'); }
        }
    }

    public function append(callable $rule): self
    { return new self([...$this->rules, \Closure::fromCallable($rule)]); }

    public function isEmpty(): bool { return $this->rules === []; }

    /** 首条规则失败立即停止；规则异常交给应用异常边界处理。 */
    public function validate(mixed $value, string $label): void
    {
        foreach ($this->rules as $rule) {
            $valid = $rule($value);
            if ($valid !== true) {
                throw new InputException(is_string($valid) ? $valid : $label . ' is invalid');
            }
        }
    }
}
