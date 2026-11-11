<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Exception\InputException;

final class InputParser
{
    /** 先拆分词元，再按显式命令和操作定义定位；该阶段不创建命令实例。 */
    public function parse(array $tokens, CommandRegistry $registry): ParsedInput
    {
        $options = []; $occurrences = []; $words = []; $literal = false;
        foreach ($tokens as $token) {
            if (!is_string($token)) { throw new InputException('argv must contain strings'); }
            if (!$literal && $token === '--') { $literal = true; continue; }
            if (!$literal && str_starts_with($token, '-') && $token !== '-') {
                if (!preg_match('/^--?([A-Za-z][A-Za-z0-9_-]*)(?:=(.*))?$/sD', $token, $match)) {
                    throw new InputException('invalid option: ' . $token);
                }
                $name = $match[1]; $value = $match[2] ?? null;
                $options[$name] = $value;
                $occurrences[$name][] = $value;
            } else { $words[] = [$token, $literal]; }
        }
        $first = array_shift($words);
        if ($first !== null && $first[1]) { throw new InputException('command name must precede --'); }
        $commandName = $first[0] ?? null;
        $command = $commandName === null ? null : $registry->definition($commandName);
        $actionName = null;
        $action = null;
        if ($command?->hasActions() && $words && !$words[0][1]) {
            $actionName = array_shift($words)[0];
            $action = $command->actionDefinition($actionName);
        }
        // 仅显式声明为多值的选项收集全部出现值，普通选项继续采用最后一次值。
        foreach (($action ?? $command)?->options() ?? [] as $name => $option) {
            if ($option->isMultiple && isset($occurrences[$name])) { $options[$name] = $occurrences[$name]; }
        }
        return new ParsedInput($commandName, $command, $actionName, $action, $options, $words);
    }
}
