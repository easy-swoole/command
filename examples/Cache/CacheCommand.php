<?php

declare(strict_types=1);

namespace EasySwoole\Command\Examples\Cache;

use EasySwoole\Command\Console\Console;
use EasySwoole\Command\Contracts\CommandInterface;
use EasySwoole\Command\Definition\ActionDefinition;
use EasySwoole\Command\Definition\CommandDefinition;
use EasySwoole\Command\Definition\Option;
use EasySwoole\Command\Input\Input;
use EasySwoole\Command\Result\CommandResult;
use EasySwoole\Command\Validation\Rules;

/** 定义、分发与各操作的实现分开，避免嵌套长链与过长的 match 分支。 */
final class CacheCommand implements CommandInterface
{
    public function __construct(private MemoryCache $cache)
    {
    }

    public static function createDefinition(): CommandDefinition
    {
        // required 检查是否提供参数，validate 进一步拒绝空字符串和纯空白。
        $store = Option::string('store', '缓存存储名称', required: true);
        $store = $store->validate(Rules::notBlank('--store 不能为空'));

        $clear = ActionDefinition::create('clear');
        $clear->description('清理缓存');
        $clear->option($store);
        $clear->example('php examples/cache.php cache clear --store=redis', '清理 Redis 缓存');

        $warmup = ActionDefinition::create('warmup');
        $warmup->description('预热缓存');
        $warmup->option($store);
        $warmup->example('php examples/cache.php cache warmup --store=default', '预热默认缓存');

        $command = CommandDefinition::create('cache');
        $command->description('缓存管理');
        $command->action($clear);
        $command->action($warmup);

        return $command;
    }

    public function definition(): CommandDefinition
    {
        return self::createDefinition();
    }

    public function execute(Input $input, Console $console): CommandResult
    {
        return match ($input->action()) {
            'clear' => $this->clear($input),
            'warmup' => $this->warmup($input),
            default => throw new \LogicException('未定义的缓存操作'),
        };
    }

    private function clear(Input $input): CommandResult
    {
        $count = $this->cache->clear($input->option('store'));

        return CommandResult::success("已清理 {$count} 项缓存");
    }

    private function warmup(Input $input): CommandResult
    {
        $count = $this->cache->warmup($input->option('store'));

        return CommandResult::success("已预热 {$count} 项缓存");
    }
}
