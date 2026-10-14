<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;
use Throwable;

/** Preserve effective PHP options without putting configuration values in process arguments. */
final class RuntimeCliConfiguration
{
    private function __construct(
        public readonly string $directory,
    ) {}

    public static function open(): self
    {
        $directory = sys_get_temp_dir() . '/laramago-runtime-ini-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, permissions: 0o700)) {
            throw new RuntimeException('Private runtime configuration unavailable.');
        }
        $configuration = new self($directory);
        try {
            RuntimeCliPermissions::restrict($directory);
            $contents = self::contents();
            $stream = fopen($configuration->file(), mode: 'x');
            if (!is_resource($stream)) {
                throw new RuntimeException('Private runtime configuration unavailable.');
            }
            try {
                if (fwrite($stream, $contents) !== strlen($contents)) {
                    throw new RuntimeException('Private runtime configuration unavailable.');
                }
            } finally {
                fclose($stream);
            }
        } catch (Throwable $error) {
            $configuration->close();
            throw $error;
        }

        return $configuration;
    }

    public function close(): void
    {
        $path = $this->file();
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function file(): string
    {
        return $this->directory . '/runtime.ini';
    }

    private static function contents(): string
    {
        $settings = ini_get_all(details: false);
        if (!is_array($settings)) {
            throw new RuntimeException('Private runtime configuration unavailable.');
        }
        $settings['display_errors'] = '0';
        $settings['log_errors'] = '0';
        $contents = '';
        foreach ($settings as $key => $value) {
            $contents .=
                $key . '="' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value) . '"' . "\n";
        }

        return $contents;
    }
}
