<?php

namespace EasySwoole\Command\Bean;

class CommandLine
{

    public string $action;

    public array $params = [];

    public array $options = [];

    public array $unknows = [];


    function getParam(string $key):mixed
    {
        if(isset($this->params[$key])){
            return $this->params[$key];
        }
        return null;
    }

    function getOption(string $key):mixed
    {
        if(isset($this->options[$key])){
            return $this->options[$key];
        }
        return null;
    }

    function hasOption(string $key):bool
    {
        if(array_key_exists($key,$this->options)){
            return true;
        }
        return false;
    }

    function hasParam(string $key):bool
    {
        if(array_key_exists($key,$this->params)){
            return true;
        }
        return false;
    }
}