<?php


namespace EasySwoole\Command\AbstractInterface;


interface CallerInterface
{
    public function commandName():string;
    public function params();
}