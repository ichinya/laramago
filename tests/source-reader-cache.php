<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\GuardedStringCastContracts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;

$options = getopt('', ['autoload:']);
require $options['autoload'] ?? dirname(__DIR__).'/vendor/autoload.php';
$directory = sys_get_temp_dir().'/laramago-reader-control-'.bin2hex(random_bytes(8));
mkdir($directory);
$path = $directory.'/source.php';
$original = '<?php namespace ReaderControl; function value(): string { return "old"; }';
$check = static function (bool $condition, string $label): void {
    if (! $condition) { throw new RuntimeException($label); }
    echo 'PASS: '.$label."\n";
};
try {
    CodebaseScanSyntax::clear();
    file_put_contents($path, $original);
    $reader = new GuardedStringCastContracts($directory);
    $first = $reader->read($path, $original);
    $check($first !== null, 'unchanged physical source can be read');
    $first['nodes'][0]->setAttribute('consumerMutation', true);
    $again = $reader->read($path, $original);
    $check($again !== null && $again['nodes'][0]->getAttribute('consumerMutation') === null,
        'consumer AST mutations remain isolated');
    $changed = str_replace('"old"', '"new"', $original);
    file_put_contents($path, $changed);
    $check($reader->read($path, $original) === null, 'same-size disk mutation vetoes the analyzed snapshot');
    $fresh = $reader->read($path, $changed);
    $check($fresh !== null && $fresh['hash'] !== $again['hash']
        && serialize($fresh['nodes']) !== serialize($again['nodes']), 'changed bytes are parsed independently');
    file_put_contents($path, '<?php function broken( {');
    $check($reader->read($path) === null, 'parse failure cannot reuse old syntax');
    file_put_contents($path, '<?php '.str_repeat(' ', 1024 * 1024));
    $check($reader->read($path) === null, 'oversize physical source is rejected');
    $check($reader->read(null) === null && $reader->read(__FILE__) === null, 'null and outside-root paths are rejected');
    file_put_contents($path, $original);
    $check($reader->read($path, $original)['hash'] === $again['hash'], 'restored bytes remain valid');
    unlink($path);
    $check($reader->read($path) === null, 'deleted source cannot reuse a retained tree');
} finally {
    if (is_file($path)) { unlink($path); }
    rmdir($directory);
}
