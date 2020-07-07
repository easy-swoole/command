<?php


namespace EasySwoole\Command\AbstractInterface;


interface CommandInterface
{
    public function commandName():string;
    public function exec(CallerInterface $caller):ResultInterface ;
    public function help(CallerInterface $caller):ResultInterface ;
}