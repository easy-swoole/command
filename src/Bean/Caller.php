<?php

namespace EasySwoole\Command\Bean;

class Caller
{
    public mixed $extraArg = null;
    function __construct(
        public readonly string|null $command,
        public readonly string|null $action,
        public readonly CommandLine $commandLine,
    )
    {

    }
}