<?php

declare(strict_types=1);

namespace EasySwoole\Command;

interface CommandInterface
{
    public function definition(): CommandDefinition;
    public function execute(Input $input, Console $console): CommandResult;
}
