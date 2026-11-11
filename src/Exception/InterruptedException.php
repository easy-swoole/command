<?php

declare(strict_types=1);

namespace EasySwoole\Command\Exception;

final class InterruptedException extends \RuntimeException
{
    public function __construct(public readonly int $signal)
    { parent::__construct('command interrupted by signal ' . $signal, 128 + $signal); }
}
