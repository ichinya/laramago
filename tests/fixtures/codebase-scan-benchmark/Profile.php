<?php

declare(strict_types=1);

// Loaded only in disposable, explicitly instrumented copies. Never a timing control.
namespace ScanBenchmark;

final class Profile
{
    public static array $counts = [];
    public static array $nanoseconds = [];

    public static function call(string $name, callable $operation): mixed
    {
        $start = hrtime(true);
        self::$counts[$name] = (self::$counts[$name] ?? 0) + 1;
        if ($name === 'parse' && self::$counts[$name] % 64 === 0) { self::event(['kind' => 'parse-progress']); }
        try { return $operation(); }
        finally { self::$nanoseconds[$name] = (self::$nanoseconds[$name] ?? 0) + hrtime(true) - $start; }
    }

    public static function event(array $row): void
    {
        $row['gc'] = gc_status();
        $cache = 'Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\CodebaseScanSyntax';
        if (class_exists($cache, false)) {
            $reflection = new \ReflectionClass($cache);
            foreach (['trees', 'queries', 'projectedSources'] as $name) {
                $row['cache'][$name.'_count'] = count($reflection->getStaticPropertyValue($name));
            }
            foreach (['uncompressedBytes', 'queryBytes'] as $name) {
                $row['cache'][$name] = $reflection->getStaticPropertyValue($name);
            }
            if ($reflection->hasProperty('stats')) { $row['cache']['stats'] = $reflection->getStaticPropertyValue('stats'); }
        }
        $row += ['pid' => getmypid(), 'counts' => self::$counts, 'nanoseconds' => self::$nanoseconds];
        file_put_contents('/output/profile-'.getmypid().'.jsonl', json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }

    public static function serialize(mixed $value): string { return self::call('serialize', static fn() => serialize($value)); }
    public static function unserialize(string $value, array $options): mixed { return self::call('unserialize', static fn() => unserialize($value, $options)); }
    public static function gzdeflate(string $value, int $level): string|false { return self::call('compress', static fn() => gzdeflate($value, $level)); }
    public static function gzinflate(string $value, int $limit): string|false { return self::call('decompress', static fn() => gzinflate($value, $limit)); }
    public static function file_get_contents(string $path): string|false { return self::call('spill_read', static fn() => file_get_contents($path)); }
    public static function file_put_contents(string $path, string $value): int|false { return self::call('spill_write', static fn() => file_put_contents($path, $value)); }
}
