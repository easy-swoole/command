<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;

/** 解析结果同时保留原始名称与解析到的定义，区分缺省输入和未知命令、操作。 */
final class ParsedInput
{
    public function __construct(
        public readonly ?string $commandName,
        public readonly ?CommandDefinition $command,
        public readonly ?string $actionName,
        public readonly ?ActionDefinition $action,
        public readonly array $options,
        public readonly array $words,
    ) {}
}
