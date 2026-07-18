<?php

namespace EasySwoole\Command\Bean;

class Action
{

    private mixed $callback = null;

    function __construct(
        public readonly string $name,
        public readonly string|null $description = null
    ){
        $this->init();
    }

    /** @var array<Option>  */
    private array $options = [];

    /** @var array<Param>  */
    private array $params = [];

    public function getOptions():array
    {
        return $this->options;
    }

    function getParams():array
    {
        return $this->params;
    }

    public function addOption(Option $option):void
    {
        $this->options[$option->name] = $option;
    }

    public function addParam(Param $param):void
    {
        $this->params[$param->name] = $param;
    }

    public function description():string|null
    {
        return $this->description;
    }

    function setCallback(callable $callback):void
    {
        $this->callback = $callback;
    }

    function getCallback():callable|null
    {
        return $this->callback;
    }

    protected function init():void{}

    public function __preCallCallback(Caller $caller,Result $result):bool
    {

        foreach ($this->options as $option){
            $v = $caller->commandLine->getOption($option->name);
            $res = $option::validate($v,$caller);
            if($res !== true){
                if(is_string($res)){
                    $result->msg = $res;
                }else{
                    $result->msg = 'option '.$option->name.' validate failed';
                }
                $result->status = ExecStatusEnum::COMMAND_ACTION_OPTION_VALIDATE_FAIL;
                $result->result = [
                    'failColumn'=>$option->name,
                ];
                return false;
            }
        }

        foreach ($this->params as $param){
            $v = $caller->commandLine->getParam($param->name);
            $res = $param::validate($v,$caller);
            if($res !== true){
                if(is_string($res)){
                    $result->msg = $res;
                }else{
                    $result->msg = 'param '.$param->name.' validate failed';
                }
                $result->status = ExecStatusEnum::COMMAND_ACTION_PARAM_VALIDATE_FAIL;
                $result->result = [
                    'failColumn'=>$param->name,
                ];
                return false;
            }
        }
        return true;
    }
}