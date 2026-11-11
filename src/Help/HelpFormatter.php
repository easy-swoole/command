<?php

declare(strict_types=1);

namespace EasySwoole\Command\Help;

use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\InputDefinition;

/** 根据显式定义分层生成帮助，不创建命令或操作的业务实例。 */
final class HelpFormatter
{
    public function overview(string $application, array $commands): string
    {
        $lines = [$application, '', 'current support command is:', ''];
        $width = $commands ? max(20, max(array_map(static fn ($c) => strlen($c->name) + 2, $commands))) : 20;
        foreach ($commands as $command) {
            $lines[] = str_pad($command->name, $width) . $command->summary();
        }
        return implode(PHP_EOL, $lines);
    }

    public function command(CommandDefinition $command, ?ActionDefinition $action = null): string
    {
        if ($action !== null) {
            return implode(PHP_EOL, array_merge([$command->name . ' ' . $action->name . '  ' . $action->summary(), ''], $this->input($action)));
        }
        if (!$command->hasActions()) {
            return implode(PHP_EOL, array_merge([$command->name . '  ' . $command->summary(), ''], $this->input($command)));
        }
        $lines = [$command->name . '  ' . $command->summary(), '', 'current support action is:', ''];
        foreach ($command->actions() as $definition) {
            $lines[] = str_pad($definition->name, max(20, strlen($definition->name) + 2)) . $definition->summary();
            array_push($lines, ...$this->input($definition));
            $lines[] = '';
        }
        return implode(PHP_EOL, $lines);
    }

    private function input(InputDefinition $definition): array
    {
        $lines = [];
        foreach ($definition->options() as $option) {
            $label = ' --' . $option->name;
            $lines[] = str_pad($label, max(20, strlen($label) + 2)) . $option->description . ($option->required ? ' (required)' : '');
        }
        foreach ($definition->arguments() as $argument) {
            $label = ' ' . ($argument->required ? '<' : '[') . $argument->name . ($argument->required ? '>' : ']');
            $lines[] = str_pad($label, max(20, strlen($label) + 2)) . $argument->description;
        }
        $lines[] = ' --help             Show help';
        return $lines;
    }
}
