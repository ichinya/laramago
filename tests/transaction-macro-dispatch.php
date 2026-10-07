<?php

declare(strict_types=1);

$package = dirname(__DIR__);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago forwarded transaction '.bin2hex(random_bytes(8));
$framework = $workspace.'/packages/laravel/framework/src/Illuminate';
foreach (['Foundation', 'Support/Facades', 'Database/Concerns', 'Collections', 'Macroable/Traits'] as $directory) { mkdir($framework.'/'.$directory, 0777, true); }
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
file_put_contents($framework.'/Macroable/Traits/Macroable.php', <<<'NATIVE'
<?php
namespace Illuminate\Support\Traits;
trait Macroable {
    /** @var array */
    protected static $macros = [];
    /**
     * @param string $name
     * @return bool
     */
    public static function hasMacro($name) { return isset(static::$macros[$name]); }
    /**
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters) { return static::$macros[$method](...$parameters); }
    /**
     * @param string $name
     * @param callable $macro
     * @return void
     */
    public static function macro($name, $macro) { static::$macros[$name] = $macro; }
}
NATIVE);
file_put_contents($framework.'/Database/DatabaseManager.php', <<<'PHP'
<?php
namespace Illuminate\Database;
use function Illuminate\Support\enum_value;
class DatabaseManager {
    use \Illuminate\Support\Traits\Macroable { __call as macroCall; }
    private array $connections = [];
    public function connection($name = null) {
        [$database, $type] = $this->parseConnectionName($name = enum_value($name) ?: $this->getDefaultConnection());
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->configure($this->makeConnection($database), $type);
            $this->dispatchConnectionEstablishedEvent($this->connections[$name]);
        }
        return $this->connections[$name];
    }
    public function __call($method, $parameters) {
        if (static::hasMacro($method)) { return $this->macroCall($method, $parameters); }
        return $this->connection()->$method(...$parameters);
    }
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

$GLOBALS['transactionFixtureMode']=$argv[3];

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
            if ($result !== null && !$this->observe && in_array($GLOBALS['transactionFixtureMode'],['controls','driver-controls'],true)) {
                $copy=static function(object $old,array $changes):object { $class=$old::class;return new $class(...array_replace(get_object_vars($old),$changes)); };
                $cache=(new ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                $callbackArgument=$context->invocation->getArgument(0,'callback');$callbackAtomic=$callbackArgument->type->atomicTypes[0];
                $callbackId=$callbackAtomic->alias??$callbackAtomic->signature?->source;
                $callbackMetadata=$context->codebase->getFunctionLike($callbackId);
                $transactionMetadata=$context->codebase->getDeclaringMethod('Illuminate\Database\Connection','transaction');
                $formalMetadata=$method->parameters[$proof['formal']];
                $refFlag=\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE;
                $flagged=static fn($flags)=>new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($flags->bits|$refFlag);
                $hasMacro=$context->codebase->getDeclaringMethod('Illuminate\Database\DatabaseManager','hasMacro');
                $alias=$context->codebase->getDeclaringMethod('Illuminate\Database\DatabaseManager','macroCall');
                $mutations=[
                    'changed macro predicate return'=>[$hasMacro,$copy($hasMacro,['returnType'=>$copy($hasMacro->returnType,['type'=>Type::string()])])],
                    'instance macro predicate'=>[$hasMacro,$copy($hasMacro,['static'=>false])],
                    'incompatible native alias formals'=>[$alias,$copy($alias,['parameters'=>[]])],
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
                ];
                if($this->inner->calls->hasConnectionExtensions()){
                    $configure=$context->codebase->getDeclaringMethod('Illuminate\\Database\\DatabaseManager','configure');
                    $extend=$context->codebase->getDeclaringMethod('Illuminate\\Database\\DatabaseManager','extend');
                    if($configure===null||$extend===null||$configure->parameters[0]->declaredType===null){throw new RuntimeException('No genuine physical driver boundary for mutation controls.');}
                    $input=$configure->parameters[0];
                    $mutations['changed physical connection formal']=[$configure,$copy($configure,['parameters'=>array_replace($configure->parameters,[0=>$copy($input,['declaredType'=>$copy($input->declaredType,['type'=>Type::int()])])])])];
                    $mutations['reference physical connection formal']=[$configure,$copy($configure,['parameters'=>array_replace($configure->parameters,[0=>$copy($input,['flags'=>$flagged($input->flags)])])])];
                    $mutations['changed extension owner']=[$extend,$copy($extend,['identifier'=>$copy($extend->identifier,['class'=>'DifferentDriverOwner'])])];
                }
                $sql=$context->codebase->getDeclaringMethod('Illuminate\Database\SqlServerConnection','transaction');
                if($sql===null||$sql->returnType===null||$sql->templates===[]){throw new RuntimeException('No genuine SQL Server template for native controls.');}
                $mutations['missing SQL Server templates']=[$sql,$copy($sql,['templates'=>[]])];
                $mutations['changed SQL Server scoped result']=[$sql,$copy($sql,['returnType'=>$copy($sql->returnType,['type'=>Type::string()])])];
                $mutations['changed SQL Server template constraint']=[$sql,$copy($sql,['templates'=>[$copy($sql->templates[0],['constraint'=>Type::string()])]])];
                $mutations['reference SQL Server callback']=[$sql,$copy($sql,['parameters'=>array_replace($sql->parameters,[0=>$copy($sql->parameters[0],['flags'=>$flagged($sql->parameters[0]->flags)])])])];
                $mutations['changed SQL Server attempt default']=[$sql,$copy($sql,['parameters'=>array_replace($sql->parameters,[1=>$copy($sql->parameters[1],['defaultType'=>$copy($sql->parameters[1]->defaultType,['type'=>Type::literalInt(2)])])])])];
                $checks=[];
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
                $original=$context->codebase->getDeclaringProperty('Illuminate\Database\DatabaseManager','$macros');
                $replacement=$copy($original,['defaultType'=>$copy($original->defaultType,['type'=>Type::string()])]);
                $slots=[];
                foreach($cache->values as $bucket=>$values){foreach($values as $key=>$value){if($value===$original||$value==$original){$slots[]=[$bucket,$key,$value];$cache->values[$bucket][$key]=$replacement;}}}
                if($slots===[]){throw new RuntimeException('No actual macro field cache slot.');}
                try{
                    if($context->codebase->getDeclaringProperty('Illuminate\Database\DatabaseManager','$macros')!==$replacement){throw new RuntimeException('Macro field active SDK lookup missed replacement.');}
                    if((new $contractClass($this->root,$this->inner->calls))->result($context,$proof)!==null){throw new RuntimeException('Changed macro default admitted.');}
                }finally{foreach($slots as [$bucket,$key,$value]){$cache->values[$bucket][$key]=$value;}}
                $restored=(new $contractClass($this->root,$this->inner->calls))->result($context,$proof);
                if($restored===null||!$context->types->equals($restored,$expected)){throw new RuntimeException('Exact template not restored after macro field change.');}
                $checks['changed native macro default']=['slotsChanged'=>count($slots),'genuinePositiveBefore'=>true,'activeLookupChanged'=>true,'refusedAfter'=>true,'sameScopedTemplateRestored'=>true];
                $anonymousClasses=[];
                foreach($context->codebase->getClassDescendants('Illuminate\Database\Connection') as $nativeName){
                    $current=$context->codebase->getClassLike($nativeName);
                    if($current!==null&&$current->nameLocation===null&&str_ends_with(str_replace('\\','/',$current->location->file??''),'/Database/AnonymousConnections.php')){$anonymousClasses[]=$current;}
                }
                if(count($anonymousClasses)!==1){throw new RuntimeException('One actual anonymous Connection class is required before controls.');}
                $anonymousClass=$anonymousClasses[0];
                $anonymousMutations=[
                    'changed anonymous new start'=>$copy($anonymousClass,['location'=>$copy($anonymousClass->location,['span'=>new \Mago\Sdk\Span($anonymousClass->location->span->start+1,$anonymousClass->location->span->end)])]),
                    'changed anonymous construction end'=>$copy($anonymousClass,['location'=>$copy($anonymousClass->location,['span'=>new \Mago\Sdk\Span($anonymousClass->location->span->start,$anonymousClass->location->span->end-1)])]),
                    'changed anonymous class identity'=>$copy($anonymousClass,['name'=>$anonymousClass->name.'-changed']),
                    'named location on anonymous class'=>$copy($anonymousClass,['nameLocation'=>$anonymousClass->location]),
                    'changed anonymous parent'=>$copy($anonymousClass,['directParentClass'=>'Illuminate\Database\Connection']),
                    'changed anonymous kind'=>$copy($anonymousClass,['kind'=>\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface]),
                ];
                foreach($anonymousMutations as $label=>$replacement){
                    $slots=[];foreach($cache->values as$bucket=>$values){foreach($values as$key=>$value){if($value===$anonymousClass||$value==$anonymousClass){$slots[]=[$bucket,$key,$value];$cache->values[$bucket][$key]=$replacement;}}}
                    if($slots===[]){throw new RuntimeException('Actual anonymous class cache lookup was not primed: '.$label);}
                    try{
                        if($context->codebase->getClassLike($anonymousClass->name)!==$replacement){throw new RuntimeException('Changed anonymous class was not selected: '.$label);}
                        if((new $contractClass($this->root,$this->inner->calls))->result($context,$proof)!==null){throw new RuntimeException('Changed anonymous identity admitted: '.$label);}
                    }finally{foreach($slots as[$bucket,$key,$value]){$cache->values[$bucket][$key]=$value;}}
                    $restored=(new $contractClass($this->root,$this->inner->calls))->result($context,$proof);
                    if($restored===null||!$context->types->equals($restored,$expected)){throw new RuntimeException('Anonymous identity restoration failed: '.$label);}
                    $checks[$label]=['slotsChanged'=>count($slots),'genuinePositiveBefore'=>true,'activeLookupChanged'=>true,'refusedAfter'=>true,'sameScopedTemplateRestored'=>true];
                }
                $row['genuineTemplateControls']=$checks;
            }
            $contract = (new ReflectionProperty($this->inner, 'contract'))->getValue($this->inner);
            foreach (['hierarchy' => [$context->codebase, $proof['owner']], 'wrapper' => [$context, $proof], 'native' => [$context]] as $stage => $arguments) {
                try { $row['stages'][$stage] = (new ReflectionMethod($contract, $stage))->invoke($contract, ...$arguments); }
                catch (Throwable $error) { $row['stages'][$stage] = get_class($error).': '.$error->getMessage(); }
            }
            $manager='Illuminate\Database\DatabaseManager';$trait='Illuminate\Support\Traits\Macroable';
            $macro=$context->codebase->getDeclaringMethod($manager,'hasMacro');$field=$context->codebase->getDeclaringProperty($manager,'$macros');
            $row['macroProfile']=['scanDefault'=>$this->inner->calls->defaultMacroDispatch(),
                'managerHierarchy'=>(new ReflectionMethod($contract,'hierarchy'))->invoke($contract,$context->codebase,$manager),
                'traitHierarchy'=>(new ReflectionMethod($contract,'hierarchy'))->invoke($contract,$context->codebase,$trait),
                'default'=>(new ReflectionMethod($contract,'defaultMacroDispatch'))->invoke($contract,$context),
                'aliasEqual'=>$context->codebase->getDeclaringMethod($manager,'macroCall')==$context->codebase->getDeclaringMethod($trait,'__call'),
                'predicateBody'=>(new ReflectionMethod($contract,'nativeBody'))->invoke($contract,$context->codebase,$macro,'return isset(static::$macros[$name]);'),
                'catalog'=>(new ReflectionMethod($contract,'macroCatalog'))->invoke($contract,$context->codebase),
                'predicate'=>$macro===null?null:['static'=>$macro->static,'formal'=>(string)($macro->parameters[0]->type?->type??''),'formalDoc'=>$macro->parameters[0]->type?->fromDocblock,'return'=>(string)($macro->returnType?->type??''),'returnDoc'=>$macro->returnType?->fromDocblock],
                'field'=>$field===null?null:['name'=>$field->name,'flags'=>$field->flags->bits,'read'=>$field->readVisibility->name,'write'=>$field->writeVisibility->name,'declared'=>$field->declaredType,'default'=>(string)($field->defaultType?->type??''),'literal'=>(new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection($context->codebase,new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root)))->default($manager,'macros')]];
            $parsedField=(new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($this->root))->read($field->location?->file??$field->nameLocation->file);
            foreach((new \PhpParser\NodeFinder)->findInstanceOf($parsedField,\PhpParser\Node\Stmt\Trait_::class) as $traitNode){foreach($traitNode->getProperties() as $declaration){foreach($declaration->props as $propertyNode){
                $row['macroProfile']['fieldSource']=['owner'=>$traitNode->namespacedName?->toString(),'name'=>$propertyNode->name->name,'nativeName'=>[$field->nameLocation?->span->start,$field->nameLocation?->span->end],'syntaxName'=>[$propertyNode->name->getStartFilePos(),$propertyNode->name->getEndFilePos()+1],
                'defaultEmpty'=>$propertyNode->default instanceof \PhpParser\Node\Expr\Array_&&$propertyNode->default->items===[],'static'=>$declaration->isStatic(),'protected'=>$declaration->isProtected()];
            }}}
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
$afterRegistry=file_get_contents($package.'/bin/laramago-worker.php');
$afterRegistry=preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/','',$afterRegistry,1);

file_put_contents($workspace.'/full-after-worker.php',"<?php\ndeclare(strict_types=1);\nrequire \$argv[1];\n".''.$afterRegistry);
$config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => [$framework]],
    'extension-hosts' => ['templates' => ['command' => [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, 'observe'], 'workers' => 1, 'request-timeout-ms' => 120000]]];
