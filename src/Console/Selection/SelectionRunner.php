<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Selection;

use EasySwoole\Command\Console\Terminal;

/** 将终端按键交给状态机，只在内容或窗口尺寸变化时重绘。 */
final class SelectionRunner
{
    public function __construct(private readonly Terminal $terminal) {}

    public function run(string $title, array $choices, bool $multiple): Selection
    {
        $state = new SelectionState($choices, $multiple);
        $previous = null;
        while (true) {
            [$rows, $columns] = $this->terminal->dimensions();
            $frame = $state->render($title, $rows);
            $signature = [$frame, $rows, $columns];
            if ($signature !== $previous) {
                $this->terminal->frame($frame);
                $previous = $signature;
            }
            if (!$this->terminal->wait(.1)) { continue; }
            $result = $state->handle($this->terminal->key());
            if ($result !== null) { return $result; }
        }
    }
}
