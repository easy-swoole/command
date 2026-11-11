<?php

declare(strict_types=1);

namespace EasySwoole\Command\Validation;

/** 通用规则同时适用于选项和位置参数，接收转换后的值，返回 true 或错误消息。 */
final class Rules
{
    /** 拒绝 null、空字符串及 Unicode 空白，保留数字 0 和布尔值 false。 */
    public static function notBlank(string $message = 'value must not be blank'): \Closure
    {
        return static fn (mixed $value): bool|string =>
            $value !== null && (!is_string($value) || preg_match('/\S/u', $value) === 1)
                ? true : $message;
    }

    /** 严格比较类型；整数枚举应使用整数列表，字符串枚举区分大小写。 */
    public static function oneOf(array $allowed, string $message = 'value is not an allowed choice'): \Closure
    {
        return static fn (mixed $value): bool|string => in_array($value, $allowed, true) ? true : $message;
    }

    /** 闭区间规则，定义阶段拒绝不合法的上下界。 */
    public static function range(int|float $min, int|float $max, string $message = 'value is out of range'): \Closure
    {
        if (!is_finite((float)$min) || !is_finite((float)$max) || $min > $max) {
            throw new \InvalidArgumentException('invalid validation range');
        }
        return static fn (mixed $value): bool|string =>
            (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= $min && $value <= $max
                ? true : $message;
    }
}
