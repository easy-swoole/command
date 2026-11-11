<?php

declare(strict_types=1);

namespace EasySwoole\Command\Exception;

use EasySwoole\Command\Input\Input;

class InputException extends \InvalidArgumentException
{
    /** 仅在整组校验失败时补充完整输入；字段绑定失败时保持 null。 */
    public ?Input $input = null;
}
