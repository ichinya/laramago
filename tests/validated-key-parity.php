<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago validated keys '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap must not run.");');
file_put_contents($workspace.'/requests.php', <<<'PHP'
    <?php

    namespace Illuminate\Contracts\Validation {
        interface Validator { public function validated(): array; }
    }
    namespace Illuminate\Validation {
        class Validator implements \Illuminate\Contracts\Validation\Validator {
            public function validated(): array { throw new \RuntimeException('Validation must not run.'); }
            public function errors(): \Illuminate\Support\MessageBag { throw new \RuntimeException('Validation must not run.'); }
            public function setRules(array $rules): void { throw new \RuntimeException('Validation must not run.'); }
        }
    }
    namespace Illuminate\Support {
        class MessageBag {
            public function isNotEmpty(): bool { throw new \RuntimeException('Validation must not run.'); }
            public function add(string $key, string $message): void { throw new \RuntimeException('Validation must not run.'); }
        }
    }
    namespace Illuminate\Http {
        class Request {
            public function user(): ?object { throw new \RuntimeException('Request must not run.'); }
            public function has(string $key): bool { throw new \RuntimeException('Request must not run.'); }
            public function input(string $key): mixed { throw new \RuntimeException('Request must not run.'); }
            public function filled(string $key): bool { throw new \RuntimeException('Request must not run.'); }
            public function merge(array $data): static { throw new \RuntimeException('Request must not run.'); }
        }
    }
    namespace Illuminate\Foundation\Http {
        class FormRequest extends \Illuminate\Http\Request {
            /** @var \Illuminate\Contracts\Validation\Validator */
            protected $validator;
            public function validated($key = null, $default = null): mixed { throw new \RuntimeException('Validation must not run.'); }
        }
    }
    namespace Example {
        use Illuminate\Validation\Validator;

        class Checker { public function calculate(mixed $value): mixed { throw new \RuntimeException('Checker must not run.'); } }
        function app(string $class): Checker { throw new \RuntimeException('Container must not run.'); }

        class TypedBase extends \Illuminate\Foundation\Http\FormRequest {
            /** @return array<string, list<string>> */
            public function rules(): array { return ['name' => ['required', 'string']]; }
            public function after(): array {
                return [function (Validator $validator): void {
                    if ($validator->errors()->isNotEmpty()) { return; }
                    $result = app(Checker::class)->calculate($this->input('name'));
                    if (! is_array($result)) { $validator->errors()->add('name', 'Invalid.'); }
                }];
            }
        }
        class TypedChild extends TypedBase {
            public function rules(): array {
                $user = $this->user();
                return [...parent::rules(), 'extra' => ['sometimes', 'string']];
            }
            protected function prepareForValidation(): void {
                if (! $this->filled('extra')) {
                    $this->merge(['extra' => $this->has('name') ? 'yes' : 'no']);
                }
            }
        }
        class TypedDirect extends TypedBase {
            /** @return array<string, string> */
            public function rules(): array {
                $user = $this->user();
                if ($user === null) { throw new \RuntimeException('No user.'); }
                return ['name' => 'required|string'];
            }
        }
        class UntypedRules extends TypedBase {
            public function rules(): array { return $this->dynamicRules(); }
            private function dynamicRules(): array { return []; }
        }
        class DynamicSpread extends TypedBase {
            public function rules(): array { return [...$this->dynamicRules(), 'name' => 'required|string']; }
            private function dynamicRules(): array { return []; }
        }
        class NumericRules extends TypedBase {
            /** @return array<int, string> */
            public function rules(): array { return [0 => 'required|string']; }
        }
        class MisdocumentedNumericRules extends TypedBase {
            /** @return array<string, string> */
            public function rules(): array { return [0 => 'required|string']; }
        }
        class CustomValidator extends TypedBase {
            public function getValidatorInstance(): Validator { throw new \RuntimeException('Custom validator must not run.'); }
        }
        class OverriddenInput extends TypedBase {
            public function input(string $key): mixed { throw new \RuntimeException('Custom input must not run.'); }
        }
        class MutatingAfter extends TypedBase {
            public function after(): array {
                return [function (Validator $validator): void { $validator->setRules([0 => 'required|string']); }];
            }
        }
        class CustomPreparation extends TypedBase {
            protected function prepareForValidation(): void { throw new \RuntimeException('Custom preparation must not run.'); }
        }
        class OverriddenValidated extends TypedBase {
            public function validated($key = null, $default = null): int { return 7; }
        }
    }
    PHP);

$cases = [
    'typed rules with complex error hook' => ['TypedBase', []],
    'typed parent spread with named preparation' => ['TypedChild', []],
    'typed direct guarded rules' => ['TypedDirect', []],
    'untyped dynamic rules stay broad' => ['UntypedRules', ['possibly-invalid-argument']],
    'dynamic rule spread stays broad' => ['DynamicSpread', ['possibly-invalid-argument']],
    'numeric rule keys stay broad' => ['NumericRules', ['possibly-invalid-argument']],
    'misdocumented numeric rules stay broad' => ['MisdocumentedNumericRules', ['possibly-invalid-argument']],
    'custom validator stays broad' => ['CustomValidator', ['possibly-invalid-argument']],
    'custom input in after hook stays broad' => ['OverriddenInput', ['possibly-invalid-argument']],
    'mutating after hook stays broad' => ['MutatingAfter', ['possibly-invalid-argument']],
    'custom preparation stays broad' => ['CustomPreparation', ['possibly-invalid-argument']],
    'overridden validated method remains authoritative' => ['OverriddenValidated', ['invalid-argument']],
];
$lines = [];
$source = "<?php\n\nnamespace Example;\n\n/** @param array<string, mixed> \$value */\nfunction acceptNamed(array \$value): void {}\n";
foreach ($cases as $label => [$class]) {
    $lines[$label] = substr_count($source, "\n") + 1;
    $source .= "function check".count($lines)."($class \$request): void { acceptNamed(\$request->validated()); }\n";
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.5',
    'source' => ['paths' => ['cases.php'], 'includes' => ['requests.php']],
    'extension-hosts' => ['laramago' => [
        'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
        'workers' => 1,
    ]],
], JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
$issues = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] ?? [];
$actual = [];
foreach ($issues as $issue) {
    $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
    if ($primary['span']['file_id']['name'] === 'cases.php') {
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
}
foreach ($cases as $label => [, $expected]) {
    $codes = $actual[$lines[$label]] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace);
    }
    echo 'PASS: '.$label."\n";
}
