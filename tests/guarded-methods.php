<?php
declare(strict_types=1);
$package=dirname(__DIR__);$fixture=__DIR__.'/fixtures/guarded-methods';
$workspace=$package.'/var/compatibility-guarded-methods-'.bin2hex(random_bytes(8));mkdir($workspace,recursive:true);
foreach(require $fixture.'/sources.php' as $path=>$bytes){if(!is_dir(dirname($workspace.'/'.$path))){mkdir(dirname($workspace.'/'.$path),recursive:true);}file_put_contents($workspace.'/'.$path,$bytes);}
require __DIR__.'/fixtures/compatibility-gate-support.php';
$worker=compatibilityProductionWorker($package,$workspace,$fixture,'GUARDED_PREP_CLASSES_ONLY','GuardedDraftPlugin','GuardedMethodCompatibilityPlugin');
$config=array (
  'extends' => '',
  'php-version' => '8.2',
  'threads' => 1,
  'source' => 
  array (
    'paths' => 
    array (
      0 => 'getter-cases.php',
      1 => 'closure-cases.php',
    ),
    'includes' => 
    array (
      0 => 'contracts.php',
    ),
  ),
  'extension-hosts' => 
  array (
  ),
);
$run=static function(string $stage,string $mode,int $workers=1)use($workspace,$package,$worker,$config):array{return compatibilityNativeGate($package,$workspace,$worker,$config,$stage,$mode,$workers);};
$rows = static function (array $issues): array { $rows = []; foreach ($issues as $issue) { $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0]; $file = basename(str_replace('\\', '/', $primary['span']['file_id']['name'])); $rows[$file][$primary['span']['start']['line'] + 1][] = $issue; } return $rows; };
$native = $run('native', 'observe'); $adapted = $run('draft', 'draft'); $n = $rows($native); $a = $rows($adapted);
$targets = ['getter-cases.php' => [3, 4], 'closure-cases.php' => [4, 5]];
foreach ($targets as $file => $lines) { foreach ($lines as $line) {
    $before = $n[$file][$line] ?? []; $after = $a[$file][$line] ?? [];
    if (! in_array('non-existent-method', array_column($before, 'code'), true) || $after !== []) { throw new RuntimeException('Missing genuine positive '.$file.':'.$line.'; inspect '.$workspace); }
    unset($n[$file][$line], $a[$file][$line]);
} }
// A refined receiver must expose its real signature without hiding new errors.
foreach ([10 => 'non-existent-method', 11 => 'invalid-argument'] as $line => $code) {
    $before = $n['getter-cases.php'][$line] ?? []; $after = $a['getter-cases.php'][$line] ?? [];
    if (! in_array('non-existent-method', array_column($before, 'code'), true)
        || count($after) !== 1 || $after[0]['code'] !== $code || $after[0]['level'] !== 'Error'
        || ! str_contains($after[0]['message'], $line === 10 ? 'Specialized' : 'int')) {
        throw new RuntimeException('Concrete receiver error was lost at getter-cases.php:'.$line.'; inspect '.$workspace);
    }
    unset($n['getter-cases.php'][$line], $a['getter-cases.php'][$line]);
}
if ($n !== $a) { throw new RuntimeException('Negative or residual full issue DTO changed; inspect '.$workspace); }
foreach (['cache-getter-return', 'cache-owner-final'] as $mode) {
    $control = $run($mode, $mode); if ($control !== $native) { throw new RuntimeException('Real cache control did not preserve exact native DTOs; inspect '.$workspace); }
    $observer = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/'.$mode.'-observer.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    if (array_filter($observer, static fn (array $row): bool => $row['stage'] === 'real-native-cache-control' && $row['genuinePositiveBefore'] && $row['slotsChanged'] > 0 && $row['deferredAfter']) === []) { throw new RuntimeException('No genuine native cache mutation witness; inspect '.$workspace); }
}
if($run('draft-three-workers','draft',3)!==$adapted){throw new RuntimeException('One/three worker semantic drift; retained '.$workspace);}
$original = file_get_contents($workspace.'/contracts.php');
$changed = str_replace('public function booting($callback): void { $this->callbacks[] = $callback; }', 'public function booting($callback): void { $callback->bindTo($this); }', $original);
if ($changed === $original) { throw new RuntimeException('Callback mutation did not change source.'); }
file_put_contents($workspace.'/contracts.php', $changed);
$changedNative = $rows($run('changed-native', 'observe')); $changedDraft = $rows($run('changed-draft', 'draft'));
if (($changedNative['closure-cases.php'] ?? []) !== ($changedDraft['closure-cases.php'] ?? [])) { throw new RuntimeException('Rebinding transport still accepted; inspect '.$workspace); }
file_put_contents($workspace.'/contracts.php', $original); $restored = $run('restored', 'draft');
if ($restored !== $adapted) { throw new RuntimeException('Restoration did not restore complete DTO results; inspect '.$workspace); }
file_put_contents($workspace.'/manifest.json', json_encode(['status' => 'draft-gates-passed', 'publicIntegrated' => false, 'workspace' => $workspace, 'targets' => $targets], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo 'PASS: guarded method draft; outputs retained at '.$workspace."\n";
