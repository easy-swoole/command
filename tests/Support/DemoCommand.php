<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Definition\Argument;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

final class DemoCommand implements CommandInterface {
    public int $executions = 0;
    public function definition(): CommandDefinition {
        return CommandDefinition::named('demo', 'run')->description('Run demo')
            ->option(Option::flag('d'))->option(Option::flag('force'))
            ->option(Option::string('mode', default: 'dev'))
            ->option(Option::integer('pid')->validate(static fn ($v) => $v === null || $v > 0))
            ->option(Option::number('watch', implicit: 2)->validate(static fn ($v) => $v === null || $v > 0))
            ->argument(new Argument('name'));
    }
    public function execute(Input $input, Console $console): CommandResult {
        ++$this->executions;
        return CommandResult::success('done', [$input->options(), $input->argument('name'), $input->provided('force')]);
    }
}
