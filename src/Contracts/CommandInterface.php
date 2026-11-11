<?php

declare(strict_types=1);

namespace EasySwoole\Command\Contracts;

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

/**
 * 一个顶层命令的对外契约，可声明多个操作。
 * definition 应只描述命令，不建立连接或初始化应用；execute 接收已经校验并转换类型的输入，通过 Input::action() 区分操作。
 */
interface CommandInterface
{
    public function definition(): CommandDefinition;
    public function execute(Input $input, Console $console): CommandResult;
}
