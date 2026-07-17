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
}