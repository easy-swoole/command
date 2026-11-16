<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console;

/** 统一输出文本的清理、颜色和可见宽度；只允许 SGR 样式，不允许内容控制终端光标。 */
final class Text
{
    private const ESCAPE = '/\x1b(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\\\)|[PX^_][\s\S]*?\x1b\\\\|[@-Z\\\\-_])/';
    private const SGR = '\x1b\[[0-9;:]*m';
    private const SGR_TOKEN = '/^' . self::SGR . '\z/';
    private const SGR_SPLIT = '/(' . self::SGR . ')/';
    private const RESET = "\033[0m";

    public static function supportsColor(mixed $stream): bool
    {
        return stream_isatty($stream) && getenv('NO_COLOR') === false && getenv('TERM') !== 'dumb';
    }

    public static function clean(string $text, bool $colors = true, bool $multiline = false): string
    {
        if ($multiline) {
            $text = str_replace("\r\n", "\n", $text);
        }
        $text = preg_replace_callback(self::ESCAPE, static fn (array $match): string =>
            $colors && preg_match(self::SGR_TOKEN, $match[0]) === 1 ? $match[0] : '', $text);
        $controls = $multiline ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
        if (!str_contains($text, "\033[")) {
            return preg_replace($controls, '?', $text);
        }

        // 捕获组将样式保留在奇数位，只清理偶数位的普通文本。
        $parts = preg_split(self::SGR_SPLIT, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $count = count($parts);
        for ($index = 0; $index < $count; $index += 2) {
            $parts[$index] = preg_replace($controls, '?', $parts[$index]);
        }

        $text = implode('', $parts);
        if ($count > 1 && !str_ends_with($text, self::RESET)) {
            $text .= self::RESET;
        }
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
            $result[] = self::fitCleanLine($style . $line, $width);
            if (!$colors) { continue; }
            preg_match_all('/' . self::SGR . '/', $line, $matches);
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
        $text = rtrim($text, " \t");
        $styles = [];
        // 从末尾读取样式，跳过列间的大段填充空格。
        while (str_ends_with($text, 'm') && ($start = strrpos($text, "\033")) !== false) {
            $style = substr($text, $start);
            if (preg_match(self::SGR_TOKEN, $style) !== 1) {
                break;
            }
            $styles[] = $style;
            $text = rtrim(substr($text, 0, $start), " \t");
        }

        return $text . implode('', array_reverse($styles));
    }

    public static function width(string $text): int
    { return mb_strwidth(self::clean($text, false), 'UTF-8'); }

    public static function fit(string $text, int $width, bool $colors = true): string
    {
        return $width <= 0 ? '' : self::fitCleanLine(self::clean($text, $colors), $width);
    }

    /** 按可见宽度裁剪已清理的一行。实时帧完成整段清理后可以直接调用。 */
    private static function fitCleanLine(string $text, int $width): string
    {
        if (!str_contains($text, "\033[")) {
            return mb_strimwidth($text, 0, $width, '', 'UTF-8');
        }

        $parts = preg_split(self::SGR_SPLIT, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        $styled = false;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $result .= $part;
                $styled = true;
                continue;
            }
            $visible = mb_strimwidth($part, 0, $width, '', 'UTF-8');
            $result .= $visible;
            $width -= mb_strwidth($visible, 'UTF-8');
            if ($visible !== $part) {
                break;
            }
        }

        // 截断不能吞掉颜色复位，避免后续单元格或界面继承颜色。
        if ($styled && !str_ends_with($result, self::RESET)) {
            $result .= self::RESET;
        }
        return $result;
    }
}
