<?php

declare(strict_types=1);

namespace EasySwoole\Command\Contracts;

use EasySwoole\Command\CommandRegistry;

/** 组件批量注册入口；注册阶段只提供定义和工厂，具体依赖可延迟到执行阶段创建。 */
interface CommandProviderInterface
{
    public function register(CommandRegistry $registry): void;
}
