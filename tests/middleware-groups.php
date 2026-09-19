<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\KernelMiddlewareDeclarations;
use Ichinya\Laramago\Analyzer\StaticAnalysis\MiddlewareGroupCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;

require dirname(__DIR__).'/vendor/autoload.php';

$root = sys_get_temp_dir().'/laramago-middleware-groups-'.bin2hex(random_bytes(8));
mkdir($root);
$assert = static function (bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS: '.$label."\n";
};
$catalog = static function (array $options) use ($root): MiddlewareGroupCatalog {
    file_put_contents($root.'/composer.json', json_encode([
        'extra' => ['laramago' => ['middleware-groups' => $options]],
    ], JSON_THROW_ON_ERROR));

    return new MiddlewareGroupCatalog($root);
};
file_put_contents($root.'/Kernel.php', <<<'PHP'
    <?php
    namespace Example;
    use Vendor\First as Start;
    class Kernel extends \Illuminate\Foundation\Http\Kernel {
        protected $middlewareGroups = ['web' => [Start::class, 'auth:admin', 'nested', Start::class], 'nested' => [], 'cycle' => ['cycle']];
        public function __construct() { throw new \RuntimeException('Never instantiate'); }
    }
    throw new \RuntimeException('Never execute');
    PHP);
$options = ['kernel-file' => 'Kernel.php', 'kernel-class' => 'Example\\Kernel'];
$expected = ['web' => ['Vendor\\First', 'auth:admin', 'nested', 'Vendor\\First'], 'nested' => [], 'cycle' => ['cycle']];
$declarations = new KernelMiddlewareDeclarations(new PhpSource($root), 'Kernel.php', 'Example\\Kernel');
$assert(
    $declarations->groups() === $expected,
    'Kernel metadata preserves imported class names, order, parameters, duplicates and cycles without execution',
);
$assert($catalog([])->groups() === null, 'Kernel discovery alone never asserts activation');
$partial = $catalog($options);
$assert(
    $partial->groups() === $expected && $partial->contains('web') === true && $partial->contains('api') === null,
    'selected effective declarations do not prove absent defaults',
);
$complete = $catalog($options + ['complete' => true]);
$assert(
    $complete->isComplete() && $complete->contains('api') === false && $complete->contains('WEB') === false,
    'only explicit completeness permits case-sensitive absence',
);
$assert($catalog($options + ['complete' => null])->groups() === null, 'malformed completeness fails closed');
$assert(
    $catalog(['kernel-file' => 'Kernel.php', 'kernel-class' => 'Other'])->groups() === null,
    'unmatched class is unknown',
);
file_put_contents(
    $root.'/groups.php',
    "<?php use Vendor\\Second as Finish; return ['web' => [Finish::class], 'empty' => []];",
);
file_put_contents($root.'/override.php', "<?php return ['web' => ['last', 'last']];");
$files = $catalog(['files' => ['groups.php', 'override.php'], 'complete' => true]);
$assert(
    $files->groups() === ['web' => ['last', 'last'], 'empty' => []],
    'ordered explicit map files replace entire groups and retain member duplicates',
);
$assert(
    $catalog(['files' => []])->contains('web') === null
    && $catalog(['files' => [], 'complete' => true])->contains('web') === false,
    'empty sources require completeness for absence',
);
foreach ([
    "<?php return ['web' => [getenv('MIDDLEWARE')]];",
    "<?php return ['web' => [...EXTRA]];",
    "<?php return ['web' => ['custom' => 'auth']];",
    "<?php return ['web' => [parent::class]];",
    "<?php return ['web' => ['auth']]; throw new Exception('Never execute');",
    "<?php return ['web' => []]; return [];",
    '<?php broken syntax',
] as $invalid) {
    file_put_contents($root.'/invalid.php', $invalid);
    $result = $catalog(['files' => ['groups.php', 'invalid.php'], 'complete' => true]);
    $assert(
        $result->groups() === null && $result->contains('missing') === null,
        'unsupported source invalidates the whole asserted catalog',
    );
}
foreach ([
    '<?php namespace Example; class Kernel extends Base {}',
    "<?php namespace Example; class Kernel { protected \$middlewareGroups = SOME_CONSTANT; }",
    "<?php namespace Example; if (true) { class Kernel { protected \$middlewareGroups = []; } }",
] as $invalid) {
    file_put_contents($root.'/Kernel.php', $invalid);
    $assert(
        $catalog($options + ['complete' => true])->groups() === null,
        'inherited, dynamic and conditional Kernel declarations remain unknown',
    );
}
$assert($catalog($options + ['files' => []])->groups() === null, 'ambiguous source selectors fail closed');
foreach ([
    "bad\0.php",
    '../groups.php',
    $root.'/groups.php',
    'missing.php',
    'https://example.test/groups.php',
] as $path) {
    $assert(
        $catalog(['files' => [$path], 'complete' => true])->groups() === null,
        'invalid and external paths fail closed',
    );
    $assert(
        $catalog(['kernel-file' => $path, 'kernel-class' => 'Example\\Kernel'])->groups() === null,
        'Kernel paths use the same containment policy',
    );
}
file_put_contents(
    $root.'/Kernel.php',
    '<?php namespace Example; class Kernel { protected $middlewareGroups = ["web" => [self::class]]; }',
);
$assert(
    $catalog(['kernel-file' => 'Kernel.php', 'kernel-class' => '\\example\\kernel'])->groups() === ['web' => [
        'Example\\Kernel',
    ]],
    'self class uses declaration spelling, not configured class spelling',
);
file_put_contents(
    $root.'/Kernel.php',
    '<?php namespace Example; class Kernel { protected $middlewareGroups = []; protected $middlewareGroups = []; }',
);
$assert($catalog($options)->groups() === null, 'duplicate group properties are unknown');
foreach (['Kernel.php', 'groups.php', 'override.php', 'invalid.php', 'composer.json'] as $file) {
    unlink($root.'/'.$file);
}
rmdir($root);
