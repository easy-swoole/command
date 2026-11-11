<?php

declare(strict_types=1);

namespace EasySwoole\Command\Examples\Console;

use EasySwoole\Command\CommandRegistry;
use EasySwoole\Command\Contracts\CommandProviderInterface;

/** 演示组件通过 Provider 批量注入命令。 */
final class DemoProvider implements CommandProviderInterface
{
    public function register(CommandRegistry $registry): void
    {
        $registry->add(new DemoCommand('Standalone PHP'));
    }
}
