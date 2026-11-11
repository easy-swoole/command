<?php

declare(strict_types=1);

namespace EasySwoole\Command;

final class Input
{
    public function __construct(
        private readonly array $options = [],
        private readonly array $arguments = [],
        private readonly array $provided = [],
    ) {}
    public function option(string $name): mixed { return $this->options[$name] ?? null; }
    public function argument(string $name): mixed { return $this->arguments[$name] ?? null; }
    public function provided(string $name): bool { return array_key_exists($name, $this->provided); }
    public function options(): array { return $this->options; }
}
