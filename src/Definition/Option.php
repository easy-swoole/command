<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Validation\ValueValidator;

/** 不可变选项定义；追加校验返回新对象，原定义仍可独立复用。 */
final class Option
{
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description,
        public readonly mixed $default,
        public readonly bool $required,
        public readonly mixed $implicit,
        private readonly array $validators = [],
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
    public static function number(string $name, string $description = '', ?float $default = null, ?float $implicit = null, bool $required = false): self
    { return new self($name, 'float', $description, $default, $required, $implicit); }

    /** 按顺序追加值校验并返回新定义；首条失败由绑定阶段中止。 */
    public function validate(callable $validator): self
    {
        return new self($this->name, $this->type, $this->description, $this->default, $this->required,
            $this->implicit, [...$this->validators, \Closure::fromCallable($validator)]);
    }

    public function bind(mixed $value, bool $provided): mixed
    {
        $label = '--' . $this->name;
        if (!$provided) {
            if ($this->required && $this->default === null) { throw new InputException($label . ' is required'); }
            $value = $this->default;
            // 没有值的可选字段不调用值校验器，有默认值的字段仍需转换和校验。
            if ($value === null) { return null; }
        } elseif ($value === null) {
            if ($this->implicit === null) { throw new InputException($label . ' requires a value'); }
            $value = $this->implicit;
        }
        $value = ValueValidator::convert($value, $this->type, $label);
        ValueValidator::validate($value, $this->validators, $label);
        return $value;
    }
}
