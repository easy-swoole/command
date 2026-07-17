# Command

### Example

```php


use EasySwoole\Command\AbstractInterface\AbstractCommand;
use EasySwoole\Command\Bean\Action;
use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\ExecStatusEnum;
use EasySwoole\Command\Bean\Option;
use EasySwoole\Command\Bean\Result;
use EasySwoole\Command\Manager;
use EasySwoole\Command\Utility;




class TestCommand extends AbstractCommand{
    function name(): string
    {
        return 'test';
    }

    function description(): string
    {
        return 'test command action';
    }

    protected function init(): void
    {
        $actionA = new Action('actionA');

        $actionA->addOption(new Option('opt1'));
        $actionA->addOption(new Option('opt2'));

        $actionA->setCallback([$this, 'actionA']);

        $this->registerAction($actionA);


        $actionB = new class('actionB') extends Action{
            protected function init(): void
            {
                $this->setCallback(function (Caller $caller, Result $result){
//                    var_dump("{$caller->command}-{$caller->action} ",$caller->commandLine->options);
                    $result->result = time();
                    $result->msg = 'this is actionB';
                });
            }
        };

        $this->registerAction($actionB);
    }

    function actionA(Caller $caller, Result $result)
    {
        $num1 = $caller->commandLine->getParam('num1');
        if(empty($num1)){
            $result->msg = 'param num1 miss,eg: num1=9501';
            $result->status = ExecStatusEnum::COMMAND_ACTION_EXEC_FAIL;
            return;
        }
        $num2 = intval($caller->commandLine->getParam('num2'));
        $result->result = $num1 + $num2;
    }

}


$manager = new Manager();
$manager->addCommand(new TestCommand());

$commandLine = Utility::parseArgv($argv);
array_shift($commandLine->unknows);
$command = array_shift($commandLine->unknows);
$action = array_shift($commandLine->unknows);
$call = new Caller($command,$action,$commandLine);
$ret = $manager->exec($call);

var_dump($ret->result,$ret->msg);

```