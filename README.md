# Command

### Example

```php
use EasySwoole\Command\AbstractInterface\AbstractCommand;
use EasySwoole\Command\Bean\Action;
use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\ExecStatusEnum;
use EasySwoole\Command\Bean\Option;
use EasySwoole\Command\Bean\Param;
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

        $actionA->addOption(new Option(
            name:'opt1'
        ));
        $actionA->addOption(new Option('opt2'));
        $actionA->setCallback([$this, 'actionA']);
        $this->registerAction($actionA);


        $actionB = new class('actionB') extends Action{
            protected function init(): void
            {
                $this->setCallback(function (Caller $caller,Result $result) {
                    $result->result = $caller->commandLine->getParam('num1') + $caller->commandLine->getParam('num2');
                });
                $this->addParam(new class('num1') extends Param{
                    public static function validate(mixed $value,Caller $caller): bool|string
                    {
                        if($value < 5){
                            return 'num1 must be greater or equal to 5';
                        }
                        return true;
                    }
                });

                $this->addParam(new class('num2') extends Param{
                    public static function validate(mixed $value,Caller $caller): bool|string
                    {
                        if($value > 5){
                            return 'num2 must be small or equal to 5';
                        }
                        return true;
                    }
                });
            }
        };


        $this->registerAction($actionB);

    }

    function actionA(Caller $caller, Result $result)
    {
        $num1 = $caller->commandLine->getOption('opt1');
        if(empty($num1)){
            $result->msg = 'option opt1 miss , eg: num1=9501';
            $result->status = ExecStatusEnum::COMMAND_ACTION_EXEC_FAIL;
            return;
        }
        $num2 = intval($caller->commandLine->getOption('opt2'));
        $result->result = $num1 + $num2;
    }

}

class Fly extends AbstractCommand{
    function name(): string
    {
        return 'fly';
    }

    function description(): string
    {
        return 'fly command action';
    }
}

$fly = new Fly();
$actionA = new Action('actionA');

$actionA->addOption(new Option('opt1'));

$actionA->addOption(new class ('opt2') extends Option
{
    public static function validate(mixed $value,Caller $caller): bool|string{
        if($value < 5){
            return 'opt1 must be greater or equal to 5';
        }
        return true;
    }
});

$actionA->setCallback(function (Caller $caller,Result $result) {
    $result->result = "your opt1 is ".$caller->commandLine->getOption('opt2');
});

$fly->registerAction($actionA);

$manager = new Manager();
$manager->addCommand(new TestCommand());
$manager->addCommand($fly);

$commandLine = Utility::parseArgv($argv);
array_shift($commandLine->unknows);
$command = array_shift($commandLine->unknows);
$action = array_shift($commandLine->unknows);
$call = new Caller($command,$action,$commandLine);
$ret = $manager->exec($call);

var_dump('================');
var_dump($ret->result,$ret->msg);



```