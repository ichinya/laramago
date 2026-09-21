<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\PolicyDiscoveryBoundaryExport;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago policy discovery '.bin2hex(random_bytes(8));
mkdir($root, 0777, true);
$checks = 0;

$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$write = static function (string $file, string $contents) use ($root): void {
    $directory = dirname($root.'/'.$file);
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents($root.'/'.$file, $contents);
};

$remove = static function (string $directory): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
};

try {
    $write('app/Models/Post.php', <<<'PHP'
        <?php
        namespace App\Models;
        use App\Policies\PostPolicy;
        use Illuminate\Database\Eloquent\Attributes\UsePolicy as NativePolicy;
        #[NativePolicy(PostPolicy::class)]
        class Post extends \App\Base\Record {}
        throw new \RuntimeException('Selected application source must never execute PRIVATE_SOURCE_VALUE');
        PHP);
    $write('app/Base/Record.php', <<<'PHP'
        <?php
        namespace App\Base;
        use App\Policies\LegacyPolicy;
        use Illuminate\Database\Eloquent\Attributes\UsePolicy;
        #[UsePolicy(class: LegacyPolicy::class)]
        class Record extends ExternalRecord {}
        PHP);
    $write('app/Models/Admin/Post.php', <<<'PHP'
        <?php
        namespace App\Models\Admin;
        class Post {}
        PHP);
    $write('app/Models/Special.php', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Attributes\UsePolicy;
        #[UsePolicy(self::class)] class Special {}
        #[UsePolicy(parent::class)] class Unresolved extends Special {}
        #[UsePolicy(Special::class), UsePolicy(Special::class)] class Repeated {}
        if (unknown()) { #[UsePolicy(Special::class)] class Conditional {} }
        $anonymous = new #[UsePolicy(Special::class)] class {};
        PHP);
    $write('duplicates-a.php', '<?php namespace App; class Duplicate {}');
    $write('duplicates-b.php', '<?php namespace App; class Duplicate {}');
    $write('broken.php', '<?php class Broken {');
    $write('outside.php', '<?php class OutsideSelection {}');

    $files = [
        'app/Models/Post.php',
        'app/Base/Record.php',
        'app/Models/Admin/Post.php',
        'app/Models/Special.php',
        'duplicates-a.php',
        'duplicates-b.php',
        'broken.php',
        'missing.php',
        '../outside.php',
        17,
        'app/Models/Post.php',
    ];
    $result = (new PolicyDiscoveryBoundaryExport)->export($root, $files);

    $assert($result['schemaVersion'] === 1, 'Schema version must be stable.');
    $assert($result['scope']['kind'] === 'policy-discovery-boundaries', 'Scope kind must identify the export.');
    $assert($result['scope']['effectivePolicyResolved'] === false, 'Source evidence must not resolve a policy.');
    $assert(
        $result['scope']['nativeResolutionOrder'] === [
            'exact-policy-map',
            'direct-use-policy-attribute',
            'policy-name-guessing',
            'parent-policy-map',
            'inherited-use-policy-attribute',
        ],
        'Native resolver channels must retain their audited order.',
    );
    $assert(
        in_array('custom-policy-name-guesser', $result['scope']['unverifiedRuntimeBoundaries'], true),
        'Custom guessers must remain a runtime boundary.',
    );
    $assert(
        in_array('container-policy-resolution', $result['scope']['unverifiedRuntimeBoundaries'], true),
        'Container resolution must remain a runtime boundary.',
    );
    $assert($result['truncated'] === false, 'Ordinary partial source errors must not imply truncation.');
    $assert(
        array_column($result['errors'], 'code') === [
            'parse-failure',
            'unreadable-source',
            'invalid-source',
            'invalid-source',
        ],
        'Read and parse errors must remain explicit and ordered.',
    );
    $assert(
        $result['errors'][3]['file'] === null,
        'Non-string source entries must be rejected without a fabricated path.',
    );
    $assert($result['selection']['ambiguousClasses'] === 1, 'Duplicate selected class declarations must be ambiguous.');
    $assert(
        $result['selection']['exportedSubjects'] === $result['selection']['uniqueClasses'],
        'Every unique selected class must have boundary metadata.',
    );

    $subjects = array_column($result['subjects'], null, 'class');
    $assert(! isset($subjects['App\\Duplicate']), 'Ambiguous class declarations must not produce a resolver subject.');
    $assert(! isset($subjects['App\\Models\\Conditional']), 'Conditional classes must not be selected.');
    $assert(
        count(array_filter(
            $result['declarations'],
            static fn (array $item): bool => $item['class'] === 'App\\Duplicate',
        )) === 2,
        'Ambiguous declarations must retain both source locations.',
    );

    $post = $subjects['App\\Models\\Post'];
    $assert(
        $post['effectivePolicy']['status'] === 'unknown',
        'A fully parsed source must still have unknown effective policy.',
    );
    $assert(
        $post['channels']['exactPolicyMap']['status'] === 'external-runtime-state',
        'Exact mappings must not be copied or inferred.',
    );
    $assert($post['channels']['directPolicyAttribute']['status'] === 'declared', 'Direct UsePolicy must be preserved.');
    $assert(
        $post['channels']['directPolicyAttribute']['policy'] === 'App\\Policies\\PostPolicy',
        'Imported policy class must resolve lexically.',
    );
    $assert(
        $post['channels']['policyNameGuessing']['customCallbackState'] === 'unknown',
        'Selected sources cannot prove absence of a custom guesser.',
    );
    $assert(
        $post['channels']['policyNameGuessing']['runtimeClassExistence'] === 'unknown',
        'Selected declarations cannot replace runtime class_exists.',
    );
    $assert(
        array_column($post['channels']['policyNameGuessing']['defaultResolver']['probeOrder'], 'name') === [
            'App\\Models\\Policies\\PostPolicy',
            'App\\Policies\\PostPolicy',
        ],
        'Default guessing probe order must match the pinned native resolver.',
    );
    $assert(
        $post['channels']['policyNameGuessing']['defaultResolver']['fallback'] === 'App\\Models\\Policies\\PostPolicy',
        'Default guessing fallback must remain separate from successful runtime probes.',
    );
    $assert(
        array_column($post['channels']['parentPolicyMap']['selectedParentChain'], 'class') === [
            'App\\Base\\Record',
            'App\\Base\\ExternalRecord',
        ],
        'Selected inheritance must stop at the first external declaration.',
    );
    $assert(
        $post['channels']['parentPolicyMap']['chainComplete'] === false,
        'An unselected ancestor must make the selected chain incomplete.',
    );
    $assert(
        $post['channels']['inheritedPolicyAttributes']['status'] === 'selected-chain-incomplete',
        'Inherited attributes must disclose incomplete ancestry.',
    );
    $assert(
        $post['channels']['inheritedPolicyAttributes']['candidates'][0]['attribute']['policy']
        === 'App\\Policies\\LegacyPolicy',
        'Selected parent attribute must retain its policy candidate.',
    );

    $admin = $subjects['App\\Models\\Admin\\Post'];
    $assert(
        array_column($admin['channels']['policyNameGuessing']['defaultResolver']['probeOrder'], 'name') === [
            'App\\Models\\Policies\\Admin\\PostPolicy',
            'App\\Policies\\Admin\\PostPolicy',
            'App\\Models\\Admin\\Policies\\PostPolicy',
            'App\\Models\\Policies\\PostPolicy',
            'App\\Policies\\PostPolicy',
        ],
        'Nested Models namespaces must preserve native special-case and reverse probe order.',
    );

    $assert(
        $subjects['App\\Models\\Special']['channels']['directPolicyAttribute']['policy'] === 'App\\Models\\Special',
        'self::class must resolve to the selected declaration.',
    );
    $assert(
        $subjects['App\\Models\\Unresolved']['channels']['directPolicyAttribute']['status'] === 'uncertain',
        'parent::class must remain unresolved.',
    );
    $assert(
        $subjects['App\\Models\\Unresolved']['channels']['directPolicyAttribute']['reason']
        === 'unresolved-policy-class',
        'Unresolved attributes must explain their boundary.',
    );
    $assert(
        $subjects['App\\Models\\Repeated']['channels']['directPolicyAttribute']['reason']
        === 'multiple-direct-use-policy-attributes',
        'Repeated direct attributes must remain uncertain.',
    );

    $attribute = $post['channels']['directPolicyAttribute'];
    $source = file_get_contents($root.'/app/Models/Post.php');
    $assert(
        $attribute['location']['contentHash'] === hash('sha256', $source),
        'Attribute locations must retain the exact source hash.',
    );
    $assert(
        substr(
            $source,
            $attribute['valueLocation']['start'],
            $attribute['valueLocation']['end'] - $attribute['valueLocation']['start'],
        ) === 'PostPolicy::class',
        'Attribute value span must identify original source bytes.',
    );
    $assert(
        substr($source, $post['declaration']['start'], $post['declaration']['end'] - $post['declaration']['start'])
        === 'Post',
        'Class declaration span must identify the original class name.',
    );
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    $assert(! str_contains($encoded, 'PRIVATE_SOURCE_VALUE'), 'Metadata must not copy application source text.');

    $limited = (new PolicyDiscoveryBoundaryExport)->export($root, array_fill(0, 257, 'app/Models/Post.php'));
    $assert($limited['truncated'] === true, 'Excess selected files must disclose truncation.');
    $assert($limited['truncationReasons'] === ['file-limit'], 'File limit must have a stable reason.');
    $assert(count($limited['subjects']) === 1, 'Repeated sources within the file budget must be deduplicated.');

    try {
        (new PolicyDiscoveryBoundaryExport)->export($root, ['named' => 'app/Models/Post.php']);
        throw new RuntimeException('Associative source selection was accepted.');
    } catch (InvalidArgumentException) {
        $checks++;
    }

    $assert(! is_file($root.'/executed'), 'Selected source must never execute.');
    echo 'PASS: '.$checks." policy discovery boundary checks\n";
} finally {
    $remove($root);
}
