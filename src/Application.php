<?php

declare(strict_types=1);

namespace EasySwoole\Command;

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Contracts\CommandProviderInterface;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Event\ExceptionEvent;
use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Exception\InterruptedException;
use EasySwoole\Command\Exception\TerminalException;
use EasySwoole\Command\Help\HelpFormatter;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Input\InputBinder;
use EasySwoole\Command\Input\InputParser;
use EasySwoole\Command\Input\ParsedInput;
use EasySwoole\Command\Result\CommandResult;
use EasySwoole\Command\Result\ExitCode;

/** 应用只组织定义解析、帮助与执行，业务初始化通过宿主中间件接入。 */
class Application
{
    private CommandRegistry $registry;
    private array $middleware = [];
    /** @var list<\Closure(ExceptionEvent): void> */
    private array $exceptionObservers = [];

    public function __construct(private string $name = 'console', ?CommandRegistry $registry = null)
    { $this->registry = $registry ?? new CommandRegistry(); }

    public function registry(): CommandRegistry { return $this->registry; }
    public function addCommand(CommandInterface $command): void { $this->registry->add($command); }
    public function addProvider(CommandProviderInterface $provider): void { $this->registry->addProvider($provider); }

    /** 中间件接收顶层命令定义、已绑定输入、控制台和 next；可从 Input::action() 获取选中的操作。 */
    public function middleware(callable $middleware): void { $this->middleware[] = $middleware; }

    /** 观察异常而不接管执行结果；观察器自身失败不能覆盖原始错误。 */
    public function onException(callable $observer): void
    { $this->exceptionObservers[] = \Closure::fromCallable($observer); }

    /** argv 不含脚本名称。帮助与输入校验在命令工厂和中间件之前完成。 */
    public function dispatch(array $argv, ?Console $console = null): CommandResult
    {
        $console ??= new Console();
        $parsed = null;
        $input = null;

        try {
            $help = ($argv[0] ?? null) === 'help';
            if ($help) {
                array_shift($argv);
            }

            $parser = new InputParser();
            $parsed = $parser->parse($argv, $this->registry);
            $parser->assertKnownTarget($parsed, $this->registry);

            $options = $parsed->options;
            $help = $help || array_key_exists('help', $options);
            unset($options['help']);

            $helpResult = $this->helpResult($parsed, $options, $help, $console);
            if ($helpResult !== null) {
                return $helpResult;
            }

            $definition = $parsed->command;
            $input = (new InputBinder())->bind(
                $parsed->action ?? $definition,
                $options,
                $parsed->words,
                $definition->name,
                $parsed->actionName,
            );
            return $this->execute($definition, $input, $console);
        } catch (\Throwable $error) {
            return $this->dispatchFailure($error, $parsed, $input);
        }
    }

    /** 处理显式帮助与命令列表，返回 null 时继续绑定业务输入。 */
    private function helpResult(ParsedInput $parsed, array $options, bool $requested, Console $console): ?CommandResult
    {
        $command = $parsed->command;
        if ($command === null) {
            if ($requested) {
                InputBinder::assertKnownOptions(null, $options);
            } elseif ($options) {
                throw new InputException('unexpected option --' . array_key_first($options));
            }

            $formatter = new HelpFormatter($console->supportsColor());
            return CommandResult::success($formatter->overview($this->name, $this->registry->definitions()));
        }

        if ($requested) {
            InputBinder::assertKnownOptions($parsed->action ?? $command, $options);
            if ($parsed->words) {
                throw new InputException('unexpected argument in help request');
            }
        } elseif ($command->hasActions() && $parsed->action === null) {
            if ($options || $parsed->words) {
                throw new InputException('action is required for ' . $command->name);
            }
        } else {
            return null;
        }

        $formatter = new HelpFormatter($console->supportsColor());
        return CommandResult::success($formatter->command($command, $parsed->action));
    }

    private function execute(CommandDefinition $definition, Input $input, Console $console): CommandResult
    {
        // 同一次分发只创建一个命令实例，供中间件多次调用 next 时复用。
        $command = null;
        $next = function () use ($definition, $input, $console, &$command): CommandResult {
            $command ??= $this->registry->create($definition->name);
            return $command->execute($input, $console);
        };
        foreach (array_reverse($this->middleware) as $middleware) {
            $inner = $next;
            $next = fn () => $middleware($definition, $input, $console, $inner);
        }
        return $next();
    }

    private function dispatchFailure(\Throwable $error, ?ParsedInput $parsed, ?Input $input): CommandResult
    {
        $input ??= $error instanceof InputException ? $error->input : null;
        $event = new ExceptionEvent($error, $parsed?->commandName, $parsed?->actionName, $input);
        foreach ($this->exceptionObservers as $observer) {
            try {
                $observer($event);
            } catch (\Throwable) {
                // 诊断故障不影响其他观察器，也不替换原始异常。
            }
        }

        $code = match (true) {
            $error instanceof InterruptedException => $error->getCode(),
            $error instanceof InputException => ExitCode::INVALID_INPUT,
            default => ExitCode::FAILURE,
        };
        return CommandResult::failure($error->getMessage(), $code);
    }

    /** 在命令行边界输出结果，业务错误写入标准错误流。 */
    public function run(array $argv, ?Console $console = null): int
    {
        $console ??= new Console();
        $result = $this->dispatch($argv, $console);
        try {
            $console->writeResult($result);
        } catch (\JsonException | TerminalException $error) {
            $message = $error instanceof \JsonException
                ? 'cannot encode command result: ' . $error->getMessage()
                : $error->getMessage();
            try {
                $console->error($message);
            } catch (TerminalException) {
                // stderr 也无法写入时，仍用退出码报告失败。
            }
            return ExitCode::FAILURE->value;
        }
        return $result->exitCode;
    }
}
