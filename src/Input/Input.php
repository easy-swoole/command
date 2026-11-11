<?php

declare(strict_types=1);

namespace EasySwoole\Command\Input;

/** 已绑定的只读输入；选项值与用户是否显式提供该选项分别保存，避免默认值掩盖输入意图。 */
final class Input
{
    public function __construct(
        private readonly array $options = [],
        private readonly array $arguments = [],
        private readonly array $provided = [],
        private readonly ?string $command = null,
        private readonly ?string $action = null,
        private readonly array $providedArguments = [],
    ) {}
    /** 已解析的命令和操作上下文；独立命令的操作为 null。 */
    public function command(): ?string { return $this->command; }
    public function action(): ?string { return $this->action; }
    public function option(string $name): mixed { return $this->options[$name] ?? null; }
    public function argument(string $name): mixed { return $this->arguments[$name] ?? null; }
    /** provided 保持选项来源语义，位置参数通过 argumentProvided 单独判断。 */
    public function provided(string $name): bool { return $this->optionProvided($name); }
    public function optionProvided(string $name): bool { return array_key_exists($name, $this->provided); }
    public function argumentProvided(string $name): bool { return array_key_exists($name, $this->providedArguments); }
    public function arguments(): array { return $this->arguments; }
    public function options(): array { return $this->options; }
}
