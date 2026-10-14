<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

/** Configure private PHP diagnostics at startup, before any application PHP executes. */
final class RuntimeCliProcess
{
    public static function isolate(string $entrypoint, array $arguments): void
    {
        if (!(bool) ini_get('display_errors') && !(bool) ini_get('log_errors')) {
            return;
        }
        if (getenv('LARAMAGO_RUNTIME_PRIVATE_PROCESS') === '1') {
            throw new RuntimeException('Private runtime configuration unavailable.');
        }
        $configuration = RuntimeCliConfiguration::open();
        $environment = getenv();
        $environment['LARAMAGO_RUNTIME_PRIVATE_PROCESS'] = '1';
        $scan = getenv('PHP_INI_SCAN_DIR');
        $scan = is_string($scan) ? $scan : PHP_CONFIG_FILE_SCAN_DIR;
        $environment['PHP_INI_SCAN_DIR'] = $scan === ''
            ? $configuration->directory
            : $scan . PATH_SEPARATOR . $configuration->directory;
        try {
            $pipes = [];
            $process = proc_open(
                [...self::command($configuration), $entrypoint, ...$arguments],
                [STDIN, STDOUT, STDERR],
                $pipes,
                env_vars: $environment,
                options: ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Private runtime process unavailable.');
            }
            $exit = proc_close($process);
            if ($exit < 0) {
                throw new RuntimeException('Private runtime process failed.');
            }
        } finally {
            $configuration->close();
        }
        exit($exit);
    }

    private static function command(RuntimeCliConfiguration $configuration): array
    {
        $ini = php_ini_loaded_file();
        $command = [PHP_BINARY, '-c', is_string($ini) ? $ini : $configuration->file()];

        return [...$command, '-d', 'display_errors=0', '-d', 'log_errors=0'];
    }
}
