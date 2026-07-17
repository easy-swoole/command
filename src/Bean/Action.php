<?php

namespace EasySwoole\Command\Bean;

class Action
{
    private mixed $callback = null;
    function __construct(
        public readonly string $name,
        public readonly string|null $description = null
    ){
        $this->init();
    }

    /** @var array<Option>  */
    protected array $options;

    public function getOptions():array
    {
        return $this->options;
    }

    public function addOption(Option $option):void
    {
        $this->options[] = $option;
    }

    function setCallback(callable $callback):void
    {
        $this->callback = $callback;
    }

    function getCallback():callable|null
    {
        return $this->callback;
    }

    protected function init():void{}
}