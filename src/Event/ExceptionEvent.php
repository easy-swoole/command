<?php

declare(strict_types=1);

namespace EasySwoole\Command\Event;

use EasySwoole\Command\Input\Input;

/** 向宿主提供原始异常及分发上下文，早期解析失败时部分上下文可能为空。 */
final class ExceptionEvent
{
    public function __construct(
        public readonly \Throwable $error,
        public readonly ?string $command,
        public readonly ?string $action,
        public readonly ?Input $input,
    ) {}
}
