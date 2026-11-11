<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\Definition\InputDefinition;
use EasySwoole\Command\Exception\InputException;

/** 绑定定义与原始输入，集中处理来源记录、默认值、字段校验和整组校验。 */
final class InputBinder
{
    /** 第二阶段依据命令定义拒绝未知字段、绑定类型，并检查必填项与多余参数。 */
    public function bind(InputDefinition $definition, array $options, array $words = [], ?string $command = null, ?string $action = null): Input
    {
        $bound = []; $arguments = []; $positional = []; $providedArguments = [];
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
                if ($positional) { $arguments[$name] = array_shift($positional); }
            }
            $provided = array_key_exists($name, $arguments);
            if ($provided) { $providedArguments[$name] = true; }
            $arguments[$name] = $argument->bind($arguments[$name] ?? null, $provided);
        }
        if ($positional) { throw new InputException('unexpected argument: ' . $positional[0]); }
        $input = new Input($bound, $arguments, $options, $command, $action, $providedArguments);
        $definition->validateBoundInput($input);
        return $input;
    }
}
