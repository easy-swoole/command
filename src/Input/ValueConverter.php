<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\Exception\InputException;

/** 选项和位置参数共用的类型转换；默认值错误与用户输入错误分别报告。 */
final class ValueConverter
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

    /** 默认值错误属于定义错误，提前拒绝非法数值，同时保留转换异常作为原因。 */
    public static function defaultValue(mixed $value, string $type, string $label): mixed
    {
        if ($value === null) { return null; }
        try { return self::convert($value, $type, $label); }
        catch (InputException $error) {
            throw new \InvalidArgumentException('invalid default for ' . $label . ': ' . $error->getMessage(), 0, $error);
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
