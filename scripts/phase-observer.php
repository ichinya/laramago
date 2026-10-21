<?php

declare(strict_types=1);

use Mago\Sdk\Analyzer\{AfterAnalysisContext, AfterAnalysisHook, BeforeAnalysisContext, BeforeAnalysisHook,
    CodebaseScanContext, CodebaseScanHook, InitializationContext, InitializationHook, Plugin, PluginDefinition, PluginRegistry};

/** Passive phase boundaries only. Never inspect or export source names or diagnostics. */
final class BenchPhaseObserver implements Plugin, InitializationHook, CodebaseScanHook, BeforeAnalysisHook, AfterAnalysisHook
{
    public function __construct(private readonly string $slot) {}
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('benchmark/phase-'.$this->slot, 'Phase observer', 'Diagnostic control; no decisions changed.');
    }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerInitializationHook($this);
        $registry->registerCodebaseScanHook($this);
        $registry->registerBeforeAnalysisHook($this);
        $registry->registerAfterAnalysisHook($this);
    }
    private function record(string $phase, array $extra = []): void
    {
        $usage = getrusage();
        $cpu = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
        file_put_contents('/probe/phase-events.jsonl', json_encode([
            'pid' => getmypid(), 'slot' => $this->slot, 'phase' => $phase,
            'monotonic_seconds' => hrtime(true) / 1e9, 'php_cpu_seconds' => $cpu,
            'php_allocator_mib' => memory_get_usage(true) / 1048576,
        ] + $extra)."\n", FILE_APPEND | LOCK_EX);
    }
    public function initialize(InitializationContext $context): void { $this->record('initialize'); }
    public function getTargets(): array { return ['**']; }
    public function scan(CodebaseScanContext $context): void
    {
        $this->record('scan', ['files' => count($context->files), 'first_batch' => $context->firstBatch, 'last_batch' => $context->lastBatch]);
    }
    public function beforeAnalysis(BeforeAnalysisContext $context): void { $this->record('before-analysis'); }
    public function afterAnalysis(AfterAnalysisContext $context): void { $this->record('after-analysis'); }
}

/** Metrics only; keys and payloads never leave this process. */
final class BenchSyntaxCacheProbe
{
    private static array $stats = [];
    private static array $sizes = [];
    public static function lookup(string $kind, string $key, bool $hit, int $entries, int $bytes): void
    {
        self::$stats[$kind] ??= ['calls' => 0, 'hits' => 0, 'misses' => 0, 'evictions' => 0, 'max_entries' => 0, 'max_serialized_bytes' => 0];
        ++self::$stats[$kind]['calls'];
        ++self::$stats[$kind][$hit ? 'hits' : 'misses'];
        self::$stats[$kind]['max_entries'] = max(self::$stats[$kind]['max_entries'], $entries);
        self::$stats[$kind]['max_serialized_bytes'] = max(self::$stats[$kind]['max_serialized_bytes'], $bytes);
    }
    public static function payload(string $kind, string $key, int $size): void
    {
        self::$sizes[$kind][$key] = max(self::$sizes[$kind][$key] ?? 0, $size);
    }
    public static function eviction(string $kind): void { ++self::$stats[$kind]['evictions']; }
    public static function flush(): void
    {
        foreach (self::$stats as $kind => &$stats) {
            $stats['unique_payloads'] = count(self::$sizes[$kind] ?? []);
            $stats['unique_serialized_bytes'] = array_sum(self::$sizes[$kind] ?? []);
        }
        file_put_contents('/probe/cache-events.jsonl', json_encode(['pid' => getmypid(), 'stats' => self::$stats])."\n", FILE_APPEND | LOCK_EX);
    }
}
register_shutdown_function([BenchSyntaxCacheProbe::class, 'flush']);
