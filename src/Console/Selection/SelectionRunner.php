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
        $renderer = new SelectionRenderer();
        $previous = null;
        $size = null;
        $dirty = true;
        while (true) {
            [$rows, $columns] = $this->terminal->dimensions();
            if ($size !== [$rows, $columns]) {
                $size = [$rows, $columns];
                $state->setPageSize($renderer->pageSize($rows, $columns));
                $dirty = true;
            }
            if ($dirty) {
                $frame = $renderer->render($title, $state, $rows, $columns);
                $signature = [$frame, $rows, $columns];
                if ($signature !== $previous) {
                    $this->terminal->frame($frame);
                    $previous = $signature;
                }
                $dirty = false;
            }
            if (!$this->terminal->wait(.1)) { continue; }
            $result = $state->handle($this->terminal->key());
            if ($result !== null) { return $result; }
            $dirty = true;
        }
    }
}
