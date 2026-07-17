<?php

namespace EasySwoole\Command;

use EasySwoole\Command\AbstractInterface\AbstractCommand;
use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\ExecStatusEnum;
use EasySwoole\Command\Bean\Result;

class Manager
{
    /**
     * @var array<AbstractCommand>
     */
    public array $commands = [];


    function addCommand(AbstractCommand $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    function getCommand(string $name): ?AbstractCommand
    {
        if (!isset($this->commands[$name])) {
            return null;
        }
        return $this->commands[$name];
    }

    function getCommands(): array
    {
        return $this->commands;
    }

    function exec(Caller $caller):Result
    {
        $result = new Result();
        if(!isset($this->commands[$caller->command])){
            $result->status = ExecStatusEnum::COMMAND_NOT_EXISTS;
            return $result;
        }
        $command = $this->commands[$caller->command];
        if(!isset($command->getActions()[$caller->action])){
            $result->status = ExecStatusEnum::COMMAND_ACTION_NOT_EXISTS;
            return $result;
        }
        $result->status = ExecStatusEnum::OK;
        $call = $command->getActions()[$caller->action]->getCallback();
        if(is_callable($call)){
            call_user_func($call,$caller,$result);
        }
        return $result;
    }
}