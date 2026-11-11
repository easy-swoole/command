<?php

declare(strict_types=1);

namespace EasySwoole\Command;

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
        try {
            $help = false;
            if (($argv[0] ?? null) === 'help') { array_shift($argv); $help = true; }
            $parser = new InputParser();
            [$definition, $options, $words, $path] = $parser->parse($argv, $this->registry);
            $help = $help || array_key_exists('help', $options);
            unset($options['help']);
            if ($definition === null) {
                $prefix = implode(' ', $path);
                $matches = array_filter($this->registry->definitions(), static fn ($d) => $prefix === '' || str_starts_with($d->name, $prefix . ' '));
                if (!$matches) { throw new InputException('unknown command: ' . $prefix); }
                if ($options) { throw new InputException('unexpected option --' . array_key_first($options)); }
                return CommandResult::success($this->help($matches));
            }
            if ($help) {
                if ($words) { throw new InputException('unexpected command or argument in help request'); }
                return CommandResult::success($this->help([$definition]));
            }
            $input = $parser->bind($definition, $options, $words);
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
