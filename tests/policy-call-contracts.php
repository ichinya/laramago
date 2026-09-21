<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-policy-contracts-'.bin2hex(random_bytes(8));
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

file_put_contents($workspace.'/app/Policies/Policies.php', <<<'PHP'
    <?php
    namespace App;
    class Post {}
    class Other {}
    class ChildPost extends Post {}
    class Team {}
    class PostPolicy {
        public function update(?object $user, Post $post, Team $team): bool { return true; }
        public function create(?object $user, Team $team): bool { return true; }
        public function optional(?object $user, Post $post, ?Team $team = null): bool { return true; }
        public function scalar(?object $user, Post $post, int $count): bool { return true; }
        public function variadic(?object $user, Post $post, Team ...$teams): bool { return true; }
        public function reference(?object $user, Post &$post): bool { return true; }
        /** @param ChildPost $post */
        public function documented(?object $user, Post $post): bool { return true; }
    }
    class BeforePolicy extends PostPolicy {
        public function before(?object $user, string $ability): ?bool { return true; }
    }
    class MagicPolicy { public function __call(string $name, array $arguments): bool { return true; } }
    PHP);
$nativePath = $framework.'/Auth/Access/Gate.php';
$nativeSource = file_get_contents($nativePath);
$nativeSource = str_replace("\r\n", "\n", $nativeSource);
$nativeSource = str_replace(
    "class Gate\n{",
    "class Gate\n{\n    public function forUser(mixed \$user): static { return \$this; }",
    $nativeSource,
);
file_put_contents($nativePath, $nativeSource);
$source = <<<'PHP'
    <?php
    use App\{Post, ChildPost, Other, Team};
    use Illuminate\Support\Facades\Gate;
    function cases(mixed $unknown, string $dynamic, array $spread, \Illuminate\Auth\Access\Gate $gate): void {
        Gate::allows('update', [new Post(), new Team()]);
        Gate::allows('update', [new ChildPost(), new Team()]);
        Gate::allows('update', [new Other(), new Team()]);
        Gate::allows('update', [new Post(), new Other()]);
        Gate::allows('update', new Post());
        Gate::allows('update', Post::class);
        Gate::allows('create', [Post::class, new Team()]);
        Gate::allows('create', [Post::class, new Other()]);
        Gate::allows('create', [Post::class, 'wrong']);
        Gate::allows('optional', new Post());
        Gate::allows('scalar', [new Post(), '12']);
        Gate::allows('scalar', [new Post(), $unknown]);
        Gate::allows('variadic', [new Post(), new Team()]);
        Gate::allows('update', [$unknown, new Other()]);
        Gate::allows('update', [new Post(), $unknown]);
        Gate::allows('update', [new Post(), ...$spread]);
        Gate::allows($dynamic, new Other());
        Gate::allows('missing', new Post());
    Gate::allows('reference', new Other());
    Gate::allows('documented', new Post());
    $gate->forUser(null)->allows('update', Post::class);
    }
    PHP;
file_put_contents($workspace.'/cases.php', $source);
$composer = static function (bool $enabled, string $policy = 'App\\PostPolicy', bool $assumptions = true) use (
    $workspace,
): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'policy-call-contracts' => [
                    'diagnose' => $enabled,
                    'native-dispatch' => $assumptions,
                    'authenticated-user' => true,
                    'no-intercepting-callbacks' => true,
                    'policies' => ['App\\Post' => $policy, 'App\\Other' => $policy, 'App\\ChildPost' => $policy],
                ],
            ],
        ],
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
        if (($issue['code'] ?? null) !== 'ichinya/laramago/laramago-policy-call-contract') {
            $unexpected[] = $issue['code'] ?? null;
            continue;
        }
        if (($issue['level'] ?? null) !== 'Warning') {
            throw new RuntimeException('Policy contract diagnostic must be a Warning; inspect '.$workspace);
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
$expected = ['new Other()', 'new Other()', 'new Post()', 'Post::class', 'Post::class', 'new Other()', "'wrong'"];
$literals = array_column($found, 'literal');
sort($expected);
sort($literals);
if ($literals !== $expected) {
    throw new RuntimeException('Policy contract mismatch: '.json_encode($found).'; inspect '.$workspace);
}
echo
    "PASS: native Gate model, additional argument, class selector, required parameter contracts and unknown/scalar suppression\n"
;
$composer(false);
if ($analyze('disabled') !== []) {
    throw new RuntimeException('Disabled contracts emitted diagnostics.');
}
$composer(true, assumptions: false);
if ($analyze('unasserted') !== []) {
    throw new RuntimeException('Incomplete dispatch assumptions emitted diagnostics.');
}
$composer(true, 'App\\BeforePolicy');
if ($analyze('before') !== []) {
    throw new RuntimeException('Intercepting policy before must defer.');
}
$composer(true, 'App\\MagicPolicy');
if ($analyze('magic') !== []) {
    throw new RuntimeException('Magic policy must defer.');
}
echo "PASS: opt-in, dispatch assumptions, inherited interception, and magic dispatch suppression\n";
echo 'Fixture: '.$workspace."\n";

$composer(true);
file_put_contents($nativePath, str_replace(
    'public function allows($ability, $arguments = [])',
    'public function allows($ability, $arguments)',
    $nativeSource,
));
if ($analyze('changed-native') !== []) {
    throw new RuntimeException('Changed native dispatch must defer.');
}
file_put_contents($nativePath, $nativeSource);
$config = json_decode(file_get_contents($workspace.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$config['extra']['laramago']['binding-files'] = ['bootstrap/bindings.php'];
file_put_contents(
    $workspace.'/bootstrap/bindings.php',
    '<?php \app()->bind(\App\PostPolicy::class, \App\BeforePolicy::class);',
);
file_put_contents($workspace.'/composer.json', json_encode($config, JSON_THROW_ON_ERROR));
if ($analyze('replacement') !== []) {
    throw new RuntimeException('Visible policy replacement must defer.');
}
echo "PASS: changed native signature and container replacement defer\n";
