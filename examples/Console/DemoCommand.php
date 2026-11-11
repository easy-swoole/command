<?php

declare(strict_types=1);

namespace EasySwoole\Command\Examples\Console;

use EasySwoole\Command\Application;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Console\Color;
use EasySwoole\Command\Console\Selection\Choice;
use EasySwoole\Command\Console\View\TableView;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

/** 演示实时视图和交互选择，参数定义按步骤组织。 */
final class DemoCommand implements CommandInterface
{
    public function __construct(private string $applicationName) {}

    public function definition(): CommandDefinition
    {
        $live = Option::flag('live', 'show a refreshing status table');

        $action = ActionDefinition::create('console');
        $action->description('Try selection and live output without a framework');
        $action->option($live);

        $command = CommandDefinition::create('demo');
        $command->description('Standalone demo commands');
        $command->action($action);

        return $command;
    }

    public function execute(Input $input, Console $console): CommandResult
    {
        if ($input->option('live')) {
            $frame = 0;
            $console->live(function () use (&$frame) {
                $row = [$this->applicationName, ++$frame, Color::green('running'), date('H:i:s')];
                return new TableView(['Application', 'Frame', 'Status', 'Time'], [$row]);
            }, interval: .5);
            return CommandResult::success();
        }
        $selection = $console->select('Choose environments', [
            new Choice('dev', 'Development', 'dev'),
            new Choice('test', 'Testing', 'test'),
            new Choice('prod', 'Production', 'produce'),
        ], multiple: true);
        if ($selection->cancelled()) { return CommandResult::success('Cancelled'); }
        if (!$console->confirm('Continue?')) { return CommandResult::success('Cancelled'); }
        return CommandResult::success('Selected: ' . implode(', ', $selection->values()));
    }
}
