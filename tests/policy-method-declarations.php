<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-policy-methods-'.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
mkdir($framework.'/Support/Facades', 0777, true);
mkdir($framework.'/Contracts/Auth/Access', 0777, true);
mkdir($framework.'/Auth/Access', 0777, true);
mkdir($workspace.'/app/Providers', 0777, true);
mkdir($workspace.'/app/Policies', 0777, true);
mkdir($workspace.'/bootstrap', 0777, true);
copy(__DIR__.'/fixtures/analysis/policy-gate-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(__DIR__.'/fixtures/analysis/policy-gate-facade.php.stub', $framework.'/Support/Facades/Gate.php');
copy(__DIR__.'/fixtures/analysis/policy-gate-contract.php.stub', $framework.'/Contracts/Auth/Access/Gate.php');
copy(__DIR__.'/fixtures/analysis/policy-gate-native.php.stub', $framework.'/Auth/Access/Gate.php');

file_put_contents($workspace.'/app/Providers/AuthServiceProvider.php', <<<'PHP'
    <?php
    namespace App\Providers;
    use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
    use App\Models\ExplicitModel;
    use App\Models\MagicModel;
    use App\Models\Post;
    use App\Models\ProtectedModel;
    use App\Policies\ExplicitPolicy;
    use App\Policies\MagicPolicy;
    use App\Policies\PostPolicy;
    use App\Policies\ProtectedPolicy;
    class AuthServiceProvider extends ServiceProvider {
        protected $policies = [
            Post::class => PostPolicy::class,
            MagicModel::class => MagicPolicy::class,
            ProtectedModel::class => ProtectedPolicy::class,
            ExplicitModel::class => ExplicitPolicy::class,
        ];
    }
    PHP);

file_put_contents($workspace.'/app/Policies/Policies.php', <<<'PHP'
    <?php
    namespace App\Models;
    class Post {}
    class MagicModel {}
    class ProtectedModel {}
    class ExplicitModel {}

    namespace App\Policies;
    class BasePolicy {
        public function inherited(?object $user, \App\Models\Post $post): bool { return true; }
    }
    class PostPolicy extends BasePolicy {
        public function update(?object $user, \App\Models\Post $post): bool { return true; }
        public function publishPost(?object $user, \App\Models\Post $post): bool { return true; }
        public function publishPostNow(?object $user, \App\Models\Post $post): bool { return true; }
        public static function staticAction(?object $user, \App\Models\Post $post): bool { return true; }
        public function before(?object $user, string $ability): ?bool { return null; }
    }
    class MagicPolicy {
        public function __call(string $method, array $arguments): bool { return true; }
    }
    class ProtectedPolicy {
        protected function protectedAction(?object $user, \App\Models\ProtectedModel $model): bool { return true; }
    }
    class ExplicitPolicy {}
    class ReplacementPolicy {
        public function missing(?object $user, \App\Models\Post $post): bool { return true; }
    }
    PHP);

file_put_contents($workspace.'/bootstrap/gates.php', <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Gate;
    Gate::define('explicit-definition', static fn (): bool => true);
    PHP);

$source = <<<'PHP'
    <?php
    use App\Models\ExplicitModel;
    use App\Models\MagicModel;
    use App\Models\Post;
    use App\Models\ProtectedModel;
    use Illuminate\Auth\Access\Gate as NativeGate;
    use Illuminate\Support\Facades\CustomGate;
    use Illuminate\Support\Facades\Gate;
    function cases(NativeGate $gate, string $dynamic): void {
        Gate::allows('update', new Post());
        Gate::allows('UPDATE', Post::class);
        Gate::allows('publish-post', Post::class);
        Gate::allows('publish-post_now', [Post::class]);
        Gate::allows('staticAction', Post::class);
        Gate::allows('inherited', new Post());
        Gate::allows('missing', Post::class);
        Gate::authorize(ability: 'absent-named', arguments: [Post::class]);
        Gate::inspect('magic-action', MagicModel::class);
        Gate::denies('protected-action', ProtectedModel::class);
        Gate::check('explicit-definition', ExplicitModel::class);
        Gate::any(['missing'], Post::class);
        Gate::none($dynamic, Post::class);
        Gate::allows('publish_post', Post::class);
        CustomGate::allows('custom-missing', Post::class);
        $gate->raw('direct-missing', new Post());
    }
    PHP;
file_put_contents($workspace.'/cases.php', $source);

$composer = static function (bool $enabled, bool $bindings = false) use ($workspace): void {
    $laramago = [
        'policy-sources' => [[
            'file' => 'app/Providers/AuthServiceProvider.php',
            'provider' => 'App\\Providers\\AuthServiceProvider',
        ]],
        'policy-method-declarations' => ['diagnose' => $enabled],
        'gate-definitions' => ['files' => ['bootstrap/gates.php'], 'complete' => false],
    ];
    if ($bindings) {
        $laramago['binding-files'] = ['bootstrap/bindings.php'];
    }
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => ['laramago' => $laramago],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};
$composer(true);

file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php', 'app/Policies', 'vendor/laravel/framework/src/Illuminate'],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 2,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function (string $name) use ($command, $workspace, $source): array {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$name.'.json', 'w'],
            2 => ['file', $workspace.'/'.$name.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$name.'.log');
    if (
        ! in_array($exit, [0, 1], true)
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            $stderr,
        )
    ) {
        throw new RuntimeException('Mago failed; inspect '.$workspace.'/'.$name.'.log');
    }
    $report = json_decode(file_get_contents($workspace.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $found = [];
    $unexpected = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if (($issue['code'] ?? null) !== 'ichinya/laramago/laramago-policy-method-declaration') {
            $unexpected[] = $issue['code'] ?? null;
            continue;
        }
        if (($issue['level'] ?? null) !== 'Note') {
            throw new RuntimeException('Policy declaration advisory must remain a Note; inspect '.$workspace);
        }
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $item): bool => $item['kind'] === 'Primary',
        ))[0];
        $span = $primary['span'];
        $found[] = [
            'literal' => substr($source, $span['start']['offset'], $span['end']['offset'] - $span['start']['offset']),
            'message' => $issue['message'] ?? '',
        ];
    }
    if ($unexpected !== []) {
        throw new RuntimeException('Unexpected Mago issues: '.json_encode($unexpected).'; inspect '.$workspace);
    }

    return $found;
};

