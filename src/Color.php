<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Command;

/**
 * Class Color
 * @package EasySwoole\Command
 * @method static string black(string $text)
 * @method static string red(string $text)
 * @method static string green(string $text)
 * @method static string brown(string $text)
 * @method static string blue(string $text)
 * @method static string cyan(string $text)
 * @method static string normal(string $text)
 * @method static string yellow(string $text)
 * @method static string magenta(string $text)
 * @method static string white(string $text)
 */
class Color
{
    public const STYLES = [
        'black' => '0;30',
        'red' => '0;31',
        'green' => '0;32',
        'brown' => '0;33',
        'blue' => '0;34',
        'cyan' => '0;36',
        'normal' => '39',// no color
        'yellow' => '1;33',
        'magenta' => '1;35',
        'white' => '1;37',
    ];

    public const COLOR_TPL = "\033[%sm%s\e[0m";


    /**
     * @param string $method
     * @param array $arguments
     * @return string
     */
    public static function __callStatic(string $method, array $arguments): string
    {
        if (isset(self::STYLES[$method])) {
            return self::render($arguments[0], $method);
        }

        return '';
    }

    /**
     * @param string $text
     * @param null $style
     * @return string
     */
    public static function render(string $text, $style = null): string
    {
        $color = self::STYLES[$style] ?? 0;
        return sprintf(self::COLOR_TPL, $color, $text);
    }
}