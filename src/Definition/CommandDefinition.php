<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

/** 顶层命令可以直接执行，也可以拥有多个操作；两种输入模式不能混用。 */
final class CommandDefinition extends InputDefinition
{
    /** @var array<string, ActionDefinition> */
    private array $actions = [];

    public static function create(string $name): self
    {
        if (func_num_args() !== 1 || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name) || $name === 'help') {
            throw new \InvalidArgumentException('invalid or reserved command name: ' . $name);
        }
        return new self($name);
    }

    public function action(ActionDefinition $action): self
    {
        if ($this->options() || $this->arguments() || $this->hasInputValidators()) {
            throw new \LogicException('a command with actions cannot declare standalone input');
        }
        if (isset($this->actions[$action->name])) {
            throw new \LogicException('duplicate action: ' . $action->name);
        }
        $this->actions[$action->name] = clone $action;
        return $this;
    }

    public function option(Option $option): static
    {
        $this->assertStandalone();
        return parent::option($option);
    }

    public function argument(Argument $argument): static
    {
        $this->assertStandalone();
        return parent::argument($argument);
    }

    public function validateInput(callable $validator): static
    {
        $this->assertStandalone();
        return parent::validateInput($validator);
    }

    private function assertStandalone(): void
    {
        if ($this->hasActions()) { throw new \LogicException('declare input on each action'); }
    }

    public function hasActions(): bool { return $this->actions !== []; }
    public function actions(): array { return $this->actions; }
    public function actionDefinition(?string $name): ?ActionDefinition
    { return $name === null ? null : ($this->actions[$name] ?? null); }

    /** 注册与读取快照时同时复制操作，防止外部修改穿透到注册器。 */
    public function __clone()
    {
        foreach ($this->actions as $name => $action) { $this->actions[$name] = clone $action; }
    }
}