file_put_contents($framework.'/Database/SqlServerConnection.php', <<<'SQL'
<?php
namespace Illuminate\Database;
class SqlServerConnection extends Connection {
    /**
     * @template TReturn
     * @param (\Closure(static): TReturn) $callback
     * @param int $attempts
     * @return TReturn
     */
    public function transaction(\Closure $callback, $attempts = 1) {
        for ($a = 1; $a <= $attempts; $a++) {
            if ($this->getDriverName() === 'sqlsrv') { return parent::transaction($callback, $attempts); }
            $this->getPdo()->exec('BEGIN TRAN');
            try { $result = $callback($this); $this->getPdo()->exec('COMMIT TRAN'); }
            catch (\Throwable $e) { $this->getPdo()->exec('ROLLBACK TRAN'); throw $e; }
            return $result;
        }
    }
}
SQL);
file_put_contents($framework.'/Database/AnonymousConnections.php', <<<'NATIVE'
<?php
namespace Illuminate\Database;
class SQLiteConnection extends Connection {}
function analysisOnlyConnection(): Connection {
    return new class extends SQLiteConnection {
        public function unrelated(): int { return 1; }
    };
}
NATIVE);
echo 'Workspace: '.$workspace."\n";
$run = static function (string $mode, int $workers = 1) use ($workspace, $config, $command, $package): array {
    $current = $config;
    $current['extension-hosts']['templates']['workers'] = $workers;
    $current['extension-hosts']['templates']['command'][8] = $mode;
    if (str_ends_with($mode, 'controls')) { $current['threads']=1; }
    if (str_starts_with($mode, 'integrated')) { $current['extension-hosts']['templates']['command'] = [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', $mode === 'integrated-before' ? $workspace.'/full-before-worker.php' : $workspace.'/full-after-worker.php', $package.'/vendor/autoload.php', $workspace]; }
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
foreach ($ranges as [$name,$start,$end,$eligible]) {
    if (!$eligible) { continue; }$joined=[];
    foreach($native['issues'] as $issue){foreach($issue['annotations'] as $annotation){
        if($annotation['kind']==='Primary'&&$annotation['span']['start']['offset']>=$start&&$annotation['span']['end']['offset']<=$end&&$issue['code']==='mixed-return-statement'&&$issue['level']==='Error'){$joined[]=$issue;break;}
    }}
    if($joined===[]){throw new RuntimeException('Genuine original template Error absent; stop before controls: '.$name);}
}
$report = $run('standalone');
$controls = $run('controls');
$controlBag=static function(array $report):array{$rows=array_map(static fn(array $issue):string=>json_encode($issue,JSON_THROW_ON_ERROR),$report['issues']);sort($rows);return $rows;};
if($controlBag($controls)!==$controlBag($report)){throw new RuntimeException('Native mutation controls changed the complete standalone report.');}
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
$managerFile=$framework.'/Database/DatabaseManager.php';$managerBytes=file_get_contents($managerFile);
$macroFile=$framework.'/Macroable/Traits/Macroable.php';$macroBytes=file_get_contents($macroFile);
$composerFile=$workspace.'/composer.json';$composerBytes=file_get_contents($composerFile);
if(!is_dir($workspace.'/app')){mkdir($workspace.'/app');}
foreach(['changed-manager-macro-dispatch','changed-manager-alias','changed-macro-predicate','nonempty-native-macro-table','configured-transaction-macro','unknown-macro-catalog','visible-transaction-macro'] as $control){
    if($control==='changed-manager-macro-dispatch'){file_put_contents($managerFile,str_replace('if (static::hasMacro($method)) { return $this->macroCall($method, $parameters); }','if (static::hasMacro($method)) { return null; }',$managerBytes));}
    if($control==='changed-manager-alias'){file_put_contents($managerFile,str_replace('__call as macroCall','__call as incompatibleAlias',$managerBytes));}
    if($control==='changed-macro-predicate'){file_put_contents($macroFile,str_replace('return isset(static::$macros[$name]);','return false;',$macroBytes));}
    if($control==='nonempty-native-macro-table'){file_put_contents($macroFile,str_replace('protected static $macros = [];',"protected static \$macros = ['transaction' => null];",$macroBytes));}
    if(in_array($control,['configured-transaction-macro','unknown-macro-catalog'],true)){
        file_put_contents($composerFile,json_encode(['config'=>['vendor-dir'=>'packages'],'extra'=>['laramago'=>['macro-files'=>[$control==='unknown-macro-catalog'?'app/missing.php':'app/macros.php']]]],JSON_THROW_ON_ERROR));
        if($control==='configured-transaction-macro'){file_put_contents($workspace.'/app/macros.php',"<?php\n\\Illuminate\\Database\\DatabaseManager::macro('transaction', static fn(): int => 1);\n");}
    }
    if($control==='visible-transaction-macro'){file_put_contents($workspace.'/cases.php',$source."\n\\Illuminate\\Database\\DatabaseManager::macro('transaction', static fn(): int => 1);\n");}
    try{
        $keep=$run($control.'-observe');$changed=$run($control);
        if($wholeIssues($keep)===[]||$wholeIssues($keep)!==$wholeIssues($changed)){throw new RuntimeException('Native/default macro dispatch veto did not preserve complete report: '.$control);}
    }finally{
        file_put_contents($managerFile,$managerBytes);file_put_contents($macroFile,$macroBytes);file_put_contents($composerFile,$composerBytes);file_put_contents($workspace.'/cases.php',$source);
        if(is_file($workspace.'/app/macros.php')){unlink($workspace.'/app/macros.php');}
    }
    if($wholeIssues($run($control.'-restored'))!==$wholeIssues($report)){throw new RuntimeException('Native/default macro dispatch restoration differed.');}
    echo 'PASS: '.$control." keeps the entire native report and restores scoped template.\n";
}
// Driver registration is distinct from a macro replacement and crosses a physical Connection boundary.
$driverManager=str_replace("    private array \$connections = [];","    private array \$connections = [];\n    private array \$extensions = [];",$managerBytes,$count);
if($count!==1){throw new RuntimeException('Exactly one native manager field anchor expected.');}
$extensionMethod="\n    public function extend(\$name, callable \$resolver) { \$this->extensions[\$name] = \$resolver; }\n";
$close=strrpos($driverManager,'}');$driverManager=substr($driverManager,0,$close).$extensionMethod.substr($driverManager,$close);
$driverSource=$source."\n\\Illuminate\\Support\\Facades\\DB::extend('fixture', static fn() => new \\Illuminate\\Database\\Connection);\n";
file_put_contents($managerFile,$driverManager);file_put_contents($workspace.'/cases.php',$driverSource);
$driverBefore=$run('driver-observe');$driverOne=$run('driver-one');$driverThree=$run('driver-three',3);
$driverControls=$run('driver-controls');if($wholeIssues($driverControls)!==$wholeIssues($driverOne)){throw new RuntimeException('Actual driver metadata mutation controls changed the complete native report.');}
$beforeBag=$wholeIssues($driverBefore);$driverBag=$wholeIssues($driverOne);$lost=array_values(array_diff($beforeBag,$driverBag));
if($driverBag!==$wholeIssues($driverThree)||count($lost)!==$corrected||array_diff($driverBag,$beforeBag)!==[]){throw new RuntimeException('Driver extension failed to restore exactly the native scoped-template obligations.');}
foreach($lost as$item){if(json_decode($item,true,flags:JSON_THROW_ON_ERROR)['code']!=='mixed-return-statement'){throw new RuntimeException('Driver extension changed an unrelated diagnostic.');}}
foreach(['changed-connection-boundary','changed-extension-storage','overridden-driver-transaction'] as$control){
    if($control==='changed-connection-boundary'){file_put_contents($managerFile,str_replace('public function configure(Connection $connection, $type): Connection { return $connection; }','public function configure(Connection $connection, $type): Connection { return new Connection; }',$driverManager,$count));if($count!==1){throw new RuntimeException('Physical configure body control did not change.');}}
    if($control==='changed-extension-storage'){file_put_contents($managerFile,str_replace('$this->extensions[$name] = $resolver;','$this->connections[$name] = $resolver;',$driverManager,$count));if($count!==1){throw new RuntimeException('Physical extension body control did not change.');}}
    if($control==='overridden-driver-transaction'){file_put_contents($workspace.'/cases.php',$driverSource."\nclass DriverTransactionOverride extends \\Illuminate\\Database\\Connection { public function transaction(\\Closure \$callback, \$attempts = 1) { return null; } }\n");}
    try{$keep=$run($control.'-observe');$changed=$run($control);if($wholeIssues($keep)===[]||$wholeIssues($keep)!==$wholeIssues($changed)){throw new RuntimeException('A changed physical driver contract was supplemented: '.$control);}}
    finally{file_put_contents($managerFile,$driverManager);file_put_contents($workspace.'/cases.php',$driverSource);}
    if($wholeIssues($run($control.'-restored'))!==$driverBag){throw new RuntimeException('Driver source restoration changed the native report: '.$control);}
    echo 'PASS: '.$control." preserves the complete nonempty native report and restores.\n";
}
$anonymousFile=$framework.'/Database/AnonymousConnections.php';$anonymousBytes=file_get_contents($anonymousFile);
$changedAnonymous=str_replace('public function unrelated(): int { return 1; }','public function unrelated(): int { return 1; } public function transaction(\Closure $callback, $attempts = 1) { return null; }',$anonymousBytes,$count);
if($count!==1){throw new RuntimeException('Anonymous source override must be meaningful.');}
file_put_contents($anonymousFile,$changedAnonymous);
try{
    $keep=$run('anonymous-transaction-override-observe');$changed=$run('anonymous-transaction-override');
    if($wholeIssues($keep)===[]||$wholeIssues($keep)!==$wholeIssues($changed)){throw new RuntimeException('Anonymous transaction override was supplemented.');}
}finally{file_put_contents($anonymousFile,$anonymousBytes);}
if($wholeIssues($run('anonymous-transaction-override-restored'))!==$wholeIssues($driverOne)){throw new RuntimeException('Anonymous override restoration changed the full report.');}
echo "PASS: anonymous transaction override veto and exact restoration.\n";
$sqlFile=$framework.'/Database/SqlServerConnection.php';$sqlBytes=file_get_contents($sqlFile);
foreach(['changed-SQL-parent-result','changed-SQL-callback-result','strong-SQL-result-doc'] as $control){
    $replacement=match($control){
        'changed-SQL-parent-result'=>str_replace('return parent::transaction($callback, $attempts);','return null;',$sqlBytes,$count),
        'changed-SQL-callback-result'=>str_replace('return $result;','return null;',$sqlBytes,$count),
        'strong-SQL-result-doc'=>str_replace('@return TReturn','@return string',$sqlBytes,$count),
    };
    if($count!==1){throw new RuntimeException('SQL driver source mutation was not meaningful.');}
    file_put_contents($sqlFile,$replacement);
    try{
        $before=$run($control.'-observe');$after=$run($control);
        if($wholeIssues($before)===[]||$wholeIssues($before)!==$wholeIssues($after)){throw new RuntimeException('Changed SQL driver contract was supplemented.');}
    }finally{file_put_contents($sqlFile,$sqlBytes);}
    if($wholeIssues($run($control.'-restored'))!==$driverBag){throw new RuntimeException('SQL driver restoration changed the whole report.');}
    echo 'PASS: '.$control." preserves genuine diagnostics and restores.\n";
}
file_put_contents($managerFile,$managerBytes);file_put_contents($workspace.'/cases.php',$source);
echo "PASS: physical driver extension boundary, one and three workers, with changed-source vetoes.\n";
echo 'PASS: '.count($cases).' source cases, '.$corrected.' exact template corrections, '.$retained." retained negative reports.\n";
