<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Command;


use EasySwoole\Command\AbstractInterface\CommandHelpInterface;

class CommandHelp implements CommandHelpInterface
{
    /**
     * @var array
     */
    protected $commands = [];

    /**
     * @var array
     */
    protected $opts = [];

    /**
     * @var int
     */
    protected $commandWidth = 1;

    /**
     * @var int
     */
    protected $optWidth = 1;

    public function addCommand(string $name, string $desc)
    {
        $this->commands[$name] = $desc;

        if (($len = strlen($name)) > $this->commandWidth) {
            $this->commandWidth = $len;
        }
    }

    public function addOpt(string $name, string $desc)
    {
        $this->opts[$name] = $desc;

        if (($len = strlen($name)) > $this->optWidth) {
            $this->optWidth = $len;
        }
    }

    /**
     * @return array
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @return array
     */
    public function getOpts(): array
    {
        return $this->opts;
    }

    /**
     * @return int
     */
    public function getCommandWidth(): int
    {
        return $this->commandWidth;
    }

    /**
     * @return int
     */
    public function getOptWidth(): int
    {
        return $this->optWidth;
    }
}