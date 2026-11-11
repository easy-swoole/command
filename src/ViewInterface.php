<?php

declare(strict_types=1);

namespace EasySwoole\Command;

interface ViewInterface
{
    public function render(int $width, int $height): string;
}
