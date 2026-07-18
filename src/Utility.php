<?php

namespace EasySwoole\Command;

use EasySwoole\Command\Bean\CommandLine;

class Utility
{
    public static function parseArgv(array $params):CommandLine
    {
        $commandLine = new CommandLine();
        while (false !== ($param = current($params))) {
            next($params);
            if (str_starts_with($param, '-')) {
                $option = ltrim($param, '-');
                $value  = null;
                if (str_contains($option, '=')) {
                    [$option, $value] = explode('=', $option, 2);
                }
                if ($option) {
                    $commandLine->options[$option] = $value;
                }
            } else if (str_contains($param, '=')) {
                [$name, $value] = explode('=', $param, 2);
                if ($name) {
                    $commandLine->params[$name] = $value;
                }
            } else {
                $commandLine->unknows[] = $param;
            }
        }
        return $commandLine;
    }

    public static function matchAlternativeCommands(string $input,array $commandNames):array
    {
        $alternatives = [];
        foreach ($commandNames as $commandName) {
            $lev = levenshtein($input, $commandName);
            if ($lev <= strlen($input) / 3 || str_contains($commandName, $input)) {
                $alternatives[$commandName] = $lev;
            }
        }
        $threshold    = 1e3;
        $alternatives = array_filter($alternatives, function ($lev) use ($threshold) {
            return $lev < 2 * $threshold;
        });
        ksort($alternatives);

        if ($alternatives) {
            return array_keys($alternatives);
        }
        return [];
    }
}