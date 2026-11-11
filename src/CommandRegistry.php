<?php

declare(strict_types=1);

namespace EasySwoole\Command;

use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Contracts\CommandProviderInterface;
use EasySwoole\Command\Definition\CommandDefinition;

/** 保存命令定义与工厂，允许组件注册命令，而无需绑定宿主的依赖注入容器。 */
final class CommandRegistry
{
    /** @var array<string, array{CommandDefinition, \Closure}> 顶层命令名称对应定义快照和延迟工厂。 */
    private array $entries = [];
    public function add(CommandInterface $command): void
    { $this->register($command->definition(), static fn () => $command); }
    public function register(CommandDefinition $definition, callable $factory): void
    {
        if (isset($this->entries[$definition->name])) { throw new \LogicException('duplicate command: ' . $definition->name); }
        // 保存定义快照，避免调用方继续修改定义而影响已注册命令；此时不执行工厂。
        $this->entries[$definition->name] = [clone $definition, \Closure::fromCallable($factory)];
    }
    /** 覆盖必须显式声明，防止多个组件注册同名命令时发生静默替换。 */
    public function replace(CommandDefinition $definition, callable $factory): void
    {
        if (!isset($this->entries[$definition->name])) { throw new \LogicException('cannot replace unknown command: ' . $definition->name); }
        $this->entries[$definition->name] = [clone $definition, \Closure::fromCallable($factory)];
    }
    public function addProvider(CommandProviderInterface $provider): void { $provider->register($this); }
    /** 只复制目标命令，普通解析无需复制其他命令及其全部操作。 */
    public function definition(string $name): ?CommandDefinition
    { return isset($this->entries[$name]) ? clone $this->entries[$name][0] : null; }

    /** 返回快照供解析和帮助使用，读取定义不应创建业务对象。 */
    public function definitions(): array
    { return array_map(static fn ($entry) => clone $entry[0], $this->entries); }
    public function create(string $name): CommandInterface
    {
        if (!isset($this->entries[$name])) { throw new \LogicException('unknown command: ' . $name); }
        $command = ($this->entries[$name][1])();
        // 校验延迟工厂的产物，避免执行到另一个路径对应的命令。
        if (!$command instanceof CommandInterface || $command->definition()->name !== $name) {
            throw new \LogicException('factory must return the registered command: ' . $name);
        }
        return $command;
    }
}
