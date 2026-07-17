<?php

namespace EasySwoole\Command\Bean;

class Option
{
    public function __construct
    (
        public readonly string $name,
        public readonly string|null $description = null,
    ){}

    public static function validate(mixed $value,Caller $caller):bool|string
    {
        return true;
    }

}