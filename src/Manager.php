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
    protected array $commands = [];


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
        $action = null;

        if(!isset($command->getActions()[$caller->action])){
            if($command->getDefaultAction()){
                $action = $command->getDefaultAction();
            }else{
                $result->status = ExecStatusEnum::COMMAND_ACTION_NOT_EXISTS;
                return $result;
            }
        }else{
            $action = $command->getActions()[$caller->action];
        }
        $result->status = ExecStatusEnum::OK;
        $action = clone $action;
        $callback = $action->getCallback();
        if(is_callable($callback)){
            $ret = $command->beforeExecute($caller,$result);
            if($ret !== true){
                if($result->status == ExecStatusEnum::OK){
                    $result->status = ExecStatusEnum::COMMAND_REJECT_EXEC;
                }
                return $result;
            }

            $res = $action->__preCallCallback($caller,$result);
            if(!$res){
                return $result;
            }
            call_user_func($callback,$caller,$result);
        }

        return $result;
    }


    function result2Msg(Caller $caller, Result $result): string
    {
        $tabLen = 20;
        $msg = '';
        switch ($result->status){
            case ExecStatusEnum::COMMAND_NOT_EXISTS:{
                if(empty($this->commands)){
                    $msg .= Color::error("not register any command")."\n";
                    break;
                }
                if(!empty($caller->command)){
                    $matches = Utility::matchAlternativeCommands($caller->command,array_keys($this->commands));
                    if(empty($matches)){
                        $msg = Color::info('command ').Color::red($caller->command).Color::info(' not exists.')."\n";
                    }else{
                        $msg = Color::info('command ').Color::red($caller->command).Color::info(' not exists , do you mean:')."\n\n";
                        foreach($matches as $match){
                            $msg .= Color::red($match)." ? \n";
                        }
                        $msg .= "\n";
                    }
                }
                $msg .= Color::info('current support command is:')."\n\n";

                foreach ($this->commands as $name => $command){
                    $repeat = $tabLen - strlen($name);
                    $repeat = str_repeat(' ', $repeat);
                    $msg .= Color::red($name).$repeat.Color::info($command->description())."\n";
                }
                break;
            }
            case ExecStatusEnum::COMMAND_ACTION_NOT_EXISTS:{
                if(empty(($this->commands[$caller->command]->getActions()))){
                    $msg .= Color::error("not any action register on {$caller->command}")."\n";
                    break;
                }
                if(!empty($caller->action)){
                    $msg .= "action ".Color::red($caller->action)." not exist \n";
                    $actions = $this->commands[$caller->command];
                    $matches = Utility::matchAlternativeCommands($caller->action,array_keys($actions->getActions()));
                    if(empty($matches)){
                        $msg = Color::info('action ').Color::red($caller->action).Color::info(' not exists.')."\n";
                    }else{
                        $msg = Color::info('action ').Color::red($caller->action).Color::info(' not exists , do you mean :')."\n\n";
                        foreach($matches as $match){
                            $msg .= Color::red($match)." ? \n";
                        }
                        $msg .= "\n";
                    }
                }
                $msg .= Color::info('current support action is:')."\n\n";
                foreach ($this->commands[$caller->command]->getActions() as $name => $action){
                    $repeat = $tabLen - strlen($name);
                    $repeat = str_repeat(' ', $repeat);
                    $msg .= Color::red($name)."{$repeat}{$action->description()}\n";
                    foreach ($action->getOptions() as $optName => $option){
                        $opt = " --{$optName}";
                        $repeat = $tabLen - strlen($opt);
                        $repeat = str_repeat(' ', $repeat);
                        $msg .= Color::notice($opt)."{$repeat}{$option->description()}"."\n";
                    }
                    foreach ($action->getParams() as $paramName => $param){
                        $opt = " {$paramName}";
                        $repeat = $tabLen - strlen($opt);
                        $repeat = str_repeat(' ', $repeat);
                        $msg .= Color::notice($opt)."{$repeat}{$param->description()}"."\n";
                    }
                    $msg .= "\n";
                }
                break;
            }
            case ExecStatusEnum::COMMAND_ACTION_PARAM_VALIDATE_FAIL:{
                if(isset($result->result['failColumn'])){
                    $failColumn = $result->result['failColumn'];
                    $failColumn = ' '.Color::red($failColumn).' ';
                }else{
                    $failColumn = ' ';
                }
                if(!empty($result->msg)){
                    $tip = " with error msg:\n\n".Color::info($result->msg);
                }else{
                    $tip = '';
                }
                $msg = "param{$failColumn}value validate fail{$tip}\n";
                break;
            }

            case ExecStatusEnum::COMMAND_ACTION_OPTION_VALIDATE_FAIL:{
                if(isset($result->result['failColumn'])){
                    $failColumn = $result->result['failColumn'];
                    $failColumn = ' '.Color::red($failColumn).' ';
                }else{
                    $failColumn = ' ';
                }
                if(!empty($result->msg)){
                    $tip = " with error msg:\n\n".Color::info($result->msg);
                }else{
                    $tip = '';
                }
                $msg = "option{$failColumn}value validate fail{$tip}\n";
                break;
            }

            case ExecStatusEnum::COMMAND_ACTION_EXEC_FAIL:{
                if(!empty($result->msg)){
                    $tip = " with error msg:\n\n".Color::info($result->msg);
                }else{
                    $tip = '';
                }
                $msg = "exec command ".Color::red($caller->command)."@".Color::red($caller->action)." fail{$tip}\n";
                break;
            }

            case ExecStatusEnum::INIT_STATUS:{
                $msg = Color::error('command status not be correct set')."\n";
            }
        }
        return $msg;
    }
}