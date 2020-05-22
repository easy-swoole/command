<?php


namespace EasySwoole\Command\AbstractInterface;


interface CommandInterface
{
    public function commandName():string;
    public function exec($args):ResultInterface ;
    public function help($args):ResultInterface ;
}