<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago forwarded transaction '.bin2hex(random_bytes(8));
$framework = $workspace.'/packages/laravel/framework/src/Illuminate';
foreach (['Foundation', 'Support/Facades', 'Database/Concerns', 'Collections'] as $directory) { mkdir($framework.'/'.$directory, 0777, true); }
file_put_contents($workspace.'/composer.json', json_encode(['config' => ['vendor-dir' => 'packages']], JSON_THROW_ON_ERROR));

// These native declarations are analysis fixtures; no framework method is loaded or called.
file_put_contents($framework.'/Foundation/Application.php', <<<'PHP'
<?php
namespace Illuminate\Foundation;
class Application {
    public function registerCoreContainerAliases() {
        foreach (['db' => [\Illuminate\Database\DatabaseManager::class]] as $key => $aliases) {
            foreach ($aliases as $alias) { $this->alias($key, $alias); }
        }
    }
}
PHP);
file_put_contents($framework.'/Support/Facades/DB.php', <<<'PHP'
<?php
namespace Illuminate\Support\Facades;
/**
 * @method static mixed transaction(\Closure $callback, int $attempts = 1)
 */
class DB extends Facade {
    protected static function getFacadeAccessor() { return 'db'; }
}
PHP);
file_put_contents($framework.'/Support/Facades/Facade.php', <<<'PHP'
<?php
namespace Illuminate\Support\Facades;
class Facade {
    protected static array $resolvedInstance = [];
    protected static mixed $app = null;
    protected static bool $cached = true;
    public static function getFacadeRoot() { return static::resolveFacadeInstance(static::getFacadeAccessor()); }
    protected static function resolveFacadeInstance($name) {
        if (isset(static::$resolvedInstance[$name])) { return static::$resolvedInstance[$name]; }
        if (static::$app) {
            if (static::$cached) { return static::$resolvedInstance[$name] = static::$app[$name]; }
            return static::$app[$name];
        }
    }
    public static function __callStatic($method, $args) {
        $instance = static::getFacadeRoot();
        if (! $instance) { throw new \RuntimeException('A facade root has not been set.'); }
        return $instance->$method(...$args);
    }
}
PHP);
file_put_contents($framework.'/Collections/functions.php', <<<'PHP'
<?php
namespace Illuminate\Support;
function enum_value($value, $default = null) {
    return match (true) {
        $value instanceof \BackedEnum => $value->value,
        $value instanceof \UnitEnum => $value->name,
        default => $value ?? $default,
    };
}
PHP);
file_put_contents($framework.'/Database/DatabaseManager.php', <<<'PHP'
<?php
namespace Illuminate\Database;
use function Illuminate\Support\enum_value;
class DatabaseManager {
    private array $connections = [];
    public function connection($name = null) {
        [$database, $type] = $this->parseConnectionName($name = enum_value($name) ?: $this->getDefaultConnection());
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->configure($this->makeConnection($database), $type);
            $this->dispatchConnectionEstablishedEvent($this->connections[$name]);
        }
        return $this->connections[$name];
    }
    public function __call($method, $parameters) { return $this->connection()->$method(...$parameters); }
    public function parseConnectionName($name): array { return [$name, null]; }
    public function getDefaultConnection(): string { return 'fixture'; }
    public function makeConnection($name): Connection { return new Connection; }
    public function configure(Connection $connection, $type): Connection { return $connection; }
    public function dispatchConnectionEstablishedEvent(Connection $connection): void {}
}
PHP);
file_put_contents($framework.'/Database/Concerns/ManagesTransactions.php', <<<'PHP'
<?php
namespace Illuminate\Database\Concerns;
use Closure;
use Throwable;
use Illuminate\Database\DeadlockException;
/** @mixin \Illuminate\Database\Connection */
trait ManagesTransactions {
    /**
     * @template TReturn of mixed
     * @param (\Closure(static): TReturn) $callback
     * @param int $attempts
     * @return TReturn
     */
    public function transaction(Closure $callback, $attempts = 1) {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();
            try { $callbackResult = $callback($this); }
            catch (Throwable $e) { $this->handleTransactionException($e, $currentAttempt, $attempts); continue; }
            $levelBeingCommitted = $this->transactions;
            try {
                if ($this->transactions === 1) { $this->fireConnectionEvent('committing'); $this->getPdo()->commit(); }
                $this->transactions = max(0, $this->transactions - 1);
            } catch (Throwable $e) { $this->handleCommitTransactionException($e, $currentAttempt, $attempts); continue; }
            $this->transactionsManager?->commit($this->getName(), $levelBeingCommitted, $this->transactions);
            $this->fireConnectionEvent('committed');
            return $callbackResult;
        }
    }
    /** @return void */
    protected function handleTransactionException(Throwable $e, $currentAttempt, $maxAttempts) {
        if ($this->causedByConcurrencyError($e) && $this->transactions > 1) {
            $this->transactions--;
            $this->transactionsManager?->rollback($this->getName(), $this->transactions);
            throw new DeadlockException($e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }
        $this->rollBack();
        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) { return; }
        throw $e;
    }
    /** @return void */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts) {
        $this->transactions = max(0, $this->transactions - 1);
        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            $pdo = $this->getPdo();
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            return;
        }
        if ($this->causedByLostConnection($e)) { $this->transactions = 0; }
        throw $e;
    }
}
PHP);
file_put_contents($framework.'/Database/Connection.php', <<<'PHP'
<?php
namespace Illuminate\Database;
class DeadlockException extends \RuntimeException {}
class TransactionManager {
    public function commit(string $name, int $level, int $transactions): void {}
    public function rollback(string $name, int $transactions): void {}
}
class Connection {
    use Concerns\ManagesTransactions;
    protected int $transactions = 1;
    protected ?TransactionManager $transactionsManager = null;
    public function beginTransaction(): void {}
    public function fireConnectionEvent(string $event): void {}
    public function getPdo(): \PDO { throw new \RuntimeException('Analysis fixture only'); }
    public function getName(): string { return 'fixture'; }
    public function rollBack(): void {}
    public function causedByConcurrencyError(\Throwable $error): bool { return false; }
    public function causedByLostConnection(\Throwable $error): bool { return false; }
}
PHP);

