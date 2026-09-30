<?php

declare(strict_types=1);

trait ClassUsesReturnFixtureFeature {}
final class ClassUsesReturnFixtureSubject { use ClassUsesReturnFixtureFeature; }
function declareClassUsesReturnFixture(): void { class ClassUsesDelayedFixture {} }
$nativeTraits = [ClassUsesReturnFixtureFeature::class => ClassUsesReturnFixtureFeature::class];
if (class_uses(new ClassUsesReturnFixtureSubject(), false) !== $nativeTraits
    || class_uses(ClassUsesReturnFixtureSubject::class) !== $nativeTraits
    || class_uses(new stdClass()) !== []
    || @class_uses('Missing\\ClassUsesReturnFixture', false) !== false
    || @class_uses(ClassUsesDelayedFixture::class) !== false) {
    throw new RuntimeException('Unexpected native class_uses contract.');
}

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago class traits '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Fixtures;
trait Feature {}
class Subject { use Feature; }
class OtherSubject {}
class Incomplete extends MissingParent {}
interface Contract {}
enum Choice { case First; }
function declareDeferred(): void { class DeferredSubject {} }
if (random_int(0, 1) === 1) { class ConditionalSubject {} }
class_alias(Subject::class, 'Fixtures\\AliasedSubject');
function consume(array $traits): void {}
/** @param array<string,trait-string> $traits */ function traitNames(array $traits): void {}
/** @param array<string,int> $values */ function integerValues(array $values): void {}
namespace Custom;
function class_uses(object|string $object_or_class, bool $autoload = true): array|false { return false; }
PHP);
$cases = [
    'known class' => ['function known(): void { consume(class_uses(Subject::class)); }', true],
    'known object' => ['function object(Subject $value): void { consume(class_uses($value)); }', true],
    'any object' => ['function any(object $value): void { consume(class_uses($value)); }', true],
    'object without autoload' => ['function noLoadObject(Subject $value): void { consume(class_uses($value, false)); }', true],
    'object with dynamic autoload' => ['function dynamicObject(object $value, bool $autoload): void { consume(class_uses($value, $autoload)); }', true],
    'named arguments' => ['function named(): void { consume(class_uses(autoload: true, object_or_class: Subject::class)); }', true],
    'union of known classes' => ['function union(bool $condition): void { $class = $condition ? Subject::class : OtherSubject::class; consume(class_uses($class)); }', true],
    'known trait' => ['function traitName(): void { consume(class_uses(Feature::class)); }', true],
    'known interface' => ['function interfaceName(): void { consume(class_uses(Contract::class)); }', true],
    'known enum' => ['function enumName(): void { consume(class_uses(Choice::class)); }', true],
    'native trait values' => ['function values(Subject $subject): void { traitNames(class_uses($subject)); }', true],
    'object and known class union' => ['function objectOrClass(bool $condition): void { $value = $condition ? new Subject() : OtherSubject::class; consume(class_uses($value)); }', true],
    'known class without autoload' => ['function noLoadClass(): void { consume(class_uses(Subject::class, false)); }', false],
    'dynamic autoload' => ['function dynamicClass(bool $autoload): void { consume(class_uses(Subject::class, $autoload)); }', false],
    'arbitrary class name' => ['function unknown(string $name): void { consume(class_uses($name)); }', false],
    'unknown literal' => ['function missing(): void { consume(class_uses("Missing\\Example")); }', false],
    'incomplete class hierarchy' => ['function incomplete(): void { consume(class_uses(Incomplete::class)); }', false],
    'mixed input' => ['function mixedInput(mixed $value): void { consume(class_uses($value)); }', false],
    'nullable object' => ['function nullable(?Subject $value): void { consume(class_uses($value)); }', false],
    'custom function' => ['function custom(): void { consume(\\Custom\\class_uses(Subject::class)); }', false],
    'unpacked arguments' => ['function unpacked(): void { consume(class_uses(...[Subject::class])); }', false],
    'incorrect trait value type' => ['function wrongValues(Subject $subject): void { integerValues(class_uses($subject)); }', false],
    'invalid autoload argument' => ['function wrongAutoload(Subject $subject): void { consume(class_uses($subject, "unknown")); }', false],
    'object and unknown name union' => ['function unsafeUnion(object|string $value): void { consume(class_uses($value)); }', false],
    'function scoped declaration' => ['function delayed(): void { consume(class_uses(DeferredSubject::class)); }', false],
    'conditional declaration' => ['function conditional(): void { consume(class_uses(ConditionalSubject::class)); }', false],
    'existing conditional object' => ['function conditionalObject(ConditionalSubject $value): void { consume(class_uses($value)); }', true],
    'native builtin class' => ['function builtin(): void { consume(class_uses(\\stdClass::class)); }', true],
    'class alias' => ['function alias(): void { consume(class_uses(AliasedSubject::class)); }', false],
];
$source = "<?php\nnamespace Fixtures;\n";
$lines = [];
foreach ($cases as $name => [$code, $positive]) {
    $lines[substr_count($source, "\n")] = $name;
    $source .= $code."\n";
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/class-traits', 'Class traits', 'Native class trait return contracts'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\ClassUsesReturnTypeProvider($this->root);
        $registry->registerFunctionReturnTypeProvider($provider);
        $registry->registerInitializationHook($provider);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/class-traits', 'Class traits', '1', analyzerPlugins: [$plugin])))->run();
PHP);
$modes = ['native', 'isolated'];
if (in_array('--integrated', $argv, true)) {
    $modes[] = 'integrated';
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$reports = [];
foreach ($modes as $mode) {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['types.php']],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['test' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 2,
        ]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true);
    if (! is_array($report) || ! isset($report['issues'])) {
        throw new RuntimeException("Mago $mode failed ($exit): ".file_get_contents($workspace.'/'.$mode.'.log'));
    }
    $reports[$mode] = [];
    foreach ($report['issues'] as $issue) {
        $span = $issue['annotations'][0]['span'] ?? [];
        if ($issue['level'] === 'Error' && ($span['file_id']['name'] ?? '') === 'cases.php') {
            $name = $lines[$span['start']['line'] ?? -1] ?? null;
            if ($name !== null) {
                $reports[$mode][$name][] = $issue['code'];
            }
        }
    }
    foreach ($cases as $name => [$code, $positive]) {
        $errors = $reports[$mode][$name] ?? [];
        if (($positive && $mode !== 'native') ? $errors !== [] : $errors === []) {
            throw new RuntimeException("Unexpected $mode diagnostics for $name: ".json_encode($errors)."; workspace $workspace");
        }
    }
    echo "$mode: ".count($cases)." class trait cases verified\n";
}
foreach ($cases as $name => [$code, $positive]) {
    if ($positive || $name === 'incorrect trait value type') {
        continue;
    }
    $expected = $reports['native'][$name] ?? [];
    sort($expected);
    foreach (array_diff($modes, ['native']) as $mode) {
        $actual = $reports[$mode][$name] ?? [];
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException("Unexpected $mode negative diagnostic delta for $name");
        }
    }
}
foreach (array_diff($modes, ['native']) as $mode) {
    if (($reports[$mode]['incorrect trait value type'] ?? []) !== ['invalid-argument']) {
        throw new RuntimeException("The $mode trait element mismatch must remain explicit.");
    }
}
