<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;

$workspace = sys_get_temp_dir().'/laramago spill '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
// Serialized trees of this cohort exceed the 64 MiB AST budget at -d memory_limit=512M.
$files = [];
for ($i = 0; $i < 300; $i++) {
    $body = "<?php\nclass Spill{$i} {\n";
    for ($m = 0; $m < 30; $m++) {
        $body .= "    public function m{$m}(\$a, \$b, \$c = 'x{$m}y{$i}') { return \$a + \$b * {$m} + \$this->h{$m}(\$c); }\n";
        $body .= "    private function h{$m}(\$x) { return str_repeat(\$x, {$m}); }\n";
    }
    $files[$workspace.'/f'.$i.'.php'] = $body."}\n";
}

$check = static function (bool $ok, string $name): void {
    if (! $ok) { throw new RuntimeException('Failed '.$name); }
    echo 'PASS: '.$name."\n";
};

CodebaseScanSyntax::clear();
$first = [];
foreach ($files as $path => $contents) {
    $first[$path] = CodebaseScanSyntax::parse($contents);
}
$misses = CodebaseScanSyntax::$stats['miss'];
$check($misses === count($files), 'one parse per unique source');
$check(CodebaseScanSyntax::$stats['evict'] > 0, 'cohort exceeds the tree budget');

$second = CodebaseScanSyntax::parse($files[array_key_first($files)]);
$check(serialize($second) === serialize($first[array_key_first($files)]), 'spilled tree preserves the resolved snapshot');
$check(CodebaseScanSyntax::$stats['miss'] === $misses, 'evicted lookup does not re-parse');
$check(CodebaseScanSyntax::$stats['spill'] === 1, 'evicted lookup serves the disk tier');

$restored = 0;
foreach ($files as $contents) {
    CodebaseScanSyntax::parse($contents);
}
$check(CodebaseScanSyntax::$stats['spill'] === count($files), 'every evicted source restores without parsing');
$check(CodebaseScanSyntax::$stats['miss'] === $misses, 'no additional parses during restore');

CodebaseScanSyntax::clear();
$dir = null;
foreach (glob(sys_get_temp_dir().'/laramago-scan-'.getmypid().'-*') ?: [] as $candidate) {
    if (is_dir($candidate)) { $dir = $candidate; }
}
$check($dir !== null && glob($dir.'/*.tree') !== [], 'spill entries survive cache generation');
CodebaseScanSyntax::parse(reset($files));
$check(CodebaseScanSyntax::$stats['miss'] === 0 && CodebaseScanSyntax::$stats['spill'] === 1, 'a new generation reuses spilled snapshots');

$victimKey = hash('sha256', $files[array_key_first($files)]);
file_put_contents($dir.'/'.$victimKey.'.tree', 'tampered');
CodebaseScanSyntax::clear();
CodebaseScanSyntax::parse($files[array_key_first($files)]);
$check(CodebaseScanSyntax::$stats['miss'] === 1, 'foreign spill bytes are never unserialized');

$changed = reset($files).'// changed';
CodebaseScanSyntax::clear();
CodebaseScanSyntax::parse($changed);
$check(CodebaseScanSyntax::$stats['miss'] === 1, 'changed contents never reuse a spilled snapshot');
