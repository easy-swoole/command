<?php

declare(strict_types=1);

namespace EasySwoole\Command;

interface CommandProviderInterface
{
    public function register(CommandRegistry $registry): void;
}
