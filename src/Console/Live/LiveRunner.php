<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Live;

use EasySwoole\Command\Console\Terminal;

/** 协调实时状态与终端事件；终端的打开、独占和清理由 Console 会话统一负责。 */
final class LiveRunner
{
    public function __construct(private readonly Terminal $terminal) {}

    public function run(callable $refresh, LiveState $state): void
    {
        while (!$state->stopped()) {
            if ($state->due($this->terminal->now())) {
                $view = $refresh();
                $state->update($view, $this->terminal->now());
            }
            if ($state->stopped()) { return; }

            [$height, $width] = $this->terminal->dimensions();
            $frame = $state->frame($height, $width);
            if ($frame !== null) { $this->terminal->frame($frame); }

            // 等待期间最多每 100ms 检查一次退出；业务查询自身的超时由调用方控制。
            if ($this->terminal->wait($state->waitTime($this->terminal->now()))) {
                $key = $this->terminal->key();
                if ($key === null || in_array($key, ['cancel', 'q', 'Q'], true)) { return; }
            }
        }
    }
}
