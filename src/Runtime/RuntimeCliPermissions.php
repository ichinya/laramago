<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

/** Windows ignores mkdir's permission bits; restrict the new directory before writing PHP options. */
final class RuntimeCliPermissions
{
    public static function restrict(string $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        $system = getenv('SystemRoot') . '/System32/';
        $identity = self::run([$system . 'whoami.exe', '/user', '/fo', 'csv', '/nh']);
        $fields = str_getcsv(trim($identity), escape: '');
        $sid = $fields[1] ?? '';
        if (preg_match('/\AS-\d+(?:-\d+)+\z/', $sid) !== 1) {
            throw new RuntimeException('Private runtime identity unavailable.');
        }
        self::run([$system . 'icacls.exe', $directory, '/inheritance:r', '/grant:r', '*' . $sid . ':(OI)(CI)F']);
    }

    private static function run(array $command): string
    {
        $pipes = [];
        $process = proc_open($command, [['file', 'NUL', 'r'], ['pipe', 'w'], ['file', 'NUL', 'w']], $pipes, options: [
            'bypass_shell' => true,
        ]);
        if (!is_resource($process)) {
            throw new RuntimeException('Private runtime permissions unavailable.');
        }
        try {
            $output = stream_get_contents($pipes[1], length: 4096);
        } finally {
            fclose($pipes[1]);
            $exit = proc_close($process);
        }
        if ($exit !== 0 || !is_string($output)) {
            throw new RuntimeException('Private runtime permissions unavailable.');
        }

        return $output;
    }
}
