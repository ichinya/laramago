<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\LivewireConventionalComponentCatalog;

$root = sys_get_temp_dir().'/laramago-livewire-162-'.bin2hex(random_bytes(8));
mkdir($root.'/app/Livewire/Admin', 0777, true);
mkdir($root.'/app/Other', 0777, true);
mkdir($root.'/app/Widgets', 0777, true);
mkdir($root.'/resources/views/livewire/admin', 0777, true);
mkdir($root.'/resources/views/other', 0777, true);
mkdir($root.'/resources/views/components/post', 0777, true);
mkdir($root.'/resources/views/components/stats', 0777, true);
$write = static function (string $path, string $contents) use ($root): void {
    file_put_contents($root.'/'.$path, $contents);
};
$config = static function (int $version) use ($write): void {
    $write('composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'livewire-conventional' => [
                    'version' => $version,
                    'class-roots' => $version === 3
                        ? [['namespace' => 'App\\Livewire', 'path' => 'app/Livewire']]
                        : [
                            ['namespace' => 'App', 'path' => 'app/Other'],
                            ['namespace' => 'App\\Widgets', 'path' => 'app/Widgets'],
                            ['namespace' => 'App\\Livewire', 'path' => 'app/Livewire'],
                        ],
                    'view-roots' => [['path' => 'resources/views']],
                    'component-view-roots' => $version === 4
                        ? [['path' => 'resources/views/components']]
                        : [],
                    'conventional-view-prefix' => 'livewire',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    $write('app/Livewire/Admin/Index.php', <<<'PHP'
        <?php
        namespace App\Livewire\Admin;
        use Livewire\Component as BaseComponent;
        class Index extends BaseComponent { }
        throw new \RuntimeException('Application source was executed');
        PHP);
    $write('app/Livewire/Custom.php', <<<'PHP'
        <?php
        namespace App\Livewire;
        class Custom extends \Livewire\Component {
            public function render() { return view('other.custom'); }
        }
        PHP);
    $write('app/Livewire/Dynamic.php', <<<'PHP'
        <?php
        namespace App\Livewire;
        class Dynamic extends \Livewire\Component {
            public function render() { return view($this->viewName); }
        }
        PHP);
    $write('app/Livewire/URLParser.php', <<<'PHP'
        <?php
        namespace App\Livewire;
        class URLParser extends \Livewire\Component {}
        PHP);
    $write('app/Widgets/Chart.php', <<<'PHP'
        <?php
        namespace App\Widgets;
        class Chart extends \Livewire\Component {}
        PHP);
    $write('resources/views/livewire/admin.blade.php', '<div>Admin</div>');
    $write('resources/views/livewire/u-r-l-parser.blade.php', '<div>URL</div>');
    $write('resources/views/other/custom.blade.php', '<div>Custom</div>');
    $write('resources/views/components/post/⚡create.blade.php', '<div>Create</div>');
    $write('resources/views/components/stats/stats.blade.php', '<div>Stats</div>');
    $write('resources/views/components/stats/stats.php', '<?php // v4 multi-file component');

    $config(3);
    $v3 = new LivewireConventionalComponentCatalog($root);
    $assert(
        $v3->component('admin')['viewPath'] === 'resources/views/livewire/admin.blade.php',
        'v3 Index convention failed',
    );
    $assert(
        $v3->component('custom')['viewPath'] === 'resources/views/other/custom.blade.php',
        'Literal render view failed',
    );
    $assert($v3->component('dynamic')['viewPath'] === null, 'Dynamic render must not claim a view');
    $assert(
        $v3->component('u-r-l-parser')['class'] === 'App\\Livewire\\URLParser',
        'Laravel Str::kebab acronym mapping failed',
    );
    $assert($v3->contains('missing') === null, 'Absence must remain unknown');
    $assert($v3->component('post.create') === null, 'v4 view roots must not leak into v3');

    $config(4);
    $v4 = new LivewireConventionalComponentCatalog($root);
    $assert(
        $v4->component('post.create')['viewPath'] === 'resources/views/components/post/⚡create.blade.php',
        'v4 Blade file mapping failed',
    );
    $assert($v4->component('stats.stats') === null, 'v4 multi-file component must defer');
    $assert(
        $v4->component('custom')['viewPath'] === 'resources/views/other/custom.blade.php',
        'v4 literal class render failed',
    );
    $assert($v4->component('u-r-l-parser')['viewPath'] === null, 'v4 class view convention must not be assumed');
    $assert($v4->component('chart')['class'] === 'App\\Widgets\\Chart', 'Candidate must retain its discovering root');
    $assert($v4->component('widgets.chart') === null, 'Earlier namespace root must not rename another root');
    $assert($v4->component('dynamic')['viewPath'] === null, 'v4 dynamic render must not claim a view');
    $assert($v4->component('admin.index') === null, 'Unproven v4 Index alias must defer');

    $write('app/Livewire/Admin.php', <<<'PHP'
        <?php
        namespace App\Livewire;
        class Admin extends \Livewire\Component {}
        PHP);
    $config(3);
    $assert(
        (new LivewireConventionalComponentCatalog($root))->components() === null,
        'Ambiguous class aliases must defer',
    );
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

echo "Livewire conventional component catalog checks passed.\n";
