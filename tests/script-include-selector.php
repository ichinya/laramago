<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\ScriptMixedAssignmentFilter;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$method = new ReflectionMethod(ScriptMixedAssignmentFilter::class, 'hasIncludeKeyword');
$checks = 0;
$tokens = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];
$verify = static function (string $source) use ($method, $tokens, &$checks): void {
    $expected = false;
    try {
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], $tokens, true)) { $expected = true; }
        }
    } catch (ParseError) {
        // Malformed lexical input cannot supply the original parsed include certificate.
        return;
    }
    if ($expected && ! $method->invoke(null, $source)) {
        throw new RuntimeException('A genuine tokenizer keyword escaped the selector at control '.$checks);
    }
    ++$checks;
};
foreach (['include', 'include_once', 'require', 'require_once', 'INCLUDE', 'REQUIRE_ONCE', 'InClUdE_OnCe', 'ReQuIrE'] as $keyword) {
    foreach ([0, 9, 10, 13, 32, 34, 36, 40, 47, 48, 59, 65, 95, 127, 128, 255] as $prefix) {
        for ($suffix = 0; $suffix < 256; ++$suffix) {
            $verify('<?php $value = '.chr($prefix).$keyword.chr($suffix).' "not-executed.php";');
            $verify('<?php $value = '.chr($suffix).$keyword.chr($prefix).' "not-executed.php";');
        }
    }
    $source='<?php $value = '.$keyword.'/* boundary */ "not-executed.php";';
    if (! $method->invoke(null, $source)) { throw new RuntimeException('Genuine keyword must remain selected'); }
    ++$checks;
}
foreach (['required', 'requirements', 'included', 'includeFiles', 'require_onceMore', 'include_once_more'] as $word) {
    if ($method->invoke(null, '<?php $rules = ["field" => "'.$word.'"];')) {
        throw new RuntimeException('Identifier tail still triggers an unnecessary parse: '.$word);
    }
    ++$checks;
}
$files = 0;
if (isset($argv[1], $argv[2])) {
    $inputs=json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    foreach ($inputs as $name=>$hash) {
        if (! str_ends_with($name, '.php')) { continue; }
        $source=file_get_contents($argv[1].'/'.$name);
        if (hash('sha256', $source)!==$hash) { throw new RuntimeException('Real tokenizer input changed'); }
        $verify($source);++$files;
    }
}
echo json_encode(['complete'=>true,'tokenizer_controls'=>$checks,'real_php_files'=>$files,'source_executed'=>false], JSON_THROW_ON_ERROR)."\n";
