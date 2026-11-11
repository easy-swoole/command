<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console;

/** 统一输出文本的清理、颜色和可见宽度；只允许 SGR 样式，不允许内容控制终端光标。 */
final class Text
{
    private const ESCAPE = '/\x1b(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\\\)|[PX^_][\s\S]*?\x1b\\\\|[@-Z\\\\-_])/';

    public static function supportsColor(mixed $stream): bool
    {
        return stream_isatty($stream) && getenv('NO_COLOR') === false && getenv('TERM') !== 'dumb';
    }

    public static function clean(string $text, bool $colors = true, bool $multiline = false): string
    {
        $text = preg_replace_callback(self::ESCAPE, static fn (array $match): string =>
            $colors && preg_match('/^\x1b\[[0-9;:]*m$/D', $match[0]) === 1 ? $match[0] : '', $text);
        // 分离允许的颜色片段，避免清理普通控制字符时破坏 SGR 的 ESC。
        $parts = preg_split('/(\x1b\[[0-9;:]*m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $styled = false;
        foreach ($parts as &$part) {
            if (preg_match('/^\x1b\[[0-9;:]*m$/D', $part) === 1) { $styled = true; continue; }
            $part = preg_replace($multiline ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', '?', $part);
        }
        unset($part);
        $text = implode('', $parts);
        if ($styled && !str_ends_with($text, "\033[0m")) { $text .= "\033[0m"; }
        return $text;
    }

    /** 原始样式跨行延续；每个可见行独立裁剪、复位，不让裁剪改变下一行的样式。 */
    public static function fitLines(string $text, int $width, int $height, bool $colors = true): string
    {
        if ($width <= 0 || $height <= 0) { return ''; }
        $text = self::clean(str_replace("\r", '', $text), $colors, multiline: true);
        $lines = array_slice(explode("\n", $text), 0, $height);
        $style = ''; $result = [];
        foreach ($lines as $line) {
            $result[] = self::fit($style . $line, $width, $colors);
            if (!$colors) { continue; }
            preg_match_all('/\x1b\[[0-9;:]*m/', $line, $matches);
            foreach ($matches[0] as $sequence) {
                // 保留局部复位的先后顺序；完整复位后，前面的样式不再参与下一行。
                $style = preg_match('/^\x1b\[(?:0;)*0?m$/D', $sequence) === 1 ? '' : $style . $sequence;
            }
        }
        return implode("\n", $result);
    }

    /** 删除可见行尾空格时保留样式复位，避免颜色码阻止普通 rtrim 清除空白。 */
    public static function trimEnd(string $text): string
    {
        return preg_replace_callback('/(?:[ \t]|\x1b\[[0-9;:]*m)+$/D',
            static fn (array $match): string => str_replace([' ', "\t"], '', $match[0]), $text);
    }

    public static function width(string $text): int
    { return mb_strwidth(self::clean($text, false), 'UTF-8'); }

    public static function fit(string $text, int $width, bool $colors = true): string
    {
        if ($width <= 0) { return ''; }
        $parts = preg_split('/(\x1b\[[0-9;:]*m)/', self::clean($text, $colors), -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = ''; $styled = false;
        foreach ($parts as $part) {
            if (preg_match('/^\x1b\[[0-9;:]*m$/D', $part) === 1) {
                $result .= $part; $styled = true;
                continue;
            }
            $visible = mb_strimwidth($part, 0, $width, '', 'UTF-8');
            $result .= $visible;
            $width -= mb_strwidth($visible, 'UTF-8');
            if ($visible !== $part) { break; }
        }
        // 截断不能吞掉颜色复位，避免后续单元格或界面继承颜色。
        if ($styled && !str_ends_with($result, "\033[0m")) { $result .= "\033[0m"; }
        return $result;
    }
}
