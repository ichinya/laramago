<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\PolicyMappingCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago policies '.bin2hex(random_bytes(8));
mkdir($workspace, 0777, true);
$assert = static function (bool $condition, string $description): void {
    if (! $condition) {
        throw new RuntimeException($description);
    }
    echo 'PASS: '.$description."\n";
};
$configure = static function (mixed $entries) use ($workspace): PolicyMappingCatalog {
    file_put_contents($workspace.'/composer.json', json_encode(
        ['extra' => ['laramago' => ['policy-sources' => $entries]]],
        JSON_THROW_ON_ERROR,
    ));

    return new PolicyMappingCatalog($workspace);
};
$provider = static function (string $declaration): string {
    return (
        '<?php namespace App; use Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider as Base; '
        .'use Domain\\Record as Model; use Policies\\RecordPolicy as Policy; class Auth extends Base { '
        .$declaration
        .' }'
    );
};
$entries = [['file' => 'auth.php', 'provider' => 'App\\Auth']];
file_put_contents($workspace.'/auth.php', $provider('protected $policies = [Model::class => Policy::class];'));
$assert($configure(null)->mappings() === null, 'source presence never activates a provider');
$catalog = $configure($entries);
$assert(
    $catalog->mappings() === ['Domain\\Record' => 'Policies\\RecordPolicy'],
    'lexical class aliases resolve without loading classes',
);
$assert($catalog->policyForExactKey('Domain\\Record') === 'Policies\\RecordPolicy', 'exact key lookup');
$assert(
    $catalog->policyForExactKey('domain\\Record') === null && $catalog->policyForExactKey('Domain\\Child') === null,
    'case and inherited or guessed policies remain unknown',
);
file_put_contents($workspace.'/auth.php', $provider(<<<'PHP'
    protected $policies = [
        Model::class => Policy::class,
        'Domain\Record' => 'Policies\Replacement',
        '\Domain\Record' => '\Policies\Slashed',
        'domain\record' => 'Policies\Lowercase',
    ];
    PHP));
file_put_contents($workspace.'/later.php', str_replace(
    'class Auth ',
    'class Later ',
    $provider("protected \$policies = ['Domain\\Record' => 'Policies\\Last'];"),
));
$catalog = $configure([...$entries, ['file' => 'later.php', 'provider' => 'App\\Later']]);
$assert(
    $catalog->mappings() === [
        'Domain\\Record' => 'Policies\\Last',
        '\\Domain\\Record' => '\\Policies\\Slashed',
        'domain\\record' => 'Policies\\Lowercase',
    ],
    'duplicate entries and ordered providers overwrite values while preserving exact native keys',
);
foreach ([
    'protected $policies = [Model::class => dynamicPolicy()];',
    'protected $policies = [...MAP];',
    'protected $policies = [Model::class => Policy::class]; public function policies() { return []; }',
    'protected $policies = [Model::class => Policy::class]; use MutatesPolicies;',
    'protected $policies = [self::class => Policy::class];',
    'protected $policies = [Model::class => parent::class];',
    'protected $policies = [Policy::class];',
    'protected $policies = ["123" => Policy::class];',
    'private $policies = [Model::class => Policy::class];',
] as $declaration) {
    file_put_contents($workspace.'/auth.php', $provider($declaration));
    $assert($configure($entries)->mappings() === null, 'unsupported declaration invalidates the complete selection');
}
file_put_contents($workspace.'/auth.php', $provider('protected $policies = [];'));
$assert($configure($entries)->mappings() === [], 'empty selected declaration is known');
$assert(
    $configure($entries)->policyForExactKey('Missing') === null,
    'empty declaration cannot exclude guessed policies or before hooks',
);
$assert(
    $configure([...$entries, ['file' => 'missing.php', 'provider' => 'App\\Missing']])->mappings() === null,
    'unreadable later provider invalidates earlier declarations',
);
file_put_contents(
    $workspace.'/auth.php',
    '<?php throw new RuntimeException("Never execute source"); '.$provider('protected $policies = [];'),
);
$assert($configure($entries)->mappings() === null, 'malformed source is never executed');
file_put_contents($workspace.'/auth.php', str_replace(
    'class Auth extends Base',
    'class Auth extends CustomBase',
    $provider('protected $policies = [];'),
));
$assert($configure($entries)->mappings() === null, 'custom hierarchy is unknown');
file_put_contents(
    $workspace.'/auth.php',
    $provider('protected $policies = [];').' throw new \\RuntimeException("Never execute source");',
);
$assert($configure($entries)->mappings() === null, 'top-level executable statements are rejected without execution');
$assert(
    $configure([['file' => 'auth.php', 'provider' => 'Wrong']])->mappings() === null,
    'provider selector must match',
);
foreach (["bad\0.php", '../auth.php', $workspace.'/auth.php', 'https://example.test/auth.php', ''] as $path) {
    $assert(
        $configure([['file' => $path, 'provider' => 'App\\Auth']])->mappings() === null,
        'invalid or external source paths remain unknown without throwing',
    );
}
foreach (['auth.php', 'later.php', 'composer.json'] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace);
