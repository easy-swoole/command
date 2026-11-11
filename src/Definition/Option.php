<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Input\ValueConverter;
use EasySwoole\Command\Validation\ValidationChain;

/** 不可变选项定义；追加校验或开启多值模式返回新对象，原定义可独立复用。 */
final class Option
{
    public readonly mixed $default;
    public readonly mixed $implicit;
    private ValidationChain $validators;

    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description,
        mixed $default,
        public readonly bool $required,
        mixed $implicit,
        public readonly bool $isMultiple = false,
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name) || $name === 'help') {
            throw new \InvalidArgumentException('invalid or reserved option name: ' . $name);
        }
        $this->validators = new ValidationChain();
        // 只验证、规范化默认类型，不在定义阶段执行可能依赖业务的自定义规则。
        $this->default = $isMultiple
            ? array_map(fn ($value) => ValueConverter::defaultValue($value, $type, '--' . $name), array_values($default))
            : ValueConverter::defaultValue($default, $type, '--' . $name);
        $this->implicit = ValueConverter::defaultValue($implicit, $type, '--' . $name . ' implicit');
        if ($isMultiple && in_array(null, $this->default, true)) {
            throw new \InvalidArgumentException('multiple option defaults must contain values');
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

    /** 逐项执行类型转换和校验；不传默认列表时，将原有默认值保留为单元素列表。 */
    public function multiple(?array $default = null): self
    {
        $default ??= $this->isMultiple ? $this->default : ($this->default === null ? [] : [$this->default]);
        $option = new self($this->name, $this->type, $this->description, $default, $this->required,
            $this->implicit, true);
        $option->validators = $this->validators;
        return $option;
    }

    public function isRequired(): bool
    { return $this->required && ($this->isMultiple ? $this->default === [] : $this->default === null); }

    /** 按顺序追加值校验并返回新定义；多值选项对每个元素分别执行规则。 */
    public function validate(callable $validator): self
    {
        $option = clone $this;
        $option->validators = $this->validators->append($validator);
        return $option;
    }

    public function bind(mixed $value, bool $provided): mixed
    {
        if (!$provided) {
            if ($this->isRequired()) { throw new InputException('--' . $this->name . ' is required'); }
            $value = $this->default;
            if ($value === null) { return null; }
        }
        if ($this->isMultiple) {
            $values = is_array($value) ? array_values($value) : [$value];
            if ($values === [] && $this->isRequired()) { throw new InputException('--' . $this->name . ' is required'); }
            return array_map(fn ($item) => $this->bindValue($item, $provided), $values);
        }
        return $this->bindValue($value, $provided);
    }

    private function bindValue(mixed $value, bool $provided): mixed
    {
        $label = '--' . $this->name;
        if ($provided && $value === null) {
            if ($this->implicit === null) { throw new InputException($label . ' requires a value'); }
            $value = $this->implicit;
        }
        $value = ValueConverter::convert($value, $this->type, $label);
        $this->validators->validate($value, $label);
        return $value;
    }
}
