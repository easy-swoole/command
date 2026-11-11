<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console\Live;

use EasySwoole\Command\Console\View\ViewInterface;

/** 实时视图状态：只管理刷新期限、快照与重绘条件，不读写终端或创建业务依赖。 */
final class LiveState
{
    private float $nextRefresh = 0;
    private string|ViewInterface $view = '';
    private ?array $dimensions = null;
    private bool $stopped = false;

    public function __construct(private readonly float $interval)
    {
        if (!is_finite($interval) || $interval <= 0) {
            throw new \InvalidArgumentException('refresh interval must be positive');
        }
    }

    public function stopped(): bool { return $this->stopped; }
    public function due(float $now): bool { return !$this->stopped && $now >= $this->nextRefresh; }

    /** 以业务回调完成时间安排下次刷新，避免慢查询结束后立即补跑积压刷新。 */
    public function update(mixed $view, float $now): void
    {
        if ($view === false) {
            $this->stopped = true;
            return;
        }
        if (!is_string($view) && !$view instanceof ViewInterface) {
            throw new \UnexpectedValueException('live callback must return text, a view, or false');
        }
        $this->view = $view;
        $this->nextRefresh = $now + $this->interval;
        $this->dimensions = null;
    }

    /** 数据或尺寸变化才返回新帧；窗口缩放只重绘已有快照，不触发业务查询。 */
    public function frame(int $height, int $width): ?string
    {
        if ($this->stopped || $this->dimensions === [$height, $width]) {
            return null;
        }
        $text = $this->view instanceof ViewInterface
            ? $this->view->render(max(0, $width - 1), max(1, $height - 3))
            : $this->view;
        $this->dimensions = [$height, $width];
        return 'Refreshing every ' . $this->interval . "s | q/Esc/Ctrl+C to exit\n\n" . $text;
    }

    public function waitTime(float $now): float
    {
        return min(.1, max(0, $this->nextRefresh - $now));
    }
}
