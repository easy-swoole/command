<?php

declare(strict_types=1);

use EasySwoole\Command\Definition\Argument;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Exception\InputException;
use EasySwoole\Command\Input\InputBinder;

test('typed input and legacy single dash', function () {
    $r = app()->dispatch(['demo', 'run', '-d', '-force=false', '--mode=produce', '--pid=0012', 'name=hello']);
    same(0, $r->exitCode); same(true, $r->data[0]['d']); same(false, $r->data[0]['force']);
    same(12, $r->data[0]['pid']); same('hello', $r->data[1]); same(true, $r->data[2]);
});

test('option presence and defaults', function () {
    $r = app()->dispatch(['demo', 'run']); same(false, $r->data[2]); same('dev', $r->data[0]['mode']);
    same(true, app()->dispatch(['demo', 'run', '--force'])->data[0]['force']);
});

test('watch optional value', function () {
    same(2.0, app()->dispatch(['demo', 'run', '--watch'])->data[0]['watch']);
    same(.25, app()->dispatch(['demo', 'run', '--watch=0.25'])->data[0]['watch']);
});

test('last repeated option wins', fn () => same('test', app()->dispatch(['demo', 'run', '--mode=dev', '--mode=test'])->data[0]['mode']));

test('options before command', fn () => same('prod', app()->dispatch(['--mode=prod', 'demo', 'run'])->data[0]['mode']));

test('equals preserved', fn () => same('a=b', app()->dispatch(['demo', 'run', '--mode=a=b'])->data[0]['mode']));

test('positional argument', fn () => same('hello', app()->dispatch(['demo', 'run', 'hello'])->data[1]));

test('literal terminator', fn () => same('-a=b', app()->dispatch(['demo', 'run', '--', '-a=b'])->data[1]));

foreach ([['--unknown'], ['--mode'], ['--pid=0'], ['--pid=-1'], ['--pid=1.5'], ['--pid=99999999999999999999999'], ['--force=bad'], ['--watch=0'], ['--watch=INF'], ['--watch=NaN']] as $options) {
    test('invalid ' . implode(' ', $options), fn () => same(2, app()->dispatch(array_merge(['demo', 'run'], $options))->exitCode));
}

test('unexpected argument', fn () => same(2, app()->dispatch(['demo', 'run', 'a', 'b'])->exitCode));

test('unknown named argument', fn () => same(2, app()->dispatch(['demo', 'run', 'unknown=x'])->exitCode));

test('duplicate named argument', fn () => same(2, app()->dispatch(['demo', 'run', 'name=x', 'name=y'])->exitCode));

test('required argument and option', function () {
    $binder = new InputBinder(); $d = CommandDefinition::create('a')->argument(new Argument('x', required: true))->option(Option::string('y', required: true));
    raises(fn () => $binder->bind($d, []), InputException::class);
    raises(fn () => $binder->bind($d, ['y' => 'yes']), InputException::class);
    same('ok', $binder->bind($d, ['y' => 'yes'], [['ok', false]])->argument('x'));
});

test('all paths beginning with reserved help are rejected', function () {
    foreach ([['help'], ['help', 'cache'], ['help cache clear']] as $path) {
        raises(fn () => CommandDefinition::create(...$path), InvalidArgumentException::class);
    }
    same('help', \EasySwoole\Command\Definition\ActionDefinition::create('help')->name);
});

test('signed leading zero integers retain boundary checks', function () {
    $option = Option::integer('number');
    foreach (['-0012' => -12, '-000' => 0, '000' => 0, '0012' => 12] as $raw => $expected) {
        same($expected, $option->bind((string)$raw, true));
    }
    same(PHP_INT_MIN, $option->bind('-000' . substr((string)PHP_INT_MIN, 1), true));
    same(PHP_INT_MAX, $option->bind('000' . PHP_INT_MAX, true));
    foreach (['999999999999999999999', '-999999999999999999999'] as $value) {
        raises(fn () => $option->bind($value, true), InputException::class);
    }
});

test('argument presence distinguishes defaults named and positional values', function () {
    $definition = CommandDefinition::create('demo')->option(Option::string('target', default: 'default'))
        ->argument(Argument::string('target', default: 'default'))->argument(Argument::integer('count', default: 2));
    $binder = new InputBinder();
    $input = $binder->bind($definition, []);
    same(['target' => 'default', 'count' => 2], $input->arguments());
    same(false, $input->argumentProvided('target')); same(false, $input->argumentProvided('count'));
    $input = $binder->bind($definition, ['target' => 'default'], [['default', false], ['count=2', false]]);
    same(true, $input->argumentProvided('target')); same(true, $input->argumentProvided('count'));
    same(true, $input->optionProvided('target')); same(true, $input->provided('target'));
    same(false, $input->argumentProvided('missing'));
    $input = $binder->bind($definition, [], [['target=', false]]);
    same('', $input->argument('target')); same(true, $input->argumentProvided('target'));
    same(false, $input->optionProvided('target')); same(false, $input->provided('target'));
    same(false, $input->argumentProvided('count'));
});
