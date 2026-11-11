<?php

declare(strict_types=1);

namespace EasySwoole\Command\Console;

use EasySwoole\Command\Exception\InterruptedException;
use EasySwoole\Command\Exception\TerminalException;

/** 可注入的终端驱动，负责原始输入模式及独占交互会话。 */
class Terminal
{
    protected mixed $input;
    protected mixed $output;
    /** 进程内共享占用状态，阻止不同 Console 实例同时修改同一个终端的输入模式。 */
    private static ?self $owner = null;
    private ?string $savedMode = null;
    private bool $opened = false;
    private bool $shutdownRegistered = false;
    private array $signalHandlers = [];
    private ?bool $asyncSignals = null;
    private array $lastSize = [24, 80];
    private float $lastSizeAt = -INF;
    public function __construct($input = null, $output = null)
    { $this->input = $input ?? STDIN; $this->output = $output ?? STDOUT; }
    public function interactive(): bool { return stream_isatty($this->input); }
    public function supported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows' && function_exists('proc_open') && $this->interactive()
            && stream_isatty($this->output) && getenv('TERM') !== 'dumb';
    }
    public function write(string $text): void { fwrite($this->output, $text); }
    public function readLine(): ?string { $line = fgets($this->input); return $line === false ? null : $line; }
    public function open(): void
    {
        if (self::$owner !== null || $this->opened) { throw new \LogicException('an interactive view is already active'); }
        if (!$this->supported()) { throw new TerminalException('an interactive terminal is required'); }
        $saved = $this->command(['-g']);
        if (!$saved) { throw new TerminalException('cannot read terminal mode'); }
        // 关闭行缓冲、输入回显及终端按键信号转换，由事件循环解释方向键与 Ctrl+C。
        if ($this->command(['-icanon', '-echo', '-isig', '-ixon', 'min', '1', 'time', '0']) === null) {
            $this->command([$saved]); throw new TerminalException('cannot enable keyboard input');
        }
        $this->savedMode = $saved; $this->opened = true; self::$owner = $this;
        if (!$this->shutdownRegistered) { register_shutdown_function([$this, 'close']); $this->shutdownRegistered = true; }
        $this->installSignals();
        // 进入备用屏幕并隐藏光标；退出时恢复原屏幕，保留用户之前的终端内容。
        $this->write("\033[?1049h\033[?25l");
    }
    /** 关闭操作可重复调用，供正常退出、异常清理与进程结束回调共用。 */
    public function close(): void
    {
        if (!$this->opened) { return; }
        $saved = $this->savedMode; $this->savedMode = null; $this->opened = false; self::$owner = null;
        try { if ($saved !== null) { $this->command([$saved]); } }
        finally { $this->restoreSignals(); $this->write("\033[?25h\033[?1049l"); }
    }
    private function installSignals(): void
    {
        if (!function_exists('pcntl_signal_get_handler') || !function_exists('pcntl_async_signals')) { return; }
        // 仅会话期间接管外部信号，通过异常展开 finally；关闭时归还宿主原有处理器。
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            $this->signalHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $signal): void { throw new InterruptedException($signal); });
        }
        $this->asyncSignals = pcntl_async_signals(true);
    }
    private function restoreSignals(): void
    {
        foreach ($this->signalHandlers as $signal => $handler) { pcntl_signal($signal, $handler); }
        $this->signalHandlers = [];
        if ($this->asyncSignals !== null) { pcntl_async_signals($this->asyncSignals); $this->asyncSignals = null; }
    }
    public function dimensions(): array
    {
        $now = $this->now();
        // 缓存短时间内的尺寸查询，避免高频键盘循环不断启动 stty 子进程。
        if ($now - $this->lastSizeAt < .2) { return $this->lastSize; }
        $this->lastSizeAt = $now;
        $size = $this->command(['size']);
        if ($size && preg_match('/^(\d+)\s+(\d+)$/D', $size, $m)) {
            $this->lastSize = [(int)$m[1] > 0 ? (int)$m[1] : 24, (int)$m[2] > 1 ? (int)$m[2] : 80];
        }
        return $this->lastSize;
    }
    protected function command(array $arguments): ?string
    {
        $process = @proc_open(array_merge(['stty'], $arguments), [0 => $this->input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { return null; }
        $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return proc_close($process) === 0 ? trim($out) : null;
    }
    public function wait(float $seconds): bool
    {
        $read = [$this->input]; $write = $except = []; $seconds = max(0, $seconds);
        return @stream_select($read, $write, $except, (int)$seconds, (int)(($seconds - (int)$seconds) * 1e6)) === 1;
    }
    public function key(): ?string
    {
        $char = fgetc($this->input);
        if ($char === false) { return null; }
        $byte = ord($char);
        // 拼接完整 UTF-8 字符，避免中文搜索按字节拆分；不完整输入采用有限等待。
        if ($byte >= 0xc2 && $byte <= 0xf4) {
            $length = $byte < 0xe0 ? 2 : ($byte < 0xf0 ? 3 : 4);
            $character = $char;
            for ($index = 1; $index < $length; ++$index) {
                if (!$this->wait(.05)) { return 'ignore'; }
                $next = fgetc($this->input);
                if ($next === false || (ord($next) & 0xc0) !== 0x80) { return 'ignore'; }
                $character .= $next;
            }
            return preg_match('//u', $character) === 1 ? $character : 'ignore';
        }
        // Esc 既可独立表示取消，也可能是方向键序列的前缀，通过短等待区分。
        if ($char === "\033") {
            if (!$this->wait(.05)) { return 'cancel'; }
            $prefix = fgetc($this->input);
            if (($prefix !== '[' && $prefix !== 'O') || !$this->wait(.05)) { return 'cancel'; }
            return match (fgetc($this->input)) { 'A' => 'up', 'B' => 'down', default => 'ignore' };
        }
        return match ($char) {
            "\r", "\n" => 'enter', ' ' => 'toggle', "\177", "\010" => 'backspace',
            "\003", "\004", "\032" => 'cancel', default => $char,
        };
    }
    public function now(): float { return hrtime(true) / 1e9; }
    public static function clean(string $text): string { return preg_replace('/[\x00-\x1f\x7f]/', '?', $text); }
    public static function width(string $text): int
    {
        if (function_exists('mb_strwidth')) { return mb_strwidth($text, 'UTF-8'); }
        return array_sum(array_map(static fn ($c) => strlen($c) > 1 ? 2 : 1, preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }
    public static function fit(string $text, int $width): string
    {
        if (function_exists('mb_strimwidth')) { return mb_strimwidth($text, 0, max(0, $width), '', 'UTF-8'); }
        $result = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $width -= strlen($char) > 1 ? 2 : 1;
            if ($width < 0) { break; }
            $result .= $char;
        }
        return $result;
    }
    public function frame(string $text): void
    {
        [$height, $width] = $this->dimensions();
        // 裁剪视口并预留边缘单元格，防止自动换行或滚屏破坏后续原地绘制。
        $lines = array_slice(explode("\n", str_replace("\r", '', $text)), 0, max(1, $height - 1));
        $this->write("\033[H\033[J" . implode("\n", array_map(static fn ($line) => self::fit(self::clean($line), $width - 1), $lines)));
    }
}
