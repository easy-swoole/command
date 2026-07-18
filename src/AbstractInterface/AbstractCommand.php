<?php

namespace EasySwoole\Command\AbstractInterface;

use EasySwoole\Command\Bean\Action;
use EasySwoole\Command\Bean\CommandLine;
use EasySwoole\Command\Manager;

abstract class AbstractCommand
{
    function __construct()
    {
        $this->init();
    }

    /** @var array<Action>  */
    protected array $actions = [];

    protected Action|null $defaultAction = null;

    abstract function name():string;

    abstract function description():string;

    protected function init():void
    {}

    public function registerAction(Action $action):void
    {
        $this->actions[$action->name] = $action;
    }

    function registerDefaultAction(Action $action):void
    {
        $this->defaultAction = $action;
        $this->actions[$action->name] = $action;
    }

    function getDefaultAction():Action|null
    {
        return $this->defaultAction;
    }

    public function getActions():array
    {
        return $this->actions;
    }
}