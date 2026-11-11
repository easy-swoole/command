<?php

declare(strict_types=1);

namespace EasySwoole\Command;

final class Argument
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly ?string $default = null,
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name)) { throw new \InvalidArgumentException('invalid argument name'); }
    }
}
