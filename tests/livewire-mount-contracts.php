<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\LivewireMountContractCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$root = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-livewire-mount-'.bin2hex(random_bytes(8));
mkdir($root.'/app/Livewire', 0777, true);
$write = static function (string $path, string $source) use ($root): void {
    file_put_contents($root.'/'.$path, $source);
};
$catalog = static function (int $version, array $selected, ?int $registrationVersion = null) use (
    $root,
): LivewireMountContractCatalog {
    file_put_contents($root.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'livewire-components' => [
                    'version' => $registrationVersion ?? $version,
                    'files' => ['registrations.php'],
                ],
                'livewire-mount-contracts' => ['version' => $version, 'components' => $selected],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    return new LivewireMountContractCatalog($root);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

$write('registrations.php', <<<'PHP'
    <?php
    use Livewire\Livewire;
    Livewire::component('posts.show', \App\Livewire\ShowPost::class);
    Livewire::component('counter', \App\Livewire\Counter::class);
    PHP);
$write('app/Livewire/ShowPost.php', <<<'PHP'
    <?php
    namespace App\Livewire;
    use Livewire\Component;
    use App\Models\Post;
    class ShowPost extends Component {
        public Post $post;
        public ?string $heading = null;
        public function mount(Post $post, string $heading = 'Default', ...$extras): void {
            $this->post = $post;
            file_put_contents(__DIR__.'/executed', 'bad');
        }
    }
    PHP);
$write('app/Livewire/Counter.php', <<<'PHP'
    <?php
    namespace App\Livewire;
    class Counter extends \Livewire\Component {
        public int $count = 0;
    }
    PHP);
$selected = [
    ['name' => 'posts.show', 'file' => 'app/Livewire/ShowPost.php'],
    ['name' => 'counter', 'file' => 'app/Livewire/Counter.php'],
];
$v3 = $catalog(3, $selected);
$post = $v3->component('posts.show');
$assert(
    $post !== null
    && $post['class'] === 'App\\Livewire\\ShowPost'
    && str_ends_with(str_replace('\\', '/', $post['file']), '/app/Livewire/ShowPost.php')
    && $post['mount']['parameters'][0] === [
        'name' => 'post',
        'type' => 'App\\Models\\Post',
        'hasDefault' => false,
        'variadic' => false,
        'line' => 8,
    ]
    && $post['mount']['parameters'][1]['hasDefault'] === true
    && $post['mount']['parameters'][2]['variadic'] === true
    && $post['declaredPublicProperties']['post']['type'] === 'App\\Models\\Post'
    && $post['declaredPublicProperties']['heading']['type'] === '?string'
    && ! file_exists($root.'/app/Livewire/executed'),
    'source-only metadata preserves native declaration types and defaults without executing mount',
);
$assert(
    $v3->component('counter')['mount'] === null
    && $v3->component('counter')['declaredPublicProperties']['count']['type'] === 'int'
    && $v3->component('unselected') === null,
    'absent mount and unselected names remain unknown while public property metadata stays positive',
);
$assert(
    $catalog(4, $selected)->component('posts.show')['mount']['parameters'][0]['type'] === 'App\\Models\\Post',
    'the same explicit registration shape provides positive v4 declaration metadata',
);
$assert($catalog(4, $selected, 3)->components() === null, 'major-version mismatch invalidates contracts');
$assert(
    $catalog(3, [['name' => 'wrong', 'file' => 'app/Livewire/ShowPost.php']])->components() === null,
    'a contract cannot claim an unregistered component',
);
$assert(
    $catalog(3, [['name' => 'posts.show', 'file' => 'app/Livewire/Counter.php']])->components() === null,
    'source class must match the effective explicit registration',
);
$write('app/Livewire/ShowPost.php', str_replace(
    'class ShowPost extends Component {',
    'class ShowPost extends Component { use SomeTrait;',
    file_get_contents($root.'/app/Livewire/ShowPost.php'),
));
$assert(
    $catalog(3, $selected)->components() === null,
    'trait-supplied hooks or properties require separate source proof',
);
$write(
    'app/Livewire/ShowPost.php',
    '<?php namespace App\\Livewire; class ShowPost extends \\Livewire\\Component { public function mount(int $id): void {} }',
);
$assert(
    $catalog(3, $selected)->component('posts.show')['mount']['parameters'][0]['type'] === 'int',
    'a fresh snapshot observes changed source',
);

foreach (['app/Livewire/ShowPost.php', 'app/Livewire/Counter.php', 'registrations.php', 'composer.json'] as $path) {
    unlink($root.'/'.$path);
}
rmdir($root.'/app/Livewire');
rmdir($root.'/app');
rmdir($root);
echo "Livewire mount contract checks passed.\n";
