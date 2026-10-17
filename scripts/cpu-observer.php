<?php

declare(strict_types=1);

/** Metrics contain public callback names only; never arguments or diagnostics. */
final class BenchCpu
{
    private static array $rows = [];
    private static array $active = [];
    private static array $activeNames = [];
    private static array $sections = [];
    private static int $next = 0;
    private static float $lastFlush = 0;
    private static array $cache = ['calls' => 0, 'hits' => 0, 'misses' => 0, 'evictions' => 0, 'peak_encoded_bytes' => 0, 'peak_entries' => 0];
    private static array $payloadSizes = [];

    public static function cacheLookup(bool $hit): void
    {
        ++self::$cache['calls'];
        ++self::$cache[$hit ? 'hits' : 'misses'];
    }

    public static function cacheEvict(): void { ++self::$cache['evictions']; }
    public static function cachePayload(string $key, int $bytes): void { self::$payloadSizes[$key] = $bytes; }
    public static function cacheSize(int $bytes, int $entries): void
    {
        self::$cache['peak_encoded_bytes'] = max(self::$cache['peak_encoded_bytes'], $bytes);
        self::$cache['peak_entries'] = max(self::$cache['peak_entries'], $entries);
    }

    private static function cpu(): float
    {
        $u = getrusage();
        return $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6
            + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
    }

    public static function startSection(): array
    {
        $caller = count(self::$activeNames) === 1 ? reset(self::$activeNames) : 'outside-or-overlapping-callbacks';
        return [self::cpu(), hrtime(true), $caller];
    }

    public static function endSection(string $section, array $start): void
    {
        $cpu = self::cpu() - $start[0];
        $wall = (hrtime(true) - $start[1]) / 1e9;
        $key = $section.' / '.$start[2];
        self::$sections[$key] ??= ['calls' => 0, 'cpu_seconds' => 0, 'wall_seconds' => 0];
        ++self::$sections[$key]['calls'];
        self::$sections[$key]['cpu_seconds'] += $cpu;
        self::$sections[$key]['wall_seconds'] += $wall;
    }

    public static function invoke(object $callback, string $method, callable $call): mixed
    {
        $class = $callback::class;
        if (!str_starts_with($class, 'Ichinya\\Laramago\\') || str_contains($class, '@anonymous')) {
            $class = 'other-callback';
        }
        $key = $class.'::'.$method;
        $id = ++self::$next;
        $cpu = self::cpu();
        $wall = hrtime(true);
        // Process CPU intervals can overlap when a hook suspends for metadata RPC.
        // Keep inclusive measurements, and separately retain only isolated calls.
        $overlap = self::$active !== [];
        foreach (self::$active as &$entry) { $entry = true; }
        unset($entry);
        self::$active[$id] = $overlap;
        self::$activeNames[$id] = $key;
        try {
            return $call();
        } finally {
            $elapsedCpu = self::cpu() - $cpu;
            $elapsedWall = (hrtime(true) - $wall) / 1e9;
            $overlap = self::$active[$id];
            unset(self::$active[$id]);
            unset(self::$activeNames[$id]);
            self::$rows[$key] ??= ['calls' => 0, 'cpu_inclusive' => 0, 'wall_inclusive' => 0,
                'isolated_calls' => 0, 'cpu_isolated' => 0, 'wall_isolated' => 0];
            $row = &self::$rows[$key];
            ++$row['calls'];
            $row['cpu_inclusive'] += $elapsedCpu;
            $row['wall_inclusive'] += $elapsedWall;
            if (!$overlap) {
                ++$row['isolated_calls'];
                $row['cpu_isolated'] += $elapsedCpu;
                $row['wall_isolated'] += $elapsedWall;
            }
            if ($method === 'afterAnalysis' || microtime(true) - self::$lastFlush >= 2) { self::flush(); }
        }
    }

    public static function flush(bool $terminal = false): void
    {
        self::$lastFlush = microtime(true);
        $path = '/metrics/cpu-'.getmypid().'.json';
        $body = ['pid' => getmypid(), 'terminal' => $terminal, 'process_cpu' => self::cpu(),
            'active_calls' => count(self::$active), 'completed_calls' => array_sum(array_column(self::$rows, 'calls')),
            'recorded_at_utc' => gmdate(DATE_ATOM), 'gc' => gc_status(),
            'syntax_cache' => self::$cache + ['unique_payloads' => count(self::$payloadSizes),
                'unique_uncompressed_bytes' => array_sum(self::$payloadSizes)],
            'sections' => self::$sections, 'rows' => self::$rows];
        file_put_contents($path.'.tmp', json_encode($body, JSON_THROW_ON_ERROR));
        rename($path.'.tmp', $path);
    }
}
BenchCpu::flush();
register_shutdown_function(static fn () => BenchCpu::flush(true));
