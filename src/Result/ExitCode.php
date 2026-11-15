<?php

declare(strict_types=1);

namespace EasySwoole\Command\Result;

/** 组件约定的标准退出码。业务自定义退出码直接使用整数。 */
enum ExitCode: int
{
    case SUCCESS = 0;
    case FAILURE = 1;
    case INVALID_INPUT = 2;

    /** 外部信号中断使用 Shell 约定的退出码。 */
    public static function forSignal(int $signal): int
    {
        return 128 + $signal;
    }
}
