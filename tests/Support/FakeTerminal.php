<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Terminal;

class FakeTerminal extends Terminal {
    public array $keys = [];
    public array $lines = [];
    public array $frames = [];
    public array $modes = [];
    public bool $tty = true;
    public bool $raw = true;
    public bool $throwOnRead = false;
    public bool $failRawMode = false;
    public array $sizes = [];
    public float $time = 0;
    public function interactive(): bool { return $this->tty; }
    public function supported(): bool { return $this->tty && $this->raw; }
    protected function command(array $args): ?string {
        $this->modes[] = $args;
        if ($args === ['-g']) { return 'saved'; }
        if ($args === ['size']) { return '24 80'; }
        return $this->failRawMode && ($args[0] ?? '') === '-icanon' ? null : '';
    }
    public function write(string $text): void { $this->frames[] = $text; }
    public function dimensions(): array { return $this->sizes ? array_shift($this->sizes) : [24, 80]; }
    public function wait(float $seconds): bool { $this->time += $seconds; return count($this->keys) > 0; }
    public function key(): ?string {
        if ($this->throwOnRead) { throw new RuntimeException('read failed'); }
        return array_shift($this->keys);
    }
    public function now(): float { return $this->time; }
    public function readLine(): ?string { return array_shift($this->lines); }
}
