<?php

declare(strict_types=1);

namespace EasySwoole\Command\Examples\Cache;

/** 演示用缓存服务，实际项目可替换为组件提供的缓存依赖。 */
final class MemoryCache
{
    private array $stores = ['default' => ['sample' => 'value']];

    public function clear(string $store): int
    {
        $count = count($this->stores[$store] ?? []);
        $this->stores[$store] = [];
        return $count;
    }

    public function warmup(string $store): int
    {
        $this->stores[$store] = ['sample' => 'value', 'ready' => true];
        return count($this->stores[$store]);
    }
}
