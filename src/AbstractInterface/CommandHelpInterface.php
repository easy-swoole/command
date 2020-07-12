<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Command\AbstractInterface;


interface CommandHelpInterface
{
    public function addCommand(string $name, string $desc);

    public function addOpt(string $name, string $desc);
}