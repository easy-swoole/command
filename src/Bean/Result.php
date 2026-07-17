<?php

namespace EasySwoole\Command\Bean;

class Result
{
    public mixed $result = null;

    public ExecStatusEnum $status = ExecStatusEnum::INIT_STATUS;

    public string|null $msg = null;
}