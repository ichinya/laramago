<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago console inputs '.bin2hex(random_bytes(8));
$framework = $workspace.'/packages/laravel/framework/src/Illuminate';
foreach (['Console/Concerns', 'Console', 'Console/Attributes', 'Contracts/Support'] as $folder) {
    if (! is_dir($framework.'/'.$folder)) {
        mkdir($framework.'/'.$folder, 0777, true);
    }
}
mkdir($workspace.'/packages/symfony/console/Input', 0777, true);
file_put_contents($workspace.'/composer.json', json_encode([
    'config' => ['vendor-dir' => 'packages'],
    'autoload' => ['files' => ['bootstrap.php']],
    'extra' => ['laramago' => new stdClass],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap must not execute.");');
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
file_put_contents($workspace.'/packages/symfony/console/Input/InputOption.php', <<<'PHP'
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
file_put_contents($workspace.'/packages/symfony/console/Input/InputArgument.php', <<<'PHP'
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
file_put_contents($workspace.'/packages/symfony/console/Input/Input.php', <<<'PHP'
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
        /**
         * @param string|null $key
         * @return ($key is null ? array<array|string|float|int|bool|null> : array|string|float|int|bool|null)
         */
        public function option($key = null) {
            if (is_null($key)) { return $this->input->getOptions(); }
            return $this->input->getOption($key);
        }
        /**
         * @param string|null $key
         * @return ($key is null ? array<array|string|float|int|bool|null> : array|string|float|int|bool|null)
         */
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
    'union-values-ternary' => "class UnionValuesTernary extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-values-branch' => "class UnionValuesBranch extends Command { protected \$signature = 'example {input} {secondary?} {--alpha=} {--beta=} {--gamma=} {--ready}'; public function probe(bool \$choice): ?string { if (\$choice) { \$name = 'alpha'; } else { \$name = 'beta'; } return \$this->option(\$name); } }",
    'union-values-foreach' => "class UnionValuesForeach extends Command { protected \$signature = 'example {--alpha=} {--beta=} {--gamma=}'; public function probe(): void { foreach (['alpha', 'beta', 'gamma'] as \$name) { \$value = \$this->option(\$name); if (\$value === null) { continue; } consumeString(\$value); } } }",
    'union-values-named' => "class UnionValuesNamed extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): ?string { return \$this->option(key: \$choice ? 'alpha' : 'beta'); } }",
    'union-flags' => "class UnionFlags extends Command { protected \$signature = 'example {--alpha} {--beta}'; public function probe(bool \$choice): bool { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-mixed-valid' => "class UnionMixedValid extends Command { protected \$signature = 'example {--alpha=} {--ready}'; public function probe(bool \$choice): string|bool|null { return \$this->option(\$choice ? 'alpha' : 'ready'); } }",
    'union-flag-wrong' => "class UnionFlagWrong extends Command { protected \$signature = 'example {--alpha} {--beta}'; public function probe(bool \$choice): string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-value-wrong' => "class UnionValueWrong extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): int { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-value-null' => "class UnionValueNull extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-mixed-wrong' => "class UnionMixedWrong extends Command { protected \$signature = 'example {--alpha=} {--ready}'; public function probe(bool \$choice): void { \$value = \$this->option(\$choice ? 'alpha' : 'ready'); if (\$value !== null) { consumeString(\$value); } } }",
    'union-value-strong-consumer' => "class UnionValueStrongConsumer extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): void { \$value = \$this->option(\$choice ? 'alpha' : 'beta'); if (\$value !== null) { consumeExact(\$value); } } }",
    'union-unknown' => "class UnionUnknown extends Command { protected \$signature = 'example {--alpha=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'missing'); } }",
    'union-reserved' => "class UnionReserved extends Command { protected \$signature = 'example {--alpha=} {--help=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'help'); } }",
    'union-empty' => "class UnionEmpty extends Command { protected \$signature = 'example {--alpha=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : ''); } }",
    'union-null' => "class UnionNull extends Command { protected \$signature = 'example {--alpha=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : null); } }",
    'union-int' => "class UnionInt extends Command { protected \$signature = 'example {--alpha=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 9); } }",
    'union-bool' => "class UnionBool extends Command { protected \$signature = 'example {--alpha=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : false); } }",
    'union-general' => "class UnionGeneral extends Command { protected \$signature = 'example {--alpha=}'; public function probe(bool \$choice, string \$other): ?string { return \$this->option(\$choice ? 'alpha' : \$other); } }",
    'union-class-string' => "class UnionClassString extends Command { protected \$signature = 'example {--alpha=} {--SelectorName=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : SelectorName::class); } }",
    'single-class-string' => "class SingleClassString extends Command { protected \$signature = 'example {--SelectorName=}'; public function probe(): ?string { return \$this->option(SelectorName::class); } }",
    'union-argument-unchanged' => "class UnionArgumentUnchanged extends Command { protected \$signature = 'example {alpha} {beta}'; public function probe(bool \$choice): string { return \$this->argument(\$choice ? 'alpha' : 'beta'); } }",
    'union-array-option' => "class UnionArrayOption extends Command { protected \$signature = 'example {--alpha=} {--beta=*}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-default-option' => "class UnionDefaultOption extends Command { protected \$signature = 'example {--alpha=} {--beta=plain}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-shortcut-option' => "class UnionShortcutOption extends Command { protected \$signature = 'example {--alpha=} {--b|beta=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-duplicate-option' => "class UnionDuplicateOption extends Command { protected \$signature = 'example {--alpha=} {--beta=} {--beta=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-custom-defaults' => "class UnionCustomDefaults extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; protected function configureDefaults(): void {} public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-custom-constructor' => "class UnionCustomConstructor extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function __construct() {} public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-mutated-input' => "class UnionMutatedInput extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function mutate(): void { \$this->input->setOption('alpha', 17); } public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-trait' => "class UnionTrait extends Command { use MutatingTrait; protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-magic' => "/** @method int option(string \$key) */ class UnionMagic extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): ?string { return \$this->option(\$choice ? 'alpha' : 'beta'); } }",
    'union-explicit-local' => "class UnionExplicitLocal extends Command { protected \$signature = 'example {--alpha=} {--beta=}'; public function probe(bool \$choice): void { /** @var false \$value */ \$value = \$this->option(\$choice ? 'alpha' : 'beta'); consumeString(\$value); } }",
];
$budgetNames = array_map(static fn (int $index): string => 'label'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), range(1, 17));
$budgetSignature = 'example '.implode(' ', array_map(static fn (string $name): string => '{--'.$name.'=}', $budgetNames));
foreach ([16, 17] as $count) {
    $literals = implode(', ', array_map(static fn (string $name): string => "'".$name."'", array_slice($budgetNames, 0, $count)));
    $cases['union-budget-'.$count] = "class UnionBudget".$count." extends Command { protected \$signature = '".$budgetSignature."'; public function probe(): ?string { foreach ([".$literals."] as \$name) { return \$this->option(\$name); } return null; } }";
}
$prefix = <<<'PHP'
    <?php
    use Illuminate\Console\Command;
    use Illuminate\Console\Definition;
    trait MutatingTrait { public function change(): void { $this->input->setOption('dry-run', 'wrong'); } }
    class ParentCommand extends Command { public function change(): void { $this->input->setOption('dry-run', 'wrong'); } }
    class SelectorName {}
    function consumeString(string $value): void {}
    /** @param 'exact' $value */ function consumeExact(string $value): void {}
    function independentWrongType(): string { return 17; }
    PHP;
$source = $prefix."\n".implode("\n", array_values($cases))."\n";
file_put_contents($workspace.'/cases.php', $source);
$lines = [];
$line = substr_count($prefix, "\n") + 2;
foreach ($cases as $name => $_) {
    $lines[$name] = $line++;
}

$observerWorker = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$plugin = new class($argv[2], ($argv[3] ?? 'complete') === 'complete') implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root, private readonly bool $complete) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('console-union-fixture', 'Console union fixture', 'Observe real input domains and exact provider contracts');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\ConsoleOptionProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerMethodReturnTypeProvider(new class($provider, $this->root, $this->complete) implements \Mago\Sdk\Analyzer\MethodReturnTypeProvider {
            private array $checked = [];
            public function __construct(private readonly \Ichinya\Laramago\Analyzer\ConsoleOptionProvider $provider, private readonly string $root, private readonly bool $complete) {}
            public function getTargets(): array { return $this->provider->getTargets(); }
            public function getReturnType(\Mago\Sdk\Analyzer\ReturnTypeProviderContext $context): ?\Mago\Sdk\Analyzer\Type {
                $result = $this->provider->getReturnType($context);
                $call = $context->invocation;
                $class = $call->receiverType?->atomicTypes[0] ?? null;
                $name = $class instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType ? $class->name : '';
                if (!isset($this->checked[$name])) {
                    $this->checked[$name] = true;
                    $type = \Mago\Sdk\Analyzer\Type::class;
                    $expected = match ($name) {
                        'UnionFlags', 'UnionFlagWrong' => $type::bool(),
                        'UnionMixedValid', 'UnionMixedWrong' => $type::union($type::bool(), $type::string(), $type::null()),
                        'UnionValuesTernary', 'UnionValuesBranch', 'UnionValuesForeach', 'UnionValuesNamed',
                        'UnionValueWrong', 'UnionValueNull', 'UnionValueStrongConsumer', 'UnionExplicitLocal', 'SingleClassString', 'UnionBudget16' => $type::union($type::string(), $type::null()),
                        default => null,
                    };
                    if ($this->complete && $expected !== null && ($result === null || !$context->types->equals($result, $expected))) { throw new \RuntimeException('Exact native provider result failed for '.$name); }
                    $argument = $call->arguments[0] ?? null;
                    $atoms = [];
                    foreach ($argument?->type?->atomicTypes ?? [] as $atom) {
                        $atoms[] = [
                            'atom' => $atom::class,
                            'kind' => $atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType ? $atom->kind->name : null,
                            'refinement' => $atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType && is_object($atom->refinement) ? $atom->refinement::class : null,
                            'literal' => $atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType && $atom->refinement instanceof \Mago\Sdk\Analyzer\Type\StringType ? $atom->refinement->literalValue : null,
                        ];
                    }
                    file_put_contents($this->root.'/observations.jsonl', json_encode([
                        'class' => $name, 'method' => $call->name, 'span' => [$call->span->start, $call->span->end],
                        'arguments' => count($call->arguments), 'atoms' => $atoms, 'result' => $result?->__toString(), 'exactDomainVerified' => $this->complete && $expected !== null,
                    ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
                    if ($name === 'UnionValuesBranch' && $result !== null) { $this->controls($context, $result); }
                    if ($name === 'UnionBudget16' && $result !== null) { $this->budgetControls($context); }
                }
                return $result;
            }
            private function evaluate(\Mago\Sdk\Analyzer\ReturnTypeProviderContext $context, ?\Mago\Sdk\Analyzer\Type $type, ?string $argumentName = null, bool $unpacked = false, bool $placeholder = false, ?\Mago\Sdk\Analyzer\Type $receiver = null, ?array $arguments = null, ?string $method = null, ?\Mago\Sdk\Analyzer\InvocationKind $kind = null): ?\Mago\Sdk\Analyzer\Type {
                $call = $context->invocation; $argument = $call->arguments[0]; $kind ??= $call->kind;
                $invocation = new \Mago\Sdk\Analyzer\Invocation(
                    $kind, $method ?? $call->name, $kind === \Mago\Sdk\Analyzer\InvocationKind::Function ? null : $call->declaringClass,
                    $kind === \Mago\Sdk\Analyzer\InvocationKind::Function ? null : ($receiver ?? $call->receiverType), $call->span,
                    $arguments ?? [new \Mago\Sdk\Analyzer\Argument($argumentName, $unpacked, $placeholder, $argument->span, $argument->expression, $type)],
                );
                return (new \Ichinya\Laramago\Analyzer\ConsoleOptionProvider($this->root))->getReturnType(new \Mago\Sdk\Analyzer\ReturnTypeProviderContext(
                    $context->phpVersion, $context->codebase, $invocation, $context->types, $context->cancellation,
                ));
            }
            private function controls(\Mago\Sdk\Analyzer\ReturnTypeProviderContext $context, \Mago\Sdk\Analyzer\Type $result): void {
                $type = \Mago\Sdk\Analyzer\Type::class;
                $nullable = $type::union($type::string(), $type::null());
                if (!$context->types->equals($result, $nullable)) { throw new \RuntimeException('Branch union lost its exact string/null result.'); }
                $alpha = $type::literalString('alpha'); $beta = $type::literalString('beta');
                $ordinary = $type::union($alpha, $beta); $checks = 1;
                $nativeOption = $context->codebase->getDeclaringMethod('Illuminate\\Console\\Concerns\\InteractsWithIO', 'option');
                $parameter = $nativeOption?->parameters[0] ?? null;
                $nativeReturn = $nativeOption?->returnType;
                $conditional = $nativeReturn?->type->atomicTypes[0] ?? null;
                if ($parameter?->name !== '$key' || !$parameter->type?->fromDocblock || !$context->types->equals($parameter->type->type, $nullable)
                    || !$nativeReturn?->fromDocblock || count($nativeReturn->type->atomicTypes) !== 1 || !$conditional instanceof \Mago\Sdk\Analyzer\Type\ConditionalType
                    || $conditional->negated || !$context->types->equals($conditional->target, $type::null())) { throw new \RuntimeException('Native IO fixture PHPDoc was not analyzed as its real key/conditional-return contract.'); }
                foreach ([$type::string(), $type::bool(), $type::int(), $type::float(), $type::null(), $type::list($type::mixed())] as $member) {
                    if (!$context->types->isContainedBy($member, $conditional->otherwise)) { throw new \RuntimeException('Native IO broad return lost an independently required member.'); }
                }
                file_put_contents($this->root.'/native-io-contract.json', json_encode(['key' => (string) $parameter->type->type, 'returnAtom' => $conditional::class, 'otherwise' => (string) $conditional->otherwise, 'otherwiseAtoms' => array_map(static fn ($atom): string => $atom::class, $conditional->otherwise->atomicTypes)], JSON_THROW_ON_ERROR));
                $checks++;
                $bad = [
                    'absent' => null, 'empty' => $type::literalString(''),
                    'unknown' => $type::union($alpha, $type::literalString('missing')),
                    'reserved' => $type::union($alpha, $type::literalString('env')),
                    'empty-member' => $type::union($alpha, $type::literalString('')),
                    'integer' => $type::union($alpha, $type::literalInt(9)),
                    'float' => $type::union($alpha, $type::float()),
                    'boolean' => $type::union($alpha, $type::false()),
                    'nullable' => $type::union($alpha, $type::null()),
                    'mixed' => $type::union($alpha, $type::mixed()),
                    'array' => $type::union($alpha, $type::list($type::string())),
                    'object' => $type::union($alpha, $type::object()),
                    'general-string' => $type::string(), 'nonempty-string' => $type::nonEmptyString(),
                    'possibly-undefined' => $ordinary->withFlags(new \Mago\Sdk\Analyzer\Type\TypeFlags(possiblyUndefined: true)),
                    'try-undefined' => $ordinary->withFlags(new \Mago\Sdk\Analyzer\Type\TypeFlags(possiblyUndefinedFromTry: true)),
                    'nullsafe-null' => $ordinary->withFlags(new \Mago\Sdk\Analyzer\Type\TypeFlags(nullsafeNull: true)),
                ];
                $classString = $type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(
                    \Mago\Sdk\Analyzer\Type\ScalarTypeKind::ClassLikeString,
                    new \Mago\Sdk\Analyzer\Type\ClassLikeStringType(\Mago\Sdk\Analyzer\Type\ClassLikeStringVariant::Literal, literal: 'alpha'),
                ));
                $bad['multi-class-string'] = $type::union($alpha, $classString);
                $bad['wrong-scalar-kind'] = $type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(
                    \Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey,
                    new \Mago\Sdk\Analyzer\Type\StringType(\Mago\Sdk\Analyzer\Type\StringLiteralKind::Value, 'beta', false, true, true, false, \Mago\Sdk\Analyzer\Type\StringCasing::Unspecified),
                ));
                $bad['wrong-scalar-kind'] = $type::union($alpha, $bad['wrong-scalar-kind']);
                foreach ($bad as $label => $argument) {
                    if ($this->evaluate($context, $argument) !== null) { throw new \RuntimeException('Incomplete/unsupported option domain accepted: '.$label); }
                    $checks++;
                }
                foreach (['key', null] as $argumentName) {
                    if (!$context->types->equals($this->evaluate($context, $ordinary, $argumentName), $nullable)) { throw new \RuntimeException('Valid positional/named union failed.'); }
                    $checks++;
                }
                if (!$context->types->equals($this->evaluate($context, $classString), $nullable)) { throw new \RuntimeException('Existing singleton class-string behavior changed.'); }
                $checks++;
                if (!$context->types->equals($this->evaluate($context, $type::union($alpha, $alpha)), $nullable)) { throw new \RuntimeException('Duplicate exact names changed the return domain.'); }
                $checks++;
                $mixed = $type::union($alpha, $type::literalString('ready'));
                $mixedResult = $this->evaluate($context, $mixed);
                if ($mixedResult === null || !$context->types->equals($mixedResult, $type::union($type::string(), $type::null(), $type::bool()))) { throw new \RuntimeException('A mixed flag/value union erased bool or null.'); }
                $checks++;
                $class = $context->invocation->receiverType->atomicTypes[0];
                $badReceivers = [
                    'base-command' => $type::namedObject('Illuminate\\Console\\Command'),
                    'unknown' => $type::namedObject('UnknownFixtureCommand'),
                    'nonobject' => $type::string(),
                    'union' => $type::union($context->invocation->receiverType, $type::null()),
                    'parameters' => $type::namedObject($class->name, $type::string()),
                    'intersections' => $type::fromAtomic(new \Mago\Sdk\Analyzer\Type\NamedObjectType($class->name, null, null, false, false, [$type::namedObject('SelectorName')->atomicTypes[0]], false)),
                ];
                foreach ($badReceivers as $label => $receiver) {
                    if ($this->evaluate($context, $ordinary, receiver: $receiver) !== null) { throw new \RuntimeException('Unsupported receiver accepted: '.$label); }
                    $checks++;
                }
                foreach ([['other', false, false], [null, true, false], [null, false, true]] as [$argumentName, $unpacked, $placeholder]) {
                    if ($this->evaluate($context, $ordinary, $argumentName, $unpacked, $placeholder) !== null) { throw new \RuntimeException('Invalid option argument binding accepted.'); }
                    $checks++;
                }
                foreach ([[], [$context->invocation->arguments[0], $context->invocation->arguments[0]]] as $arguments) {
                    if ($this->evaluate($context, $ordinary, arguments: $arguments) !== null) { throw new \RuntimeException('Wrong option argument count accepted.'); }
                    $checks++;
                }
                foreach ([\Mago\Sdk\Analyzer\InvocationKind::Function, \Mago\Sdk\Analyzer\InvocationKind::StaticMethod] as $kind) {
                    if ($this->evaluate($context, $ordinary, kind: $kind) !== null) { throw new \RuntimeException('Nonnative invocation kind accepted.'); }
                    $checks++;
                }
                if ($this->evaluate($context, $type::union($type::literalString('input'), $type::literalString('secondary')), method: 'argument') !== null) { throw new \RuntimeException('Argument-name union was expanded by the option-only change.'); }
                $checks++;
                if (!$context->types->equals($this->evaluate($context, $type::literalString('input'), method: 'argument'), $type::string())) { throw new \RuntimeException('Existing literal argument semantics changed.'); }
                $checks++;
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                foreach ([['Illuminate\\Console\\Parser', 'parseOption'], ['Illuminate\\Console\\Concerns\\InteractsWithIO', 'option'], ['Symfony\\Component\\Console\\Input\\InputOption', 'setDefault']] as [$owner, $method]) {
                    $metadata = $context->codebase->getDeclaringMethod($owner, $method);
                    if ($metadata === null) { throw new \RuntimeException('Missing native declaration for metadata controls.'); }
                    foreach (['owner', 'file'] as $variant) {
                        $snapshot = $cache->values; $values = get_object_vars($metadata);
                        if ($variant === 'owner') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, $metadata->identifier->name, 'DifferentOwner'); }
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('/unrelated/native.php', $metadata->location->span); }
                        $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values); $replaced = 0;
                        try {
                            foreach ($cache->values as $operation => $entries) {
                                foreach ($entries as $key => $entry) {
                                    if ($entry instanceof \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata && $entry->identifier->equals($metadata->identifier)) { $cache->values[$operation][$key] = $changed; $replaced++; }
                                }
                            }
                            if ($replaced === 0 || $this->evaluate($context, $ordinary) !== null) { throw new \RuntimeException('Native metadata mismatch accepted: '.$owner.' '.$variant); }
                            $checks++;
                        } finally { $cache->values = $snapshot; }
                    }
                }
                if (!$context->types->equals($this->evaluate($context, $ordinary), $nullable)) { throw new \RuntimeException('Metadata controls failed to restore the original proof.'); }
                $checks++;
                file_put_contents($this->root.'/context-checks.json', json_encode(['checks' => $checks], JSON_THROW_ON_ERROR));
            }
            private function budgetControls(\Mago\Sdk\Analyzer\ReturnTypeProviderContext $context): void {
                $type = \Mago\Sdk\Analyzer\Type::class;
                $sixteen = [];
                for ($index = 1; $index <= 16; $index++) { $sixteen[] = $type::literalString('label'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)); }
                $first = array_shift($sixteen); $known = $type::union($first, ...$sixteen);
                $result = $this->evaluate($context, $known);
                if ($result === null || !$context->types->equals($result, $type::union($type::string(), $type::null()))) { throw new \RuntimeException('Sixteen known names should retain string/null.'); }
                if ($this->evaluate($context, $type::union($known, $type::literalString('label17'))) !== null) { throw new \RuntimeException('Seventeen raw atoms exceeded the name budget.'); }
                $duplicates = array_fill(0, 16, $type::literalString('label01')); $first = array_shift($duplicates); $sixteenDuplicates = $type::union($first, ...$duplicates);
                if ($this->evaluate($context, $sixteenDuplicates) === null || $this->evaluate($context, $type::union($sixteenDuplicates, $type::literalString('label01'))) !== null) { throw new \RuntimeException('Raw-atom budget was applied after duplicate removal.'); }
                file_put_contents($this->root.'/budget-checks.json', json_encode(['checks' => 4], JSON_THROW_ON_ERROR));
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/console-unions', name: 'Console unions', version: '1', analyzerPlugins: [$plugin])))->run();
PHP;
file_put_contents($workspace.'/observer-worker.php', $observerWorker);
$syntax = static function (string $path): void {
    $process = proc_open([PHP_BINARY, '-l', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not syntax-check generated source.'); }
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Invalid generated PHP '.$path.': '.$stdout.$stderr); }
};
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS)) as $item) {
    if ($item->isFile() && str_ends_with($item->getFilename(), '.php')) { $syntax($item->getPathname()); }
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (bool $disabled, int $workers = 1, bool $standalone = false, ?array $includes = null) use ($workspace, $package, $command): array {
    static $number = 0;
    $label = ($disabled ? 'native' : ($standalone ? 'standalone' : 'integrated-'.$workers)).'-'.(++$number);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => $includes ?? [$workspace.'/packages/laravel', $workspace.'/packages/symfony']],
        'extension-hosts' => $disabled ? new stdClass : [
            'laramago' => [
                'command' => $standalone
                    ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/observer-worker.php', $package.'/vendor/autoload.php', $workspace, $includes === [] ? 'absent' : 'complete']
                    : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => $workers,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/'.$label.'.json', 'w'],
        2 => ['file', $workspace.'/'.$label.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$label.'.log');
    if ($exit > 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request|Fatal error|Parse error/i', $stderr)) {
        throw new RuntimeException('Provider failure: '.$stderr);
    }
    return json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
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
$signature = static function (array $issues): array {
    $result = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
    sort($result);
    return $result;
};
$groups = static function (array $issues) use ($signature): array {
    $result = [];
    foreach ($issues as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
        if ($primary['span']['file_id']['name'] === 'cases.php') { $result[$primary['span']['start']['line'] + 1][] = $issue; }
    }
    foreach ($result as &$items) { $items = $signature($items); }
    return $result;
};
$nativeRaw = $run(true);
$standaloneRaw = $run(false, standalone: true);
$adaptedRaw = $run(false);
$threeWorkers = $run(false, 3);
if ($signature($standaloneRaw) !== $signature($adaptedRaw) || $signature($adaptedRaw) !== $signature($threeWorkers)) {
    throw new RuntimeException('Standalone and one/three-worker complete issue signatures disagree; inspect '.$workspace);
}
$native = $summarize($nativeRaw);
$adapted = $summarize($adaptedRaw);
$nativeGroups = $groups($nativeRaw);
$adaptedGroups = $groups($adaptedRaw);
$observations = [];
foreach (file($workspace.'/observations.jsonl', FILE_IGNORE_NEW_LINES) as $row) {
    $item = json_decode($row, true, flags: JSON_THROW_ON_ERROR);
    $observations[$item['class']] = $item;
}
foreach (['UnionValuesTernary' => 2, 'UnionValuesBranch' => 2, 'UnionValuesForeach' => 3, 'UnionValuesNamed' => 2, 'UnionFlags' => 2, 'UnionBudget16' => 16, 'UnionBudget17' => 17] as $class => $count) {
    $item = $observations[$class] ?? null;
    if ($item === null || count($item['atoms']) !== $count) { throw new RuntimeException('Missing genuine literal union shape for '.$class.'; inspect '.$workspace); }
    foreach ($item['atoms'] as $atom) {
        if ($atom['kind'] !== 'String' || $atom['refinement'] !== 'Mago\\Sdk\\Analyzer\\Type\\StringType' || $atom['literal'] === null) { throw new RuntimeException('Expected real ordinary literal-string atoms for '.$class.'; inspect '.$workspace); }
    }
    if (($class === 'UnionBudget17') !== ($item['result'] === null)) { throw new RuntimeException('Genuine union budget/source result mismatch for '.$class.'; inspect '.$workspace); }
}
$contextChecks = json_decode(file_get_contents($workspace.'/context-checks.json'), true, flags: JSON_THROW_ON_ERROR)['checks'];
$budgetChecks = json_decode(file_get_contents($workspace.'/budget-checks.json'), true, flags: JSON_THROW_ON_ERROR)['checks'];
if ($contextChecks !== 48 || $budgetChecks !== 4) { throw new RuntimeException('Missing exact genuine context controls: '.$contextChecks.' '.$budgetChecks.'; inspect '.$workspace); }
foreach (['UnionUnknown', 'UnionReserved', 'UnionEmpty', 'UnionNull', 'UnionInt', 'UnionBool', 'UnionGeneral', 'UnionClassString', 'UnionArgumentUnchanged', 'UnionBudget17'] as $class) {
    if (!array_key_exists($class, $observations) || $observations[$class]['result'] !== null) { throw new RuntimeException('Source whole-domain deferral missing for '.$class.'; inspect '.$workspace); }
}
$specific = ['union-flag-wrong', 'union-value-wrong', 'union-value-null', 'union-mixed-wrong', 'union-value-strong-consumer', 'union-explicit-local'];
$positive = ['union-values-ternary', 'union-values-branch', 'union-values-foreach', 'union-values-named', 'union-flags', 'union-mixed-valid', 'single-class-string', 'union-budget-16'];
$retained = 0;
foreach ($lines as $name => $line) {
    $actual = $adapted[$line] ?? [];
    $baseline = $native[$line] ?? [];
    if (in_array($name, [
        'flag', 'value', 'named', 'required-argument', 'optional-argument',
        'default-argument', 'array-argument', 'named-argument',
    ], true) || in_array($name, $positive, true)) {
        if ($actual !== [] || $baseline === []) {
            throw new RuntimeException($name.' should gain its precise type; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif (in_array($name, [
        'bad-type', 'wrong-argument-return', 'wrong-optional-argument-return',
        'wrong-array-argument-return',
    ], true) || in_array($name, $specific, true)) {
        if ($actual === [] || ($name !== 'bad-type' && !in_array($name, $specific, true) && ($adaptedGroups[$line] ?? []) === ($nativeGroups[$line] ?? []))) {
            throw new RuntimeException('Wrong inferred input type must remain a specific diagnostic; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
        }
    } elseif ($actual !== $baseline || ($nativeGroups[$line] ?? []) !== ($adaptedGroups[$line] ?? [])) {
        throw new RuntimeException($name.' must retain native diagnostics; native '.json_encode($baseline).'; adapted '.json_encode($actual).'; inspect '.$workspace);
    } else {
        $retained += count($baseline);
    }
    echo 'PASS: '.$name."\n";
}
if ($retained === 0 || !isset($native[$lines['union-unknown']])) { throw new RuntimeException('Whole-domain negative source groups must be nonempty.'); }
// The independent wrong return lies outside every command/provider invocation.
$independentLine = substr_count(substr($prefix, 0, strpos($prefix, 'function independentWrongType')), "\n") + 1;
if (($native[$independentLine] ?? []) === [] || ($nativeGroups[$independentLine] ?? []) !== ($adaptedGroups[$independentLine] ?? [])) { throw new RuntimeException('Unrelated native diagnostics were not retained.'); }
$nativeAbsent = $run(true, includes: []);
$standaloneAbsent = $run(false, standalone: true, includes: []);
if ($signature($nativeAbsent) !== $signature($standaloneAbsent)) { throw new RuntimeException('Single-file analysis without framework declarations must retain native diagnostics; inspect '.$workspace); }
echo "PASS: genuine union domains, ".$contextChecks." context controls, ".$budgetChecks." raw-budget controls, complete signatures, safe single-file deferral\n";

$parserPath = $framework.'/Console/Parser.php';
$parserSource = file_get_contents($parserPath);
file_put_contents($parserPath, str_replace('InputOption::VALUE_NONE', 'InputOption::VALUE_OPTIONAL', $parserSource));
$changedParser = $summarize($run(false));
if (($changedParser[$lines['flag']] ?? []) !== ($native[$lines['flag']] ?? [])) {
    throw new RuntimeException('Changed parser must retain native option type; inspect '.$workspace);
}
if (($changedParser[$lines['union-values-branch']] ?? []) !== ($native[$lines['union-values-branch']] ?? [])) { throw new RuntimeException('Changed parser must also defer an eligible union.'); }
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

$symfonyPath = $workspace.'/packages/symfony/console/Input/InputOption.php';
$symfonySource = file_get_contents($symfonyPath);
file_put_contents($symfonyPath, str_replace('? $default : false', '? $default : null', $symfonySource));
$changedInput = $summarize($run(false));
if (($changedInput[$lines['flag']] ?? []) !== ($native[$lines['flag']] ?? [])) {
    throw new RuntimeException('Changed input default must retain native option type; inspect '.$workspace);
}
if (($changedInput[$lines['union-values-branch']] ?? []) !== ($native[$lines['union-values-branch']] ?? [])) { throw new RuntimeException('Changed input default must also defer an eligible union.'); }
file_put_contents($symfonyPath, $symfonySource);
echo "PASS: changed input default defers\n";

$argumentPath = $workspace.'/packages/symfony/console/Input/InputArgument.php';
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
if (($changedDefaults[$lines['union-values-branch']] ?? []) !== ($native[$lines['union-values-branch']] ?? [])) { throw new RuntimeException('Changed framework defaults must also defer an eligible union.'); }
file_put_contents($commandPath, $commandSource);
echo "PASS: changed framework defaults defer\n";

echo 'Console input checks passed: '.count($cases).' source cases, '.(count($positive) - 1).' new precise union source domains, preserved singleton class-string, '.$retained.' retained native negative reports, '.$contextChecks.' genuine context controls, '.$budgetChecks.' raw union budget controls, five native source changes, standalone and one/three integrated workers, explicit root with spaces, nonstandard vendor directory and single-file deferral; workspace='.$workspace.".\n";
if (in_array('--keep-workspace', $argv, true)) { exit(0); }

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