$cases = [
    'untyped direct forwarding' => ['', 'return DB::transaction(function () use ($operation) { return $operation(); });', true],
    'static untyped forwarding' => ['', 'return DB::transaction(static function () use ($operation) { return $operation(); });', true],
    'named callback and positive attempts' => ['', 'return DB::transaction(attempts: 3, callback: function () use ($operation) { return $operation(); });', true],
    'unrelated ordinary prefix' => ['object $record, ', 'return DB::transaction(function () use ($record, $operation) { $record->inspect(); return $operation(); });', true],
    'explicit mixed wrapper remains authoritative' => ['', 'return DB::transaction(function () use ($operation): mixed { return $operation(); });', false],
    'stronger wrapper doc remains authoritative' => ['', 'return DB::transaction(/** @return int */ function () use ($operation) { return $operation(); });', false],
    'by-reference capture defers' => ['', 'return DB::transaction(function () use (&$operation) { return $operation(); });', false],
    'rebound formal defers' => ['', '$operation = static fn (): int => 1; return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'operation alias defers' => ['', '$copy = $operation; return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'operation passed to another action defers' => ['', 'inspectOperation($operation); return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'operation invoked outside wrapper defers' => ['', '$operation(); return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'multiple operation invokes defer' => ['', 'return DB::transaction(function () use ($operation) { $operation(); return $operation(); });', false],
    'conditional fallback defers' => ['bool $fallback, ', 'return DB::transaction(function () use ($operation, $fallback) { if ($fallback) { return null; } return $operation(); });', false],
    'catch fallback defers' => ['', 'return DB::transaction(function () use ($operation) { try { return $operation(); } catch (\Throwable $e) { return null; } });', false],
    'wrapper parameter defers' => ['', 'return DB::transaction(function ($connection) use ($operation) { return $operation(); });', false],
    'zero attempts defers' => ['', 'return DB::transaction(function () use ($operation) { return $operation(); }, 0);', false],
    'negative attempts defers' => ['', 'return DB::transaction(function () use ($operation) { return $operation(); }, -1);', false],
    'unknown attempts defers' => ['int $attempts, ', 'return DB::transaction(function () use ($operation) { return $operation(); }, $attempts);', false],
    'by-reference unrelated formal defers' => ['int &$counter, ', 'return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'dynamic local extraction defers' => ['array $values, ', 'extract($values); return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'stronger local phpstan var defers' => ['', '/** @phpstan-var Closure():int $operation */ return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'stronger local psalm var defers' => ['', '/** @psalm-var Closure():int $operation */ return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'closure formal by-reference defers' => ['', 'return DB::transaction(function () use ($operation) { return $operation(); });', false, '&'],
    'changed facade service defers' => ['', 'DB::swap(new \stdClass); return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'changed facade application defers' => ['', 'DB::setFacadeApplication(new \stdClass); return DB::transaction(function () use ($operation) { return $operation(); });', false],
    'wrong enclosing return contract defers' => ['', 'return DB::transaction(function () use ($operation) { return $operation(); });', false, '', 'int'],
];
$source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nfunction inspectOperation(Closure \$operation): void {}\nfinal class ForwardingService {\n";
$ranges = [];
foreach ($cases as $name => $case) {
    [$parameters, $body, $eligible] = $case;
    $reference = $case[3] ?? '';
    $return = $case[4] ?? 'TResult';
    $start = strlen($source);
    $source .= "/**\n * @template TResult\n * @param Closure(): TResult \$operation\n * @return ".$return."\n */\n";
    $source .= 'public function scenario'.count($ranges).'('.$parameters.'Closure '.$reference.'$operation): mixed { '.$body." }\n";
    $ranges[] = [$name, $start, strlen($source), $eligible];
}
$source .= "}\n";
file_put_contents($workspace.'/cases.php', $source);

file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];

use Ichinya\Laramago\Analyzer\ForwardedTransactionTemplateProvider;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
final class ObservedTemplates implements MethodReturnTypeProvider {
    public function __construct(private readonly ForwardedTransactionTemplateProvider $inner, private readonly string $root, private readonly bool $observe) {}
    public function getTargets(): array { return $this->inner->getTargets(); }
    public function getReturnType(ReturnTypeProviderContext $context): ?Type {
        $result = $this->observe ? null : $this->inner->getReturnType($context);
        $proof = $this->inner->calls->proof($context->invocation);
        $row = ['span' => [$context->invocation->span->start, $context->invocation->span->end],
            'invocation' => var_export($context->invocation, true), 'proof' => $proof !== null, 'type' => (string) ($result ?? ''),
            'result' => var_export($result, true)];
        if ($proof !== null) {
            $method = $context->codebase->getMethod($proof['owner'], $proof['scope']->name->name)
                ?? $context->codebase->getDeclaringMethod($proof['owner'], $proof['scope']->name->name);
            $formal = $method?->parameters[$proof['formal']]->type?->type->atomicTypes[0] ?? null;
            $expected = $formal instanceof CallableType ? $formal->signature?->returnType : null;
            $row['enclosing'] = var_export($method, true);
            $row['exactTemplate'] = $result === null || $expected === null ? null : $context->types->equals($result, $expected);
            if ($result !== null && !$this->observe) {
                $copy=static function(object $old,array $changes):object { $class=$old::class;return new $class(...array_replace(get_object_vars($old),$changes)); };
                $cache=(new ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                $callbackArgument=$context->invocation->getArgument(0,'callback');$callbackAtomic=$callbackArgument->type->atomicTypes[0];
                $callbackId=$callbackAtomic->alias??$callbackAtomic->signature?->source;
                $callbackMetadata=$context->codebase->getFunctionLike($callbackId);
                $transactionMetadata=$context->codebase->getDeclaringMethod('Illuminate\Database\Connection','transaction');
                $formalMetadata=$method->parameters[$proof['formal']];
                $refFlag=\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE;
                $flagged=static fn($flags)=>new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($flags->bits|$refFlag);
                $mutations=[
                    'missing enclosing formals'=>[$method,$copy($method,['parameters'=>[]])],
                    'missing enclosing templates'=>[$method,$copy($method,['templates'=>[]])],
                    'incompatible enclosing result'=>[$method,$copy($method,['returnType'=>$copy($method->returnType,['type'=>Type::string()])])],
                    'changed enclosing template constraint'=>[$method,$copy($method,['templates'=>[$copy($method->templates[0],['constraint'=>Type::string()])]])],
                    'reference callable formal'=>[$method,$copy($method,['parameters'=>array_replace($method->parameters,[$proof['formal']=>$copy($formalMetadata,['flags'=>$flagged($formalMetadata->flags)])])])],
                    'undocumented callable formal'=>[$method,$copy($method,['parameters'=>array_replace($method->parameters,[$proof['formal']=>$copy($formalMetadata,['type'=>$copy($formalMetadata->type,['fromDocblock'=>false])])])])],
                    'authoritative wrapper return'=>[$callbackMetadata,$copy($callbackMetadata,['declaredReturnType'=>$copy($method->returnType,['type'=>Type::string(),'fromDocblock'=>false])])],
                    'reference wrapper'=>[$callbackMetadata,$copy($callbackMetadata,['flags'=>$flagged($callbackMetadata->flags)])],
                    'changed wrapper location'=>[$callbackMetadata,$copy($callbackMetadata,['location'=>$copy($callbackMetadata->location,['span'=>new \Mago\Sdk\Span($callbackMetadata->location->span->start+1,$callbackMetadata->location->span->end)])])],
                    'missing native transaction templates'=>[$transactionMetadata,$copy($transactionMetadata,['templates'=>[]])],
                    'changed native transaction result'=>[$transactionMetadata,$copy($transactionMetadata,['returnType'=>$copy($transactionMetadata->returnType,['type'=>Type::string()])])],
                    'changed native transaction constraint'=>[$transactionMetadata,$copy($transactionMetadata,['templates'=>[$copy($transactionMetadata->templates[0],['constraint'=>Type::string()])]])],
                ];$checks=[];
                $contractClass=\Ichinya\Laramago\Analyzer\StaticAnalysis\ForwardedTransactionTemplateContract::class;
                foreach($mutations as $label=>[$original,$replacement]) {
                    $canonical=$context->codebase->getFunctionLike($original->identifier);
                    if($canonical===null||$canonical!=$original) { throw new RuntimeException('Canonical SDK lookup differs from witnessed metadata '.$label); }
                    if($replacement==$original) { throw new RuntimeException('No-op template control '.$label); }$slots=[];
                    foreach($cache->values as $bucket=>$values) { foreach($values as $key=>$value) { if($value===$original||$value==$original) {$slots[]=[$bucket,$key,$value];$cache->values[$bucket][$key]=$replacement;} } }
                    if($slots===[]) { throw new RuntimeException('No witnessed SDK cache slot '.$label); }
                    try {
                        $selected=$context->codebase->getFunctionLike($original->identifier);
                        if($selected!==$replacement) { throw new RuntimeException('Active SDK lookup missed the replacement '.$label); }
                        $changed=(new $contractClass($this->root,$this->inner->calls))->result($context,$proof);
                        if($changed!==null) { throw new RuntimeException('Changed native template contract still admitted '.$label); }
                    } finally { foreach($slots as [$bucket,$key,$value]) {$cache->values[$bucket][$key]=$value;} }
                    $restored=(new $contractClass($this->root,$this->inner->calls))->result($context,$proof);
                    if($restored===null||!$context->types->equals($restored,$expected)) { throw new RuntimeException('Native template restoration failed '.$label); }
                    $checks[$label]=['slotsChanged'=>count($slots),'genuinePositiveBefore'=>true,'activeLookupChanged'=>true,'refusedAfter'=>true,'sameScopedTemplateRestored'=>true];
                }
                $row['genuineTemplateControls']=$checks;
            }
            $contract = (new ReflectionProperty($this->inner, 'contract'))->getValue($this->inner);
            foreach (['hierarchy' => [$context->codebase, $proof['owner']], 'wrapper' => [$context, $proof], 'native' => [$context]] as $stage => $arguments) {
                try { $row['stages'][$stage] = (new ReflectionMethod($contract, $stage))->invoke($contract, ...$arguments); }
                catch (Throwable $error) { $row['stages'][$stage] = get_class($error).': '.$error->getMessage(); }
            }
            $standard = $context->codebase->getDeclaringMethod('Illuminate\Database\Connection', 'transaction');
            $row['nativeTransaction'] = var_export($standard, true);
            foreach ((new ReflectionClass($contract))->getConstant('BODIES') as $selector => $body) {
                [$owner, $name] = explode('::', $selector);
                $native = $context->codebase->getDeclaringMethod($owner === 'Illuminate\Database\Concerns\ManagesTransactions' ? 'Illuminate\Database\Connection' : $owner, $name);
                $row['nativeBodies'][$selector] = $native !== null && (new ReflectionMethod($contract, 'nativeBody'))->invoke($contract, $context->codebase, $native, $body);
            }
        }
        file_put_contents($this->root.'/audit.jsonl', json_encode($row, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        return $result;
    }
}
final class TemplatePlugin implements Plugin {
    public function __construct(private readonly string $root, private readonly bool $observe) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('templates', 'Templates', 'Invented template forwarding fixture.'); }
    public function register(PluginRegistry $registry): void {
        $provider = new ForwardedTransactionTemplateProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider(new ObservedTemplates($provider, $this->root, $this->observe));
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(identifier: 'templates', name: 'Templates', version: '1',
    analyzerPlugins: [new TemplatePlugin($argv[2], str_ends_with($argv[3], 'observe'))])))->run();
PHP);
// Replace only this provider for the native full-registry before/after comparison.
$registry=file_get_contents($package.'/bin/laramago-worker.php');
$registry=preg_replace('/^\s*new (?:\\\\?Ichinya\\\\Laramago\\\\Analyzer\\\\)?ForwardedTransactionTemplatePlugin\([^\r\n]*\),\s*$/m','',$registry,1,$removed);
if($removed!==1) { throw new RuntimeException('The transaction provider registration must be unique.'); }
file_put_contents($workspace.'/full-before-worker.php',$registry);
$config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => [$framework]],
    'extension-hosts' => ['templates' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, 'observe'], 'workers' => 1]]];
echo 'Workspace: '.$workspace."\n";
$run = static function (string $mode, int $workers = 1) use ($workspace, $config, $command, $package): array {
    $current = $config;
    $current['extension-hosts']['templates']['workers'] = $workers;
    $current['extension-hosts']['templates']['command'][6] = $mode;
    if (str_starts_with($mode, 'integrated')) { $current['extension-hosts']['templates']['command'] = [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated-before' ? $workspace.'/full-before-worker.php' : $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]; }
    file_put_contents($workspace.'/mago.json', json_encode($current, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]); $exit = proc_close($process); file_put_contents($workspace."/".$mode.".closed.json", json_encode(["exitCode"=>$exit,"childClosed"=>true],JSON_THROW_ON_ERROR));
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if (! in_array($exit, [0, 1], true) || preg_match('/provider failed|rejected request|hook .*failed|pars(?:e|ing) errors?|fatal error|timed out|invalid extension frame|native analysis fallback|orchestrator error|uncaught|PHP Warning/i', $log)) {
        throw new RuntimeException('Invalid analysis '.$exit.'; inspect '.$workspace.'/'.$mode.'.log');
    }
    return json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR);
};
$observe = in_array('--observe', $argv, true);
$native = $run('observe');
if ($observe) { echo "PASS: observation completed without provider failures.\n"; exit(0); }
$report = $run('standalone');
$observations = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/audit.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
$group = static function (array $report, int $start, int $end): array {
    $issues = [];
    foreach ($report['issues'] ?? [] as $issue) {
        foreach ($issue['annotations'] ?? [] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['start']['offset'] >= $start && $annotation['span']['end']['offset'] <= $end) {
                $issues[] = json_encode($issue, JSON_THROW_ON_ERROR); break;
            }
        }
    }
    sort($issues); return $issues;
};
$corrected = $retained = 0; $lostAll = [];
foreach ($ranges as [$name, $start, $end, $eligible]) {
    $provided = array_values(array_filter($observations, static fn (array $entry): bool => $entry['span'][0] >= $start && $entry['span'][1] <= $end && $entry['type'] !== ''));
    if (($provided !== []) !== $eligible) { throw new RuntimeException('Wrong source domain presence: '.$name.'; inspect '.$workspace); }
    $before = $group($native, $start, $end); $after = $group($report, $start, $end);
    if (! $eligible) {
        if ($before === [] || $before !== $after) { throw new RuntimeException('Deferred nonempty exact native reports changed: '.$name.'; inspect '.$workspace); }
        $retained += count($before);
    } else {
        foreach ($provided as $entry) { if ($entry['exactTemplate'] !== true) { throw new RuntimeException('Authoritative template identity lost: '.$name.'; inspect '.$workspace); } }
        $lost = array_values(array_diff($before, $after)); $added = array_values(array_diff($after, $before));
        if ($lost === [] || $added !== []) { throw new RuntimeException('No exact native-only template correction: '.$name.'; inspect '.$workspace); }
        foreach ($lost as $json) { if (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['code'] !== 'mixed-return-statement') { throw new RuntimeException('Unrelated diagnostics changed: '.$name); } }
        $corrected += count($lost); $lostAll = [...$lostAll, ...$lost];
    }
    echo 'PASS: '.$name."\n";
}
{
    $before = $run('integrated-before'); $one = $run('integrated1'); $three = $run('integrated3', 3);
    $whole = static function(array $report):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$report['issues']);sort($rows);return $rows; };
    $beforeRows=$whole($before);$counts=array_count_values($lostAll);$expected=[];
    foreach($beforeRows as $row) { if(($counts[$row]??0)>0) { $counts[$row]--; }else {$expected[]=$row;} }
    if(array_sum($counts)!==0) { throw new RuntimeException('Full existing registry has different native template obligations; inspect '.$workspace); }
    if($whole($one)!==$whole($three)||$whole($one)!==$expected) { throw new RuntimeException('Integrated worker complete before/after residual differs; inspect '.$workspace); }
    echo "PASS: actual package worker, one and three workers have exact standalone reports.\n";
}
$facadePath=$framework.'/Support/Facades/Facade.php';$originalFacade=file_get_contents($facadePath);
$wholeIssues=static function(array $report):array { $rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$report['issues']);sort($rows);return $rows; };
foreach([
    'changed-facade-root'=>'public static function getFacadeRoot() { return null; }',
    'changed-facade-resolver'=>'protected static function resolveFacadeInstance($name) { return null; }',
] as $label=>$replacement) {
    $method=$label==='changed-facade-root'?'getFacadeRoot':'resolveFacadeInstance';
    if($method==='getFacadeRoot') {$changed=str_replace('public static function getFacadeRoot() { return static::resolveFacadeInstance(static::getFacadeAccessor()); }',$replacement,$originalFacade,$count);}
    else {$changed=preg_replace('/protected static function resolveFacadeInstance\(\$name\) \{.*?\n    \}/s',$replacement,$originalFacade,1,$count);}
    if($count!==1) { throw new RuntimeException('Unknown native facade source control anchor.'); }
    file_put_contents($facadePath,$changed);
    try {
        $keep=$run($label.'-observe');$candidate=$run($label);
        if($wholeIssues($keep)===[]||$wholeIssues($keep)!==$wholeIssues($candidate)) { throw new RuntimeException('Changed native facade dispatch was still supplemented.'); }
    } finally { file_put_contents($facadePath,$originalFacade); }
    $restored=$run($label.'-restored');
    if($wholeIssues($restored)!==$wholeIssues($report)) { throw new RuntimeException('Native facade source restoration changed the exact report.'); }
    echo 'PASS: '.$label.' defers and exact report restores.' ."\n";
}
echo 'PASS: '.count($cases).' source cases, '.$corrected.' exact template corrections, '.$retained." retained negative reports.\n";
