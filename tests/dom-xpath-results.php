<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago xpath '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{}');
$source = <<<'PHP'
    <?php
    function acceptNode(DOMNode $node): void {}
    function acceptString(string $value): void {}
    function guarded(DOMXPath $xpath): void { $nodes = $xpath->query('//title'); if ($nodes !== false) { foreach ($nodes as $node) { acceptNode($node); } } }
    function wrongValue(DOMXPath $xpath): void { $nodes = $xpath->query('//title'); if ($nodes !== false) { foreach ($nodes as $node) { acceptString($node); } } }
    /** @return DOMNodeList<DOMNode> */
    function falseStillPossible(DOMXPath $xpath): DOMNodeList { return $xpath->query('['); }
    function namespaceNodes(DOMXPath $xpath): void { $nodes = $xpath->query('/root/namespace::*'); if ($nodes !== false) { foreach ($nodes as $node) { acceptNode($node); } } }
    function spacedNamespaceNodes(DOMXPath $xpath): void { $nodes = $xpath->query('/root/namespace :: *'); if ($nodes !== false) { foreach ($nodes as $node) { acceptNode($node); } } }
    function dynamicNodes(DOMXPath $xpath, string $expression): void { $nodes = $xpath->query($expression); if ($nodes !== false) { foreach ($nodes as $node) { acceptNode($node); } } }
    function callableNodes(DOMXPath $xpath): void { $nodes = $xpath->query('php:function("customNodes")'); if ($nodes !== false) { foreach ($nodes as $node) { acceptNode($node); } } }
    PHP;
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2', 'source' => ['paths' => ['cases.php']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 1]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']], $pipes);
if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
fclose($pipes[0]); $exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) { throw new RuntimeException('Mago failed: '.$workspace.' '.$log); }
$actual = [];
foreach (json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] ?? [] as $issue) {
    if ($issue['level'] !== 'Error') { continue; }
    $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
$expected = [4 => [], 5 => ['invalid-argument'], 7 => ['falsable-return-statement', 'invalid-return-statement'],
    8 => ['invalid-iterator', 'mixed-argument'], 9 => ['invalid-iterator', 'mixed-argument'],
    10 => ['invalid-iterator', 'mixed-argument'], 11 => ['invalid-iterator', 'mixed-argument']];
foreach ($expected as $line => $codes) {
    $got = $actual[$line] ?? []; sort($got); sort($codes);
    if ($got !== $codes) { throw new RuntimeException('line '.$line.': expected '.json_encode($codes).', got '.json_encode($got).'; inspect '.$workspace); }
    unset($actual[$line]); echo 'PASS: XPath result line '.$line."\n";
}
if ($actual !== []) { throw new RuntimeException('Unexpected diagnostics: '.$workspace); }
$document = new DOMDocument();
if (! $document->loadXML('<root xmlns:x="urn:test"/>')) { throw new RuntimeException('Cannot load namespace probe.'); }
$namespaceNodes = (new DOMXPath($document))->query('/root/namespace::*');
if ($namespaceNodes === false || $namespaceNodes->length < 1) { throw new RuntimeException('Namespace probe produced no nodes.'); }
foreach ($namespaceNodes as $node) {
    if (! $node instanceof DOMNameSpaceNode || $node instanceof DOMNode) { throw new RuntimeException('Unexpected namespace node hierarchy.'); }
}
echo "PASS: native PHP namespace nodes are DOMNameSpaceNode and not DOMNode\n";
