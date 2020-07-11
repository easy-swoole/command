<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Command;


use EasySwoole\Command\AbstractInterface\CommandInterface;
use EasySwoole\Component\Singleton;

class CommandManager
{
    use Singleton;

    /**
     * default metas
     */
    private $metas = [
        'name' => 'EasySwoole Command',
        'desc' => 'Welcome To EasySwoole Command Console!'
    ];

    /**
     * a b framework=easyswoole
     * @var array
     */
    private $args = [];

    /**
     * --config=dev.php -d
     * @var array
     */
    private $opts = [];

    /**
     * 当前执行的command
     * @var string
     */
    private $command = '';

    /**
     * 脚本
     * @var string
     */
    private $script = '';

    /**
     * add commands
     * @var array
     */
    private $commands = [];

    /**
     * @var int
     */
    private $width = 1;

    /**
     * @var array
     */
    private $originArgv = [];

    /**
     * CommandManager constructor.
     */
    public function __construct()
    {

    }

    /**
     * @param array $argv
     */
    private function init(array $argv = [])
    {
        $this->originArgv = $argv;
        // script
        $script = array_shift($argv);
        $this->script = $script;

        // command
        $command = array_shift($argv);
        $this->command = $command;

        $this->parseArgv(array_values($argv));
    }

    /**
     * @param array $argv
     * @return string
     */
    public function run(array $argv = []): string
    {
        $this->init($argv);
        if (!$command = $this->command) {
            return $this->displayHelp();
        }

        if ($command == '--help' || $command == '-h') {
            return $this->displayHelp();
        }

        if (!array_key_exists($command, $this->commands)) {
            return $this->displayAlternativesHelp($command);
        }

        if (isset($this->opts['h']) || isset($this->opts['help'])) {
            return $this->displayCommandHelp($command);
        }

        /** @var CommandInterface $handler */
        $handler = $this->commands[$command];
        return $handler->exec();
    }


    /**
     * @return array
     */
    public function getOriginArgv(): array
    {
        return $this->originArgv;
    }

    /**
     * @param array $params
     */
    private function parseArgv(array $params)
    {
        while (false !== ($param = current($params))) {
            next($params);

            // first str eq - this is option
            if ($param[0] === '-') {
                $value = true;
                $option = substr($param, 1);

                // --config=dev
                if (strpos($option, '-') === 0) {
                    $option = substr($option, 1);

                    if (strpos($option, '=') !== false) {
                        [$option, $value] = explode('=', $option, 2);
                    }

                }

                $this->opts[$option] = $value;

            } elseif (isset($option[1]) && $option[1] === '=') {
                [$option, $value] = explode('=', $option, 2);
                $this->opts[$option] = $value;
            }

            // 存在 属于option
            if (isset($option) && isset($value)) continue;

            if (strpos($param, '=') !== false) {
                [$name, $value] = explode('=', $param, 2);
                $this->args[$name] = $value;
            } else {
                $this->args[] = $param;
            }
        }
    }

    /**
     * @param array $metas
     */
    public function setMetas(array $metas)
    {
        $this->metas = array_merge($this->metas, $metas);
    }

    public function addCommand(CommandInterface $handler)
    {
        $command = $handler->commandName();
        $this->commands[$command] = $handler;

        if (($len = strlen($command)) > $this->width) {
            $this->width = $len;
        }

    }

    private function displayAlternativesHelp($command): string
    {
        $text = "The command '{$command}' is not exists!\nDid you mean one of these?\n";
        foreach (array_keys($this->commands) as $key) {
            if (strpos($key, $command) !== false) {
                $text .= "  $key\n";
            }
        }
        return Color::red($text);
    }

    private function displayCommandHelp($command)
    {
        /** @var CommandInterface $handler */
        $handler = $this->commands[$command];

        $fullCmd = $this->script . " $command";

        $desc = $handler->desc() ? ucfirst($handler->desc()) : 'No description for the command';
        $desc = "<brown>$desc</brown>";
        $usage = "$fullCmd [args ...] [--opts ...]";

        $nodes = [
            $desc,
            "<brown>Usage:</brown>" . "\n  $usage\n\n",
        ];

        $userHelp = $handler->help();

        $userHelp = array_map(function ($v) use ($fullCmd) {
            return "  $fullCmd $v\n";
        }, $userHelp);

        $help = implode("\n", $nodes) . "<brown>Examples:</brown>\n" . implode("", $userHelp);


        return Color::render($help) . PHP_EOL;
    }

    private function displayHelp()
    {
        // help
        $desc = ucfirst($this->metas['desc']) . "\n";
        $usage = "<cyan>{$this->script} COMMAND -h</cyan>";

        $help = "<brown>{$desc}Usage:</brown>" . " $usage\n<brown>Commands:</brown>\n";
        $data = $this->commands;
        ksort($data);

        /**
         * @var string $command
         * @var CommandInterface $handler
         */
        foreach ($data as $command => $handler) {
            $command = str_pad($command, $this->width, ' ');
            $desc = $handler->desc() ? ucfirst($handler->desc()) : 'No description for the command';
            $help .= "  <brown>$command</brown>  $desc\n";
        }

        $help .= "\nFor command usage please run: $usage";

        return Color::render($help) . PHP_EOL;
    }

    /**
     * @return array
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * @param array $args
     */
    public function setArgs(array $args): void
    {
        $this->args = $args;
    }

    /**
     * @return array
     */
    public function getOpts(): array
    {
        return $this->opts;
    }

    /**
     * @param array $opts
     */
    public function setOpts(array $opts): void
    {
        $this->opts = $opts;
    }

    /**
     * @param string|int $name
     * @param mixed $default
     *
     * @return mixed|null
     */
    public function getArg($name, $default = null)
    {
        return $this->args[$name] ?? $default;
    }

    /**
     * @param string|int $name
     * @param mixed $default
     * @return mixed|null
     */
    public function getOpt($name, $default = null)
    {
        return $this->opts[$name] ?? $default;
    }

    /**
     * @param $name
     * @return bool
     */
    public function issetArg($name)
    {
        return isset($this->args[$name]);
    }


    /**
     * @param string|int $name
     * @return bool
     */
    public function issetOpt($name)
    {
        return isset($this->opts[$name]);
    }


}