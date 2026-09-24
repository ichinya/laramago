<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$autoload = getenv('MAGO_SDK_AUTOLOAD') ?: $package.'/vendor/autoload.php';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago controller calls '.bin2hex(random_bytes(8));
mkdir($workspace.'/custom dependencies', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap must not run.");');
file_put_contents(
    $workspace.'/custom dependencies/autoload.php',
    '<?php throw new RuntimeException("Application autoload must not run.");',
);

$object = static fn (string $name): array => ['class' => 'Fixture\\'.$name];
// Each independent declaration has one asserted final positional call.
$cases = [
    'Missing' => ['string $id', [], ['missing']],
    'ObjectMismatch' => ['Service $service', [$object('Bad')], ['type']],
    'ObjectMatch' => ['Service $service', [$object('Good')], []],
    'SubtypeMatch' => ['Good $service', [$object('ChildGood')], []],
    'PositionalNames' => ['string $differentName, Service $dependency', ['string', $object('Good')], []],
    'SwappedPositions' => ['Service $dependency, string $differentName', ['string', $object('Good')], ['type']],
    'NullableAcceptsNull' => ['?Service $service', ['null'], []],
    'NullableRejectsObject' => ['?Service $service', [$object('Bad')], ['type']],
    'NonNullableNull' => ['Service $service', ['null'], ['type']],
    'UnionAcceptsObject' => ['Service|Bad $service', [$object('Bad')], []],
    'UnionRejectsObject' => ['Service|Good $service', [$object('Bad')], ['type']],
    'UnionAcceptsNull' => ['Service|Bad|null $service', ['null'], []],
    'UnionRejectsScalar' => ['Service|Bad|null $service', ['string'], ['type']],
    'WeakScalar' => ['int $id', ['string'], []],
    'WeakScalarUnion' => ['Service|int $id', ['string'], []],
    'ArrayToScalar' => ['int $id', ['array'], ['type']],
    'ScalarToArray' => ['array $id', ['string'], ['type']],
    'ArrayToObject' => ['object $id', ['array'], ['type']],
    'ObjectToArray' => ['array $id', [$object('Good')], ['type']],
    'ScalarToIterable' => ['iterable $id', ['string'], ['type']],
    'ArrayToIterable' => ['iterable $id', ['array'], []],
    'NullToScalar' => ['string $id', ['null'], ['type']],
    'ScalarToNull' => ['null $id', ['string'], ['type']],
    'NullToNull' => ['null $id', ['null'], []],
    'CallableUnknown' => ['callable $id', ['string'], []],
    'IterableObjectUnknown' => ['iterable $id', [$object('Bad')], []],
    'StringableCoercion' => ['string $id', [$object('StringValue')], []],
    'Optional' => ['string $id = "default"', [], []],
    'ImplicitNullable' => ['Service $service = null', ['null'], []],
    'RequiredAfterOptional' => ['string $first = "default", string $second', ['string'], ['missing']],
    'VariadicEmpty' => ['Service ...$services', [], []],
    'VariadicMismatch' => ['Service ...$services', [$object('Good'), $object('Bad'), 'array'], ['type', 'type']],
    'ExtraArguments' => ['', ['string', 'array'], []],
    'ReferenceDeferred' => ['Service &$service, string $id', [$object('Bad')], []],
    'IntersectionDeferred' => ['Service&Other $service', [$object('Bad')], []],
    'UnknownActual' => ['Service $service', [$object('MissingClass')], []],
    'IncompleteActual' => ['Service $service', [$object('Unknown')], []],
    'AbstractActual' => ['Service $service', [$object('AbstractService')], []],
    'InterfaceActual' => ['Service $service', [$object('Other')], []],
    'UnknownExpected' => ['MissingClass $service', [$object('Bad')], []],
    'IncompleteExpected' => ['Unknown $service', [$object('Bad')], []],
    'UnknownUnionArm' => ['Service|MissingClass $service', [$object('Bad')], []],
    'SelfDeferred' => ['self $service', [$object('Bad')], []],
    'Untyped' => ['$service', [$object('Bad')], []],
    'Mixed' => ['mixed $service', ['null'], []],
    'Documented' => ['Service $service', [$object('Good')], [], '/** @param ChildGood $service */'],
    'AttributeResult' => ['#[Injection] Service $service', [$object('Bad')], ['type']],
    'PrivateAction' => ['Service $service', [$object('Bad')], [], '', 'private'],
    'StaticAction' => ['Service $service', [$object('Bad')], [], '', 'public static'],
    'AbstractOwner' => ['Service $service', [$object('Bad')], [], '', 'public', 'abstract'],
    'Invoke' => ['Service $service', [$object('Bad')], ['type'], '', 'public', '', '__invoke'],
    'ConstructorDeferred' => ['Service $service', [$object('Bad')], [], '', 'public', '', '__construct'],
    'InheritedDeferred' => ['', [$object('Bad')], [], '', '', '', 'action', 'BaseController'],
    'IncompleteOwner' => ['Service $service', [$object('Bad')], [], '', 'public', '', 'action', 'MissingBase'],
];
$source = <<<'PHP'
    <?php
    namespace Fixture;
    interface Service {}
    interface Other {}
    class Good implements Service {}
    class ChildGood extends Good {}
    class Bad {}
    class Unknown extends MissingBase {}
    abstract class AbstractService {}
    class StringValue { public function __toString(): string { return 'value'; } }
    #[\Attribute(\Attribute::TARGET_PARAMETER)] class Injection {}
    class BaseController { public function action(Service $service): void {} }
    function nativeFailure(): int { return 'wrong'; }
    throw new \RuntimeException('Application source must not execute.');
    PHP;
$source .= "\n";
$entries = [];
$expected = [];
$codes = [
    'missing' => 'ichinya/laramago/laramago-missing-controller-arguments',
    'type' => 'ichinya/laramago/laramago-incompatible-controller-argument',
];
foreach ($cases as $name => $case) {
    [$parameters, $arguments, $issues] = $case;
    $doc = $case[3] ?? '';
    $visibility = $case[4] ?? 'public';
    $modifier = $case[5] ?? '';
    $method = $case[6] ?? 'action';
    $extends = isset($case[7]) ? 'extends '.$case[7] : '';
    $returnType = $method === '__construct' ? '' : ': void';
    $declaration = $visibility === '' ? '' : "$doc $visibility function $method($parameters)$returnType {}";
    $source .= "$modifier class {$name}Controller $extends { $declaration }\n";
    $entries[] = ['controller' => 'Fixture\\'.$name.'Controller', 'method' => $method, 'arguments' => $arguments];
    $expected[$name.'Controller'] = array_map(static fn (string $code): string => $codes[$code], $issues);
}
file_put_contents($workspace.'/cases.php', $source);
$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $autoload,
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$policy = ['final-positional-call-asserted' => true, 'entries' => $entries];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks, $workspace): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message.'; inspect '.$workspace);
    }
};
$analyze = static function (string $label, mixed $contract, bool $enabled = true) use (
    $workspace,
    $command,
    $configuration,
    $check,
): array {
    file_put_contents($workspace.'/composer.json', json_encode([
        'config' => ['vendor-dir' => 'custom dependencies'],
        'extra' => ['laramago' => ['controller-call-contracts' => $contract]],
    ], JSON_THROW_ON_ERROR));
    $config = $configuration;
    if (! $enabled) {
        unset($config['extension-hosts']);
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$label.'.json', 'w'],
            2 => ['file', $workspace.'/'.$label.'.log', 'w'],
        ],
        $pipes,
    );
    $check(is_resource($process), 'Mago starts');
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$label.'.log');
    $check(
        $exit === 1 && ! preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log),
        $label.': Mago completes without a worker failure',
    );
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $check(
        in_array('invalid-return-statement', array_column($report['issues'] ?? [], 'code'), true),
        $label.': native diagnostics survive',
    );

    return $report['issues'] ?? [];
};
$pluginIssues =
    static fn (array $issues): array => array_values(array_filter($issues, static fn (array $issue): bool => str_starts_with(
        $issue['code'],
        'ichinya/laramago/',
    )));
