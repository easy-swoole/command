<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Exception\InputException;

final class InputParser
{
    /**
     * 第一阶段只拆分词元并定位命令，不做类型转换或业务初始化。
     * argv 不包含脚本名称；优先匹配已注册的最长命令路径。
     *
     * @return array{?CommandDefinition, array, array, array} 命令定义、原始选项、剩余参数及全部非选项词元。
     */
    public function parse(array $tokens, CommandRegistry $registry): array
    {
        $options = []; $words = []; $literal = false;
        foreach ($tokens as $token) {
            if (!is_string($token)) { throw new InputException('argv must contain strings'); }
            // 终止符之后保留字面值标记，防止以短横线开头或包含等号的参数被再次解释。
            if (!$literal && $token === '--') { $literal = true; continue; }
            if (!$literal && str_starts_with($token, '-') && $token !== '-') {
                [$name, $value] = array_pad(explode('=', ltrim($token, '-'), 2), 2, null);
                if ($name === '') { throw new InputException('invalid option'); }
                $options[$name] = $value; // 保留原有命令行语义：重复选项取最后一次传入的值。
            } else { $words[] = [$token, $literal]; }
        }
        // 按路径长度选择最具体的命令，例如同时存在 cache 与 cache clear 时优先后者。
        $definitions = $registry->definitions(); $matched = null; $length = 0;
        foreach ($definitions as $definition) {
            $path = explode(' ', $definition->name);
            if (count($path) > $length && array_column(array_slice($words, 0, count($path)), 0) === $path) {
                $matched = $definition; $length = count($path);
            }
        }
        return [$matched, $options, array_slice($words, $length), array_column($words, 0)];
    }
    /** 第二阶段依据命令定义拒绝未知字段、绑定类型，并检查必填项与多余参数。 */
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
        // 命名参数优先占位，其余位置参数按定义顺序填充尚未提供的字段。
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
