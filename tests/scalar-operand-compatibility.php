<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\CastCompatibilityFilter;
use Ichinya\Laramago\Analyzer\ScalarOperandCompatibilityFilter;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Span;

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago scalar operands '.bin2hex(random_bytes(8));
mkdir($workspace);
$cases = [
    'nullable concatenation' => 'function nullableString(?string $value): string { return "key=".$value; }',
    'false concatenation' => 'function falseString(string|false $value): string { return "key=".$value; }',
    'boolean concatenation' => 'function booleanString(bool $value): string { return "key=".$value; }',
    'nullable arithmetic' => 'function nullableArithmetic(?int $value): int { return $value + 1; }',
    'false arithmetic' => 'function falseArithmetic(int|false $value): int { return $value + 1; }',
    'mixed condition' => 'function condition(mixed $value, mixed $other): bool { return $value || $other; }',
    'mixed comparison' => 'function comparison(mixed $value, mixed $other): bool { return $value != $other; }',
    'mixed spaceship' => 'function spaceship(mixed $value, mixed $other): int { return $value <=> $other; }',
    'mixed arithmetic' => 'function arithmetic(mixed $value): int { return $value + 1; }',
    'mixed concatenation' => 'function concatenation(mixed $value): string { return "key=".$value; }',
    'object concatenation' => 'function objectString(object $value): string { return "key=".$value; }',
    'nullable nonnumeric' => 'function nonnumeric(?string $value): int { return $value + 1; }',
    'object arithmetic' => 'function objectArithmetic(object $value): int { return $value + 1; }',
    'invalid nested argument' => 'function nestedArgument(mixed $value): bool { return strlen($value) > 0; }',
    'array string' => '/** @param array<string, mixed> $value */ function arrayString(array $value): string { return "key=".$value; }',
    'wrong return' => 'function wrongReturn(mixed $value, mixed $other): string { return $value <=> $other; }',
    'parenthesized condition' => 'function groupedCondition(array $data, bool $flag): bool { return $flag || ($data["enabled"] ?? false); }',
    'nested parenthesized condition' => 'function nestedCondition(array $data, bool $flag): bool { return (($data["enabled"] ?? false)) && $flag; }',
    'commented condition' => 'function commentedCondition(mixed $value, bool $flag): bool { return $flag || (/* before */ ($value) /* after */); }',
    'parenthesized comparison' => 'function groupedComparison(array $data): bool { return 1 < ($data["limit"] ?? PHP_INT_MAX); }',
    'parenthesized inequality' => 'function groupedInequality(mixed $value): bool { return (($value)) != 1; }',
    'parenthesized spaceship' => 'function groupedSpaceship(mixed $value): int { return 1 <=> (($value)); }',
    'parenthesized mixed arithmetic' => 'function groupedArithmetic(mixed $value): int { return 1 + (($value)); }',
    'parenthesized mixed concatenation' => 'function groupedConcatenation(mixed $value): string { return "key=".(($value)); }',
    'parenthesized invalid nested argument' => 'function groupedArgument(mixed $value): bool { return false || (strlen($value) > 0); }',
    'parenthesized invalid nested cast' => 'function groupedCast(object $value): bool { return false || ((string) $value); }',
    'parenthesized nested arithmetic' => 'function nestedArithmetic(mixed $value): bool { return false || (($value + 1) > 0); }',
];
file_put_contents($workspace.'/cases.php', "<?php\n".implode("\n", $cases)."\n");
foreach (['disabled', 'enabled'] as $mode) {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
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
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $codes[$annotation['span']['start']['line'] - 1][] = $issue['code'];
                break;
            }
        }
    }
    foreach ([8 => 'mixed-operand', 9 => 'mixed-operand', 10 => 'invalid-operand', 11 => 'invalid-operand', 12 => 'invalid-operand', 13 => 'mixed-argument', 14 => 'array-to-string-conversion', 15 => 'invalid-return-statement', 22 => 'mixed-operand', 23 => 'mixed-operand', 24 => 'mixed-argument', 25 => 'invalid-type-cast', 26 => 'mixed-operand'] as $line => $code) {
        if (! in_array($code, $codes[$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost '.$code.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach ([...range(0, 7), ...range(16, 21)] as $line) {
        $advisories = array_intersect(['possibly-null-operand', 'possibly-false-operand', 'mixed-operand', 'invalid-operand'], $codes[$line] ?? []);
        if (($advisories === []) !== ($mode === 'enabled')) {
            throw new RuntimeException($mode.' wrong scalar operand policy: '.json_encode($codes).' '.$workspace);
        }
    }
    echo 'PASS: scalar operand compatibility '.$mode.' ('.count($cases).' cases)' . "\n";
}

require_once $package.'/vendor/autoload.php';
$cancel = new class implements CancellationTokenInterface {
    public function isCancelled(): bool { return false; }
    public function throwIfCancelled(): void {}
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$codebase = (new ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
$types = (new ReflectionClass(TypeComparator::class))->newInstanceWithoutConstructor();
$scalarFilter = new ScalarOperandCompatibilityFilter;
$castFilter = new CastCompatibilityFilter;
$checkSpan = static function (
    string $contents,
    string $needle,
    bool $cast,
    IssueFilterDecision $expected,
    string $code = 'mixed-operand',
    ?string $message = null,
    ?string $file = null,
    bool $duplicate = false,
) use ($cancel, $codebase, $types, $scalarFilter, $castFilter): void {
    $start = strpos($contents, $needle);
    if ($start === false) {
        throw new RuntimeException('Missing direct span fixture.');
    }
    $annotation = new Annotation(AnnotationKind::Primary, new Span($start, $start + strlen($needle)), file: $file);
    $issue = new ReportedIssue(
        Level::Warning,
        $code,
        $message ?? ($cast ? 'Casting `mixed` to `bool`.' : 'Right operand in `||` operation has `mixed` type.'),
        [], null, null, $duplicate ? [$annotation, $annotation] : [$annotation], [],
    );
    $context = new IssueFilterContext(
        PHPVersion::fromParts(8, 2), $codebase, $types, $cancel, 'unsaved.php', $contents, $issue,
    );
    if (($cast ? $castFilter : $scalarFilter)->filterIssue($context) !== $expected) {
        throw new RuntimeException('Unexpected span policy for '.$needle.' in '.$contents);
    }
};
$boolean = '<?php $flag || (/* before */ ($item["ready"] ?? false) /* after */);';
$checkSpan($boolean, '(/* before */ ($item["ready"] ?? false) /* after */)', false, IssueFilterDecision::Remove);
$checkSpan($boolean, '($item["ready"] ?? false)', false, IssueFilterDecision::Remove);
$checkSpan($boolean, '$item["ready"] ?? false', false, IssueFilterDecision::Remove);
$checkSpan($boolean, '($item["ready"] ?? false', false, IssueFilterDecision::Keep);
$checkSpan($boolean, '$item["ready"]', false, IssueFilterDecision::Keep);
$checkSpan($boolean, '$flag || (/* before */ ($item["ready"] ?? false) /* after */)', false, IssueFilterDecision::Keep);
$checkSpan($boolean, '($item["ready"] ?? false)', false, IssueFilterDecision::Keep, file: 'other.php');
$checkSpan($boolean, '($item["ready"] ?? false)', false, IssueFilterDecision::Keep, duplicate: true);
$checkSpan($boolean, '($item["ready"] ?? false)', false, IssueFilterDecision::Keep, code: 'mixed-array-access');
$checkSpan($boolean, '($item["ready"] ?? false)', false, IssueFilterDecision::Keep, message: 'Unknown operand.');
$checkSpan('<?php $flag || ($item);', '($item)', false, IssueFilterDecision::Remove);
$checkSpan('<?php $flag + ($item);', '($item)', false, IssueFilterDecision::Keep);
$checkSpan('<?php $flag || ($item);', '($item)', false, IssueFilterDecision::Remove);
$checkSpan('<?php (', '(', false, IssueFilterDecision::Keep);
$checkSpan('<?php consume((bool) (/* before */ ($item) /* after */));', '(/* before */ ($item) /* after */)', true, IssueFilterDecision::Remove);
$checkSpan('<?php consume((bool) ($item));', '((bool) ($item))', true, IssueFilterDecision::Keep);
$checkSpan('<?php consume($flag || ($item));', '($flag || ($item))', false, IssueFilterDecision::Keep);
$checkSpan('<?php (bool) ($item + 1);', '$item', true, IssueFilterDecision::Keep);
$checkSpan('<?php (bool) ((string) $item);', '((string) $item)', true, IssueFilterDecision::Keep, code: 'invalid-type-cast', message: 'Cannot reliably cast generic `object` to `string`.');
echo 'PASS: exact grouping spans, cache identity, and nested diagnostic boundaries'.PHP_EOL;