$nativeIssues = static fn (array $issues): array => array_values(array_filter(
    $issues,
    static fn (array $issue): bool => ! str_starts_with($issue['code'], 'ichinya/laramago/'),
));

$baseline = $analyze('native', $policy, false);
$enabled = $analyze('enabled', $policy);
$check($nativeIssues($baseline) === $nativeIssues($enabled), 'Native diagnostics remain byte-for-byte unchanged');
$found = [];
foreach ($pluginIssues($enabled) as $issue) {
    $check(
        preg_match('/Fixture\\\\([a-zA-Z]+Controller)::/', $issue['message'], $match) === 1,
        'Diagnostic identifies the configured controller',
    );
    $found[$match[1]][] = $issue['code'];
    $check($issue['level'] === 'Warning', 'Controller finding is a warning');
    $check(count($issue['annotations']) === 1, 'Controller finding has an exact source annotation');
    $line = explode("\n", $source)[$issue['annotations'][0]['span']['start']['line']];
    $check(str_contains($line, 'class '.$match[1].' '), 'Annotation points to the correct controller declaration');
}
foreach ($expected as $name => $issues) {
    $check(($found[$name] ?? []) === $issues, $name.': exact expected diagnostics');
}
$check(count($pluginIssues($enabled)) === array_sum(array_map('count', $expected)), 'No extra plugin findings');

