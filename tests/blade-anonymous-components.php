<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeAnonymousComponentCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade anonymous '.bin2hex(random_bytes(8));
mkdir($workspace.'/resources/views/components/forms', 0777, true);
mkdir($workspace.'/resources/views/components/panel', 0777, true);
mkdir($workspace.'/resources/views/components/notice', 0777, true);
mkdir($workspace.'/package/widgets', 0777, true);

$write = static function (
    string $relative,
    string $content = '@php(throw new RuntimeException("Blade must not execute"))',
) use ($workspace): void {
    file_put_contents($workspace.'/'.$relative, $content);
};
$configure = static function (mixed $roots) use ($workspace): BladeAnonymousComponentCatalog {
    $composer = $roots === null
        ? []
        : ['extra' => ['laramago' => ['blade-anonymous-components' => ['roots' => $roots]]]];
    file_put_contents($workspace.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

    return new BladeAnonymousComponentCatalog($workspace);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

$write('resources/views/components/button.blade.php');
$write('resources/views/components/forms/input.blade.php');
$write('resources/views/components/panel/index.blade.php');
$write('resources/views/components/notice/notice.blade.php');
$write('resources/views/components/bad name.blade.php');
$write('resources/views/components/not-blade.php', '<?php throw new RuntimeException("PHP must not execute");');
$write('package/widgets/card.blade.php');

$assert($configure(null)->components() === null, 'files alone do not enable the catalog');
$roots = [
    ['path' => 'resources/views/components'],
    ['path' => 'package/widgets', 'prefix' => 'package'],
];
$catalog = $configure($roots);
$components = $catalog->components();
$assert($components !== null && count($components) === 5, 'only selected valid Blade files are cataloged');
$byName = [];
foreach ($components ?? [] as $component) {
    $byName[$component->relativeName] = $component;
}
$assert(
    $byName['button']->path === 'resources/views/components/button.blade.php'
    && $byName['forms.input']->candidateNames === ['forms.input']
    && $byName['panel.index']->candidateNames === ['panel.index', 'panel']
    && $byName['notice.notice']->candidateNames === ['notice.notice', 'notice'],
    'direct, nested, index and repeated-segment candidate names preserve exact file paths',
);
$assert(
    $byName['card']->rootPath === 'package/widgets'
    && $byName['card']->prefix === 'package'
    && $byName['card']->candidateNames === ['card'],
    'a prefix is retained as metadata without claiming a registration',
);
$write('resources/views/components/later.blade.php');
$assert(count($catalog->components() ?? []) === 5, 'catalog instances retain their source snapshot');
$assert(count($configure($roots)->components() ?? []) === 6, 'a new instance observes a new Blade file');

foreach ([
    [['path' => '../outside']],
    [['path' => 'missing']],
    [['path' => 'resources/views/components', 'prefix' => 'bad::prefix']],
    [['path' => 'resources/views/components', 'active' => true]],
] as $invalid) {
    $assert($configure($invalid)->components() === null, 'invalid or unavailable roots remain unknown');
}

$assert(
    $configure([['path' => 'resources/views/components']])->components() !== null,
    'Blade directives and PHP files are never evaluated during enumeration',
);

$assert(
    $configure(array_fill(0, 129, ['path' => 'resources/views/components']))->components() === null,
    'oversized root lists remain unknown instead of scanning without a bound',
);

$deep = 'resources/views/components';
for ($i = 0; $i <= 33; $i++) {
    $deep .= '/d';
    mkdir($workspace.'/'.$deep);
}
$write($deep.'/hidden.blade.php');
$assert(
    $configure([['path' => 'resources/views/components']])->components() === null,
    'a directory at the depth limit invalidates the catalog instead of hiding deeper Blade files',
);
