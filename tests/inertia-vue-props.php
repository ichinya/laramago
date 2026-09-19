<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Ichinya\Laramago\Analyzer\StaticAnalysis\VueDefineProps;

require dirname(__DIR__).'/vendor/autoload.php';

$parser = new VueDefineProps;
$cases = [
    '<template>{{ "defineProps({fake: String})" }}</template><script>defineProps({ordinary: String})</script><script setup>const props = defineProps(["title", \'user-id\']);</script>' =>
        ['names' => ['title', 'user-id'], 'complete' => true],
    '<script setup lang="ts">const props = defineProps({ title: String, user: { type: Object, default: () => ({ name: "a" }) } });</script>' =>
        ['names' => ['title', 'user'], 'complete' => true],
    '<script setup>defineProps({ readonly: Boolean })</script>' => ['names' => ['readonly'], 'complete' => true],
    '<script setup>defineProps([",", "}", "plain"])</script>' => [
        'names' => [',', '}', 'plain'],
        'complete' => true,
    ],
    '<script setup lang="ts">const props = defineProps<{ title: string; readonly user?: { name: string }; "user-id": number }>();</script>' =>
        ['names' => ['title', 'user', 'user-id'], 'complete' => true],
    '<script setup>defineProps(["plain", "escaped\\u0061"])</script>' => [
        'names' => ['plain'],
        'complete' => false,
    ],
    '<script setup>defineProps(["known", dynamic, "alsoKnown"])</script>' => [
        'names' => ['known', 'alsoKnown'],
        'complete' => false,
    ],
    '<script setup>defineProps({ known: String, ...shared, [computed]: Number })</script>' => [
        'names' => ['known'],
        'complete' => false,
    ],
    '<script setup lang="ts">defineProps<{ known: string; [key: string]: unknown }>()</script>' => [
        'names' => ['known'],
        'complete' => false,
    ],
    '<script setup>// defineProps({ fake: String })'
        ."\n"
        .'const sample = "defineProps([\'fake\'])"; const pattern = /defineProps\\(fake\\)/; defineProps(["real"])</script>' =>
        ['names' => ['real'], 'complete' => true],
    '<!-- <script setup>defineProps(["fake"])</script> --><script setup>defineProps([])</script>' => [
        'names' => [],
        'complete' => true,
    ],
];
foreach ($cases as $source => $expected) {
    $actual = $parser->read($source);
    if ($actual !== $expected) {
        throw new RuntimeException('Unexpected literal prop names: '.json_encode($actual, JSON_THROW_ON_ERROR));
    }
}
echo "PASS: literal Vue macro names preserve source order and completeness\n";

foreach ([
    '<script>defineProps({ ordinary: String })</script>',
    '<script setup>const text = "defineProps({ fake: String })";</script>',
    '<script setup>const text = `defineProps({ fake: String })`;</script>',
    '<template><script setup>defineProps(["fake"])</script></template>',
    '<docs><script setup>defineProps(["fake"])</script></docs>',
    '<i18n>{"text":"<script setup>defineProps([\"fake\"])</script>"}</i18n>',
    '<template><div title="<script setup>defineProps([\'fake\'])</script>"></div></template>',
    '<style>/* <script setup>defineProps(["fake"])</script> */</style>',
    '<script data-description="this setup is fake">defineProps(["fake"])</script>',
    '<script setup>const defineProps = localMacro; defineProps({ fake: String })</script>',
    '<script setup>import { defineProps } from "other"; defineProps({ fake: String })</script>',
    '<script setup>object.defineProps({ fake: String })</script>',
    '<script setup>function nested() { return defineProps({ fake: String }) }</script>',
    '<script setup>if (flag) defineProps({ fake: String })</script>',
    '<script setup>if (flag) /;defineProps({ fake: String })/;</script>',
    '<script setup>const props = withDefaults(defineProps<{ known: string }>(), {})</script>',
    '<script setup lang="ts">defineProps<ImportedProps>()</script>',
    '<script setup lang="ts">defineProps<{ known: string } & SharedProps>()</script>',
    '<script setup>defineProps({ one: String }); defineProps({ two: String })</script>',
] as $source) {
    if ($parser->read($source) !== null) {
        throw new RuntimeException('An uncertain or non-setup macro must remain unknown.');
    }
}
echo "PASS: comments, strings, shadowing and dynamic types cannot prove a prop set\n";
if ($parser->read('<script setup>'.str_repeat(' ', 524289).'defineProps(["tooLarge"])</script>') !== null) {
    throw new RuntimeException('Oversized frontend input must remain unknown.');
}
if ($parser->read('<script setup>defineProps(["before"])'."\0".'</script>') !== null) {
    throw new RuntimeException('Binary frontend input must remain unknown.');
}

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago vue props '.bin2hex(random_bytes(8));
mkdir($workspace.'/resources/js/Pages', 0777, true);
mkdir($workspace.'/second/Pages', 0777, true);
file_put_contents(
    $workspace.'/resources/js/Pages/Invoice.vue',
    '<script setup>defineProps(["invoice", "currency"])</script>',
);
file_put_contents($workspace.'/resources/js/Pages/Other.tsx', 'defineProps(["notVue"])');
file_put_contents($workspace.'/second/Pages/Invoice.vue', '<script setup>defineProps(["different"])</script>');
file_put_contents($workspace.'/resources/js/Pages/Big.vue', '<script setup>'.str_repeat(' ', 524289).'</script>');

/** @param list<string> $paths */
$catalog = static function (array $paths) use ($workspace): ReferenceCatalogs {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'reference-catalogs' => [
                    'inertia-pages' => [
                        'paths' => $paths,
                        'extensions' => ['vue', 'tsx'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    return new ReferenceCatalogs($workspace);
};

$single = $catalog(['resources/js/Pages']);
if ($single->inertiaPageProps('Invoice') !== ['names' => ['invoice', 'currency'], 'complete' => true]) {
    throw new RuntimeException('Expected props from the configured, exact-case Vue page.');
}
file_put_contents($workspace.'/resources/js/Pages/Invoice.vue', '<script setup>defineProps(["changed"])</script>');
if ($single->inertiaPageProps('Invoice') !== ['names' => ['invoice', 'currency'], 'complete' => true]) {
    throw new RuntimeException('Expected a stable catalog snapshot after first reading a page.');
}
if ($single->inertiaPageProps('invoice') !== null || $single->inertiaPageProps('Other') !== null) {
    throw new RuntimeException('Unknown, wrong-case and non-Vue pages must not acquire Vue props.');
}
if ($single->inertiaPageProps('Big') !== null) {
    throw new RuntimeException('Oversized page files must not produce prop metadata.');
}
$duplicateRoot = $catalog(['resources/js/Pages', 'resources/js/Pages']);
if ($duplicateRoot->inertiaPageProps('Invoice') !== ['names' => ['changed'], 'complete' => true]) {
    throw new RuntimeException('Repeated paths to one file must resolve to one page.');
}
$ambiguous = $catalog(['resources/js/Pages', 'second/Pages']);
if ($ambiguous->inertiaPageProps('Invoice') !== null) {
    throw new RuntimeException('Distinct pages with one name have no proven runtime selection.');
}
echo "PASS: configured Vue pages resolve by file identity and cache their first result\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$resolvedRoot = realpath($workspace);
foreach ($iterator as $entry) {
    $resolved = realpath($entry->getPathname());
    if (
        $resolvedRoot === false
        || $resolved === false
        || ! str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside temporary workspace.');
    }
    $entry->isDir() ? rmdir($resolved) : unlink($resolved);
}
rmdir($workspace);
