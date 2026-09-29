<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago simplexml '.bin2hex(random_bytes(8));
mkdir($workspace);
$fixture = <<<'PHP'
<?php
class ChildXml extends SimpleXMLElement { public int $fixed = 4; }
/** @property-read string $documented */
class DocumentedXml extends SimpleXMLElement {}
class CustomXml extends SimpleXMLElement { public function __get(string $name): int { return 42; } }
class Unrelated {}
function child(SimpleXMLElement $xml): ?SimpleXMLElement { return $xml->project; }
function absent(SimpleXMLElement $xml): ?SimpleXMLElement { return $xml->missing; }
function nested(SimpleXMLElement $xml): ?SimpleXMLElement { return $xml->project->metrics; }
function countChild(SimpleXMLElement $xml): int { return count($xml->project); }
function attribute(SimpleXMLElement $xml): string { return (string) $xml->project['metric']; }
function unsafeCount(SimpleXMLElement $xml): int { return count($xml->missing->nested); }
function deepChain(SimpleXMLElement $xml): ?SimpleXMLElement { return $xml->missing->nested->leaf; }
function guardedCount(SimpleXMLElement $xml): int { $child = $xml->missing->nested; return $child === null ? 0 : count($child); }
function derived(ChildXml $xml): ?ChildXml { return $xml->project; }
function fixed(ChildXml $xml): int { return $xml->fixed; }
function documented(DocumentedXml $xml): string { return $xml->documented; }
function custom(CustomXml $xml): int { return $xml->anything; }
function unrelated(Unrelated $value): void { $value->missing; }
function wrongReturn(SimpleXMLElement $xml): string { return $xml->project; }
PHP;
file_put_contents($workspace.'/cases.php', $fixture."\n");

$lineOf = static function (string $needle) use ($fixture): int {
    foreach (explode("\n", $fixture) as $index => $line) {
        if (str_starts_with($line, 'function '.$needle.'(')) {
            return $index;
        }
    }
    throw new RuntimeException('Missing fixture: '.$needle);
};

foreach (['disabled', 'enabled'] as $mode) {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.5',
        'source' => ['paths' => ['cases.php']],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
        2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $codes = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] !== 'Primary' || $annotation['span']['file_id']['name'] !== 'cases.php') {
                continue;
            }
            $codes[$annotation['span']['start']['line']][] = $issue['code'];
            break;
        }
    }
    foreach (['child', 'absent', 'nested', 'countChild', 'attribute', 'unsafeCount', 'deepChain', 'guardedCount'] as $case) {
        $line = $lineOf($case);
        $hasUnknown = array_intersect(['non-documented-property', 'non-existent-property'], $codes[$line] ?? []) !== [];
        if ($hasUnknown !== ($mode === 'disabled')) {
            throw new RuntimeException($mode.' wrong SimpleXML child '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach (['nested', 'unsafeCount', 'deepChain'] as $case) {
        $line = $lineOf($case);
        $warning = 'possibly-null-property-access';
        if ($mode === 'enabled' && ! in_array($warning, $codes[$line] ?? [], true)) {
            throw new RuntimeException('Lost nullable chain warning in '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    if ($mode === 'enabled') {
        $unsafe = $codes[$lineOf('unsafeCount')] ?? [];
        $guarded = $codes[$lineOf('guardedCount')] ?? [];
        if (! in_array('possibly-null-argument', $unsafe, true)
            || in_array('possibly-null-argument', $guarded, true)) {
            throw new RuntimeException('Unsafe count or guarded count changed: '.json_encode($codes).' '.$workspace);
        }
    }
    foreach (['nested', 'countChild', 'attribute'] as $case) {
        $line = $lineOf($case);
        $mixedCode = match ($case) {
            'nested' => 'mixed-property-access',
            'countChild' => 'mixed-argument',
            'attribute' => 'mixed-array-access',
        };
        $hasMixed = in_array($mixedCode, $codes[$line] ?? [], true);
        if ($hasMixed !== ($mode === 'disabled')) {
            throw new RuntimeException($mode.' wrong '.$mixedCode.' in '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    $derived = $lineOf('derived');
    if (! in_array('non-existent-property', $codes[$derived] ?? [], true)) {
        throw new RuntimeException($mode.' changed unsupported subclass: '.json_encode($codes).' '.$workspace);
    }
    foreach (['fixed', 'documented'] as $case) {
        $line = $lineOf($case);
        if (in_array('non-documented-property', $codes[$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost explicit contract '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    $custom = $lineOf('custom');
    if (! in_array('non-documented-property', $codes[$custom] ?? [], true)) {
        throw new RuntimeException($mode.' changed custom magic lookup: '.json_encode($codes).' '.$workspace);
    }
    foreach (['unrelated', 'wrongReturn'] as $case) {
        $line = $lineOf($case);
        $required = $case === 'unrelated' ? 'non-existent-property' : ($mode === 'disabled' ? 'mixed-return-statement' : 'invalid-return-statement');
        if (! in_array($required, $codes[$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost '.$required.' for '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    if ($mode === 'enabled' && ! in_array('nullable-return-statement', $codes[$lineOf('wrongReturn')] ?? [], true)) {
        throw new RuntimeException('Lost nullable return warning: '.json_encode($codes).' '.$workspace);
    }
    echo 'PASS: SimpleXML properties '.$mode."\n";
}
