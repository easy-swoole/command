<?php

namespace EasySwoole\Command\Bean;

enum ExecStatusEnum
{
    case OK;

    case COMMAND_NOT_EXISTS;

    case COMMAND_ACTION_NOT_EXISTS;

    case COMMAND_ACTION_EXEC_FAIL;

    case INIT_STATUS;
}