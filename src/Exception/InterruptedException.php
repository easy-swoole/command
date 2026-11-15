<?php

declare(strict_types=1);

namespace EasySwoole\Command\Exception;

use EasySwoole\Command\Result\ExitCode;

final class InterruptedException extends \RuntimeException
{
    public function __construct(public readonly int $signal)
    { parent::__construct('command interrupted by signal ' . $signal, ExitCode::forSignal($signal)); }
}
