<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\ContainerInjectionAttributeExport;

require dirname(__DIR__).'/vendor/autoload.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$fixture = sys_get_temp_dir().'/laramago-container-injection-attributes-'.bin2hex(random_bytes(8));
mkdir($fixture.'/app', 0777, true);

try {
    file_put_contents($fixture.'/app/Candidates.php', <<<'PHP'
        <?php

        namespace App;

        use Attribute;
        use Illuminate\Container\Attributes\Config;
        use Illuminate\Container\Attributes\Context;
        use Illuminate\Container\Attributes\CurrentUser;
        use Illuminate\Container\Attributes\Give as Inject;
        use Illuminate\Container\Attributes\RouteParameter;
        use Illuminate\Container\Attributes\Storage;
        use Illuminate\Container\Attributes\Tag;

        file_put_contents(__DIR__.'/executed', 'unsafe');

        #[Attribute(Attribute::TARGET_PARAMETER)]
        final class Marker {}
        interface Service {}
        interface Filesystem {}
        interface User {}

        final class Handler
        {
            /** Existing PHPDoc remains independent analyzer evidence. */
            public function __construct(
                #[Marker]
                #[Inject(Service::class, ['mode' => 'sync'])]
                Service $service,
                #[Config(key: 'app.name', default: 'fallback')]
                string $name,
                #[Tag('handlers')]
                #[RouteParameter('handler')]
                iterable $handlers,
            ) {}

            public function action(#[RouteParameter] mixed $post): void
            {
                $callback = function (#[Context('trace', hidden: true)] mixed $trace): void {};
                $arrow = fn (#[Storage('public')] Filesystem $disk): Filesystem => $disk;
            }
        }

        function greet(#[CurrentUser('web')] ?User $user): void {}
        function customOnly(#[Marker] mixed $value): void {}
        PHP);
    file_put_contents($fixture.'/app/AllKinds.php', <<<'PHP'
        <?php

        namespace App;

        use Illuminate\Container\Attributes\Auth;
        use Illuminate\Container\Attributes\Authenticated;
        use Illuminate\Container\Attributes\Cache;
        use Illuminate\Container\Attributes\Database;
        use Illuminate\Container\Attributes\DB;
        use Illuminate\Container\Attributes\Give;
        use Illuminate\Container\Attributes\Log;
        use Illuminate\Container\Attributes\RequestAttribute;

        function allKinds(
            #[Auth] mixed $guard,
            #[Authenticated('staff')] mixed $authenticated,
            #[Cache(store: 'redis', memo: true)] mixed $cache,
            #[Database('mysql')] mixed $database,
            #[DB] mixed $db,
            #[Give('clock')] mixed $service,
            #[Log(channel: 'stack', name: 'worker')] mixed $logger,
            #[RequestAttribute('tenant')] mixed $requestAttribute,
        ): void {}
        PHP);

    $exporter = new ContainerInjectionAttributeExport;
    $metadata = $exporter->export($fixture, ['app/Candidates.php']);

    $check($metadata['schemaVersion'] === 1, 'Schema version.');
    $check(
        $metadata['scope'] === [
            'kind' => 'container-injection-attribute-candidates',
            'evidence' => 'source-only',
            'exhaustive' => false,
            'containerInvocationValidated' => false,
            'nativeFrameworkDeclarationValidated' => false,
            'effectiveContextualHandlerValidated' => false,
            'injectedValueTypeInferred' => false,
        ],
        'The schema explicitly denies runtime and type inference.',
    );
    $check($metadata['selection']['filesRead'] === 1, 'One selected source was read.');
    $check($metadata['selection']['parametersScanned'] === 8, 'All callable parameters were observed.');
    $check($metadata['selection']['attributedParameters'] === 7, 'Custom-only attributes are not misclassified.');
    $check($metadata['selection']['recognizedAttributes'] === 8, 'Repeated built-in candidates are counted.');
    $check(count($metadata['contracts']) === 7, 'Seven attributed parameter candidates.');
    $check(! is_file($fixture.'/app/executed'), 'Selected project PHP was never executed.');

    [$service, $name, $handlers, $route, $context, $storage, $user] = $metadata['contracts'];
    $check($service['callable']['kind'] === 'method', 'Constructor is a method declaration candidate.');
    $check($service['callable']['class'] === 'App\\Handler', 'Containing class resolves through the namespace.');
    $check($service['callable']['name'] === '__construct', 'Constructor name.');
    $check($service['callable']['phpDocSpan'] !== null, 'Existing callable PHPDoc provenance is retained.');
    $check($service['parameter']['name'] === 'service', 'Parameter name.');
    $check($service['parameter']['nativeType'] === 'Service', 'Original native type syntax.');
    $check($service['parameter']['resolvedNativeType'] === 'App\\Service', 'Resolved native type candidate.');
    $check($service['effectiveInjectedType'] === null, 'No injected type is inferred.');
    $check($service['runtimeResolutionValidated'] === false, 'Runtime resolution is explicitly unvalidated.');
    $check($service['attributes'][0]['class'] === 'Illuminate\\Container\\Attributes\\Give', 'Aliased Give resolves.');
    $check($service['attributes'][0]['kind'] === 'give', 'Stable built-in kind.');
    $check(
        $service['attributes'][0]['arguments'][0]['nativeRoleCandidate'] === 'class',
        'Give first argument follows the pinned native constructor role.',
    );
    $check(
        $service['attributes'][0]['arguments'][0]['expressionKind'] === 'class-name'
        && $service['attributes'][0]['arguments'][0]['class'] === 'App\\Service',
        'Give class-name syntax is provenance, not a resolved injected type.',
    );
    $check(
        $service['attributes'][0]['arguments'][1]['expressionKind'] === 'array'
        && $service['attributes'][0]['arguments'][1]['nativeRoleCandidate'] === 'params',
        'Give parameter override expression remains unevaluated.',
    );
    $check($service['otherAttributes'][0]['class'] === 'App\\Marker', 'Other parameter attributes remain visible.');

    $check($name['attributes'][0]['kind'] === 'config', 'Config kind.');
    $check($name['attributes'][0]['arguments'][0]['name'] === 'key', 'Named argument name retained.');
    $check($name['attributes'][0]['arguments'][0]['nativeRoleCandidate'] === 'key', 'Known named role candidate.');
    $check($name['attributes'][0]['arguments'][0]['literalValue'] === 'app.name', 'String literal provenance.');
    $check($name['attributes'][0]['arguments'][1]['literalValue'] === 'fallback', 'Default literal provenance.');

    $check(
        array_column($handlers['attributes'], 'kind') === ['tag', 'route-parameter'],
        'Multiple built-in attributes remain in source order without choosing an effective resolver.',
    );
    $check($handlers['parameter']['resolvedNativeType'] === 'iterable', 'Built-in native type retained.');
    $check($route['attributes'][0]['arguments'] === [], 'Defaulted native attribute arguments remain omitted.');
    $check($context['callable']['kind'] === 'closure', 'Closure callback candidate is identified.');
    $check(
        $context['attributes'][0]['arguments'][1]['nativeRoleCandidate'] === 'hidden'
        && $context['attributes'][0]['arguments'][1]['literalValue'] === true,
        'Named boolean argument provenance.',
    );
    $check($storage['callable']['kind'] === 'arrow-function', 'Arrow callback candidate is identified.');
    $check($storage['attributes'][0]['kind'] === 'storage', 'Storage kind.');
    $check($user['callable']['kind'] === 'function', 'Namespaced function candidate is identified.');
    $check($user['callable']['name'] === 'App\\greet', 'Namespaced function name.');
    $check($user['parameter']['nativeType'] === '?User', 'Original nullable type syntax.');
    $check($user['parameter']['resolvedNativeType'] === '?App\\User', 'Resolved nullable type candidate.');

    $source = file_get_contents($fixture.'/app/Candidates.php');
    $give = $service['attributes'][0];
    $check(
        substr($source, $give['start'], $give['end'] - $give['start']) === "Inject(Service::class, ['mode' => 'sync'])",
        'Attribute byte span identifies the original source syntax.',
    );
    $check($service['contentHash'] === hash('sha256', $source), 'Exact source hash.');
    $check($metadata['errors'] === [], 'Valid explicit source has no errors.');
    $check($metadata['truncated'] === false, 'Valid small source is complete within export bounds.');

    $allKinds = $exporter->export($fixture, ['app/AllKinds.php']);
    $check(
        array_map(
            static fn (array $contract): string => $contract['attributes'][0]['kind'],
            $allKinds['contracts'],
        ) === ['auth', 'authenticated', 'cache', 'database', 'db', 'give', 'log', 'request-attribute'],
        'Every remaining pinned built-in injection attribute has a stable kind.',
    );
    $giveIdentifier = $allKinds['contracts'][5];
    $check(
        $giveIdentifier['attributes'][0]['arguments'][0]['literalValue'] === 'clock'
        && $giveIdentifier['effectiveInjectedType'] === null,
        'An arbitrary Give service identifier never becomes an injected type.',
    );
    $check(
        array_column($allKinds['contracts'][6]['attributes'][0]['arguments'], 'nativeRoleCandidate') === [
            'channel',
            'name',
        ],
        'Named Log arguments retain their pinned constructor roles.',
    );
    $check($allKinds['errors'] === [], 'All built-in kind fixtures parse without execution.');

    file_put_contents($fixture.'/app/Broken.php', '<?php function broken(');
    $broken = $exporter->export($fixture, ['app/Broken.php']);
    $check($broken['errors'][0]['code'] === 'parse-failure', 'Parse failures remain explicit.');
    $check($broken['contracts'] === [], 'Broken source produces no stale contracts.');

    $check(
        $exporter->export($fixture, ['../outside.php'])['errors'][0]['code'] === 'invalid-source',
        'Parent traversal is rejected.',
    );
    $check(
        $exporter->export($fixture, ['app/Candidates.php', 'app/Candidates.php'])['selection']['filesRead'] === 1,
        'Repeated resolved sources are deduplicated.',
    );
    $limited = $exporter->export($fixture, array_fill(0, 257, 'app/Candidates.php'));
    $check($limited['truncated'] === true, 'Explicit source count is bounded.');
    $check($limited['truncationReasons'] === ['file-limit'], 'File-limit reason remains visible.');

    $invalidList = false;
    try {
        $exporter->export($fixture, ['source' => 'app/Candidates.php']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative source selections are rejected.');
} finally {
    foreach (['Candidates.php', 'AllKinds.php', 'Broken.php', 'executed'] as $file) {
        if (is_file($fixture.'/app/'.$file)) {
            unlink($fixture.'/app/'.$file);
        }
    }
    rmdir($fixture.'/app');
    rmdir($fixture);
}

echo "Container injection attribute export: {$checks} checks passed.\n";
