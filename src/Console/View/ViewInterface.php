<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\View;

interface ViewInterface
{
    public function render(int $width, int $height): string;
}
