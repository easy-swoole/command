<?php

declare(strict_types=1);

namespace EasySwoole\Command;

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Contracts\CommandProviderInterface;
use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Exception\InterruptedException;
use EasySwoole\Command\Input\InputParser;
use EasySwoole\Command\Result\CommandResult;

/** 组织解析、校验、中间件与命令执行；具体业务初始化由宿主应用通过中间件接入。 */
class Application
{
    private CommandRegistry $registry;
    private array $middleware = [];
    public function __construct(private string $name = 'console', ?CommandRegistry $registry = null)
    { $this->registry = $registry ?? new CommandRegistry(); }
    public function registry(): CommandRegistry { return $this->registry; }
    public function addCommand(CommandInterface $command): void { $this->registry->add($command); }
    public function addProvider(CommandProviderInterface $provider): void { $this->registry->addProvider($provider); }
    /** 中间件接收命令定义、已校验的输入、控制台及返回 CommandResult 的 next 回调。 */
    public function middleware(callable $middleware): void { $this->middleware[] = $middleware; }
    /** 分发命令并返回结果，不自动输出结果消息；argv 不包含脚本名称。 */
    public function dispatch(array $argv, ?Console $console = null): CommandResult
    {
        $console ??= new Console();
        // 在应用边界统一转换退出码；分发只返回结果，由 run 决定输出到标准输出或标准错误。
        try {
            $help = false;
            if (($argv[0] ?? null) === 'help') { array_shift($argv); $help = true; }
            $parser = new InputParser();
            [$definition, $options, $words, $path] = $parser->parse($argv, $this->registry);
            $help = $help || array_key_exists('help', $options);
            unset($options['help']);
            // 仅命令组名称匹配时展示帮助；未知路径报错，避免误执行其他操作。
            if ($definition === null) {
                $prefix = implode(' ', $path);
                $matches = array_filter($this->registry->definitions(), static fn ($d) => $prefix === '' || str_starts_with($d->name, $prefix . ' '));
                if (!$matches) { throw new InputException('unknown command: ' . $prefix); }
                if ($options) { throw new InputException('unexpected option --' . array_key_first($options)); }
                return CommandResult::success($this->help($matches));
            }
            // 帮助先于参数绑定与工厂调用，缺少必填值也可查看帮助，且不会创建业务依赖。
            if ($help) {
                if ($words) { throw new InputException('unexpected command or argument in help request'); }
                return CommandResult::success($this->help([$definition]));
            }
            // 在执行中间件之前完成校验，防止无效输入触发框架初始化或网络连接。
            $input = $parser->bind($definition, $options, $words);
            // 同一次分发复用一个延迟创建的命令实例，避免实时刷新反复构建依赖。
            $command = null;
            $next = function () use ($definition, $input, $console, &$command): CommandResult {
                $command ??= $this->registry->create($definition->name);
                return $command->execute($input, $console);
            };
            // 倒序包裹回调，使最先注册的中间件最先进入、最后退出。
            foreach (array_reverse($this->middleware) as $middleware) {
                $inner = $next;
                $next = fn () => $middleware($definition, $input, $console, $inner);
            }
            return $next();
        } catch (InterruptedException $error) { return CommandResult::failure($error->getMessage(), 128 + $error->signal); }
        catch (InputException $error) { return CommandResult::failure($error->getMessage(), 2); }
        catch (\Throwable $error) { return CommandResult::failure($error->getMessage()); }
    }
    /** 命令行入口：输出结果消息并返回进程退出码。 */
    public function run(array $argv, ?Console $console = null): int
    {
        $console ??= new Console();
        $result = $this->dispatch($argv, $console);
        if ($result->message !== null && $result->message !== '') {
            $console->line($result->message, $result->exitCode !== 0);
        }
        return $result->exitCode;
    }
    private function help(array $definitions): string
    {
        $lines = [$this->name, ''];
        foreach ($definitions as $definition) {
            $lines[] = $definition->name . '  ' . $definition->summary();
            foreach ($definition->options() as $option) {
                $suffix = $option->type === 'bool' ? '[=bool]' : '=<' . $option->type . '>';
                $lines[] = '  --' . $option->name . $suffix . '  ' . $option->description . ($option->required ? ' (required)' : '');
            }
            foreach ($definition->arguments() as $argument) {
                $lines[] = '  ' . ($argument->required ? '<' : '[') . $argument->name . ($argument->required ? '>' : ']') . '  ' . $argument->description;
            }
        }
        $lines[] = '  --help  Show help';
        return implode(PHP_EOL, $lines);
    }
}
