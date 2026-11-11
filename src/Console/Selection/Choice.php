<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Selection;

final class Choice
{
    public function __construct(
        public readonly string|int $id,
        public readonly string $label,
        public readonly mixed $value,
        public readonly string $description = '',
    ) {}
}
