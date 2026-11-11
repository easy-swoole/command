<?php

declare(strict_types=1);

namespace EasySwoole\Command\Validation;

use EasySwoole\Command\Exception\InputException;

/** 选项和位置参数共用类型转换及校验链，避免两种输入的边界规则不一致。 */
final class ValueValidator
{
    public static function convert(mixed $value, string $type, string $label): mixed
    {
        return match ($type) {
            'string' => is_scalar($value) || $value instanceof \Stringable
                ? (string)$value : throw new InputException($label . ' must be a string'),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new InputException($label . ' must be a boolean'),
            'int' => self::integer($value, $label),
            'float' => is_numeric($value) && is_finite((float)$value)
                ? (float)$value : throw new InputException($label . ' must be a finite number'),
            default => throw new \LogicException('unsupported input type: ' . $type),
        };
    }

    /** @param list<\Closure> $validators 按添加顺序执行，首个失败立即中止。 */
    public static function validate(mixed $value, array $validators, string $label): void
    {
        foreach ($validators as $validator) {
            $valid = $validator($value);
            if ($valid !== true) {
                throw new InputException(is_string($valid) ? $valid : $label . ' is invalid');
            }
        }
    }

    private static function integer(mixed $value, string $label): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^-?\d+$/D', (string)$value)) {
            throw new InputException($label . ' must be an integer');
        }
        // 先去除符号和前导零，再检查整数边界，不提前转换以免发生溢出截断。
        $text = (string)$value;
        $negative = str_starts_with($text, '-');
        $digits = ltrim($negative ? substr($text, 1) : $text, '0');
        $normalized = ($negative && $digits !== '' ? '-' : '') . ($digits === '' ? '0' : $digits);
        $number = filter_var($normalized, FILTER_VALIDATE_INT);
        if ($number === false) { throw new InputException($label . ' is out of integer range'); }
        return $number;
    }
}
