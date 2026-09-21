<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\PolicyAdditionalArgumentContractExport;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-policy-additional-'.bin2hex(random_bytes(8));
mkdir($root, 0777, true);
$checks = 0;
$check = static function (bool $condition, string $description) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($description);
    }
    $checks++;
};
$write = static function (string $file, string $contents) use ($root): void {
    file_put_contents($root.'/'.$file, $contents);
};

try {
    $write('provider.php', <<<'PHP'
        <?php
        namespace App\Providers;
        use App\Models\Post;
        use App\Policies\PostPolicy;
        use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
        final class Auth extends AuthServiceProvider
        {
            protected $policies = [Post::class => PostPolicy::class];
        }
        throw new \RuntimeException('Selected application source must never execute.');
        PHP);
    $write('policy.php', <<<'PHP'
        <?php
        namespace App\Policies;
        use App\Models\Post;
        use App\Models\User;
        use Domain\Context as RequestContext;
        final class PostPolicy
        {
            public function update(User $user, Post $post, RequestContext $context, int $count = 1): bool
            {
                return true;
            }
            public function archive(User $user, Post $post, RequestContext ...$contexts): bool
            {
                return true;
            }
            public function view(User $user, Post $post): bool { return true; }
            public function create(User $user, RequestContext $context): bool { return true; }
            public function before(User $user, string $ability): ?bool { return null; }
        }
        PHP);
    $calls = <<<'PHP'
        <?php
        namespace App\Actions;
        use App\Models\Post;
        use Illuminate\Support\Facades\Gate as Access;

        Access::authorize('update', [$post, $context, 3]);
        \Illuminate\Support\Facades\Gate::allows('view', $post);
        Access::inspect('anonymous');
        Access::raw('create', [Post::class, $context]);
        Access::check('keyed', [0 => $post, 'context' => $context]);
        Access::denies('archive', [$post, ...$contexts]);
        Access::any('ignored-aggregate', [$post, $context]);
        Access::authorize($dynamicAbility, [$post, $context]);
        \App\Gate::authorize('foreign', [$post, $context]);
        PHP;
    $write('calls.php', $calls);
    $write('models.php', '<?php namespace App\Models; class Post {} class User {}');

    $metadata = (new PolicyAdditionalArgumentContractExport)->export(
        $root,
        ['provider.php', 'policy.php', 'calls.php', 'models.php'],
    );
    $check($metadata['schemaVersion'] === 1, 'Versioned export contract.');
    $check(
        $metadata['scope'] === [
            'kind' => 'policy-additional-argument-contract-candidates',
            'evidence' => 'source-only',
            'semantics' => 'mapped-policy-parameters-after-model-and-unjoined-gate-argument-lists',
            'exhaustive' => false,
            'runtimeDispatchValidated' => false,
        ],
        'Scope states that declarations and calls are unjoined candidates.',
    );
    $check($metadata['errors'] === [] && ! $metadata['truncated'], 'Selected sources parse within bounds.');
    $check(
        $metadata['dependency']['kind'] === 'policy-model-argument-contract-candidates'
        && $metadata['dependency']['errors'] === []
        && ! $metadata['dependency']['truncated'],
        'Item 087 dependency status is retained without duplicating its mappings.',
    );
    $check(
        $metadata['selection']['policyContractsWithModelParameterCandidate'] === 4,
        'Mapped methods with an item 087 model-parameter candidate participate.',
    );
    $check(
        $metadata['selection']['additionalParameterCandidates'] === 3,
        'Additional declared parameters are counted.',
    );
    $check($metadata['selection']['gateCallCandidates'] === 6, 'Direct literal singular Gate calls are selected.');
    $check(
        $metadata['selection']['additionalArgumentCandidates'] === 3,
        'Only complete list-array extras are counted.',
    );
    $check(
        $metadata['selection']['unknownArgumentLists'] === 2,
        'Keyed and unpacked arrays remain explicitly unknown.',
    );

    $policies = [];
    foreach ($metadata['policyContracts'] as $contract) {
        $policies[$contract['policyMethodRef']['method']] = $contract;
    }
    $check(
        array_keys($policies) === ['update', 'archive', 'view', 'create'],
        'Item 087 method candidates remain in declaration order.',
    );
    $check(
        count($policies['update']['additionalParameterCandidates']) === 2,
        'Parameters after user and model are retained.',
    );
    [$context, $count] = $policies['update']['additionalParameterCandidates'];
    $check(
        $context['position'] === 2 && $context['positionAfterUser'] === 1 && $context['positionAfterModel'] === 0,
        'Declared parameter positions are explicit.',
    );
    $check($context['name'] === 'context', 'Declared parameter name is retained.');
    $check($context['nativeType'] === 'RequestContext', 'Native type spelling is retained.');
    $check($context['resolvedNativeType'] === 'Domain\\Context', 'Imported type is resolved without loading source.');
    $check(
        ! $context['hasDefault'] && ! $context['variadic'] && ! $context['byReference'],
        'Required parameter flags are retained.',
    );
    $check($count['nativeType'] === 'int' && $count['hasDefault'], 'Optional scalar declaration metadata is retained.');
    $check(
        $policies['archive']['additionalParameterCandidates'][0]['variadic'],
        'Variadic additional parameter is retained.',
    );
    $check(
        $policies['view']['additionalParameterCandidates'] === [],
        'Methods without extras remain useful contracts.',
    );
    $check(
        $policies['create']['additionalParameterCandidates'] === [],
        'A class-level-looking method is not reinterpreted without item 089 provenance.',
    );
    $check(
        $policies['update']['runtimeDispatchValidated'] === false,
        'Declaration metadata does not claim policy dispatch.',
    );
    $check(
        substr(
            file_get_contents($policies['update']['policyMethodRef']['file']),
            $context['nativeTypeSpan']['start'],
            $context['nativeTypeSpan']['end'] - $context['nativeTypeSpan']['start'],
        ) === 'RequestContext',
        'Native type span identifies original bytes.',
    );

    $gateCalls = [];
    foreach ($metadata['gateCalls'] as $call) {
        $gateCalls[$call['ability']['value']] = $call;
    }
    $check(
        array_keys($gateCalls) === ['update', 'view', 'anonymous', 'create', 'keyed', 'archive'],
        'Literal singular abilities retain source order.',
    );
    $update = $gateCalls['update'];
    $check($update['gate']['method'] === 'authorize', 'Original Gate method is retained.');
    $check(
        $update['arguments']['state'] === 'list-array' && $update['arguments']['complete'],
        'List-array shape is complete.',
    );
    $check(
        $update['arguments']['subject']['expressionKind'] === 'variable',
        'Subject expression provenance is retained.',
    );
    $check(count($update['arguments']['additionalArguments']) === 2, 'Additional Gate arguments are retained.');
    $check(
        $update['arguments']['additionalArguments'][0]['positionInGateArguments'] === 1
        && $update['arguments']['additionalArguments'][0]['positionAfterSubject'] === 0,
        'Gate argument positions are explicit.',
    );
    $check(
        $update['arguments']['additionalArguments'][1]['expressionKind'] === 'literal',
        'Argument expression kind is metadata only.',
    );
    $check(
        substr(
            file_get_contents($update['gate']['file']),
            $update['ability']['start'],
            $update['ability']['end'] - $update['ability']['start'],
        ) === "'update'",
        'Ability span identifies original literal bytes.',
    );
    $check(
        hash_file('sha256', $update['gate']['file']) === $update['gate']['contentHash'],
        'Call hash matches exact source bytes.',
    );
    $check(
        $gateCalls['view']['arguments']['state'] === 'single-expression',
        'Single model expression is distinguished.',
    );
    $check($gateCalls['anonymous']['arguments']['state'] === 'omitted', 'Omitted arguments are distinguished.');
    $check(
        $gateCalls['create']['arguments']['subject']['expressionKind'] === 'class-constant-fetch',
        'Class constants remain lexical expressions for item 089 to interpret.',
    );
    $check(! $gateCalls['keyed']['arguments']['complete'], 'Keyed arrays do not invent positional policy arguments.');
    $check(
        ! $gateCalls['archive']['arguments']['complete'],
        'Unpacked arrays do not invent positional policy arguments.',
    );
    $check(! file_exists($root.'/executed'), 'Selected application PHP is never executed.');

    $invalid = (new PolicyAdditionalArgumentContractExport)->export(
        $root,
        ['../calls.php', 'missing.php', 'calls.php', 'calls.php'],
    );
    $check(
        array_column($invalid['errors'], 'code') === ['invalid-source', 'unreadable-source'],
        'Invalid, unreadable and duplicate selections stay bounded.',
    );
    $check($invalid['policyContracts'] === [], 'Absent policy mappings cannot create additional parameter contracts.');
    $check(count($invalid['gateCalls']) === 6, 'Valid selected call source remains independently useful.');

    $write('broken.php', '<?php Gate::authorize(');
    $broken = (new PolicyAdditionalArgumentContractExport)->export($root, ['broken.php']);
    $check($broken['errors'][0]['code'] === 'parse-failure', 'Parse failure is explicit.');
    $check(
        $broken['gateCalls'] === [] && $broken['policyContracts'] === [],
        'Broken input produces no stale metadata.',
    );

    $invalidList = false;
    try {
        (new PolicyAdditionalArgumentContractExport)->export($root, ['source' => 'calls.php']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative selections are rejected.');
} finally {
    foreach (glob($root.'/*.php') ?: [] as $file) {
        unlink($file);
    }
    rmdir($root);
}

echo "Policy additional argument contract export: {$checks} checks passed.\n";
