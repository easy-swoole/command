<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

/** 单个操作的参数、帮助和元数据；不承担业务执行。 */
final class ActionDefinition extends InputDefinition
{
    public static function create(string $name): self
    {
        if (func_num_args() !== 1 || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name)) {
            throw new \InvalidArgumentException('invalid action name: ' . $name);
        }
        return new self($name);
    }
}
