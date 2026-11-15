<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\Definition\InputDefinition;
use EasySwoole\Command\Exception\InputException;

/** 绑定定义与原始输入，集中处理来源记录、默认值、字段校验和整组校验。 */
final class InputBinder
{
    /** 只检查选项名，供帮助请求和实际绑定共用。根级帮助没有业务选项。 */
    public static function assertKnownOptions(?InputDefinition $definition, array $options): void
    {
        $knownOptions = $definition?->options() ?? [];
        foreach (array_keys($options) as $name) {
            if (!isset($knownOptions[$name])) {
                throw new InputException('unknown option --' . $name);
            }
        }
    }

    /** 按选定的定义绑定字段，再执行整组输入校验。 */
    public function bind(
        InputDefinition $definition,
        array $options,
        array $words = [],
        ?string $command = null,
        ?string $action = null,
    ): Input {
        self::assertKnownOptions($definition, $options);
        $boundOptions = $this->bindOptions($definition, $options);
        [$boundArguments, $providedArguments] = $this->bindArguments($definition, $words);

        $input = new Input($boundOptions, $boundArguments, $options, $command, $action, $providedArguments);
        $definition->validateBoundInput($input);
        return $input;
    }

    private function bindOptions(InputDefinition $definition, array $options): array
    {
        $bound = [];
        foreach ($definition->options() as $name => $option) {
            $bound[$name] = $option->bind($options[$name] ?? null, array_key_exists($name, $options));
        }
        return $bound;
    }

    /** @return array{array<string, mixed>, array<string, true>} 绑定后的参数与显式提供的参数名 */
    private function bindArguments(InputDefinition $definition, array $words): array
    {
        $definitions = $definition->arguments();
        $arguments = [];
        $positional = [];
        foreach ($words as [$word, $literal]) {
            if ($literal || !str_contains($word, '=')) {
                $positional[] = $word;
                continue;
            }

            [$name, $value] = explode('=', $word, 2);
            if (!isset($definitions[$name])) {
                throw new InputException('unknown argument ' . $name);
            }
            if (array_key_exists($name, $arguments)) {
                throw new InputException('duplicate argument ' . $name);
            }
            $arguments[$name] = $value;
        }

        // 命名参数优先占位，其余词元按定义顺序绑定到尚未提供的字段。
        $offset = 0;
        $positionalCount = count($positional);
        $providedArguments = [];
        foreach ($definitions as $name => $argument) {
            $provided = array_key_exists($name, $arguments);
            $value = $arguments[$name] ?? null;
            if (!$provided && $offset < $positionalCount) {
                $value = $positional[$offset++];
                $provided = true;
            }
            if ($provided) {
                $providedArguments[$name] = true;
            }
            $arguments[$name] = $argument->bind($value, $provided);
        }

        if ($offset < $positionalCount) {
            throw new InputException('unexpected argument: ' . $positional[$offset]);
        }
        return [$arguments, $providedArguments];
    }
}
