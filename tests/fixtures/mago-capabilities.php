<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Tests\Support;

/** Probe the selected engine rather than guessing when a native fix was released. */
final class MagoCapabilities
{
    /** @param list<string> $command */
    public static function implicitVariadics(array $command): bool
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago native capabilities '.bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root.'/probe.php', <<<'PHP'
<?php
class NativeVariadicProbe {
    public function variadic(): array { return func_get_args(); }
    public function fixed(): void {}
}
function accepted(): void { (new NativeVariadicProbe)->variadic(1, 2); }
function rejected(): void { (new NativeVariadicProbe)->fixed(1); }
PHP);
        file_put_contents($root.'/mago.json', json_encode([
            'php-version' => '8.2',
            'source' => ['paths' => ['probe.php']],
        ], JSON_THROW_ON_ERROR));
        $process = proc_open([...$command, '--workspace', $root, '--no-extensions', 'analyze', '--reporting-format=json'],
            [0 => ['pipe', 'r'], 1 => ['file', $root.'/report.json', 'w'], 2 => ['file', $root.'/stderr.log', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Cannot probe native variadic behavior.');
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        $report = json_decode(file_get_contents($root.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($exit !== 1 || ! is_array($report['issues'] ?? null)) {
            throw new \RuntimeException('Native capability probe did not complete; inspect '.$root);
        }
        $actual = [];
        foreach ($report['issues'] as $issue) {
            if ($issue['level'] !== 'Error') { continue; }
            $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
            $actual[$primary['span']['start']['line']][] = $issue['code'];
        }
        if (($actual[6] ?? []) !== ['too-many-arguments']) {
            throw new \RuntimeException('Native fixed-arity negative control failed; inspect '.$root);
        }
        unset($actual[6]);
        $variadic = $actual[5] ?? [];
        unset($actual[5]);
        if ($actual !== [] || ! in_array($variadic, [[], ['too-many-arguments']], true)) {
            throw new \RuntimeException('Unexpected native variadic diagnostics; inspect '.$root);
        }

        return $variadic === [];
    }
}
