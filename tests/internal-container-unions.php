<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago container union '.bin2hex(random_bytes(8));
mkdir($workspace.'/vendor/internal/container/src', recursive: true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
$contract = <<<'PHP'
<?php
namespace Internal\Container;
interface Container {
    /**
     * @template T of object
     * @param class-string<T> $id
     * @param array<string,mixed> $arguments
     * @return T
     */
    public function get(string $id, array $arguments = []): object;
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string,mixed> $arguments
     * @return T
     */
    public function make(string $class, array $arguments = []): object;
}
PHP;
file_put_contents($workspace.'/vendor/internal/container/src/Container.php', $contract);
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Fixtures;
class First {} class Second {} class Third {}
class MissingHierarchy extends MissingParent {}
trait Feature {}
interface Service {}
enum Choice { case One; }
interface NarrowContainer extends \Internal\Container\Container {
    /**
     * @template T of First
     * @param class-string<T> $id
     * @param array<string,mixed> $arguments
     * @return T
     */
    public function get(string $id, array $arguments = []): object;
}
interface UntypedContainer {
    public function get(string $id, array $arguments = []): object;
}
PHP);
$cases = [
    'literal class union' => ['function union(Container $c, bool $pick): First|Second { return $c->get($pick ? First::class : Second::class); }', true],
    'variable class union' => ['function variable(Container $c, bool $pick): First|Second { $class = $pick ? First::class : Second::class; return $c->get($class); }', true],
    'guarded names with unavailable class' => ['function guarded(Container $c): void { foreach (["First", "OptionalService"] as $name) { $class = "Fixtures\\\\".$name; if (! class_exists($class)) { continue; } $service = $c->get($class); } }', true],
    'three class union' => ['function triple(Container $c, int $pick): First|Second|Third { $class = match ($pick) { 1 => First::class, 2 => Second::class, default => Third::class }; return $c->get($class); }', true],
    'make class union' => ['function make(Container $c, bool $pick): First|Second { return $c->make($pick ? First::class : Second::class); }', true],
    'named reversed arguments' => ['function named(Container $c, bool $pick): First|Second { return $c->get(arguments: ["service" => 1], id: $pick ? First::class : Second::class); }', true],
    'named make argument' => ['function namedMake(Container $c, bool $pick): First|Second { return $c->make(class: $pick ? First::class : Second::class); }', true],
    'single class' => ['function single(Container $c): First { return $c->get(First::class); }', true],
    'literal class name' => ['function literal(Container $c): First { return $c->get("Fixtures\\\\First"); }', true],
    'interface class' => ['function contract(Container $c): Service { return $c->get(Service::class); }', true],
    'enum class' => ['function enumClass(Container $c): Choice { return $c->get(Choice::class); }', true],
    'dynamic class string' => ["/** @param class-string<First> \$class */\nfunction bounded(Container \$c, string \$class): First { return \$c->get(\$class); }", true],
    'generic class string' => ["/**\n * @template T of object\n * @param class-string<T> \$class\n * @return T\n */\nfunction generic(Container \$c, string \$class): object { return \$c->get(\$class); }", true],
    'bounded generic class string' => ["/**\n * @template T of First\n * @param class-string<T> \$class\n * @return T\n */\nfunction genericBound(Container \$c, string \$class): First { return \$c->make(\$class); }", true],
    'bounded class string union' => ["/** @param class-string<First>|class-string<Second> \$class */\nfunction boundedUnion(Container \$c, string \$class): First|Second { return \$c->get(\$class); }", true],
    'unbounded class string' => ["/** @param class-string \$class */\nfunction any(Container \$c, string \$class): object { return \$c->get(\$class); }", true],
    'unpacked inference boundary' => ['function unpacked(Container $c): First { return $c->get(...[First::class]); }', false],
    'narrow return' => ['function narrow(Container $c, bool $pick): First { return $c->get($pick ? First::class : Second::class); }', false],
    'mixed input' => ['function mixedInput(Container $c, mixed $class): object { return $c->get($class); }', false],
    'nullable input' => ['function nullable(Container $c, bool $pick): object { return $c->get($pick ? First::class : null); }', false],
    'arbitrary string' => ['function arbitrary(Container $c, string $class): object { return $c->get($class); }', false],
    'unknown class name' => ['function unknown(Container $c): First { return $c->get("Missing\\\\Subject"); }', false],
    'integer input' => ['function integer(Container $c): object { return $c->get(123); }', false],
    'wrong second argument' => ['function second(Container $c, bool $pick): object { return $c->get($pick ? First::class : Second::class, "invalid"); }', false],
    'wrong second argument keys' => ['function secondKeys(Container $c): object { return $c->get(First::class, [12]); }', false],
    'missing argument' => ['function missing(Container $c): object { return $c->get(); }', false],
    'extra argument' => ['function extra(Container $c): object { return $c->get(First::class, [], 1); }', false],
    'unknown named argument' => ['function badNamed(Container $c): object { return $c->get(name: First::class); }', false],
    'constrained override' => ['function overridden(NarrowContainer $c, bool $pick): object { return $c->get($pick ? First::class : Second::class); }', false],
    'unrelated contract' => ['function unrelated(UntypedContainer $c): First { return $c->get(First::class); }', false],
    'union result method' => ['function badMethod(Container $c, bool $pick): void { $c->get($pick ? First::class : Second::class)->missing(); }', false],
    'unknown class reference' => ['function absent(Container $c): object { return $c->get(Absent::class); }', false],
    'trait class reference' => ['function traitClass(Container $c): First { return $c->get(Feature::class); }', false],
    'incomplete class hierarchy' => ['function incomplete(Container $c): First { return $c->get(MissingHierarchy::class); }', false],
    'guarded optional union cannot narrow' => ['function guardedNarrow(Container $c): First { foreach (["First", "OptionalService"] as $name) { $class = "Fixtures\\\\".$name; if (class_exists($class)) { return $c->get($class); } } throw new \\LogicException; }', false],
];
$source = "<?php\nnamespace Fixtures;\nuse Internal\\Container\\Container;\n";
$lines = [];
foreach ($cases as $name => [$code, $positive]) {
    $start = substr_count($source, "\n");
    $source .= $code."\n";
    for ($line = $start; $line < substr_count($source, "\n"); ++$line) {
        $lines[$line] = $name;
    }
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/container-union', 'Container union', 'Generic container class string contracts'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $registry->registerMethodReturnTypeProvider(new \Ichinya\Laramago\Analyzer\InternalContainerProvider);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/container-union', 'Container union', '1', analyzerPlugins: [$plugin])))->run();
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
        'source' => ['paths' => ['cases.php'], 'includes' => ['types.php', 'vendor/internal/container/src/Container.php']],
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
        foreach ($issue['annotations'] as $annotation) {
            $span = $annotation['span'] ?? [];
            if ($issue['level'] === 'Error' && $annotation['kind'] === 'Primary' && ($span['file_id']['name'] ?? '') === 'cases.php') {
                $name = $lines[$span['start']['line'] ?? -1] ?? null;
                if ($name !== null) {
                    $reports[$mode][$name][] = $issue['code'];
                }
                break;
            }
        }
    }
    foreach ($cases as $name => [$code, $positive]) {
        $errors = $reports[$mode][$name] ?? [];
        if (($positive && $mode !== 'native' && $errors !== []) || (! $positive && $errors === [])) {
            throw new RuntimeException("Unexpected $mode diagnostics for $name: ".json_encode($errors)."; workspace $workspace");
        }
    }
    echo "$mode: ".count($cases)." container class string cases verified\n";
}
if (($reports['native']['guarded names with unavailable class'] ?? []) !== ['possibly-invalid-argument']) {
    throw new RuntimeException('The native fixture must reproduce first-member generic inference.');
}
foreach (array_diff($modes, ['native']) as $mode) {
    if (($reports[$mode]['narrow return'] ?? []) !== ['invalid-return-statement']) {
        throw new RuntimeException("The $mode union must preserve both object alternatives.");
    }
    foreach ($cases as $name => [$code, $positive]) {
        if ($positive || in_array($name, ['guarded optional union cannot narrow', 'trait class reference', 'incomplete class hierarchy'], true)) {
            continue;
        }
        $expected = $reports['native'][$name] ?? [];
        $actual = $reports[$mode][$name] ?? [];
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException("Unexpected $mode negative diagnostic delta for $name");
        }
    }
    foreach (['guarded optional union cannot narrow', 'trait class reference', 'incomplete class hierarchy'] as $name) {
        if (($reports[$mode][$name] ?? []) !== ['less-specific-return-statement']) {
            throw new RuntimeException("The $mode deferred class contract must remain object for $name.");
        }
    }
}

