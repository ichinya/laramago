<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago property lists '.bin2hex(random_bytes(8));
mkdir($workspace);
$framework = <<<'PHP'
<?php
namespace Illuminate\Database\Eloquent\Concerns {
    trait GuardsAttributes {
        /** @var array<int, string> */
        protected $fillable = [];
        /** @var array<string> */
        protected $guarded = [];
    }
    trait HidesAttributes {
        /** @var array<string> */
        protected $hidden = [];
    }
}
namespace Illuminate\Database\Eloquent {
    abstract class Model {
        use \Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
        use \Illuminate\Database\Eloquent\Concerns\HidesAttributes;
    }
}
PHP;
$cases = [
    'native fillable list' => ['class FillableNames extends Model {', '/** @var list<string> */ protected $fillable = [];', true],
    'native hidden list' => ['class HiddenNames extends Model {', '/** @var list<string> */ protected $hidden = [];', true],
    'inherited native property' => ['class EmptyBase extends Model {} class InheritedNames extends EmptyBase {', '/** @var list<string> */ protected $fillable = [];', true],
    'integer elements' => ['class InvalidElements extends Model {', '/** @var list<int> */ protected $fillable = [];', false],
    'integer hidden elements' => ['class InvalidHiddenElements extends Model {', '/** @var list<int> */ protected $hidden = [];', false],
    'narrower nonempty list' => ['class NonemptyNames extends Model {', '/** @var non-empty-list<string> */ protected $fillable = ["id"];', false],
    'custom parent override' => ['class CustomBase extends Model { /** @var array<int,string> */ protected $fillable = []; } class CustomNames extends CustomBase {', '/** @var list<string> */ protected $fillable = [];', false],
    'other Eloquent property' => ['class GuardedNames extends Model {', '/** @var list<string> */ protected $guarded = [];', false],
    'nonmodel property' => ['class PlainBase { /** @var array<int,string> */ protected $fillable = []; } class PlainNames extends PlainBase {', '/** @var list<string> */ protected $fillable = [];', false],
    'native declaration change' => ['class NativeNames extends Model {', '/** @var list<string> */ protected array $fillable = [];', false],
    'static declaration change' => ['class StaticNames extends Model {', '/** @var list<string> */ protected static $fillable = [];', false],
    'wrong keys' => ['class WrongKeys extends Model {', '/** @var array<string,string> */ protected $fillable = [];', false],
    'property hooks' => ['class HookedNames extends Model {', '/** @var list<string> */ protected $fillable { get => ["id"]; set { $this->fillable = $value; } }', false],
];
$source = "<?php\nnamespace Fixtures;\nuse Illuminate\\Database\\Eloquent\\Model;\n";
$expected = [];
foreach ($cases as $label => [$opening, $property, $accepted]) {
    $source .= $opening."\n";
    $expected[substr_count($source, "\n")] = [$label, $accepted];
    $source .= $property."\n}\n";
}
file_put_contents($workspace.'/cases.php', $source);
$worker = <<<'PHP'
<?php
require AUTOLOAD;
$root = $argv[1];
$plugin = new class($root) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('test/property-lists', 'Property lists', 'Native model list contracts.');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\EloquentPropertyListContractFilter($this->root);
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/property-lists', 'Property lists', '1', analyzerPlugins: [$plugin])))->run();
PHP;
file_put_contents($workspace.'/worker.php', str_replace('AUTOLOAD', var_export($package.'/vendor/autoload.php', true), $worker));
$modes = ['disabled', 'enabled', 'changed-default', 'changed-parent-type', 'changed-native-type'];
if (in_array('--integrated', $argv, true)) {
    $modes[] = 'integrated';
}
foreach ($modes as $mode) {
    $declarations = match ($mode) {
        'changed-default' => str_replace('$fillable = []', '$fillable = ["custom"]', $framework),
        'changed-parent-type' => str_replace('@var array<int, string>', '@var array<int, int>', $framework),
        'changed-native-type' => str_replace('protected $fillable', 'protected array $fillable', $framework),
        default => $framework,
    };
    file_put_contents($workspace.'/framework.php', $declarations);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.4',
        'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : ['test' => [
            'command' => $mode === 'integrated'
                ? [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
                : [PHP_BINARY, $workspace.'/worker.php', $workspace],
            'workers' => 2,
        ]],
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if (! in_array($exit, [0, 1], true) || preg_match('/provider failed|rejected request|hook .* failed|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.'; inspect '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $diagnostics = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $diagnostics[$annotation['span']['start']['line']][] = $issue['code'];
                break;
            }
        }
    }
    foreach ($expected as $line => [$label, $accepted]) {
        $removed = $accepted && $mode !== 'disabled' && ($mode === 'enabled' || $mode === 'integrated' || $label === 'native hidden list');
        $actual = in_array('incompatible-property-type', $diagnostics[$line] ?? [], true);
        if ($actual === $removed) {
            throw new RuntimeException($mode.' '.$label.': unexpected property compatibility '.json_encode($diagnostics[$line] ?? []).'; inspect '.$workspace);
        }
    }
    echo 'PASS: Eloquent property list contracts '.$mode.' ('.count($cases).' cases)'.PHP_EOL;
}
