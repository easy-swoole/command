<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Definition\InputDefinition;
use EasySwoole\Command\Exception\InputException;

final class InputParser
{
    /** 先拆分词元，再按显式命令和操作定义定位；该阶段不创建命令实例。 */
    public function parse(array $tokens, CommandRegistry $registry): ParsedInput
    {
        $options = []; $words = []; $literal = false;
        foreach ($tokens as $token) {
            if (!is_string($token)) { throw new InputException('argv must contain strings'); }
            if (!$literal && $token === '--') { $literal = true; continue; }
            if (!$literal && str_starts_with($token, '-') && $token !== '-') {
                [$name, $value] = array_pad(explode('=', ltrim($token, '-'), 2), 2, null);
                if ($name === '') { throw new InputException('invalid option'); }
                $options[$name] = $value;
            } else { $words[] = [$token, $literal]; }
        }
        $first = array_shift($words);
        if ($first !== null && $first[1]) { throw new InputException('command name must precede --'); }
        $commandName = $first[0] ?? null;
        $command = $commandName === null ? null : ($registry->definitions()[$commandName] ?? null);
        $actionName = null;
        $action = null;
        if ($command?->hasActions() && $words && !$words[0][1]) {
            $actionName = array_shift($words)[0];
            $action = $command->actionDefinition($actionName);
        }
        return new ParsedInput($commandName, $command, $actionName, $action, $options, $words);
    }
    /** 第二阶段依据命令定义拒绝未知字段、绑定类型，并检查必填项与多余参数。 */
    public function bind(InputDefinition $definition, array $options, array $words = [], ?string $command = null, ?string $action = null): Input
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
        return new Input($bound, $arguments, $options, $command, $action);
    }
}
