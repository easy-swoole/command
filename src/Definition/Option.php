<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

use EasySwoole\Command\Exception\InputException;

/** 不可变的选项声明；将原始字符串与存在性转换为业务可直接使用的类型值。 */
final class Option
{
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description,
        public readonly mixed $default,
        public readonly bool $required,
        public readonly mixed $implicit,
        private readonly ?\Closure $validator = null,
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name) || $name === 'help') {
            throw new \InvalidArgumentException('invalid or reserved option name: ' . $name);
        }
    }
    public static function string(string $name, string $description = '', ?string $default = null, bool $required = false): self
    { return new self($name, 'string', $description, $default, $required, null); }
    public static function flag(string $name, string $description = '', bool $default = false): self
    { return new self($name, 'bool', $description, $default, false, true); }
    public static function integer(string $name, string $description = '', ?int $default = null, bool $required = false): self
    { return new self($name, 'int', $description, $default, $required, null); }
    public static function number(string $name, string $description = '', ?float $default = null, ?float $implicit = null): self
    { return new self($name, 'float', $description, $default, false, $implicit); }
    public function validate(callable $validator): self
    { return new self($this->name, $this->type, $this->description, $this->default, $this->required, $this->implicit, \Closure::fromCallable($validator)); }
    public function bind(mixed $value, bool $provided): mixed
    {
        // 未提供选项时使用默认值；显式提供但省略值时，只允许使用声明过的隐式值。
        if (!$provided) {
            if ($this->required && $this->default === null) { throw new InputException('--' . $this->name . ' is required'); }
            $value = $this->default;
        } elseif ($value === null) {
            if ($this->implicit === null) { throw new InputException('--' . $this->name . ' requires a value'); }
            $value = $this->implicit;
        }
        // 先转换类型再调用自定义校验，保证校验器接收到整数、布尔等明确类型。
        if ($value !== null) {
            $value = match ($this->type) {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? throw new InputException('--' . $this->name . ' must be a boolean'),
                'int' => $this->integerValue($value),
                'float' => is_numeric($value) && is_finite((float)$value) ? (float)$value
                    : throw new InputException('--' . $this->name . ' must be a finite number'),
                default => (string)$value,
            };
        }
        if ($this->validator !== null) {
            $valid = ($this->validator)($value);
            if ($valid !== true) { throw new InputException(is_string($valid) ? $valid : '--' . $this->name . ' is invalid'); }
        }
        return $value;
    }
    private function integerValue(mixed $value): int
    {
        if (!preg_match('/^-?\d+$/D', (string)$value)) { throw new InputException('--' . $this->name . ' must be an integer'); }
        $number = filter_var((string)$value, FILTER_VALIDATE_INT);
        // 接受带前导零的整数，同时保留溢出检查。
        if ($number === false) {
            $normalized = ltrim((string)$value, '0');
            $number = filter_var($normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT);
        }
        if ($number === false) { throw new InputException('--' . $this->name . ' is out of integer range'); }
        return $number;
    }
}
