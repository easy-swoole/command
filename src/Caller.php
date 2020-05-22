<?php


namespace EasySwoole\Command;


use EasySwoole\Command\AbstractInterface\CallerInterface;

class Caller implements CallerInterface
{
    private $command;
    private $params;

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command)
    {
       $this->command = $command;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }

    public function getParams()
    {
        return $this->params;
    }

}