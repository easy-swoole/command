<?php

declare(strict_types=1);

namespace EasySwoole\Command\Help;

use EasySwoole\Command\Console\Color;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\InputDefinition;
use EasySwoole\Command\Definition\Option;

/** 顶层和操作列表保持简洁，单条命令帮助展示调用形式、类型、默认值及示例。 */
final class HelpFormatter
{
    /** 默认生成纯文本；应用入口根据输出终端能力启用颜色。 */
    public function __construct(private bool $colors = false) {}

    public function overview(string $application, array $commands): string
    {
        $lines = [$this->style($application, 'info'), '', $this->style('current support command is:', 'info'), ''];
        $width = $commands ? max(20, max(array_map(static fn ($c) => strlen($c->name) + 2, $commands))) : 20;
        foreach ($commands as $command) {
            $lines[] = $this->column($command->name, $width, 'red') . $this->style($command->summary(), 'info');
        }
        return implode(PHP_EOL, $lines);
    }

    public function command(CommandDefinition $command, ?ActionDefinition $action = null): string
    {
        if ($action !== null) { return $this->detail($command->name . ' ' . $action->name, $action); }
        if (!$command->hasActions()) { return $this->detail($command->name, $command); }

        $lines = [$this->style($command->name, 'red') . '  ' . $command->summary(), '', $this->style('current support action is:', 'info'), ''];
        foreach ($command->actions() as $definition) {
            $lines[] = $this->column($definition->name, 20, 'red') . $definition->summary();
            array_push($lines, ...$this->input($definition, false));
            $lines[] = '';
        }
        return implode(PHP_EOL, array_merge($lines, $this->examples($command)));
    }

    private function detail(string $name, InputDefinition $definition): string
    {
        $usage = [$name];
        foreach ($definition->options() as $option) {
            $label = $this->optionLabel($option);
            $required = $option->required && $option->default === null;
            $usage[] = $required ? $label : '[' . $label . ']';
        }
        foreach ($definition->arguments() as $argument) {
            $label = '<' . $argument->name . ':' . $argument->type . '>';
            $usage[] = $argument->required && $argument->default === null ? $label : '[' . $label . ']';
        }
        $usage[] = '[--help]';
        $lines = [$this->style($name, 'red') . '  ' . $definition->summary(), '', $this->style('Usage:', 'info') . ' ' . implode(' ', $usage), ''];
        array_push($lines, ...$this->input($definition, true), ...$this->examples($definition));
        return implode(PHP_EOL, $lines);
    }

    private function optionLabel(Option $option): string
    {
        $label = '--' . $option->name;
        if ($option->type === 'bool') { return $label . '[=bool]'; }
        return $label . ($option->implicit !== null ? '[=<' . $option->type . '>]' : '=<' . $option->type . '>');
    }

    private function input(InputDefinition $definition, bool $detailed): array
    {
        $lines = [];
        foreach ($definition->options() as $option) {
            $label = ' ' . ($detailed ? $this->optionLabel($option) : '--' . $option->name);
            $description = $option->description;
            if ($option->required && $option->default === null) { $description .= ' (required)'; }
            if ($detailed) {
                if ($option->default !== null) { $description .= ' [default: ' . $this->value($option->default) . ']'; }
                if ($option->implicit !== null) { $description .= ' [implicit: ' . $this->value($option->implicit) . ']'; }
            }
            $lines[] = $this->column($label, 24, 'notice') . $description;
        }
        foreach ($definition->arguments() as $argument) {
            $label = ' <' . $argument->name . ($detailed ? ':' . $argument->type : '') . '>';
            $description = $argument->description;
            if ($argument->required && $argument->default === null) { $description .= ' (required)'; }
            if ($detailed && $argument->default !== null) { $description .= ' [default: ' . $this->value($argument->default) . ']'; }
            $lines[] = $this->column($label, 24, 'notice') . $description;
        }
        $lines[] = $this->column(' --help', 24, 'notice') . 'Show help';
        return $lines;
    }

    /** 先用原始文字计算列宽，再添加颜色，避免 ANSI 控制序列参与对齐。 */
    private function column(string $label, int $width, string $style): string
    {
        return $this->style($label, $style) . str_repeat(' ', max(2, $width - strlen($label)));
    }

    private function style(string $text, string $style): string
    { return $this->colors ? Color::render($text, $style) : $text; }

    private function value(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function examples(InputDefinition $definition): array
    {
        if (!$definition->examples()) { return []; }
        $lines = ['', $this->style('Examples:', 'info')];
        foreach ($definition->examples() as $example) {
            $lines[] = '  ' . $this->style($example['command'], 'notice')
                . ($example['description'] !== '' ? '  # ' . $example['description'] : '');
        }
        return $lines;
    }
}
