<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

final class CommandDefinition
{
    /** @var array<string, Option> */
    private array $options = [];
    /** @var array<string, Argument> */
    private array $arguments = [];
    private array $metadata = [];
    private string $description = '';
    private function __construct(public readonly string $name) {}
    public static function named(string ...$path): self
    {
        $name = implode(' ', $path);
        // 根路径 help 由应用入口处理，允许注册会造成命令无法执行；子路径 help 不受影响。
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*(?: [A-Za-z][A-Za-z0-9_-]*)*$/D', $name) || explode(' ', $name)[0] === 'help') {
            throw new \InvalidArgumentException('invalid or reserved command name: ' . $name);
        }
        return new self($name);
    }
    public function description(string $text): self { $this->description = $text; return $this; }
    public function summary(): string { return $this->description; }
    public function option(Option $option): self
    {
        if (isset($this->options[$option->name])) { throw new \LogicException('duplicate option: ' . $option->name); }
        $this->options[$option->name] = $option; return $this;
    }
    public function argument(Argument $argument): self
    {
        if (isset($this->arguments[$argument->name])) { throw new \LogicException('duplicate argument: ' . $argument->name); }
        if ($argument->required && array_filter($this->arguments, static fn ($a) => !$a->required)) {
            throw new \LogicException('required arguments must precede optional arguments');
        }
        $this->arguments[$argument->name] = $argument; return $this;
    }
    public function metadata(string $key, mixed $value): self { $this->metadata[$key] = $value; return $this; }
    public function meta(string $key): mixed { return $this->metadata[$key] ?? null; }
    public function options(): array { return $this->options; }
    public function arguments(): array { return $this->arguments; }
}
