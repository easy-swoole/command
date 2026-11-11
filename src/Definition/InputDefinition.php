<?php

declare(strict_types=1);

namespace EasySwoole\Command\Definition;

use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Validation\ValidationChain;

/** 命令及操作共用的输入规则与说明，不包含执行回调。 */
abstract class InputDefinition
{
    /** @var array<string, Option> */
    private array $options = [];
    /** @var array<string, Argument> */
    private array $arguments = [];
    /** 所有字段绑定后执行的整组输入规则，快照共享不可变校验链。 */
    private ValidationChain $inputValidators;
    private array $metadata = [];
    private string $description = '';
    /** @var list<array{command: string, description: string}> */
    private array $examples = [];
    protected function __construct(public readonly string $name)
    { $this->inputValidators = new ValidationChain(); }
    public function description(string $text): static { $this->description = $text; return $this; }
    public function summary(): string { return $this->description; }
    public function option(Option $option): static
    {
        if (isset($this->options[$option->name])) { throw new \LogicException('duplicate option: ' . $option->name); }
        $this->options[$option->name] = $option; return $this;
    }
    public function argument(Argument $argument): static
    {
        if (isset($this->arguments[$argument->name])) { throw new \LogicException('duplicate argument: ' . $argument->name); }
        if ($argument->isRequired() && array_filter($this->arguments, static fn ($a) => !$a->isRequired())) {
            throw new \LogicException('required arguments must precede optional arguments');
        }
        $this->arguments[$argument->name] = $argument; return $this;
    }
    /** 按顺序追加整组规则；类型与单字段校验全部通过后执行，首条失败立即停止。 */
    public function validateInput(callable $validator): static
    {
        $this->inputValidators = $this->inputValidators->append($validator);
        return $this;
    }
    public function hasInputValidators(): bool { return !$this->inputValidators->isEmpty(); }
    /** @internal 由输入绑定流程调用，不创建业务实例。 */
    public function validateBoundInput(Input $input): void
    { $this->inputValidators->validate($input, 'input'); }

    public function metadata(string $key, mixed $value): static { $this->metadata[$key] = $value; return $this; }
    public function meta(string $key): mixed { return $this->metadata[$key] ?? null; }
    /** 示例保存完整调用形式，仅用于详细帮助，不参与解析。 */
    public function example(string $command, string $description = ''): static
    {
        if (trim($command) === '') { throw new \InvalidArgumentException('example must not be blank'); }
        $this->examples[] = ['command' => $command, 'description' => $description];
        return $this;
    }
    public function examples(): array { return $this->examples; }
    public function options(): array { return $this->options; }
    public function arguments(): array { return $this->arguments; }
}
