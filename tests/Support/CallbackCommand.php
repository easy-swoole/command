<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

/** 让测试只声明输入规则和业务行为，不重复编写匿名命令类。 */
final class CallbackCommand implements CommandInterface
{
    private Closure $callback;

    public function __construct(private CommandDefinition $definition, callable $callback)
    { $this->callback = Closure::fromCallable($callback); }

    public function definition(): CommandDefinition { return $this->definition; }
    public function execute(Input $input, Console $console): CommandResult
    { return ($this->callback)($input, $console); }
}
