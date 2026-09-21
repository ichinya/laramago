<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\PolicyModelArgumentContractExport;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-policy-contract-'.bin2hex(random_bytes(8));
mkdir($root, 0777, true);
$assertions = 0;
$assert = static function (bool $condition, string $description) use (&$assertions): void {
    if (! $condition) {
        throw new RuntimeException($description);
    }
    $assertions++;
    echo 'PASS: '.$description."\n";
};
$write = static function (string $file, string $contents) use ($root): void {
    file_put_contents($root.'/'.$file, $contents);
};

try {
    $write('provider.php', <<<'PHP'
        <?php
        namespace App\Providers;
        use Domain\Post as Article;
        use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
        use Policies\PostPolicy;

        final class Auth extends AuthServiceProvider
        {
            protected $policies = [Article::class => PostPolicy::class];
        }

        throw new \RuntimeException('Application source must never execute.');
        PHP);
    $write('policy.php', <<<'PHP'
        <?php
        namespace Policies;
        use Domain\Marker;
        use Domain\Other;
        use Domain\Post as Article;
        use Domain\User;

        final class PostPolicy
        {
            public function exact(User $user, Article $post): bool { return true; }
            public function nullable(User $user, ?Article $post): bool { return true; }
            public function union(User $user, Article|Other $post): bool { return true; }
            public function anyObject(User $user, object $post): bool { return true; }
            public function untyped(User $user, $post): bool { return true; }
            public function unrelated(User $user, Other $post): bool { return true; }
            public function numericScalar(User $user, int|float $post): bool { return true; }
            public function stringable(User $user, string $post): bool { return true; }
            public function callback(User $user, callable $post): bool { return true; }
            public function traversable(User $user, iterable $post): bool { return true; }
            public function boolean(User $user, bool $post): bool { return true; }
            public function arrayOnly(User $user, array $post): bool { return true; }
            public function intersection(User $user, Article&Marker $post): bool { return true; }
            public function create(User $user): bool { return true; }
            public function before(User $user, string $ability): ?bool { return null; }
            protected function hidden(User $user, Article $post): bool { return true; }
            public function __call(string $name, array $arguments): mixed { return null; }
        }

        throw new \RuntimeException('Application source must never execute.');
        PHP);
    $write('models.php', <<<'PHP'
        <?php
        namespace Domain;
        class Post {}
        class Other {}
        class User {}
        interface Marker {}
        PHP);

    $export = (new PolicyModelArgumentContractExport)->export(
        $root,
        ['provider.php', 'policy.php', 'models.php'],
    );
    $assert($export['schemaVersion'] === 1, 'schema version is stable');
    $assert(
        $export['scope'] === [
            'kind' => 'policy-model-argument-contract-candidates',
            'evidence' => 'source-only',
            'semantics' => 'literal-mapping-to-second-declared-policy-parameter',
            'exhaustive' => false,
            'runtimeDispatchValidated' => false,
        ],
        'scope states the source-only non-runtime contract',
    );
    $assert($export['errors'] === [] && $export['truncated'] === false, 'valid selection is complete within bounds');
    $assert(
        $export['selection'] === [
            'providerClasses' => 1,
            'literalMappings' => 1,
            'matchedMappings' => 1,
            'unmatchedMappings' => 0,
            'ambiguousMappings' => 0,
            'unsupportedMappingCandidates' => 0,
            'contractCandidates' => 14,
        ],
        'selection counts providers, mappings and eligible public methods',
    );
    $assert(count($export['mappings']) === 1, 'one literal mapping is retained');
    $mapping = $export['mappings'][0];
    $assert($mapping['model'] === 'Domain\\Post', 'model alias resolves without loading source');
    $assert($mapping['policy'] === 'Policies\\PostPolicy', 'policy alias resolves without loading source');
    $assert($mapping['provider'] === 'App\\Providers\\Auth', 'provider declaration is identified');
    $assert($mapping['confidence'] === 'literal-mapping-candidate', 'mapping confidence is explicit');
    $assert(
        substr(file_get_contents($mapping['file']), $mapping['start'], $mapping['end'] - $mapping['start'])
        === 'Article::class => PostPolicy::class',
        'mapping span preserves the original source bytes',
    );
    $assert(hash_file('sha256', $mapping['file']) === $mapping['contentHash'], 'mapping hash matches source bytes');

    $contracts = [];
    foreach ($export['contracts'] as $contract) {
        $contracts[$contract['policyMethod']['method']] = $contract;
    }
    $assert(
        ! isset($contracts['before'], $contracts['hidden'], $contracts['__call']),
        'non-ability methods are excluded',
    );
    foreach (['exact', 'nullable', 'union', 'anyObject', 'untyped'] as $method) {
        $assert($contracts[$method]['declarationCompatibility'] === 'compatible', $method.' is provably compatible');
    }
    $assert(
        $contracts['unrelated']['declarationCompatibility'] === 'unknown',
        'unrelated named type defers without ancestry proof',
    );
    $assert(
        $contracts['numericScalar']['declarationCompatibility'] === 'incompatible',
        'numeric-only union rejects an object argument',
    );
    $assert(
        $contracts['stringable']['declarationCompatibility'] === 'unknown',
        'string defers because weak calls accept Stringable objects',
    );
    $assert(
        $contracts['callback']['declarationCompatibility'] === 'unknown',
        'callable defers because invokable objects may match',
    );
    $assert(
        $contracts['traversable']['declarationCompatibility'] === 'unknown',
        'iterable defers because Traversable objects may match',
    );
    $assert($contracts['boolean']['declarationCompatibility'] === 'incompatible', 'bool rejects an object argument');
    $assert($contracts['arrayOnly']['declarationCompatibility'] === 'incompatible', 'array rejects an object argument');
    $assert(
        $contracts['intersection']['declarationCompatibility'] === 'unknown',
        'intersection defers without interface proof',
    );
    $assert(
        $contracts['create']['declarationCompatibility'] === 'not-present',
        'class-level candidate does not invent a model parameter',
    );
    $assert($contracts['exact']['runtimeDispatchValidated'] === false, 'contract never claims effective Gate dispatch');
    $parameter = $contracts['exact']['modelParameterCandidate'];
    $assert($parameter['position'] === 1 && $parameter['positionAfterUser'] === 0, 'parameter positions are explicit');
    $assert($parameter['name'] === 'post', 'parameter name is retained');
    $assert($parameter['nativeType'] === 'Article', 'native type preserves source spelling');
    $assert($parameter['resolvedNativeType'] === 'Domain\\Post', 'resolved type preserves declaration meaning');
    $assert($parameter['mappedModel'] === 'Domain\\Post', 'mapped model accompanies the parameter candidate');
    $assert(
        substr(
            file_get_contents($contracts['exact']['policyMethod']['file']),
            $parameter['nativeTypeSpan']['start'],
            $parameter['nativeTypeSpan']['end'] - $parameter['nativeTypeSpan']['start'],
        ) === 'Article',
        'native type span points to original syntax',
    );

    $write('unmatched.php', <<<'PHP'
        <?php
        namespace App\Providers;
        use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
        final class Extra extends AuthServiceProvider
        {
            protected $policies = [\Domain\Missing::class => \Policies\MissingPolicy::class];
        }
        PHP);
    $unmatched = (new PolicyModelArgumentContractExport)->export($root, ['unmatched.php']);
    $assert($unmatched['selection']['unmatchedMappings'] === 1, 'unselected policy declarations remain unmatched');
    $assert($unmatched['contracts'] === [], 'unmatched mappings do not invent contracts');

    $write('literal.php', <<<'PHP'
        <?php
        namespace App\Providers;
        use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
        final class Literal extends AuthServiceProvider
        {
            protected $policies = ['\\Domain\\Post' => '\\Policies\\PostPolicy'];
        }
        PHP);
    $literal = (new PolicyModelArgumentContractExport)->export($root, ['literal.php', 'policy.php']);
    $assert($literal['mappings'][0]['model'] === '\\Domain\\Post', 'literal model class name is retained');
    $assert($literal['mappings'][0]['policy'] === '\\Policies\\PostPolicy', 'literal policy class name is retained');
    $assert($literal['selection']['matchedMappings'] === 1, 'leading slash does not prevent declaration matching');
    $assert(
        $literal['contracts'][0]['declarationCompatibility'] === 'compatible',
        'leading slash does not prevent exact compatibility',
    );

    $write('duplicate.php', str_replace(
        'final class PostPolicy',
        'final class PostPolicy',
        file_get_contents($root.'/policy.php'),
    ));
    $ambiguous = (new PolicyModelArgumentContractExport)->export(
        $root,
        ['provider.php', 'policy.php', 'duplicate.php'],
    );
    $assert($ambiguous['selection']['ambiguousMappings'] === 1, 'duplicate policy declarations are ambiguous');
    $assert($ambiguous['contracts'] === [], 'ambiguous policy declarations do not invent contracts');

    $write('dynamic.php', <<<'PHP'
        <?php
        namespace App\Providers;
        use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
        final class Dynamic extends AuthServiceProvider
        {
            protected $policies = [\Domain\Post::class => policy()];
        }
        PHP);
    $dynamic = (new PolicyModelArgumentContractExport)->export($root, ['dynamic.php']);
    $assert($dynamic['mappings'] === [], 'dynamic policy expression is not treated as a mapping');
    $assert(
        $dynamic['selection']['unsupportedMappingCandidates'] === 1,
        'unsupported provider mapping is disclosed',
    );

    $invalid = (new PolicyModelArgumentContractExport)->export(
        $root,
        ['../provider.php', 'missing.php', 'models.php', 'models.php'],
    );
    $assert(
        array_column($invalid['errors'], 'code') === ['invalid-source', 'unreadable-source'],
        'invalid and unreadable sources remain visible',
    );
    $assert($invalid['mappings'] === [] && $invalid['contracts'] === [], 'source errors cannot create contracts');

    echo 'PASS: '.$assertions." policy model argument contract checks\n";
} finally {
    foreach (glob($root.'/*.php') ?: [] as $file) {
        unlink($file);
    }
    rmdir($root);
}
