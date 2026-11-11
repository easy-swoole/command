<?php

declare(strict_types=1);

namespace EasySwoole\Command;

final class CommandResult
{
    public function __construct(
        public int $exitCode = 0,
        public ?string $message = null,
        public mixed $data = null,
    ) {}

    public static function success(?string $message = null, mixed $data = null): self
    { return new self(0, $message, $data); }
    public static function failure(string $message, int $exitCode = 1, mixed $data = null): self
    { return new self($exitCode, $message, $data); }
}
