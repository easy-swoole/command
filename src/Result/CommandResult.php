<?php

declare(strict_types=1);

namespace EasySwoole\Command\Result;

/** 命令执行结果，退出码统一保存为整数，供宿主和命令行使用。 */
final class CommandResult
{
    public int $exitCode;

    public function __construct(
        ExitCode|int $exitCode = ExitCode::SUCCESS,
        public ?string $message = null,
        public mixed $data = null,
    ) {
        $this->exitCode = $exitCode instanceof ExitCode ? $exitCode->value : $exitCode;
    }

    public static function success(?string $message = null, mixed $data = null): self
    { return new self(ExitCode::SUCCESS, $message, $data); }
    public static function failure(string $message, ExitCode|int $exitCode = ExitCode::FAILURE, mixed $data = null): self
    { return new self($exitCode, $message, $data); }
}
