<?php

declare(strict_types=1);

namespace EasySwoole\Command;

final class InputParser
{
    /** argv 不包含脚本名称；优先匹配已注册的最长命令路径。 */
    public function parse(array $tokens, CommandRegistry $registry): array
    {
        $options = []; $words = []; $literal = false;
        foreach ($tokens as $token) {
            if (!is_string($token)) { throw new InputException('argv must contain strings'); }
            if (!$literal && $token === '--') { $literal = true; continue; }
            if (!$literal && str_starts_with($token, '-') && $token !== '-') {
                [$name, $value] = array_pad(explode('=', ltrim($token, '-'), 2), 2, null);
                if ($name === '') { throw new InputException('invalid option'); }
                $options[$name] = $value; // 保留原有命令行语义：重复选项取最后一次传入的值。
            } else { $words[] = [$token, $literal]; }
        }
        $definitions = $registry->definitions(); $matched = null; $length = 0;
        foreach ($definitions as $definition) {
            $path = explode(' ', $definition->name);
            if (count($path) > $length && array_column(array_slice($words, 0, count($path)), 0) === $path) {
                $matched = $definition; $length = count($path);
            }
        }
        return [$matched, $options, array_slice($words, $length), array_column($words, 0)];
    }
    public function bind(CommandDefinition $definition, array $options, array $words = []): Input
    {
        $bound = []; $arguments = []; $positional = [];
        foreach ($options as $name => $_) {
            if (!isset($definition->options()[$name])) { throw new InputException('unknown option --' . $name); }
        }
        foreach ($definition->options() as $name => $option) {
            $bound[$name] = $option->bind($options[$name] ?? null, array_key_exists($name, $options));
        }
        foreach ($words as [$word, $literal]) {
            if (!$literal && str_contains($word, '=')) {
                [$name, $value] = explode('=', $word, 2);
                if (!isset($definition->arguments()[$name])) { throw new InputException('unknown argument ' . $name); }
                if (array_key_exists($name, $arguments)) { throw new InputException('duplicate argument ' . $name); }
                $arguments[$name] = $value;
            } else { $positional[] = $word; }
        }
        foreach ($definition->arguments() as $name => $argument) {
            if (!array_key_exists($name, $arguments)) {
                $arguments[$name] = $positional ? array_shift($positional) : $argument->default;
            }
            if ($argument->required && $arguments[$name] === null) { throw new InputException('argument ' . $name . ' is required'); }
        }
        if ($positional) { throw new InputException('unexpected argument: ' . $positional[0]); }
        return new Input($bound, $arguments, $options);
    }
}
