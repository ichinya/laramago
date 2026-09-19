<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\EnvironmentNameCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago environment catalog '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);
file_put_contents($workspace.'/.env', "SECRET_ONLY_IN_RUNTIME=private\nAPP_KEY=private-value\n");
file_put_contents($workspace.'/.env.example', "TEMPLATE_ONLY=example\n");

/** @param array<string, mixed>|null $configuration */
$catalog = static function (?array $configuration) use ($workspace): EnvironmentNameCatalog {
    file_put_contents($workspace.'/composer.json', json_encode(
        $configuration === null ? [] : ['extra' => ['laramago' => ['environment-names' => $configuration]]],
        JSON_THROW_ON_ERROR,
    ));

    return new EnvironmentNameCatalog($workspace);
};

$assert = static function (bool $condition, string $description): void {
    if (! $condition) {
        throw new RuntimeException($description);
    }
    echo 'PASS: '.$description."\n";
};

$absent = $catalog(null);
$assert($absent->names() === null && $absent->contains('APP_KEY') === null, 'environment files alone supply no names');

$incomplete = $catalog(['names' => ['APP_KEY', 'VITE_API_URL', 'APP_KEY', 'feature.flag']]);
$assert(
    $incomplete->names() === ['APP_KEY', 'VITE_API_URL', 'feature.flag']
    && ! $incomplete->isComplete()
    && $incomplete->contains('APP_KEY') === true
    && $incomplete->contains('TEMPLATE_ONLY') === null
    && $incomplete->contains('SECRET_ONLY_IN_RUNTIME') === null
    && $incomplete->contains('app_key') === null,
    'incomplete names preserve exact case and never infer missing names from templates or runtime files',
);

$complete = $catalog(['names' => ['APP_KEY', 'VITE_API_URL'], 'complete' => true]);
$assert(
    $complete->isComplete()
    && $complete->contains('APP_KEY') === true
    && $complete->contains('TEMPLATE_ONLY') === false
    && $complete->contains('SECRET_ONLY_IN_RUNTIME') === false,
    'absence requires an explicit complete application assertion',
);

$empty = $catalog(['names' => [], 'complete' => true]);
$assert(
    $empty->names() === [] && $empty->contains('ANYTHING') === false,
    'an explicit empty complete catalog is valid',
);

foreach ([
    ['names' => ['VALID', 1], 'complete' => true],
    ['names' => ['VALID', 'BAD NAME'], 'complete' => true],
    ['names' => ['VALID', "BAD\x7FNAME"], 'complete' => true],
    ['names' => ['VALID', 'BAD=NAME'], 'complete' => true],
    ['names' => ['VALID'], 'complete' => 'true'],
    ['names' => ['VALID'], 'complete' => null],
    ['names' => ['key' => 'VALID'], 'complete' => true],
    ['complete' => true],
] as $configuration) {
    $invalid = $catalog($configuration);
    $assert(
        $invalid->names() === null && $invalid->contains('VALID') === null,
        'malformed catalogs cannot prove known or uncataloged names',
    );
}

file_put_contents($workspace.'/composer.json', '{');
$malformed = new EnvironmentNameCatalog($workspace);
$assert($malformed->names() === null && ! $malformed->isComplete(), 'malformed Composer metadata remains unknown');

foreach (['.env', '.env.example', 'composer.json'] as $filename) {
    unlink($workspace.'/'.$filename);
}
rmdir($workspace);
