<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Selection;

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Console\Text;

/** 在无法开启原始终端模式时，通过编号完成选择。 */
final class NumberedSelectionRunner
{
    public function __construct(private readonly Console $console) {}

    /** @param list<Choice> $choices 已由 Console 校验并连续编号的选项 */
    public function run(string $title, array $choices, bool $multiple): Selection
    {
        $this->console->interactionLine($title);
        foreach ($choices as $index => $choice) {
            $this->console->interactionLine('[' . ($index + 1) . '] ' . Text::clean($choice->label));
            if ($choice->description !== '') {
                $this->console->interactionLine('    ' . Text::clean($choice->description));
            }
        }
        $this->console->interactionLine('[0] Cancel');

        while (true) {
            $answer = $this->console->prompt($multiple ? 'Enter indexes (comma-separated) [0]: ' : 'Enter index [0]: ');
            if ($answer === null) {
                return Selection::eof();
            }

            $answer = trim($answer);
            if ($answer === '' || $answer === '0' || strtolower($answer) === 'q') {
                return Selection::cancel();
            }

            $selected = [];
            foreach (preg_split('/[\s,，]+/u', $answer) as $index) {
                $number = (int) $index;
                if (!ctype_digit($index) || $number < 1 || $number > count($choices)) {
                    $selected = [];
                    break;
                }
                $selected[$number - 1] = true;
            }
            if ($selected && ($multiple || count($selected) === 1)) {
                // 与方向键模式一致，按候选列表顺序返回已选项。
                return Selection::selected(array_values(array_intersect_key($choices, $selected)));
            }
            $this->console->interactionLine('Invalid selection; enter listed indexes or 0 to cancel.');
        }
    }
}
