<?php

declare(strict_types=1);

namespace EasySwoole\Command;

final class CommandRegistry
{
    private array $entries = [];
    public function add(CommandInterface $command): void
    { $this->register($command->definition(), static fn () => $command); }
    public function register(CommandDefinition $definition, callable $factory): void
    {
        if (isset($this->entries[$definition->name])) { throw new \LogicException('duplicate command: ' . $definition->name); }
        $this->entries[$definition->name] = [clone $definition, \Closure::fromCallable($factory)];
    }
    public function replace(CommandDefinition $definition, callable $factory): void
    {
        if (!isset($this->entries[$definition->name])) { throw new \LogicException('cannot replace unknown command: ' . $definition->name); }
        $this->entries[$definition->name] = [clone $definition, \Closure::fromCallable($factory)];
    }
    public function addProvider(CommandProviderInterface $provider): void { $provider->register($this); }
    public function definitions(): array
    { return array_map(static fn ($entry) => clone $entry[0], $this->entries); }
    public function create(string $name): CommandInterface
    {
        $command = ($this->entries[$name][1])();
        if (!$command instanceof CommandInterface || $command->definition()->name !== $name) {
            throw new \LogicException('factory must return the registered command: ' . $name);
        }
        return $command;
    }
}