$invalid = [
    'absent' => null,
    'false-assertion' => ['final-positional-call-asserted' => false, 'entries' => $entries],
    'missing-assertion' => ['entries' => $entries],
    'truthy-assertion' => ['final-positional-call-asserted' => 'true', 'entries' => $entries],
    'unknown-policy-field' => [...$policy, 'native-dispatch' => true],
    'duplicate-entry' => [...$policy, 'entries' => [...$entries, $entries[0]]],
    'invalid-late-entry' => [...$policy, 'entries' => [...$entries, ['controller' => 'Invalid']]],
    'too-many-entries' => [...$policy, 'entries' => array_fill(0, 257, $entries[0])],
];
foreach ([
    'unknown-entry-field' => [...$entries[0], 'route' => '/example'],
    'named-arguments' => [...$entries[0], 'arguments' => ['id' => 'string']],
    'unknown-type' => [...$entries[0], 'arguments' => ['mixed']],
    'null-descriptor' => [...$entries[0], 'arguments' => [null]],
    'bad-class' => [...$entries[0], 'arguments' => [['class' => 'Foo::class']]],
    'extra-descriptor-field' => [...$entries[0], 'arguments' => [['class' => 'Fixture\\Bad', 'exact' => true]]],
    'bad-method' => [...$entries[0], 'method' => 'action()'],
    'bad-controller' => [...$entries[0], 'controller' => 'Fixture\\'],
    'too-many-arguments' => [...$entries[0], 'arguments' => array_fill(0, 65, 'string')],
] as $label => $entry) {
    $invalid[$label] = [...$policy, 'entries' => [$entries[1], $entry]];
}
$caseDuplicate = $entries[0];
$caseDuplicate['controller'] = '\\'.strtoupper($caseDuplicate['controller']);
$caseDuplicate['method'] = strtoupper($caseDuplicate['method']);
$invalid['case-insensitive-duplicate'] = [...$policy, 'entries' => [...$entries, $caseDuplicate]];
foreach ($invalid as $label => $contract) {
    $check($pluginIssues($analyze($label, $contract)) === [], $label.': invalid or absent policy defers completely');
}
$casePolicy = $policy;
foreach ($casePolicy['entries'] as &$entry) {
    $entry['controller'] = '\\'.strtoupper($entry['controller']);
    $entry['method'] = strtoupper($entry['method']);
}
unset($entry);
$check(
    $pluginIssues($analyze('case-normalization', $casePolicy)) === $pluginIssues($enabled),
    'Class and method names are case insensitive',
);
file_put_contents($workspace.'/cases.php', $source.<<<'PHP'

    class DuplicateController { public function action(Service $service): void {} }
    class DuplicateController { public function action(Service $service): void {} }
    class DuplicateMethodController {
        public function action(Service $service): void {}
        public function action(Service $service): void {}
    }
    PHP);
$duplicates = $analyze('ambiguous-declarations', [
    'final-positional-call-asserted' => true,
    'entries' => [
        ['controller' => 'Fixture\\DuplicateController', 'method' => 'action', 'arguments' => [$object('Bad')]],
        ['controller' => 'Fixture\\DuplicateMethodController', 'method' => 'action', 'arguments' => [$object('Bad')]],
    ],
]);
$check($pluginIssues($duplicates) === [], 'Ambiguous class and method declarations defer');
// A new worker observes the current controller declaration, not a previous snapshot.
file_put_contents($workspace.'/cases.php', str_replace(
    'function action(string $id): void',
    'function action(string $id = "value"): void',
    $source,
));
$fresh = $analyze('updated-source', ['final-positional-call-asserted' => true, 'entries' => [$entries[0]]]);
$check($pluginIssues($fresh) === [], 'Rerun observes a changed signature');
$check(! is_file($workspace.'/.env'), 'The project has no environment file');

$resolvedWorkspace = realpath($workspace);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $file) {
    $resolvedFile = realpath($file->getPathname());
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
echo "Controller call contracts: {$checks} checks passed.\n";
