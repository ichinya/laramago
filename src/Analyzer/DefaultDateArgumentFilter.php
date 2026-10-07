<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{CodebaseScanContext,CodebaseScanHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,MetadataFlags};
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Offline framework-default date creation, with source-visible replacement configurations vetoed. */
final class DefaultDateArgumentFilter implements IssueFilterHook,CodebaseScanHook
{
    private const FACTORY='Illuminate\\Support\\DateFactory';
    private const FACADE='Illuminate\\Support\\Facades\\Date';
    private const CARBON='Illuminate\\Support\\Carbon';
    private const BASE_FACADE='Illuminate\\Support\\Facades\\Facade';
    private array $files=[];
    private bool $complete=false;
    private bool $blocked=false;
    private int $bytes=0;
    public array $stages=[];
    public array $dependencies=[];
    public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes(): array { return ['less-specific-argument']; }
    public function getTargets(): array { return ['**']; }
    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->files=[];$this->complete=$this->blocked=false;$this->bytes=0; }
        $source=new GuardedStringCastContracts($this->root);$finder=new NodeFinder;
        foreach ($context->files as $entry) {
            $context->cancellation->throwIfCancelled();$this->bytes+=strlen($entry->contents);
            if (strlen($entry->contents)>2_000_000 || $this->bytes>67_108_864 || count($this->files)>=100_000) { $this->blocked=true;break; }
            $path=$source->path($entry->path);
            if ($path==='') { continue; } // Builtin virtual source cannot configure a user Date facade.
            if (isset($this->files[$path]) && $this->files[$path]!==hash('sha256',$entry->contents)) { $this->blocked=true;break; }
            $this->files[$path]=hash('sha256',$entry->contents);
            try { $nodes=(new ParserFactory)->createForNewestSupportedVersion()->parse($entry->contents)??[];$nodes=(new NodeTraverser(new NameResolver))->traverse($nodes); }
            catch (\PhpParser\Error) { $this->blocked=true;break; }
            // Declaration of the audited swap implementation does not itself replace the Date handler.
            // Its callers remain subject to the selected facade/container override vetoes below.
            $ordinarySwapCalls=[];
            foreach ($finder->findInstanceOf($nodes,Node\Stmt\Class_::class) as $class) {
                $swap=$class->getMethod('swap');
                if ($class->namespacedName?->toString()!==self::BASE_FACADE || $swap===null) { continue; }
                $body=substr($entry->contents,$swap->getStartFilePos(),$swap->getEndFilePos()-$swap->getStartFilePos()+1);
                foreach ($this->dispatchContracts() as [$receiver,$method,$physicalOwner,$expected]) {
                    if ($receiver!==self::BASE_FACADE || $method!=='swap' || $source->tokens($body)!==$source->tokens($expected)) { continue; }
                    foreach ($finder->findInstanceOf($swap->stmts,Node\Expr\MethodCall::class) as $call) { $ordinarySwapCalls[spl_object_id($call)]=true; }
                }
            }
            // Only calls can change the handler. The exact factory declaration itself is checked separately.
            foreach ($finder->findInstanceOf($nodes,Node\Expr\StaticCall::class) as $call) {
                if (! $call->class instanceof Node\Name || ! in_array(strtolower($call->class->toString()),[strtolower(self::FACTORY),strtolower(self::FACADE)],true)) { continue; }
                if (! $call->name instanceof Node\Identifier || in_array(strtolower($call->name->name),[
                    'use','useclass','usefactory','usecallable','swap','resolved','setfacadeapplication','clearresolvedinstance','clearresolvedinstances','macro','mixin',
                    'spy','partialmock','shouldreceive','expects','fake',
                ],true)) { $this->blocked=true; }
            }
            foreach ($finder->findInstanceOf($nodes,Node\Expr\MethodCall::class) as $call) {
                if (isset($ordinarySwapCalls[spl_object_id($call)])) { continue; }
                if ($call->name instanceof Node\Identifier && in_array(strtolower($call->name->name),['useclass','usefactory','usecallable'],true)) { $this->blocked=true; }
                if (! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->name),['bind','bindif','singleton','singletonif','scoped','instance','offsetset'],true)) { continue; }
                $key=$call->args[0]->value??null;
                $ordinaryClassKey=$key instanceof Node\Expr\ClassConstFetch && $key->class instanceof Node\Name && $key->name instanceof Node\Identifier
                    && strtolower($key->name->name)==='class' && ! in_array(strtolower($key->class->toString()),[strtolower(self::FACTORY),strtolower(self::FACADE)],true);
                if (! $ordinaryClassKey && (! $key instanceof Node\Scalar\String_ || in_array(strtolower($key->value),['date',strtolower(self::FACTORY)],true))) { $this->blocked=true; }
            }
        }
        $this->complete=$context->lastBatch && ! $this->blocked;
    }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $proof=new self($this->root);$proof->files=$this->files;$proof->complete=$this->complete;$proof->blocked=$this->blocked;
        $decision=$proof->evaluate($context);$this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;
        return $decision;
    }
    private function evaluate(IssueFilterContext $context): IssueFilterDecision
    {
        $contracts=new SourceArgumentDeclarationContracts($this->root);
        try {
            $receiving=$contracts->receiving($context,['less-specific-argument']);if ($receiving===null) { return IssueFilterDecision::Keep; }
            ['source'=>$source,'file'=>$file,'argument'=>$argument]=$receiving;
            $now=$argument->value;
            if (! $contracts->stage('direct zero-argument default date helper',$now instanceof Node\Expr\FuncCall && $now->name instanceof Node\Name
                && strcasecmp($now->name->toString(),'now')===0 && $now->args===[] && ! $now->isFirstClassCallable()
                && str_contains($context->issue->message,'provided type `Carbon\\CarbonInterface` is less specific.'))) { return IssueFilterDecision::Keep; }
            if (! $contracts->stage('complete source-visible default date configuration',$this->complete && ! $this->blocked)) { return IssueFilterDecision::Keep; }
            foreach ($this->files as $path=>$hash) {
                if (hash_file('sha256',$path)!==$hash) { $contracts->stage('current configuration source snapshots',false);return IssueFilterDecision::Keep; }
            }
            $resolved=$now->name->getAttribute('resolvedName');$namespaced=$now->name->getAttribute('namespacedName');
            if ($resolved instanceof Node\Name && strcasecmp($resolved->toString(),'now')!==0
                || $namespaced instanceof Node\Name && strcasecmp($namespaced->toString(),'now')!==0 && $context->codebase->getFunction($namespaced->toString())!==null) {
                $contracts->stage('unshadowed global helper binding',false);return IssueFilterDecision::Keep;
            }
            $helper=$context->codebase->getFunction('now');$contracts->dependencies['helper']=$helper;
            $helperFile=$helper===null?null:$source->read($helper->location->file);
            $helpers=$helperFile===null?[]:(new NodeFinder)->findInstanceOf($helperFile['nodes'],Node\Stmt\Function_::class);
            $helpers=array_values(array_filter($helpers,static fn(Node\Stmt\Function_ $node):bool=>$node->name->name==='now' && $source->located($helper->location,$node,$helperFile)));
            $parameter=$helper?->parameters[0]??null;
            if (! $contracts->stage('genuine physical helper and unchanged interface return',count($helpers)===1 && count($helper->parameters)===1
                && $helper->identifier->class===null && $helper->identifier->name==='now' && ! $helper->flags->contains(MetadataFlags::BY_REFERENCE)
                && $parameter->name==='$tz' && ! $parameter->flags->contains(MetadataFlags::BY_REFERENCE) && ! $parameter->flags->contains(MetadataFlags::VARIADIC)
                && $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) && $parameter->defaultType!==null && $parameter->outType===null
                && $contracts->same($context,$parameter->defaultType->type,Type::null())
                && $contracts->same($context,$helper->declaredReturnType?->type,Type::namedObject('Carbon\\CarbonInterface'))
                && $contracts->same($context,$helper->returnType?->type,Type::namedObject('Carbon\\CarbonInterface')))) { return IssueFilterDecision::Keep; }
            $returned=$helpers[0]->stmts[0]??null;$dateCall=$returned instanceof Node\Stmt\Return_?$returned->expr:null;
            if (! $contracts->stage('exact helper delegates to Date now with its default timezone',count($helpers[0]->stmts)===1
                && $dateCall instanceof Node\Expr\StaticCall && $dateCall->class instanceof Node\Name && $dateCall->class->toString()===self::FACADE
                && $dateCall->name instanceof Node\Identifier && $dateCall->name->name==='now' && count($dateCall->args)===1
                && $dateCall->args[0]->value instanceof Node\Expr\FuncCall && $dateCall->args[0]->value->name instanceof Node\Name
                && in_array($dateCall->args[0]->value->name->toString(),['enum_value','Illuminate\\Support\\enum_value'],true) && count($dateCall->args[0]->value->args)===1
                && $dateCall->args[0]->value->args[0]->value instanceof Node\Expr\Variable && $dateCall->args[0]->value->args[0]->value->name==='tz')) { return IssueFilterDecision::Keep; }
            $enumName=$dateCall->args[0]->value->name->toString();
            if (! $this->boundFunction($context,$source,$contracts,$enumName,<<<'PHP'
function enum_value($value, $default = null) {
    return match (true) {
        $value instanceof \BackedEnum => $value->value,
        $value instanceof \UnitEnum => $value->name,
        default => $value ?? value($default),
    };
}
PHP) || ! $this->unshadowedValueFallback($context,$source,$contracts,$enumName)
                || ! $this->boundFunction($context,$source,$contracts,'value','function value($value, ...$args) { return $value instanceof Closure ? $value(...$args) : $value; }')) { return IssueFilterDecision::Keep; }
            $classes=[];
            foreach ([self::FACTORY,self::FACADE,self::CARBON,self::BASE_FACADE] as $name) {
                $native=$context->codebase->getClass($name);$contracts->dependencies[$name]=$native;
                $physical=$native===null?null:$source->read($native->location->file);
                $matches=$physical===null?[]:(new NodeFinder)->findInstanceOf($physical['nodes'],Node\Stmt\Class_::class);
                $matches=array_values(array_filter($matches,static fn(Node\Stmt\Class_ $node):bool=>$node->namespacedName?->toString()===$name && $source->located($native->location,$node,$physical)));
                if (! $contracts->stage('physical default class '.$name,$native!==null && $native->kind===ClassLikeKind::Class_ && $native->unresolvedHierarchyDependencies===[] && count($matches)===1)) { return IssueFilterDecision::Keep; }
                $classes[$name]=[$matches[0],$physical];
            }
            [$factory,$factoryFile]=$classes[self::FACTORY];[$facade]=$classes[self::FACADE];[$carbon]=$classes[self::CARBON];
            $factoryDefault=$this->classConstant($factory,'DEFAULT_CLASS_NAME');$facadeDefault=$this->classConstant($facade,'DEFAULT_FACADE');
            if (! $contracts->stage('exact framework default class constants',$factoryDefault===self::CARBON && $facadeDefault===self::FACTORY
                && $carbon->extends?->toString()==='Carbon\\Carbon' && $facade->extends?->toString()===self::BASE_FACADE
                && $facade->getMethod('now')===null && $facade->getMethod('getFacadeRoot')===null && $facade->getMethod('swap')===null && $facade->getMethod('__callStatic')===null
                && $facade->getProperties()===[] && (new NodeFinder)->findInstanceOf($facade->stmts,Node\Stmt\TraitUse::class)===[]
                && $factory->getMethod('now')===null && $factory->getMethod('__construct')===null
                && (new NodeFinder)->findInstanceOf($factory->stmts,Node\Stmt\TraitUse::class)===[])) { return IssueFilterDecision::Keep; }
            foreach (['dateClass','callable','factory'] as $field) {
                $fields=[];foreach ($factory->getProperties() as $property) { foreach ($property->props as $item) { if ($item->name->name===$field) { $fields[]=[$property,$item]; } } }
                $native=$context->codebase->getProperty(self::FACTORY,'$'.$field);$contracts->dependencies['factory:$'.$field]=$native;
                if (! $contracts->stage('physical unconfigured static handler '.$field,count($fields)===1 && $fields[0][0]->isProtected() && $fields[0][0]->isStatic()
                    && $fields[0][1]->default===null && $native!==null && $native->hooks===[] && $native->flags->contains(MetadataFlags::STATIC)
                    && ! $native->flags->contains(MetadataFlags::VIRTUAL_PROPERTY))) { return IssueFilterDecision::Keep; }
            }
            $dispatch=$factory->getMethod('__call');$dispatchNative=$context->codebase->getMethod(self::FACTORY,'__call');$contracts->dependencies['factoryDispatch']=$dispatchNative;
            if (! $contracts->stage('genuine unchanged date factory dispatch',$dispatch!==null && $dispatchNative!==null
                && ! $dispatchNative->static && ! $dispatchNative->flags->contains(MetadataFlags::BY_REFERENCE)
                && $source->located($dispatchNative->location,$dispatch,$factoryFile) && $this->defaultDispatch($source,$dispatch,$factoryFile))) { return IssueFilterDecision::Keep; }
            foreach ($this->dispatchContracts() as [$receiver,$method,$physicalOwner,$expected]) {
                if (! $this->boundMethod($context,$source,$contracts,$receiver,$method,$physicalOwner,$expected)) { return IssueFilterDecision::Keep; }
            }
            [$baseFacade]=$classes[self::BASE_FACADE];
            foreach (['app','resolvedInstance'] as $field) {
                $fields=[];foreach ($baseFacade->getProperties() as $property) { foreach ($property->props as $item) { if ($item->name->name===$field) { $fields[]=[$property,$item]; } } }
                $native=$context->codebase->getProperty(self::BASE_FACADE,'$'.$field);$contracts->dependencies['facade:$'.$field]=$native;
                if (! $contracts->stage('physical initially unbound facade field '.$field,count($fields)===1 && $fields[0][0]->isProtected() && $fields[0][0]->isStatic()
                    && $fields[0][1]->default===null && $native!==null && $native->hooks===[] && $native->flags->contains(MetadataFlags::STATIC)
                    && ! $native->flags->contains(MetadataFlags::VIRTUAL_PROPERTY))) { return IssueFilterDecision::Keep; }
            }
            $domain=Type::namedObject(self::CARBON);
            if (! $contracts->admits($context,$receiving,$domain)) { return IssueFilterDecision::Keep; }
            $this->certificate=['file'=>$context->file,'sourceSha256'=>$file['hash'],'argumentSpan'=>SourceArgumentDeclarationContracts::span($now),
                'domain'=>self::CARBON,'policy'=>'offline framework default date class with source-visible configuration vetoes',
                'runtimeConfigurationObserved'=>false,'afterFileAuthority'=>false,'nativeTypesReplaced'=>false];
            return IssueFilterDecision::Remove;
        } finally { $this->stages=$contracts->stages;$this->dependencies=$contracts->dependencies; }
    }
    private function boundMethod(IssueFilterContext $context,GuardedStringCastContracts $source,SourceArgumentDeclarationContracts $contracts,string $receiver,string $name,string $physicalOwner,string $expected): bool
    {
        $native=$context->codebase->getMethod($receiver,$name)??$context->codebase->getDeclaringMethod($receiver,$name);$contracts->dependencies[$receiver.'::'.$name]=$native;
        $declaring=$native===null?null:$context->codebase->getDeclaringMethod($receiver,$name);
        $file=$native===null?null:$source->read($native->location->file);
        $methods=$file===null?[]:(new NodeFinder)->findInstanceOf($file['nodes'],Node\Stmt\ClassMethod::class);
        $methods=array_values(array_filter($methods,static fn(Node\Stmt\ClassMethod $method):bool=>$method->name->name===$name && $source->located($native->location,$method,$file)));
        $method=$methods[0]??null;$owner=$method===null?null:($file['parents'][spl_object_id($method)]??null);
        if (! $contracts->stage('physical native default dispatch '.$receiver.'::'.$name,count($methods)===1 && $native!==null && $declaring!==null
            && $native->identifier==$declaring->identifier && $native->identifier->name===$name
            && $owner instanceof Node\Stmt\ClassLike && $owner->namespacedName?->toString()===$physicalOwner
            && $method->isStatic() && ! $method->byRef && ! $native->flags->contains(MetadataFlags::BY_REFERENCE)
            && $native->static && $source->located($native->nameLocation,$method->name,$file)
            && count($native->parameters)===count($method->params))) { return false; }
        foreach ($method->params as $index=>$parameter) {
            $formal=$native->parameters[$index];
            if (! $contracts->stage('unchanged dispatch formal '.$receiver.'::'.$name.' #'.($index+1),$parameter->var instanceof Node\Expr\Variable
                && is_string($parameter->var->name) && $formal->name==='$'.$parameter->var->name && ! $parameter->byRef && ! $parameter->variadic
                && ! $formal->flags->contains(MetadataFlags::BY_REFERENCE) && ! $formal->flags->contains(MetadataFlags::VARIADIC) && $formal->outType===null
                && $source->located($formal->location,$parameter,$file) && $source->located($formal->nameLocation,$parameter->var,$file)
                && ($parameter->type===null?$formal->declaredType===null:$source->located($formal->declaredType?->location,$parameter->type,$file)))) { return false; }
        }
        $body=substr($file['contents'],$method->getStartFilePos(),$method->getEndFilePos()-$method->getStartFilePos()+1);
        return $contracts->stage('exact unchanged physical dispatch body '.$receiver.'::'.$name,$source->tokens($body)===$source->tokens($expected));
    }
    private function boundFunction(IssueFilterContext $context,GuardedStringCastContracts $source,SourceArgumentDeclarationContracts $contracts,string $name,string $expected): bool
    {
        $native=$context->codebase->getFunction($name);$contracts->dependencies['global:'.$name]=$native;
        $file=$native===null?null:$source->read($native->location->file);
        $functions=$file===null?[]:(new NodeFinder)->findInstanceOf($file['nodes'],Node\Stmt\Function_::class);
        $functions=array_values(array_filter($functions,static fn(Node\Stmt\Function_ $function):bool=>$function->namespacedName?->toString()===$name && $source->located($native->location,$function,$file)));
        $function=$functions[0]??null;
        if (! $contracts->stage('physical declared default helper '.$name,count($functions)===1 && $native!==null && $native->identifier->class===null && $native->identifier->name===$name
            && ! $function->byRef && ! $native->flags->contains(MetadataFlags::BY_REFERENCE) && $source->located($native->nameLocation,$function->name,$file)
            && count($native->parameters)===count($function->params))) { return false; }
        foreach ($function->params as $index=>$parameter) {
            $formal=$native->parameters[$index];
            if (! $contracts->stage('unchanged declared helper formal '.$name.' #'.($index+1),$parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                && $formal->name==='$'.$parameter->var->name && ! $parameter->byRef && ! $formal->flags->contains(MetadataFlags::BY_REFERENCE) && $formal->outType===null
                && $formal->flags->contains(MetadataFlags::VARIADIC)===$parameter->variadic && $formal->declaredType===null
                && $formal->flags->contains(MetadataFlags::HAS_DEFAULT)===($parameter->default!==null)
                && $source->located($formal->location,$parameter,$file) && $source->located($formal->nameLocation,$parameter->var,$file)
                && ($parameter->default===null || $formal->defaultType!==null && $contracts->same($context,$formal->defaultType->type,Type::null())))) { return false; }
        }
        $body=substr($file['contents'],$function->getStartFilePos(),$function->getEndFilePos()-$function->getStartFilePos()+1);
        return $contracts->stage('exact declared helper body '.$name,$source->tokens($body)===$source->tokens($expected));
    }
    /** Bind the unqualified value() call in the selected physical enum helper. */
    private function unshadowedValueFallback(IssueFilterContext $context,GuardedStringCastContracts $source,SourceArgumentDeclarationContracts $contracts,string $enumName): bool
    {
        $native=$contracts->dependencies['global:'.$enumName]??null;
        $file=$native===null?null:$source->read($native->location->file);
        $functions=$file===null?[]:(new NodeFinder)->findInstanceOf($file['nodes'],Node\Stmt\Function_::class);
        $functions=array_values(array_filter($functions,static fn(Node\Stmt\Function_ $function):bool=>$function->namespacedName?->toString()===$enumName && $source->located($native->location,$function,$file)));
        $calls=count($functions)===1?(new NodeFinder)->findInstanceOf($functions[0]->stmts,Node\Expr\FuncCall::class):[];
        $call=count($calls)===1?$calls[0]:null;
        if (! $contracts->stage('exact unqualified enum default value call',$call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name
            && ! $call->name instanceof Node\Name\FullyQualified && $call->name->toString()==='value' && count($call->args)===1
            && $call->args[0]->value instanceof Node\Expr\Variable && $call->args[0]->value->name==='default')) { return false; }
        if ($enumName==='enum_value') { return true; }
        $namespaced=$call->name->getAttribute('namespacedName');
        $shadow=$context->codebase->getFunction('Illuminate\Support\value');
        return $contracts->stage('unshadowed namespace fallback to genuine global value',$namespaced instanceof Node\Name
            && $namespaced->toString()==='Illuminate\Support\value' && $shadow===null);
    }
    /** @return list<array{string,string,string,string}> */
    private function dispatchContracts(): array
    {
        return [
            [self::FACADE,'getFacadeAccessor',self::FACADE,"protected static function getFacadeAccessor() { return 'date'; }"],
            [self::FACADE,'resolveFacadeInstance',self::FACADE,<<<'PHP'
protected static function resolveFacadeInstance($name) {
    if (! isset(static::$resolvedInstance[$name]) && ! isset(static::$app, static::$app[$name])) {
        $class = static::DEFAULT_FACADE;
        static::swap(new $class);
    }
    return parent::resolveFacadeInstance($name);
}
PHP],
            [self::BASE_FACADE,'getFacadeRoot',self::BASE_FACADE,'public static function getFacadeRoot() { return static::resolveFacadeInstance(static::getFacadeAccessor()); }'],
            [self::BASE_FACADE,'swap',self::BASE_FACADE,<<<'PHP'
public static function swap($instance) {
    static::$resolvedInstance[static::getFacadeAccessor()] = $instance;
    if (isset(static::$app)) { static::$app->instance(static::getFacadeAccessor(), $instance); }
}
PHP],
            [self::BASE_FACADE,'resolveFacadeInstance',self::BASE_FACADE,<<<'PHP'
protected static function resolveFacadeInstance($name) {
    if (isset(static::$resolvedInstance[$name])) { return static::$resolvedInstance[$name]; }
    if (static::$app) {
        if (static::$cached) { return static::$resolvedInstance[$name] = static::$app[$name]; }
        return static::$app[$name];
    }
}
PHP],
            [self::BASE_FACADE,'__callStatic',self::BASE_FACADE,<<<'PHP'
public static function __callStatic($method, $args) {
    $instance = static::getFacadeRoot();
    if (! $instance) { throw new RuntimeException('A facade root has not been set.'); }
    return $instance->$method(...$args);
}
PHP],
            [self::CARBON,'now','Carbon\\Traits\\Creator','public static function now(DateTimeZone|string|int|null $timezone = null): static { return new static(null, $timezone); }'],
        ];
    }
    private function classConstant(Node\Stmt\Class_ $class,string $name): ?string
    {
        foreach ($class->getConstants() as $declaration) { foreach ($declaration->consts as $constant) {
            if ($constant->name->name===$name && $constant->value instanceof Node\Expr\ClassConstFetch && $constant->value->class instanceof Node\Name
                && $constant->value->name instanceof Node\Identifier && strtolower($constant->value->name->name)==='class') { return $constant->value->class->toString(); }
        } }return null;
    }
    private function defaultDispatch(GuardedStringCastContracts $source,Node\Stmt\ClassMethod $method,array $file): bool
    {
        $body=substr($file['contents'],$method->getStartFilePos(),$method->getEndFilePos()-$method->getStartFilePos()+1);
        $expected=<<<'PHP'
public function __call($method, $parameters) {
    $defaultClassName = static::DEFAULT_CLASS_NAME;
    if (static::$callable) { return call_user_func(static::$callable, $defaultClassName::$method(...$parameters)); }
    if (static::$factory) { return static::$factory->$method(...$parameters); }
    $dateClass = static::$dateClass ?: $defaultClassName;
    if (method_exists($dateClass, $method) || method_exists($dateClass, 'hasMacro') && $dateClass::hasMacro($method)) { return $dateClass::$method(...$parameters); }
    $date = $defaultClassName::$method(...$parameters);
    if (method_exists($dateClass, 'instance')) { return $dateClass::instance($date); }
    return new $dateClass($date->format('Y-m-d H:i:s.u'), $date->getTimezone());
}
PHP;
        return $source->tokens($body)===$source->tokens($expected);
    }
}
