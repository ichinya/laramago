<?php

declare(strict_types=1);

// Exercise the installed Mago worker against source-only Laravel contracts.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago artisan command '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = $workspace.'/laravel/framework/src/Illuminate';
foreach (['Support/Facades', 'Foundation/Console', 'Contracts/Console', 'Console', 'Foundation'] as $folder) {
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    class Facade {
        protected static function getFacadeAccessor() {}
        public static function getFacadeRoot() { return null; }
        protected static function resolveFacadeInstance($name) {}
        public static function __callStatic($method, $args) { return static::getFacadeRoot()->$method(...$args); }
    }
    PHP);
file_put_contents($framework.'/Support/Facades/Artisan.php', <<<'PHP'
    <?php
    namespace Illuminate\Support\Facades;
    /** @method static \Illuminate\Foundation\Console\ClosureCommand command(string $signature, \Closure $callback) */
    class Artisan extends Facade {
        protected static function getFacadeAccessor() { return \Illuminate\Contracts\Console\Kernel::class; }
    }
    PHP);
file_put_contents($framework.'/Contracts/Console/Kernel.php', <<<'PHP'
    <?php
    namespace Illuminate\Contracts\Console;
    interface Kernel {}
    PHP);
file_put_contents($framework.'/Foundation/Console/Kernel.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Console;
    use Closure;
    class Kernel implements \Illuminate\Contracts\Console\Kernel {
        public function command($signature, Closure $callback) {
            $command = new ClosureCommand($signature, $callback);
            return $command;
        }
    }
    PHP);
file_put_contents($framework.'/Console/Command.php', <<<'PHP'
    <?php
    namespace Illuminate\Console;
    class Command {
        public function comment(string $message): void {}
    }
    PHP);
file_put_contents($framework.'/Foundation/Console/ClosureCommand.php', <<<'PHP'
    <?php
    namespace Illuminate\Foundation\Console;
    class ClosureCommand extends \Illuminate\Console\Command {
        protected \Closure $callback;
        public function __construct(string $signature, \Closure $callback) { $this->callback = $callback; }
        public function execute(): int { return (int) ($this->callback->bindTo($this, $this))(); }
        public function purpose(string $description): static { return $this; }
    }
    PHP);
file_put_contents($workspace.'/other.php', <<<'PHP'
    <?php
    class Outside {
        /** @param \Closure $callback */
        public static function command(string $signature, \Closure $callback): void {}
    }
    class CustomArtisan extends \Illuminate\Support\Facades\Artisan {
        public static function command(string $signature, \Closure $callback): \Illuminate\Foundation\Console\ClosureCommand {
            return new \Illuminate\Foundation\Console\ClosureCommand($signature, $callback);
        }
    }
    class CustomKernel implements \Illuminate\Contracts\Console\Kernel {}
    PHP);
file_put_contents($workspace.'/cases.php', <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Artisan;
    Artisan::command('good', function (): void { $this->comment('works'); })->purpose('description');
    Artisan::command('missing', function (): void { $this->missing(); });
    Artisan::command('wrong', function (): void { $this->comment(12); });
    Artisan::command('static', static function (): void { $this->comment('never'); });
    Artisan::command('bad-argument', 123);
    Outside::command('outside', function (): void { $this->comment('unbound'); });
    CustomArtisan::command('custom', function (): void { $this->comment('unbound'); });
    PHP);

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $disabled, bool $customBinding) use ($workspace, $package, $command): array {
    if ($customBinding) {
        mkdir($workspace.'/bootstrap');
        file_put_contents($workspace.'/bootstrap/bindings.php', <<<'PHP'
            <?php
            app()->singleton(\Illuminate\Contracts\Console\Kernel::class, CustomKernel::class);
            PHP);
        file_put_contents($workspace.'/composer.json', json_encode([
            'extra' => ['laramago' => ['binding-files' => ['bootstrap/bindings.php']]],
        ], JSON_THROW_ON_ERROR));
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => ['other.php', $workspace.'/laravel'],
        ],
        'extension-hosts' => $disabled ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/report.json', 'w'],
        2 => ['file', $workspace.'/stderr.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
        throw new RuntimeException('Provider failure: '.$stderr);
    }

    return json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};

$summarize = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
        if ($primary['span']['file_id']['name'] === 'cases.php') {
            $result[$primary['span']['start']['line'] + 1][] = $issue['code'];
        }
    }
    foreach ($result as &$codes) {
        sort($codes);
    }

    return $result;
};
$native = $summarize($run(true, false));
$adapted = $summarize($run(false, false));
$custom = $summarize($run(false, true));
foreach ([3, 4, 5, 6, 7, 8, 9] as $line) {
    $expected = $native[$line] ?? [];
    if (in_array($line, [3, 4, 5, 6], true)) {
        $expected = match ($line) {
            3 => [],
            4 => ['non-existent-method'],
            5 => ['invalid-argument'],
            6 => ['semantics'],
        };
    }
    if (($adapted[$line] ?? []) !== $expected) {
        throw new RuntimeException('Unexpected adapted diagnostics at line '.$line.': '.json_encode($adapted[$line] ?? []).'; native '.json_encode($native[$line] ?? []).'; inspect '.$workspace);
    }
}
if (($custom[3] ?? []) !== ($native[3] ?? [])) {
    throw new RuntimeException('Custom kernel binding must retain native closure diagnostics; inspect '.$workspace);
}
unlink($workspace.'/composer.json');
unlink($workspace.'/bootstrap/bindings.php');
rmdir($workspace.'/bootstrap');
$facadePath = $framework.'/Support/Facades/Artisan.php';
$facadeSource = file_get_contents($facadePath);
file_put_contents($facadePath, str_replace(
    'class Artisan extends Facade {',
    'class Artisan extends Facade { public static function command(string $signature, \\Closure $callback): \\Illuminate\\Foundation\\Console\\ClosureCommand { return new \\Illuminate\\Foundation\\Console\\ClosureCommand($signature, $callback); }',
    $facadeSource,
));
$nativeFacade = $summarize($run(false, false));
if (($nativeFacade[3] ?? []) !== ($native[3] ?? [])) {
    throw new RuntimeException('Native facade method must retain native closure diagnostics; inspect '.$workspace);
}
file_put_contents($facadePath, $facadeSource);
file_put_contents($facadePath, str_replace(
    '\\Illuminate\\Contracts\\Console\\Kernel::class',
    '\\OtherKernel::class',
    $facadeSource,
));
$changedAccessor = $summarize($run(false, false));
if (($changedAccessor[3] ?? []) !== ($native[3] ?? [])) {
    throw new RuntimeException('Changed facade accessor must retain native closure diagnostics; inspect '.$workspace);
}
file_put_contents($facadePath, $facadeSource);
$commandPath = $framework.'/Foundation/Console/ClosureCommand.php';
$commandSource = file_get_contents($commandPath);
file_put_contents($commandPath, str_replace(
    '$this->callback->bindTo($this, $this)',
    '$this->callback->bindTo(null, null)',
    $commandSource,
));
$changedBinding = $summarize($run(false, false));
if (($changedBinding[3] ?? []) !== ($native[3] ?? [])) {
    throw new RuntimeException('Changed closure binding must retain native diagnostics; inspect '.$workspace);
}
echo "PASS: Artisan closure receiver, native errors, custom binding and unrelated calls\n";

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($resolved);
