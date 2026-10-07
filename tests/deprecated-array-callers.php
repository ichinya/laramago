<?php
declare(strict_types=1);
$package=dirname(__DIR__);$fixture=__DIR__.'/fixtures/deprecated-array-callers';
$workspace=$package.'/var/compatibility-deprecated-array-callers-'.bin2hex(random_bytes(8));mkdir($workspace,recursive:true);
foreach(require $fixture.'/sources.php' as $path=>$bytes){if(!is_dir(dirname($workspace.'/'.$path))){mkdir(dirname($workspace.'/'.$path),recursive:true);}file_put_contents($workspace.'/'.$path,$bytes);}
require $package.'/tests/fixtures/compatibility-bounded-worker-support.php';
$worker=compatibilityProductionWorker($package,$workspace,$fixture,'DEPRECATED_PREP_CLASSES_ONLY','DeprecatedDraftPlugin','DeprecatedMethodCompatibilityPlugin');
$config=array (
  'extends' => '',
  'php-version' => '8.5',
  'threads' => 1,
  'source' => 
  array (
    'paths' => 
    array (
      0 => 'cases.php',
      1 => 'equal-a.php',
      2 => 'equal-b.php',
    ),
    'includes' => 
    array (
      0 => 'declarations.php',
      1 => 'vendor/example',
    ),
  ),
  'extension-hosts' => 
  array (
  ),
);
$run=static function(string $stage,string $mode,string $version='8.5', int $workers=1)use($workspace,$package,$worker,$config):array{$config['php-version']=$version;return compatibilityNativeGate($package,$workspace,$worker,$config,$stage,$mode,$workers);};
$normalize = static function (array $issues): array { $rows = array_map(static fn (array $issue): string => json_encode($issue, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $issues); sort($rows); return $rows; };
$select = static function (array $issues, string $file, int $line, ?string $code = null): array { return array_values(array_filter($issues, static function (array $issue) use ($file, $line, $code): bool {
    foreach ($issue['annotations'] as $annotation) { if ($annotation['kind'] === 'Primary') { return $annotation['span']['file_id']['name'] === $file && $annotation['span']['start']['line'] + 1 === $line && ($code === null || $issue['code'] === $code); } } return false;
})); };
$observer = static function (string $stage) use ($workspace): array { return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/'.$stage.'-observer.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)); };
$native = $run('native', 'observe'); $adapted = $run('draft', 'draft');
$positives = ['cases.php' => [4, 5, 6, 7, 8, 9, 10, 25, 27, 29], 'equal-a.php' => [3], 'equal-b.php' => [3]]; $remaining = $native;
foreach ($positives as $file => $lines) { foreach ($lines as $line) {
    $before = $select($native, $file, $line, 'deprecated-method'); $after = $select($adapted, $file, $line, 'deprecated-method');
    if (count($before) !== 1 || $before[0]['level'] !== 'Warning' || $after !== []) { throw new RuntimeException('No genuine native positive '.$file.':'.$line.'; retained '.$workspace); }
    $target = $before[0]; $remaining = array_values(array_filter($remaining, static fn (array $issue): bool => $issue !== $target));
} }
if ($normalize($remaining) !== $normalize($adapted)) { throw new RuntimeException('Any non-target complete DTO changed; retained '.$workspace); }
foreach ([7, 8, 9, 29] as $line) { if (array_filter($select($native, 'cases.php', $line), static fn (array $issue): bool => $issue['level'] === 'Error') === []) { throw new RuntimeException('Missing independently invalid argument Error at '.$line.'; retained '.$workspace); } }
foreach (['preserve', 'cache-deprecated-flag', 'cache-method-return', 'cache-builtin-since', 'cache-caller-return'] as $mode) {
    if ($normalize($run($mode, $mode)) !== $normalize($native)) { throw new RuntimeException('Opt-in/cache control changed native DTOs; retained '.$workspace); }
    if (str_starts_with($mode, 'cache-')) {
        $controls = array_filter($observer($mode), static fn (array $row): bool => $row['stage'] === 'real-native-cache-control' && $row['role'] === $mode && $row['genuinePositiveBefore'] && $row['slotsChanged'] > 0 && $row['deferredAfter'] && $row['exactProfileRestored']);
        $methods = array_unique(array_column($controls, 'method'));
        if (count($methods) < ($mode === 'cache-builtin-since' ? 1 : 3)) { throw new RuntimeException('No genuine cache mutation for all admitted API roles; retained '.$workspace); }
    }
}
if ($normalize($run('draft-three-workers', 'draft', workers: 3)) !== $normalize($adapted)) { throw new RuntimeException('Worker-count semantic drift; retained '.$workspace); }
$versionNative = $run('version-84-native', 'observe', '8.4'); $versionDraft = $run('version-84-draft', 'draft', '8.4');
foreach ([5, 8, 10, 23] as $line) { if ($select($versionNative, 'cases.php', $line) !== $select($versionDraft, 'cases.php', $line)) { throw new RuntimeException('PHP 8.4 builtin policy failed to defer; retained '.$workspace); } }
foreach (['vendor/example/request.php' => ['string $key', 'string $lookup'], 'vendor/example/coverage.php' => ['$filter): Driver', '$selection): Driver']] as $path => [$before, $after]) {
    $original = file_get_contents($workspace.'/'.$path); $changed = str_replace($before, $after, $original);
    if ($changed === $original) { throw new RuntimeException('Source drift control was a no-op.'); } file_put_contents($workspace.'/'.$path, $changed);
    $tag = str_contains($path, 'request') ? 'request-source-drift' : 'coverage-source-drift'; $changedNative = $run($tag.'-native', 'observe'); $changedDraft = $run($tag.'-draft', 'draft');
    $lines = str_contains($path, 'request') ? [4, 7] : [6, 9];
    foreach ($lines as $line) { if (count($select($changedNative, 'cases.php', $line, 'deprecated-method')) !== 1 || $select($changedNative, 'cases.php', $line) !== $select($changedDraft, 'cases.php', $line)) { throw new RuntimeException('Changed physical source still trusted; retained '.$workspace); } }
    file_put_contents($workspace.'/'.$path, $original);
    if ($normalize($run($tag.'-restored', 'draft')) !== $normalize($adapted)) { throw new RuntimeException('Source restoration changed DTOs; retained '.$workspace); }
}
file_put_contents($workspace.'/manifest.json', json_encode(['status' => 'deprecated-method-draft-gates-passed', 'publicIntegrated' => false, 'workspace' => $workspace, 'positives' => $positives, 'typesChanged' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo 'PASS: exact method advisories only; retained '.$workspace."\n";