// Altered and overridden declarations must keep their native contracts. Every
// variant reproduces a native error at the guarded union, then compares all errors.
$variants = [
    'narrow template bound' => str_replace('@template T of object', '@template T of \\Fixtures\\First', $contract),
    'non generic return' => str_replace('@return T', '@return object', $contract),
    'narrow constructor values' => str_replace('array<string,mixed>', 'array<string,int>', $contract),
    'parameter reference' => str_replace('string $id', 'string &$id', $contract),
    'native union parameter' => str_replace('string $id', 'string|int $id', $contract),
    'required second parameter' => str_replace('array $arguments = []', 'array $arguments', $contract),
    'nonempty constructor default' => str_replace('array $arguments = []', 'array $arguments = ["service" => 1]', $contract),
    'unexpected method assertion' => str_replace('@return T', "@return T\n     * @psalm-assert class-string \$id", $contract),
];
file_put_contents($workspace.'/cases.php', "<?php\nnamespace Fixtures;\nuse Internal\\Container\\Container;\n".$cases['guarded names with unavailable class'][0]."\n");
foreach ($variants as $variant => $declaration) {
    file_put_contents($workspace.'/vendor/internal/container/src/Container.php', $declaration);
    $reference = null;
    foreach ($modes as $mode) {
        $config = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
        $config['extension-hosts'] = $mode === 'native' ? new stdClass : ['test' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
        ]];
        file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
        $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
            0 => ['pipe', 'r'], 1 => ['file', $workspace.'/altered.json', 'w'], 2 => ['file', $workspace.'/altered.log', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start Mago.');
        }
        fclose($pipes[0]);
        proc_close($process);
        $report = json_decode(file_get_contents($workspace.'/altered.json'), true, flags: JSON_THROW_ON_ERROR);
        $errors = array_values(array_filter($report['issues'] ?? [], static fn ($issue): bool => $issue['level'] === 'Error'));
        if ($errors === [] || ($reference !== null && $reference !== $errors)) {
            throw new RuntimeException("Unexpected $mode altered contract diagnostics for $variant; workspace $workspace");
        }
        $reference = $errors;
    }
}
echo count($variants)." altered container contracts retain native diagnostics\n";