$found = $analyze('enabled');
$literals = array_column($found, 'literal');
$expected = ["'missing'", "'absent-named'", "'protected-action'", "'publish_post'", "'direct-missing'"];
sort($literals);
sort($expected);
if ($literals !== $expected) {
    throw new RuntimeException('Policy method notes mismatch: '.json_encode($found).'; inspect '.$workspace);
}
foreach ($found as $record) {
    if (
        ! str_contains($record['message'], 'declaration-quality note')
        || ! str_contains($record['message'], 'does not establish a runtime authorization failure')
        || ! str_contains($record['message'], 'magic dispatch')
        || ! str_contains($record['message'], 'guest handling')
    ) {
        throw new RuntimeException('Policy note overstates runtime certainty; inspect '.$workspace);
    }
}
echo "PASS: native Gate calls, Mago method metadata, exact normalization, and qualified notes\n";

$composer(false);
if ($analyze('disabled') !== []) {
    throw new RuntimeException('Disabled policy emitted notes; inspect '.$workspace);
}
echo "PASS: explicit advisory opt-in\n";

$nativeGate = $framework.'/Auth/Access/Gate.php';
$nativeSource = file_get_contents($nativeGate);
$before = 'public function allows($ability, $arguments = [])';
$after = 'public function allows($ability, $arguments)';
if ($nativeSource === false || substr_count($nativeSource, $before) !== 1) {
    throw new RuntimeException('Native Gate fixture signature changed unexpectedly.');
}
file_put_contents($nativeGate, str_replace($before, $after, $nativeSource));
$composer(true);
file_put_contents($workspace.'/cases.php', <<<'PHP'
    <?php
    use App\Models\Post;
    use Illuminate\Support\Facades\Gate;
    Gate::allows('missing', Post::class);
    PHP);
if ($analyze('changed-native') !== []) {
    throw new RuntimeException('Changed native Gate signature must defer; inspect '.$workspace);
}
file_put_contents($nativeGate, $nativeSource);
echo "PASS: changed native Gate signature defers\n";

file_put_contents($workspace.'/bootstrap/bindings.php', <<<'PHP'
    <?php
    \app()->bind(\App\Policies\PostPolicy::class, \App\Policies\ReplacementPolicy::class);
    PHP);
$composer(true, true);
file_put_contents($workspace.'/cases.php', <<<'PHP'
    <?php
    use App\Models\Post;
    use Illuminate\Support\Facades\Gate;
    Gate::allows('missing', Post::class);
    PHP);
if ($analyze('replacement') !== []) {
    throw new RuntimeException('Known policy container replacement must defer; inspect '.$workspace);
}
echo "PASS: selected policy container replacement defers\n";

echo 'Fixture: '.$workspace."\n";
