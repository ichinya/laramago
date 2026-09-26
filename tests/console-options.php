<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago console inputs '.bin2hex(random_bytes(8));
$framework = $workspace.'/laravel/framework/src/Illuminate';
foreach (['Console/Concerns', 'Console', 'Console/Attributes', 'Contracts/Support'] as $folder) {
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
mkdir($workspace.'/symfony/console/Input', 0777, true);
file_put_contents($framework.'/Console/Parser.php', <<<'PHP'
    <?php
    namespace Illuminate\Console;
    use Symfony\Component\Console\Input\InputArgument;
    use Symfony\Component\Console\Input\InputOption;
    class Parser {
        public static function parse(string $expression) {
            if (preg_match_all('/\{\s*(.*?)\s*\}/', $expression, $matches) && count($matches[1])) {
                return array_merge(['name'], static::parameters($matches[1]));
            }
            return ['name', [], []];
        }
        protected static function parameters(array $tokens): array {
            $arguments = [];
            $options = [];
            foreach ($tokens as $token) {
                if (preg_match('/^-{2,}(.*)/', $token, $matches)) { $options[] = static::parseOption($matches[1]); }
                else { $arguments[] = static::parseArgument($token); }
            }
            return [$arguments, $options];
        }
        protected static function parseOption(string $token) {
            return match (true) {
                str_ends_with($token, '=') => new InputOption($token, null, InputOption::VALUE_OPTIONAL),
                default => new InputOption($token, null, InputOption::VALUE_NONE),
            };
        }
        protected static function parseArgument(string $token) {
            [$token, $description] = static::extractDescription($token);
            return match (true) {
                str_ends_with($token, '?*') => new InputArgument(trim($token, '?*'), InputArgument::IS_ARRAY, $description),
                str_ends_with($token, '*') => new InputArgument(trim($token, '*'), InputArgument::IS_ARRAY | InputArgument::REQUIRED, $description),
                str_ends_with($token, '?') => new InputArgument(trim($token, '?'), InputArgument::OPTIONAL, $description),
                (bool) preg_match('/(.+)\=\*(.+)/', $token, $matches) => new InputArgument($matches[1], InputArgument::IS_ARRAY, $description, preg_split('/,\s?/', $matches[2])),
                (bool) preg_match('/(.+)\=(.+)/', $token, $matches) => new InputArgument($matches[1], InputArgument::OPTIONAL, $description, $matches[2]),
                default => new InputArgument($token, InputArgument::REQUIRED, $description),
            };
        }
        protected static function extractDescription(string $token) {
            $parts = preg_split('/\s+:\s+/', trim($token), 2);
            return count($parts) === 2 ? $parts : [$token, ''];
        }
    }
    class Definition { public function addOptions(array $options): void {} public function addArguments(array $arguments): void {} }
    PHP);
file_put_contents($workspace.'/symfony/console/Input/InputOption.php', <<<'PHP'
    <?php
    namespace Symfony\Component\Console\Input;
    class InputOption {
        public const VALUE_NONE = 1;
        public const VALUE_OPTIONAL = 4;
        private mixed $default;
        public function __construct($name, $shortcut = null, $mode = null, mixed $default = null) { $this->setDefault($default); }
        public function acceptValue(): bool { return true; }
        public function isNegatable(): bool { return false; }
        public function setDefault(mixed $default): void {
            $this->default = $this->acceptValue() || $this->isNegatable() ? $default : false;
        }
        public function getDefault(): mixed { return $this->default; }
    }
    PHP);
file_put_contents($workspace.'/symfony/console/Input/InputArgument.php', <<<'PHP'
    <?php
    namespace Symfony\Component\Console\Input;
    class InputArgument {
        public const REQUIRED = 1;
        public const OPTIONAL = 2;
        public const IS_ARRAY = 4;
        private mixed $default;
        public function __construct($name, $mode = null, $description = '', mixed $default = null) { $this->setDefault($default); }
        public function isRequired(): bool { return false; }
        public function isArray(): bool { return false; }
        public function setDefault(mixed $default): void {
            if ($this->isArray() && $default === null) { $default = []; }
            $this->default = $default;
        }
        public function getDefault(): mixed { return $this->default; }
    }
    PHP);
file_put_contents($workspace.'/symfony/console/Input/Input.php', <<<'PHP'
    <?php
    namespace Symfony\Component\Console\Input;
    class Input {
        protected array $options = [];
        protected array $arguments = [];
        protected Definition $definition;
        public function getOption(string $name): mixed {
            return array_key_exists($name, $this->options) ? $this->options[$name] : $this->definition->getOption($name)->getDefault();
        }
        public function getArgument(string $name): mixed {
            return $this->arguments[$name] ?? $this->definition->getArgument($name)->getDefault();
        }
    }
    class Definition {
        public function getOption(string $name): InputOption { return new InputOption($name); }
        public function getArgument(string $name): InputArgument { return new InputArgument($name); }
    }
    PHP);
file_put_contents($framework.'/Console/Concerns/InteractsWithIO.php', <<<'PHP'
    <?php
    namespace Illuminate\Console\Concerns;
    interface Input {
        public function getOptions(): array;
        public function getArguments(): array;
        public function getOption(string $key): mixed;
        public function getArgument(string $key): mixed;
        public function setOption(string $key, mixed $value): void;
        public function setArgument(string $key, mixed $value): void;
    }
    trait InteractsWithIO {
        protected Input $input;
        public function option($key = null) {
            if (is_null($key)) { return $this->input->getOptions(); }
            return $this->input->getOption($key);
        }
        public function argument($key = null) {
            if (is_null($key)) { return $this->input->getArguments(); }
            return $this->input->getArgument($key);
        }
    }
    PHP);
file_put_contents($framework.'/Console/Command.php', <<<'PHP'
    <?php
    namespace Illuminate\Console;
    class Command {
        use Concerns\InteractsWithIO;
        protected $signature;
        public function __construct() {
            $this->configureFromAttributes();
            if (isset($this->signature)) { $this->configureUsingFluentDefinition(); }
            $this->configureDefaults();
        }
        protected function configureFromAttributes() {}
        protected function configureUsingFluentDefinition() {
            [$name, $arguments, $options] = Parser::parse($this->signature);
            $this->getDefinition()->addArguments($arguments);
            $this->getDefinition()->addOptions($options);
        }
        protected function configureDefaults(): void {}
        protected function configureIsolation() {}
        public function getDefinition(): Definition { return new Definition; }
    }
    PHP);
file_put_contents($framework.'/Console/Attributes/Signature.php', <<<'PHP'
    <?php
    namespace Illuminate\Console\Attributes;
    #[\Attribute] class Signature { public function __construct(public string $signature) {} }
    PHP);
$cases = [
    'flag' => "class Flag extends Command { protected \$signature = 'example {--dry-run : Dry run}'; public function probe(): bool { return \$this->option('dry-run'); } }",
    'value' => "class Value extends Command { protected \$signature = 'example {--name= : Name}'; public function probe(): ?string { return \$this->option('name'); } }",
    'named' => "class Named extends Command { protected \$signature = 'example {--dry-run}'; public function probe(): bool { return \$this->option(key: 'dry-run'); } }",
    'dynamic-key' => "class DynamicKey extends Command { protected \$signature = 'example {--dry-run}'; public function probe(string \$key): bool { return \$this->option(\$key); } }",
    'bad-type' => "class BadType extends Command { protected \$signature = 'example {--dry-run}'; public function probe(): string { return \$this->option('dry-run'); } }",
    'missing' => "class Missing extends Command { protected \$signature = 'example {--known}'; public function probe(): bool { return \$this->option('typo'); } }",
    'array' => "class ArrayOption extends Command { protected \$signature = 'example {--names=*}'; public function probe(): ?string { return \$this->option('names'); } }",
    'dynamic-signature' => "class DynamicSignature extends Command { protected \$signature = self::SIGNATURE; private const SIGNATURE = 'example {--dry-run}'; public function probe(): bool { return \$this->option('dry-run'); } }",
    'custom-constructor' => "class CustomConstructor extends Command { protected \$signature = 'example {--dry-run}'; public function __construct() {} public function probe(): bool { return \$this->option('dry-run'); } }",
    'custom-defaults' => "class CustomDefaults extends Command { protected \$signature = 'example {--dry-run}'; protected function configureDefaults(): void {} public function probe(): bool { return \$this->option('dry-run'); } }",
    'custom-definition' => "class CustomDefinition extends Command { protected \$signature = 'example {--dry-run}'; public function getDefinition(): Definition { return new Definition; } public function probe(): bool { return \$this->option('dry-run'); } }",
    'custom-option' => "class CustomOption extends Command { protected \$signature = 'example {--dry-run}'; public function option(\$key = null): string { return 'custom'; } public function probe(): string { return \$this->option('dry-run'); } }",
    'mutated-input' => "class MutatedInput extends Command { protected \$signature = 'example {--dry-run}'; public function mutate(): void { \$this->input->setOption('dry-run', 'wrong'); } public function probe(): bool { return \$this->option('dry-run'); } }",
    'trait-method' => "class TraitMethod extends Command { use MutatingTrait; protected \$signature = 'example {--dry-run}'; public function probe(): bool { return \$this->option('dry-run'); } }",
    'intermediate-parent' => "class IntermediateParent extends ParentCommand { protected \$signature = 'example {--dry-run}'; public function probe(): bool { return \$this->option('dry-run'); } }",
    'attribute-signature' => "#[\\Illuminate\\Console\\Attributes\\Signature('example {--other}')] class AttributeSignature extends Command { protected \$signature = 'example {--dry-run}'; public function probe(): bool { return \$this->option('dry-run'); } }",
    'magic-option' => "/** @method string option(string \$key) */ class MagicOption extends Command { protected \$signature = 'example {--dry-run}'; public function probe(): string { return \$this->option('dry-run'); } }",
    'required-argument' => "class RequiredArgument extends Command { protected \$signature = 'example {email : Address}'; public function probe(): string { return \$this->argument('email'); } }",
    'optional-argument' => "class OptionalArgument extends Command { protected \$signature = 'example {email?}'; public function probe(): ?string { return \$this->argument('email'); } }",
    'default-argument' => "class DefaultArgument extends Command { protected \$signature = 'example {email=admin@example.com}'; public function probe(): string { return \$this->argument('email'); } }",
    'array-argument' => "class ArrayArgument extends Command { protected \$signature = 'example {emails*}'; public function probe(): array { return \$this->argument('emails'); } }",
    'named-argument' => "class NamedArgument extends Command { protected \$signature = 'example {email}'; public function probe(): string { return \$this->argument(key: 'email'); } }",
    'wrong-argument-return' => "class WrongArgumentReturn extends Command { protected \$signature = 'example {email}'; public function probe(): int { return \$this->argument('email'); } }",
    'wrong-optional-argument-return' => "class WrongOptionalArgumentReturn extends Command { protected \$signature = 'example {email?}'; public function probe(): string { return \$this->argument('email'); } }",
    'wrong-array-argument-return' => "class WrongArrayArgumentReturn extends Command { protected \$signature = 'example {emails*}'; public function probe(): string { return \$this->argument('emails'); } }",
    'missing-argument' => "class MissingArgument extends Command { protected \$signature = 'example {email}'; public function probe(): string { return \$this->argument('typo'); } }",
    'dynamic-argument-key' => "class DynamicArgumentKey extends Command { protected \$signature = 'example {email}'; public function probe(string \$key): string { return \$this->argument(\$key); } }",
    'custom-argument' => "class CustomArgument extends Command { protected \$signature = 'example {email}'; public function argument(\$key = null): int { return 1; } public function probe(): int { return \$this->argument('email'); } }",
    'mutated-argument' => "class MutatedArgument extends Command { protected \$signature = 'example {email}'; public function mutate(): void { \$this->input->setArgument('email', 123); } public function probe(): string { return \$this->argument('email'); } }",
    'dynamic-argument-signature' => "class DynamicArgumentSignature extends Command { protected \$signature = self::SIGNATURE; private const SIGNATURE = 'example {email}'; public function probe(): string { return \$this->argument('email'); } }",
    'custom-argument-constructor' => "class CustomArgumentConstructor extends Command { protected \$signature = 'example {email}'; public function __construct() {} public function probe(): string { return \$this->argument('email'); } }",
    'invalid-argument-token' => "class InvalidArgumentToken extends Command { protected \$signature = 'example {email|other}'; public function probe(): string { return \$this->argument('email'); } }",
];
$prefix = <<<'PHP'
    <?php
    use Illuminate\Console\Command;
    use Illuminate\Console\Definition;
    trait MutatingTrait { public function change(): void { $this->input->setOption('dry-run', 'wrong'); } }
    class ParentCommand extends Command { public function change(): void { $this->input->setOption('dry-run', 'wrong'); } }
    PHP;
$source = $prefix."\n".implode("\n", array_values($cases))."\n";
file_put_contents($workspace.'/cases.php', $source);
$lines = [];
$line = substr_count($prefix, "\n") + 2;
foreach ($cases as $name => $_) {
    $lines[$name] = $line++;
}

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $disabled) use ($workspace, $package, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [$workspace.'/laravel', $workspace.'/symfony']],
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
$native = $summarize($run(true));
$adapted = $summarize($run(false));
foreach ($lines as $name => $line) {
    $actual = $adapted[$line] ?? [];
    $baseline = $native[$line] ?? [];
    if (in_array($name, [
        'flag', 'value', 'named', 'required-argument', 'optional-argument',
        'default-argument', 'array-argument', 'named-argument',
    ], true)) {
        if ($actual !== [] || $baseline === []) {
            throw new RuntimeException($name.' should gain its precise type; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif (in_array($name, [
        'bad-type', 'wrong-argument-return', 'wrong-optional-argument-return',
        'wrong-array-argument-return',
    ], true)) {
        if ($actual === [] || ($name !== 'bad-type' && $actual === $baseline)) {
            throw new RuntimeException('Wrong inferred input type must remain a specific diagnostic; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($actual !== $baseline) {
        throw new RuntimeException($name.' must retain native diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
}

$parserPath = $framework.'/Console/Parser.php';
$parserSource = file_get_contents($parserPath);
file_put_contents($parserPath, str_replace('InputOption::VALUE_NONE', 'InputOption::VALUE_OPTIONAL', $parserSource));
$changedParser = $summarize($run(false));
if (($changedParser[$lines['flag']] ?? []) !== ($native[$lines['flag']] ?? [])) {
    throw new RuntimeException('Changed parser must retain native option type; inspect '.$workspace);
}
file_put_contents($parserPath, $parserSource);
echo "PASS: changed parser defers\n";

file_put_contents($parserPath, str_replace(
    'new InputArgument($token, InputArgument::REQUIRED, $description)',
    'new InputArgument($token, InputArgument::OPTIONAL, $description)',
    $parserSource,
));
$changedArgumentParser = $summarize($run(false));
if (($changedArgumentParser[$lines['required-argument']] ?? []) !== ($native[$lines['required-argument']] ?? [])) {
    throw new RuntimeException('Changed argument parser must retain native type; inspect '.$workspace);
}
file_put_contents($parserPath, $parserSource);
echo "PASS: changed argument parser defers\n";

$symfonyPath = $workspace.'/symfony/console/Input/InputOption.php';
$symfonySource = file_get_contents($symfonyPath);
file_put_contents($symfonyPath, str_replace('? $default : false', '? $default : null', $symfonySource));
$changedInput = $summarize($run(false));
if (($changedInput[$lines['flag']] ?? []) !== ($native[$lines['flag']] ?? [])) {
    throw new RuntimeException('Changed input default must retain native option type; inspect '.$workspace);
}
file_put_contents($symfonyPath, $symfonySource);
echo "PASS: changed input default defers\n";

$argumentPath = $workspace.'/symfony/console/Input/InputArgument.php';
$argumentSource = file_get_contents($argumentPath);
file_put_contents($argumentPath, str_replace('$default = [];', '$default = [123];', $argumentSource));
$changedArgumentDefault = $summarize($run(false));
if (($changedArgumentDefault[$lines['array-argument']] ?? []) !== ($native[$lines['array-argument']] ?? [])) {
    throw new RuntimeException('Changed argument default must retain native type; inspect '.$workspace);
}
file_put_contents($argumentPath, $argumentSource);
echo "PASS: changed argument default defers\n";

$commandPath = $framework.'/Console/Command.php';
$commandSource = file_get_contents($commandPath);
file_put_contents($commandPath, str_replace(
    'protected function configureDefaults(): void {}',
    'protected function configureDefaults(): void { $this->signature = "changed"; }',
    $commandSource,
));
$changedDefaults = $summarize($run(false));
if (($changedDefaults[$lines['flag']] ?? []) !== ($native[$lines['flag']] ?? [])) {
    throw new RuntimeException('Changed framework defaults must retain native option type; inspect '.$workspace);
}
file_put_contents($commandPath, $commandSource);
echo "PASS: changed framework defaults defer\n";

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
