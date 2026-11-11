<?php

declare(strict_types=1);

namespace EasySwoole\Command;

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Contracts\CommandProviderInterface;
use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Exception\InterruptedException;
use EasySwoole\Command\Help\HelpFormatter;
use EasySwoole\Command\Input\InputParser;
use EasySwoole\Command\Result\CommandResult;

/** 应用只组织定义解析、帮助与执行，业务初始化通过宿主中间件接入。 */
class Application
{
    private CommandRegistry $registry;
    private array $middleware = [];

    public function __construct(private string $name = 'console', ?CommandRegistry $registry = null)
    { $this->registry = $registry ?? new CommandRegistry(); }

    public function registry(): CommandRegistry { return $this->registry; }
    public function addCommand(CommandInterface $command): void { $this->registry->add($command); }
    public function addProvider(CommandProviderInterface $provider): void { $this->registry->addProvider($provider); }

    /** 中间件接收顶层命令定义、已绑定输入、控制台和 next；可从 Input::action() 获取选中的操作。 */
    public function middleware(callable $middleware): void { $this->middleware[] = $middleware; }

    /** argv 不含脚本名称；帮助和输入校验均先于命令工厂和中间件。 */
    public function dispatch(array $argv, ?Console $console = null): CommandResult
    {
        $console ??= new Console();
        try {
            $help = false;
            if (($argv[0] ?? null) === 'help') { array_shift($argv); $help = true; }
            $parser = new InputParser();
            $parsed = $parser->parse($argv, $this->registry);
            $options = $parsed->options;
            $help = $help || array_key_exists('help', $options);
            unset($options['help']);
            $formatter = new HelpFormatter();
            if ($parsed->commandName === null) {
                if ($options) { throw new InputException('unexpected option --' . array_key_first($options)); }
                return CommandResult::success($formatter->overview($this->name, $this->registry->definitions()));
            }
            $definition = $parsed->command;
            if ($definition === null) { throw new InputException('unknown command: ' . $parsed->commandName); }
            if ($parsed->actionName !== null && $parsed->action === null) {
                throw new InputException('unknown action: ' . $parsed->actionName . ' on ' . $definition->name);
            }
            if ($help) {
                if ($parsed->words) { throw new InputException('unexpected argument in help request'); }
                return CommandResult::success($formatter->command($definition, $parsed->action));
            }
            if ($definition->hasActions() && $parsed->action === null) {
                if ($options || $parsed->words) { throw new InputException('action is required for ' . $definition->name); }
                return CommandResult::success($formatter->command($definition));
            }
            $input = $parser->bind($parsed->action ?? $definition, $options, $parsed->words, $definition->name, $parsed->actionName);
            // 一个顶层命令只实例化一次，多次刷新可复用自身依赖与操作处理器。
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
        } catch (InterruptedException $error) {
            return CommandResult::failure($error->getMessage(), 128 + $error->signal);
        } catch (InputException $error) {
            return CommandResult::failure($error->getMessage(), 2);
        } catch (\Throwable $error) {
            return CommandResult::failure($error->getMessage());
        }
    }

    /** 在命令行边界输出结果，业务错误写入标准错误流。 */
    public function run(array $argv, ?Console $console = null): int
    {
        $console ??= new Console();
        $result = $this->dispatch($argv, $console);
        if ($result->message !== null && $result->message !== '') {
            $console->line($result->message, $result->exitCode !== 0);
        }
        return $result->exitCode;
    }
}
