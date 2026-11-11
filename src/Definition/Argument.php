<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Input\ValueConverter;
use EasySwoole\Command\Validation\ValidationChain;

/** 位置参数支持与选项一致的类型、默认值和校验链；required 不代替非空值规则。 */
final class Argument
{
    public readonly mixed $default;
    private ValidationChain $validators;

    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool $required = false,
        mixed $default = null,
        public readonly string $type = 'string',
        array $validators = [],
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name)) {
            throw new \InvalidArgumentException('invalid argument name');
        }
        if (!in_array($type, ['string', 'int', 'float', 'bool'], true)) {
            throw new \InvalidArgumentException('invalid argument type');
        }
        $this->default = ValueConverter::defaultValue($default, $type, 'argument ' . $name);
        $this->validators = new ValidationChain($validators);
    }

    public static function string(string $name, string $description = '', ?string $default = null, bool $required = false): self
    { return new self($name, $description, $required, $default); }
    public static function integer(string $name, string $description = '', ?int $default = null, bool $required = false): self
    { return new self($name, $description, $required, $default, 'int'); }
    public static function number(string $name, string $description = '', ?float $default = null, bool $required = false): self
    { return new self($name, $description, $required, $default, 'float'); }
    public static function boolean(string $name, string $description = '', ?bool $default = null, bool $required = false): self
    { return new self($name, $description, $required, $default, 'bool'); }

    public function isRequired(): bool { return $this->required && $this->default === null; }

    /** 按顺序追加值校验并返回新定义，不修改已有参数规则。 */
    public function validate(callable $validator): self
    {
        $argument = clone $this;
        $argument->validators = $this->validators->append($validator);
        return $argument;
    }

    public function bind(mixed $value, bool $provided): mixed
    {
        $label = 'argument ' . $this->name;
        if (!$provided) {
            if ($this->isRequired()) { throw new InputException($label . ' is required'); }
            $value = $this->default;
            // 可选且无默认值时保持 null，有默认值时仍需通过类型转换与校验。
            if ($value === null) { return null; }
        } elseif ($value === null) {
            throw new InputException($label . ' requires a value');
        }
        $value = ValueConverter::convert($value, $this->type, $label);
        $this->validators->validate($value, $label);
        return $value;
    }
}
