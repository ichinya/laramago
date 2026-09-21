<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\BindingCompatibilityExport;

require dirname(__DIR__).'/vendor/autoload.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$fixture = sys_get_temp_dir().'/laramago-binding-compatibility-'.bin2hex(random_bytes(8));
mkdir($fixture.'/app', 0777, true);

try {
    file_put_contents($fixture.'/app/contracts.php', <<<'PHP'
        <?php

        namespace App\Contracts;

        interface RootContract {}
        interface ChildContract extends RootContract {}
        interface OtherContract {}
        PHP);
    file_put_contents($fixture.'/app/services.php', <<<'PHP'
        <?php

        namespace App\Services;

        use App\Contracts\ChildContract;

        class ParentService implements ChildContract {}
        final class CompatibleService extends ParentService {}
        final class PartiallyKnownCompatibleService implements ChildContract, ExternalContract {}
        final class UnrelatedService {}
        final class UnknownService extends ExternalService {}
        PHP);
    file_put_contents($fixture.'/app/provider.php', <<<'PHP'
        <?php

        namespace App\Providers;

        use App\Contracts\OtherContract;
        use App\Contracts\RootContract;
        use App\Services\CompatibleService;
        use App\Services\PartiallyKnownCompatibleService;
        use App\Services\UnrelatedService;
        use App\Services\UnknownService;

        file_put_contents(__DIR__.'/executed', 'unsafe');

        $app->bind(RootContract::class, CompatibleService::class);
        $app->singleton(OtherContract::class, UnrelatedService::class);
        $app->scoped(RootContract::class, UnknownService::class);
        $app->bind(RootContract::class, fn () => new CompatibleService);
        $custom->bind('service', CompatibleService::class);
        $app->bind(abstract: RootContract::class, concrete: CompatibleService::class);
        $app->bind(RootContract::class, PartiallyKnownCompatibleService::class);
        PHP);

    $metadata = (new BindingCompatibilityExport)->export($fixture, [
        'app/contracts.php',
        'app/services.php',
        'app/provider.php',
    ]);

    $check($metadata['schemaVersion'] === 1, 'Schema version.');
    $check($metadata['scope']['semantics'] === 'optional-declaration-quality-advisory', 'Advisory scope.');
    $check($metadata['scope']['runtimeFailureClaimed'] === false, 'No runtime error claim.');
    $check($metadata['scope']['altersInferredTypes'] === false, 'No native or PHPDoc type override.');
    $check(! is_file($fixture.'/app/executed'), 'Selected application source is never executed.');
    $rootDeclaration = $metadata['declarations'][0];
    $check($rootDeclaration['name'] === 'App\\Contracts\\RootContract', 'Selected declaration name.');
    $check(
        substr(
            file_get_contents($fixture.'/app/contracts.php'),
            $rootDeclaration['start'],
            $rootDeclaration['end'] - $rootDeclaration['start'],
        ) === 'interface RootContract {}',
        'Declaration byte span identifies original source.',
    );
    $check(
        $rootDeclaration['contentHash'] === hash_file('sha256', $fixture.'/app/contracts.php'),
        'Declaration source hash is preserved.',
    );
    $check(
        $metadata['selection'] === [
            'registrationCandidates' => 7,
            'exportedCandidates' => 6,
            'unsupportedRegistrationCandidates' => 1,
        ],
        'Supported and deferred source candidates remain visible.',
    );

    [$compatible, $incompatible, $unknown, $factory, $named, $partiallyKnown] = $metadata['candidates'];
    $check($compatible['compatibility']['status'] === 'compatible', 'Inherited interface compatibility.');
    $check(
        $compatible['compatibility']['path'] === [
            'App\\Services\\CompatibleService',
            'App\\Services\\ParentService',
            'App\\Contracts\\ChildContract',
            'App\\Contracts\\RootContract',
        ],
        'Compatibility path preserves selected hierarchy.',
    );
    $check(
        $incompatible['compatibility']['status'] === 'incompatible-under-selected-declarations',
        'Closed unrelated declarations produce only a policy candidate.',
    );
    $check($incompatible['runtimeFailureClaimed'] === false, 'Unrelated bindings remain valid Laravel registrations.');
    $check($unknown['compatibility']['status'] === 'unknown-hierarchy', 'Unselected ancestors defer.');
    $check($factory['compatibility']['status'] === 'unknown-custom-factory', 'Custom factory results defer.');
    $check($factory['concrete']['class'] === null, 'Custom factories do not gain a guessed return class.');
    $check($named['compatibility']['status'] === 'compatible', 'Exact native named arguments are supported.');
    $check($named['registration']['method'] === 'bind', 'Registration method is preserved.');
    $check($partiallyKnown['compatibility']['status'] === 'compatible', 'A selected path proves compatibility.');
    $check(
        $partiallyKnown['compatibility']['hierarchyComplete'] === false,
        'Other unselected hierarchy edges preserve incompleteness.',
    );
    $check(
        substr(
            file_get_contents($fixture.'/app/provider.php'),
            $incompatible['concrete']['start'],
            $incompatible['concrete']['end'] - $incompatible['concrete']['start'],
        ) === 'UnrelatedService::class',
        'Concrete byte span identifies original source.',
    );
    $check(
        $incompatible['registration']['contentHash'] === hash_file('sha256', $fixture.'/app/provider.php'),
        'Source hash accompanies review locations.',
    );
    $check($metadata['errors'] === [], 'Valid selected sources have no errors.');

    file_put_contents($fixture.'/app/duplicate.php', <<<'PHP'
        <?php

        namespace App\Services;

        final class UnrelatedService {}
        PHP);
    $duplicate = (new BindingCompatibilityExport)->export($fixture, [
        'app/contracts.php',
        'app/services.php',
        'app/provider.php',
        'app/duplicate.php',
    ]);
    $check(
        $duplicate['candidates'][1]['compatibility']['status'] === 'unknown-hierarchy',
        'Duplicate concrete declarations never produce a guessed incompatibility.',
    );

    file_put_contents($fixture.'/app/provider.php', '<?php $app->bind(Missing::class, Missing::class);');
    $identity = (new BindingCompatibilityExport)->export($fixture, ['app/provider.php']);
    $check(
        $identity['candidates'][0]['compatibility']['status'] === 'compatible',
        'Exact names retain identity compatibility.',
    );
    $check(
        $identity['candidates'][0]['compatibility']['hierarchyComplete'] === false,
        'Identity alone cannot prove an unselected hierarchy complete.',
    );
    file_put_contents($fixture.'/app/provider.php', <<<'PHP'
        <?php
        interface Target extends Missing {}
        class Concrete implements Target {}
        $app->bind(Target::class, Concrete::class);
        PHP);
    $inherited = (new BindingCompatibilityExport)->export($fixture, ['app/provider.php']);
    $check(
        $inherited['candidates'][0]['compatibility']['status'] === 'compatible',
        'A selected target edge proves compatibility.',
    );
    $check(
        $inherited['candidates'][0]['compatibility']['hierarchyComplete'] === false,
        'The target declaration may still have unknown ancestors.',
    );

    file_put_contents($fixture.'/app/broken.php', '<?php $app->bind(');
    $broken = (new BindingCompatibilityExport)->export($fixture, ['app/broken.php']);
    $check($broken['errors'][0]['code'] === 'parse-failure', 'Parse failures remain explicit.');
    $check($broken['candidates'] === [], 'Broken sources produce no stale candidates.');

    $invalidList = false;
    try {
        (new BindingCompatibilityExport)->export($fixture, ['source' => 'app/provider.php']);
    } catch (InvalidArgumentException) {
        $invalidList = true;
    }
    $check($invalidList, 'Associative source selections are rejected.');
    $check(
        (new BindingCompatibilityExport)->export($fixture, ['../outside.php'])['errors'][0]['code']
        === 'invalid-source',
        'Parent traversal is rejected.',
    );
    $check(
        (new BindingCompatibilityExport)->export($fixture, [])['selection']['registrationCandidates'] === 0,
        'Empty selection never discovers project files.',
    );
    $limited = (new BindingCompatibilityExport)->export($fixture, array_fill(0, 257, 'app/provider.php'));
    $check($limited['truncated'] === true, 'Explicit file selection is bounded.');
    $check($limited['truncationReasons'] === ['file-limit'], 'Reached limits retain their reason.');
} finally {
    foreach (['contracts.php', 'services.php', 'provider.php', 'duplicate.php', 'broken.php', 'executed'] as $name) {
        if (is_file($fixture.'/app/'.$name)) {
            unlink($fixture.'/app/'.$name);
        }
    }
    rmdir($fixture.'/app');
    rmdir($fixture);
}

echo "Binding compatibility export: {$checks} checks passed.\n";
