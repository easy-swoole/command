<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use EasySwoole\Command\Application;
use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Console\Selection\Choice;
use EasySwoole\Command\Console\View\TableView;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Contracts\CommandProviderInterface;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;

final class DemoCommand implements CommandInterface
{
    public function __construct(private string $applicationName) {}

    public function definition(): CommandDefinition
    {
        return CommandDefinition::named('demo', 'console')
            ->description('Try selection and live output without a framework')
            ->option(Option::flag('live', 'show a refreshing status table'));
    }

    public function execute(Input $input, Console $console): CommandResult
    {
        if ($input->option('live')) {
            $frame = 0;
            $console->live(function () use (&$frame) {
                return new TableView(['Application', 'Frame', 'Time'], [[$this->applicationName, ++$frame, date('H:i:s')]]);
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

final class DemoProvider implements CommandProviderInterface
{
    public function register(CommandRegistry $registry): void
    {
        $registry->add(new DemoCommand('Standalone PHP'));
    }
}

$app = new Application('Command 3.x demo');
$app->addProvider(new DemoProvider());
exit($app->run(array_slice($argv, 1)));
