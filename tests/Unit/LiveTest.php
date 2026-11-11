<?php

declare(strict_types=1);

use EasySwoole\Command\Console\Live\LiveState;

test('live state separates deadlines resizing and stopping', function () {
    $state = new LiveState(2);
    same(true, $state->due(0));
    $state->update('snapshot', 10);
    same(false, $state->due(11));
    same(true, $state->due(12));
    same(.1, $state->waitTime(11));
    same(0.0, $state->waitTime(13));
    same(true, str_contains($state->frame(24, 80), 'snapshot'));
    same(null, $state->frame(24, 80));
    same(true, str_contains($state->frame(20, 60), 'snapshot'));
    $state->update('next', 12);
    same(true, str_contains($state->frame(20, 60), 'next'));
    $state->update(false, 14);
    same(true, $state->stopped());
    same(null, $state->frame(20, 60));
    same(false, $state->due(20));
});

test('live invalid snapshot fails explicitly', function () {
    raises(fn () => (new LiveState(1))->update([], 0), UnexpectedValueException::class);
});

test('live resize reuses data snapshot without querying again', function () {
    $terminal = new class extends FakeTerminal {
        public int $reads = 0;
        public int $waits = 0;
        public function dimensions(): array { return ++$this->reads < 3 ? [24, 80] : [20, 50]; }
        public function wait(float $seconds): bool { $this->time += $seconds; return ++$this->waits === 3; }
        public function key(): ?string { return 'q'; }
    };
    $queries = 0;
    console($terminal)->live(function () use (&$queries) { ++$queries; return 'snapshot'; }, 60);
    same(1, $queries);
    $frames = array_filter($terminal->frames, static fn ($frame) => str_contains($frame, 'snapshot'));
    same(2, count($frames));
});
