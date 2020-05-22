<?php


namespace EasySwoole\Command;


use EasySwoole\Command\AbstractInterface\CallerInterface;
use EasySwoole\Command\AbstractInterface\ResultInterface;

class Runner
{
    private $container;

    function __construct(Container $container = null)
    {
        if($container == null){
            $container = new Container();
        }
        $this->container = $container;
    }

    function commandContainer():Container
    {
        return $this->container;
    }

    function run(CallerInterface $caller):?ResultInterface
    {
        $run = $this->container->get($caller->getCommand());
        if($run){
            return $run->exec($caller->getParams());
        }
        return null;
    }

    function help(CallerInterface $caller):?ResultInterface
    {
        $run = $this->container->get($caller->getCommand());
        if($run){
            return $run->help($caller->getParams());
        }
        return null;
    }
}