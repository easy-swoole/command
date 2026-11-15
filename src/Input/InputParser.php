<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Exception\InputException;

final class InputParser
{
    /** 先拆分词元，再定位命令和 Action。调用方可以在检查目标前读取原始名称。 */
    public function parse(array $tokens, CommandRegistry $registry): ParsedInput
    {
        $occurrences = [];
        $words = [];
        $literal = false;

        foreach ($tokens as $token) {
            if (!is_string($token)) {
                throw new InputException('argv must contain strings');
            }
            if (!$literal && $token === '--') {
                $literal = true;
                continue;
            }
            if ($literal || !str_starts_with($token, '-') || $token === '-') {
                $words[] = [$token, $literal];
                continue;
            }

            if (!preg_match('/^--?([A-Za-z][A-Za-z0-9_-]*)(?:=(.*))?$/sD', $token, $match)) {
                throw new InputException('invalid option: ' . $token);
            }
            $occurrences[$match[1]][] = $match[2] ?? null;
        }

        $first = array_shift($words);
        if ($first !== null && $first[1]) {
            throw new InputException('command name must precede --');
        }
        $commandName = $first[0] ?? null;
        $command = $commandName === null ? null : $registry->definition($commandName);
        $actionName = null;
        $action = null;
        if ($command?->hasActions() && $words && !$words[0][1]) {
            $actionName = array_shift($words)[0];
            $action = $command->actionDefinition($actionName);
        }

        // 多值选项保留每次出现的值，普通选项采用最后一次的值。
        $definitions = ($action ?? $command)?->options() ?? [];
        $options = [];
        foreach ($occurrences as $name => $values) {
            $options[$name] = ($definitions[$name]->isMultiple ?? false)
                ? $values
                : $values[array_key_last($values)];
        }
        return new ParsedInput($commandName, $command, $actionName, $action, $options, $words);
    }

    /** 区分未指定目标和名称写错。Action 的拼写建议只查当前命令。 */
    public function assertKnownTarget(ParsedInput $parsed, CommandRegistry $registry): void
    {
        if ($parsed->commandName !== null && $parsed->command === null) {
            throw new InputException('unknown command: ' . $parsed->commandName
                . $this->spellingSuggestion($parsed->commandName, $registry->names()));
        }

        $command = $parsed->command;
        if ($command !== null && $parsed->actionName !== null && $parsed->action === null) {
            throw new InputException('unknown action: ' . $parsed->actionName . ' on ' . $command->name
                . $this->spellingSuggestion($parsed->actionName, array_keys($command->actions()), $command->name . ' '));
        }
    }

    /** 只比较相近名称，最多提示三个候选。匹配仍由解析器按原始名称完成。 */
    private function spellingSuggestion(string $name, array $candidates, string $prefix = ''): string
    {
        $name = strtolower($name);
        $length = strlen($name);
        $limit = $length >= 5 ? 2 : ($length >= 2 ? 1 : 0);
        $matches = [];
        foreach ($candidates as $candidate) {
            if (abs(strlen($candidate) - $length) > $limit) {
                continue;
            }
            $distance = levenshtein($name, strtolower($candidate));
            if ($distance <= $limit) {
                $matches[$prefix . $candidate] = $distance;
            }
        }

        if ($matches === []) {
            return '';
        }
        asort($matches);
        $suggestions = array_slice(array_keys($matches), 0, 3);
        return '. Did you mean ' . implode(', ', $suggestions) . '?';
    }
}
