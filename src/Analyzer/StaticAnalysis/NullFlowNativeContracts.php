<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Assertion\{TypeAssertion,TypeAssertionKind};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags,PropertyMetadata};
use Mago\Sdk\Analyzer\Type\{NamedObjectType,SimpleAtomicType,SimpleAtomicTypeKind};
use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory,PrettyPrinter\Standard};
use PhpParser\NodeVisitor\NameResolver;

/** Current source/native declarations. Compact caches never retain syntax trees or SDK snapshots. */
final class NullFlowNativeContracts
{
    private array $sources=[];
    public function __construct(private readonly string $root='.') {}
    public function clear():void { $this->sources=[]; }
    public function current(string $file,string $bytes):bool { $current=$this->bytes($file);return $current!==null&&hash('sha256',$current)===hash('sha256',$bytes); }
    public function bytes(string $file):?string {
        $file=str_replace('\\','/',$file);if(str_starts_with($file,'//?/')) { $file=substr($file,4); }
        $disk=str_starts_with($file,'/')||preg_match('~^[A-Za-z]:/~',$file)===1?$file:rtrim($this->root,'/\\').'/'.$file;
        $size=@filesize($disk);$bytes=$size!==false&&$size<=2_000_000?@file_get_contents($disk):false;return $bytes===false?null:$bytes;
    }
    public function source(string $file,string $bytes):array {
        $key=hash('sha256',$file."\0".$bytes);if(isset($this->sources[$key])) { return $this->sources[$key]; }
        if(strlen($bytes)>2_000_000) { return []; }
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }
        catch(\PhpParser\Error) { return []; }
        $finder=new NodeFinder;$printer=new Standard;$result=['classes'=>[],'functions'=>[],'calls'=>[],'accesses'=>[],'sha256'=>hash('sha256',$bytes)];$describer=new SourceNullFacts;
        foreach($finder->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\ClassLike&&$node->name!==null&&isset($node->namespacedName)) as $class) {
            $name=$class->namespacedName->toString();$methods=$properties=[];
            foreach($class->getMethods() as $method) { $methods[strtolower($method->name->name)]=$this->functionSource($method,$printer,$finder); }
            foreach($class->getProperties() as $property) { foreach($property->props as $item) {
                $setterOnly=PhysicalSetOnlyPropertyRead::source($property,$item->name->name);
                $properties[$item->name->name]=['span'=>self::nodeSpan($property),'nameSpan'=>self::nodeSpan($item->name),'typeSpan'=>$property->type===null?null:self::nodeSpan($property->type),
                    'type'=>$property->type===null?null:$printer->prettyPrint([$property->type]),'ordinary'=>$property->hooks===[]&&$property->attrGroups===[]&&!$property->isStatic()&&!$property->isAbstract(),'setterOnlyRead'=>$setterOnly];
            } }
            foreach($class->getMethod('__construct')?->params??[] as $parameter) { if($parameter->isPromoted()&&is_string($parameter->var->name)) {
                $properties[$parameter->var->name]=['span'=>self::nodeSpan($parameter),'nameSpan'=>self::nodeSpan($parameter->var),'typeSpan'=>$parameter->type===null?null:self::nodeSpan($parameter->type),
                    'type'=>$parameter->type===null?null:$printer->prettyPrint([$parameter->type]),'ordinary'=>!$parameter->byRef&&!$parameter->variadic&&$parameter->hooks===[]&&$parameter->attrGroups===[]];
            } }
            $result['classes'][strtolower($name)]=['name'=>$name,'span'=>self::nodeSpan($class),'nameSpan'=>self::nodeSpan($class->name),'methods'=>$methods,'properties'=>$properties];
        }
        foreach($finder->findInstanceOf($nodes,Node\Stmt\Function_::class) as $function) { $result['functions'][strtolower($function->namespacedName->toString())]=$this->functionSource($function,$printer,$finder); }
        foreach($finder->findInstanceOf($nodes,Node\Expr\CallLike::class) as $call) {
            if($call instanceof Node\Expr\New_) { continue; }
            $description=$describer->describe($call);if($description===null) { continue; }
            $description['nameSpan']=$call->name instanceof Node?self::nodeSpan($call->name):null;
            $description['receiverSpan']=$call instanceof Node\Expr\MethodCall?self::nodeSpan($call->var):null;
            $result['calls'][]=$description;
        }
        foreach($finder->findInstanceOf($nodes,Node\Expr\PropertyFetch::class) as $property) { if($property->name instanceof Node\Identifier) {
            $result['accesses'][]=['receiverSpan'=>self::nodeSpan($property->var),'span'=>self::nodeSpan($property),'name'=>$property->name->name];
        } }
        unset($nodes);if(count($this->sources)>=8) { unset($this->sources[array_key_first($this->sources)]); }return $this->sources[$key]=$result;
    }
    private function functionSource(Node\Stmt\Function_|Node\Stmt\ClassMethod $node,Standard $printer,NodeFinder $finder):array {
        $parameters=[];foreach($node->params as $param) {
            $parameters[]=['name'=>'$'.$param->var->name,'nameSpan'=>self::nodeSpan($param->var),'byReference'=>$param->byRef,'variadic'=>$param->variadic,'hasDefault'=>$param->default!==null,
                'typeSpan'=>$param->type===null?null:self::nodeSpan($param->type),'type'=>$param->type===null?null:$printer->prettyPrint([$param->type])];
        }
        $writes=$reads=[];$ambient=false;
        foreach($finder->find($node->stmts??[],static fn(Node $item):bool=>$item instanceof Node\Expr\Assign||$item instanceof Node\Expr\AssignOp||$item instanceof Node\Expr\AssignRef
            ||$item instanceof Node\Expr\PreInc||$item instanceof Node\Expr\PostInc||$item instanceof Node\Expr\PreDec||$item instanceof Node\Expr\PostDec||$item instanceof Node\Stmt\Unset_) as $write) {
            $targets=$write instanceof Node\Stmt\Unset_?$write->vars:[$write->var];foreach($targets as $target) {
                while($target instanceof Node\Expr\PropertyFetch||$target instanceof Node\Expr\ArrayDimFetch) { $target=$target->var; }
                if($target instanceof Node\Expr\Variable&&is_string($target->name)) { $writes[]=$target->name; }else { $ambient=true; }
            }
        }
        foreach($finder->findInstanceOf($node->stmts??[],Node\Expr\PropertyFetch::class) as $read) { if($read->var instanceof Node\Expr\Variable&&$read->var->name==='this'&&$read->name instanceof Node\Identifier) { $reads[]=$read->name->name; } }
        if($finder->findFirst($node->stmts??[],static fn(Node $item):bool=>$item instanceof Node\Stmt\Global_||$item instanceof Node\Stmt\Static_||$item instanceof Node\Expr\Eval_||$item instanceof Node\Expr\Include_||$item instanceof Node\Expr\AssignRef)!==null) { $ambient=true; }
        $doc=$node->getDocComment()?->getText()??'';
        $delegate=null;if(count($node->stmts??[])===1&&$node->stmts[0] instanceof Node\Stmt\Return_&&$node->stmts[0]->expr!==null) { $delegate=(new SourceNullFacts)->describe($node->stmts[0]->expr); }
        return ['span'=>self::nodeSpan($node),'nameSpan'=>self::nodeSpan($node->name),'parameters'=>$parameters,'byReference'=>$node->byRef,'static'=>$node instanceof Node\Stmt\ClassMethod&&$node->isStatic(),
            'returnType'=>$node->returnType===null?null:$printer->prettyPrint([$node->returnType]),'returnTypeSpan'=>$node->returnType===null?null:self::nodeSpan($node->returnType),
            'doc'=>$doc,'hasDocblock'=>$doc!=='','sourceSha256'=>hash('sha256',$printer->prettyPrint([$node])),
            'writes'=>array_values(array_unique($writes)),'reads'=>array_values(array_unique($reads)),'ambient'=>$ambient,
            'bodySha256'=>hash('sha256',$printer->prettyPrint($node->stmts??[])),'returnDelegate'=>$delegate];
    }
    public function caller(IssueFilterContext $context,array $scope,array &$certificate):bool {
        if($scope['topLevel']) { $certificate['caller']=['topLevel'=>true,'currentSourceHash'=>hash('sha256',$context->contents)];return true; }
        $native=$scope['owner']===null?$context->codebase->getFunction($scope['name']):$context->codebase->getDeclaringMethod($scope['owner'],$scope['name']);
        $bound=$native===null?null:$this->functionBound($context,$native);
        if($bound===null||!self::sameFile($native->location->file,$context->file)||$bound['source']['span']!==$scope['span']||$bound['source']['nameSpan']!==$scope['nameSpan']
            ||$native->flags->contains(MetadataFlags::BY_REFERENCE)||strcasecmp($native->identifier->class??'',$scope['owner']??'')!==0||strcasecmp($native->identifier->name,$scope['name'])!==0) { return false; }
        $certificate['caller']=$bound;return true;
    }
    public function functionBound(IssueFilterContext $context,FunctionLikeMetadata $native):?array {
        $file=$native->location->file;if($file===null||$native->nameLocation===null||$native->flags->contains(MetadataFlags::BUILTIN)) { return null; }
        $bytes=$this->bytes($file);if($bytes===null) { return null; }$index=$this->source($file,$bytes);
        $owner=$native->identifier->class;$source=$owner===null?($index['functions'][strtolower($native->identifier->name)]??null):($index['classes'][strtolower($owner)]['methods'][strtolower($native->identifier->name)]??null);
        if($source===null&&$owner!==null) {
            // A genuine declaring query may retain the appearing owner for a physical trait declaration.
            $appearing=$context->codebase->getClassLike($owner);$traits=$appearing?->usedTraits??[];
            foreach($index['classes'] as $class) { if(!in_array(strtolower($class['name']),array_map('strtolower',$traits),true)) { continue; }
                $candidate=$class['methods'][strtolower($native->identifier->name)]??null;
                if($candidate!==null&&self::span($native->nameLocation->span)===$candidate['nameSpan']) { if($source!==null) { return null; }$source=$candidate; }
            }
        }
        if($source===null||self::span($native->nameLocation->span)!==$source['nameSpan']||$native->location->span->start>$source['span'][0]||$native->location->span->end!==$source['span'][1]
            ||count($native->parameters)!==count($source['parameters'])||$native->static!==$source['static']||$native->hasDocblock!==$source['hasDocblock']||$native->flags->contains(MetadataFlags::BY_REFERENCE)!==$source['byReference']) { return null; }
        foreach($source['parameters'] as $number=>$param) {
            $formal=$native->parameters[$number];
            if($formal->name!==$param['name']||self::span($formal->nameLocation->span)!==$param['nameSpan']||$formal->flags->contains(MetadataFlags::BY_REFERENCE)!==$param['byReference']
                ||$formal->flags->contains(MetadataFlags::VARIADIC)!==$param['variadic']||$formal->flags->contains(MetadataFlags::HAS_DEFAULT)!==$param['hasDefault']) { return null; }
            if($param['typeSpan']!==null&&($formal->declaredType===null||$formal->declaredType->location===null||self::span($formal->declaredType->location->span)!==$param['typeSpan']||!self::sameFile($formal->declaredType->location->file,$file))) { return null; }
            $physical=$param['type']===null?null:self::physicalType($param['type']);if($physical!==null&&($formal->declaredType===null||!$context->types->equals($formal->declaredType->type,$physical))) { return null; }
        }
        if($source['returnTypeSpan']!==null&&($native->declaredReturnType===null||$native->declaredReturnType->location===null||self::span($native->declaredReturnType->location->span)!==$source['returnTypeSpan']||!self::sameFile($native->declaredReturnType->location->file,$file))) { return null; }
        $physical=$source['returnType']===null?null:self::physicalType($source['returnType']);if($physical!==null&&($native->declaredReturnType===null||!$context->types->equals($native->declaredReturnType->type,$physical))) { return null; }
        if(!$this->current($file,$bytes)) { return null; }return ['native'=>$native,'source'=>$source,'file'=>$file,'sourceSha256'=>hash('sha256',$bytes)];
    }
    public function className(IssueFilterContext $context,array $expression,array $scope,array &$certificate,int $depth=0):?string {
        if($depth>12) { return null; }
        if($expression['kind']==='new') { $name=$expression['class']; }
        elseif($expression['kind']==='variable') {
            if($expression['name']==='this') { $name=$scope['owner']; }
            elseif(isset($scope['types'][$expression['name']])) { $name=$scope['types'][$expression['name']]; }
            elseif(isset($scope['origins'][$expression['name']])) { return $this->className($context,$scope['origins'][$expression['name']],$scope,$certificate,$depth+1); }
            else { return null; }
        } elseif($expression['kind']==='property') {
            $bound=$this->expressionBound($context,$expression,$scope,$certificate,$depth+1);if($bound===null) { return null; }return self::objectName($bound['native']->type?->type??$bound['native']->declaredType?->type);
        } elseif(in_array($expression['kind'],['method','static','function'],true)) {
            $query=$this->queryCollection($context,$expression,$certificate);if($query!==null) { return $query; }
            $bound=$this->expressionBound($context,$expression,$scope,$certificate,$depth+1);if($bound===null) { return null; }
            $route=$this->zeroArgumentRequestRoute($context,$expression,$bound,$scope,$certificate);if($route!==null) { return $route; }
            return self::objectName($bound['native']->returnType?->type??$bound['native']->declaredReturnType?->type);
        } else { return null; }
        if($name===null) { return null; }$class=$context->codebase->getClassLike($name);
        if($class===null||$class->hasIncompleteHierarchy()||strcasecmp($class->name,$name)!==0||$class->nameLocation===null||$class->location->file===null) { return null; }
        $bytes=$this->bytes($class->location->file);$source=$bytes===null?null:($this->source($class->location->file,$bytes)['classes'][strtolower($name)]??null);
        if($source===null||self::span($class->nameLocation->span)!==$source['nameSpan']||$class->location->span->end!==$source['span'][1]||!$this->current($class->location->file,$bytes)) { return null; }
        $certificate['classes'][strtolower($name)]=['native'=>$class,'file'=>$class->location->file,'sourceSha256'=>hash('sha256',$bytes)];return $class->name;
    }
    public function expressionBound(IssueFilterContext $context,array $expression,array $scope,array &$certificate,int $depth=0):?array {
        if($depth>12) { return null; }$kind=$expression['kind'];
        if($kind==='variable') {
            if(isset($scope['origins'][$expression['name']])) { return $this->expressionBound($context,$scope['origins'][$expression['name']],$scope,$certificate,$depth+1); }
            return null;
        }
        $native=null;$owner=null;
        if($kind==='function') { $native=$context->codebase->getFunction($expression['name']); }
        elseif($kind==='static'||$kind==='new') { $owner=$expression['class'];$native=$context->codebase->getDeclaringMethod($owner,$kind==='new'?'__construct':$expression['name']); }
        elseif($kind==='method'||$kind==='property') {
            $owner=$this->className($context,$expression['receiver'],$scope,$certificate,$depth+1);if($owner===null) { return null; }
            $native=$kind==='property'?$context->codebase->getDeclaringProperty($owner,'$'.$expression['name']):$context->codebase->getDeclaringMethod($owner,$expression['name']);
        } else { return null; }
        if($native instanceof FunctionLikeMetadata) {
            $bound=$this->functionBound($context,$native);if($bound===null||!$this->argumentsBound($expression,$native)) { return null; }
            $certificate['declarations'][($native->identifier->class===null?'':$native->identifier->class.'::').$native->identifier->name]=$bound;return $bound;
        }
        if(!$native instanceof PropertyMetadata||$native->nameLocation===null||$native->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)||$native->flags->contains(MetadataFlags::STATIC)||$native->flags->contains(MetadataFlags::WRITEONLY)) { return null; }
        $file=$native->nameLocation->file;if($file===null) { return null; }$bytes=$this->bytes($file);if($bytes===null) { return null; }
        $index=$this->source($file,$bytes);$physical=null;
        foreach($index['classes'] as $class) { $item=$class['properties'][$expression['name']]??null;if($item!==null&&($item['ordinary']||($item['setterOnlyRead']??null)!==null)&&self::span($native->nameLocation->span)===$item['nameSpan']) { if($physical!==null) { return null; }$physical=$item; } }
        if($physical!==null&&($native->hooks!==[]?!isset($physical['setterOnlyRead'])||!PhysicalSetOnlyPropertyRead::native($context,$native,$physical['setterOnlyRead'],$file):!$physical['ordinary'])) { return null; }
        if($physical===null||$native->declaredType===null||$native->type===null||$physical['typeSpan']===null||$native->declaredType->location===null
            ||self::span($native->declaredType->location->span)!==$physical['typeSpan']||!self::sameFile($native->declaredType->location->file,$file)||!$this->current($file,$bytes)) { return null; }
        $physicalType=self::physicalType($physical['type']);if($physicalType!==null&&!$context->types->equals($native->declaredType->type,$physicalType)) { return null; }
        $bound=['native'=>$native,'source'=>$physical,'file'=>$file,'sourceSha256'=>hash('sha256',$bytes)];$certificate['properties'][$owner.'::$'.$expression['name']]=$bound;return $bound;
    }
    private function argumentsBound(array $expression,FunctionLikeMetadata $native):bool {
        $arguments=$expression['arguments']??[];$required=0;foreach($native->parameters as $formal) { if(!$formal->flags->contains(MetadataFlags::HAS_DEFAULT)&&!$formal->flags->contains(MetadataFlags::VARIADIC)) { $required++; } }
        if(count($arguments)<$required||count($arguments)>count($native->parameters)) { return false; }
        foreach($arguments as $index=>$argument) { $formal=$native->parameters[$index]??null;if($formal===null||$argument['name']!==null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null) { return false; } }return true;
    }
    public function verify(IssueFilterContext $context,array $proof,array &$certificate):bool {
        $scope=$proof['scope'];$fact=$proof['fact'];if(!$this->caller($context,$scope,$certificate)) { return false; }
        $target=$this->expressionBound($context,$proof['expression'],$scope,$certificate);
        if($fact['kind']==='nonnullable-producer-catch-flag') {
            $target=$this->expressionBound($context,$fact['producer'],$scope,$certificate);
            if($target===null||!$target['native'] instanceof FunctionLikeMetadata||$target['native']->declaredReturnType===null||self::containsNull($target['native']->declaredReturnType->type)||self::objectName($target['native']->declaredReturnType->type)===null) { return false; }
        } elseif($target===null) { return false; }
        if($fact['kind']!=='nonnullable-producer-catch-flag'&&$target['native'] instanceof FunctionLikeMetadata&&!$this->rememberable($target)) { return false; }
        if($target['native'] instanceof FunctionLikeMetadata&&($target['native']->returnType===null||$target['native']->declaredReturnType!==null&&!$context->types->isContainedBy($target['native']->returnType->type,$target['native']->declaredReturnType->type))) { return false; }
        if($fact['kind']==='documented-postcondition') {
            $guard=$this->expressionBound($context,$fact['guard'],$scope,$certificate);if($guard===null||!$guard['native'] instanceof FunctionLikeMetadata) { return false; }
            $native=$guard['native'];$formal=$native->parameters[0]??null;$assertions=$formal===null?[]:($native->assertions[$formal->name]??$native->assertions[ltrim($formal->name,'$')]??[]);
            $nullAssertion=false;foreach($assertions as $assertion) { if($assertion instanceof TypeAssertion&&$assertion->kind===TypeAssertionKind::IsNotType&&$context->types->equals($assertion->type,Type::null())) { $nullAssertion=true; } }
            if(!$nullAssertion||$native->assertionsInferred||$formal===null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->outType!==null
                ||preg_match('/@(?:phpstan-|psalm-)assert\s+!null\s+'.preg_quote($formal->name,'/').'\b/',$guard['source']['doc'])!==1) { return false; }
            $certificate['postcondition']=$guard;
        } elseif($fact['kind']==='conditional-method-postcondition') {
            $guard=$this->expressionBound($context,$fact['guard'],$scope,$certificate);if($guard===null||!$guard['native'] instanceof FunctionLikeMetadata||!$this->rememberable($guard)) { return false; }
            $path='$this->'.$proof['expression']['name'].'()';$assertions=$guard['native']->ifFalseAssertions[$path]??$guard['native']->ifFalseAssertions[ltrim($path,'$')]??[];
            if($assertions===[]||$guard['native']->assertionsInferred||preg_match('/@phpstan-assert-if-false\s+([A-Za-z_][A-Za-z0-9_]*)\s+'.preg_quote($path,'/').'(?:\s|$)/m',$guard['source']['doc'])!==1) { return false; }
            $nonNullAsserted=[];foreach($assertions as $assertion) { if(!$assertion instanceof TypeAssertion||$assertion->kind!==TypeAssertionKind::IsType||self::containsNull($assertion->type)) { return false; }$nonNullAsserted[]=$assertion->type; }
            if($nonNullAsserted===[]) { return false; }
            $receiving=$certificate['nativeReceivingCallable']->parameters[$certificate['nativeReceivingParameterIndex']]->type->type??null;
            if($receiving===null) { return false; }
            $contained=false;foreach($nonNullAsserted as $asserted) { if($context->types->isContainedBy($asserted,$receiving)) { $contained=true; } }
            if(!$contained) {
                $model=$certificate['queryModel']['native']??null;
                if($model===null||strcasecmp($model->name,self::objectName($receiving)??'')!==0) { return false; }
                // The closed Eloquent query fixes TValue to this non-null model declaration.
                $certificate['conditionalQueryTemplateBoundToReceivingModel']=true;
            }
            $certificate['conditionalPostcondition']=$guard;
        } elseif($fact['kind']!=='condition'&&$fact['kind']!=='nonnullable-producer-catch-flag') { return false; }
        if(!$this->effectsAdmissible($context,$proof,$certificate)) { return false; }
        $certificate['target']=$target;$certificate['factKind']=$fact['kind'];$certificate['sourceAndNativeDeclarationsBound']=true;
        $certificate['phpstanDefaultRememberPossiblyImpureFunctionValues']=true;$certificate['phpstanDefaultTreatPhpDocTypesAsCertain']=true;
        $certificate['currentAnalyzedSourceSha256']=hash('sha256',$context->contents);return $this->current($context->file,$context->contents);
    }
    public function receivingContract(IssueFilterContext $context,array $proof,array &$certificate):bool {
        $native=$certificate['target']['native'];$type=$native instanceof FunctionLikeMetadata?$native->returnType?->type:$native->type?->type;
        if($type===null) { return false; }
        if($context->issue->code==='possible-method-access-on-null') {
            $name=self::objectName($type);
            if($name===null) { return true; } // A mixed framework return remains mixed; this rule only proves its guarded non-null path.
            $use=$certificate['sourceUse'];$member=$context->codebase->getDeclaringMethod($name,$use['name']);$bound=$member===null?null:$this->functionBound($context,$member);
            if($bound===null||!$this->argumentsBound($use,$member)) { return false; }$certificate['nativeOuterMember']=$bound;return true;
        }
        if($context->issue->code==='possibly-null-property-access') { return self::objectName($type)!==null; }
        $receiving=in_array($context->issue->code,['nullable-return-statement','invalid-return-statement'],true)?($certificate['nativeReceivingReturn']->declaredReturnType->type??null):($certificate['nativeReceivingCallable']->parameters[$certificate['nativeReceivingParameterIndex']]->type->type??null);
        if($receiving===null) { return false; }
        if($proof['fact']['kind']==='conditional-method-postcondition') { return isset($certificate['conditionalPostcondition']); }
        $parts=array_values(array_filter($type->atomicTypes,static fn($atomic):bool=>!$atomic instanceof SimpleAtomicType||$atomic->kind!==SimpleAtomicTypeKind::Null));
        if($parts!==[]&&$context->types->isContainedBy(Type::fromAtomics(...$parts),$receiving)) { $certificate['refinedNativeOutputContainedByReceivingType']=true;return true; }
        if($this->literalConsoleInput($context,$proof,$receiving,$certificate)) { return true; }
        if($this->literalHeaderContract($context,$proof,$certificate)&&$context->types->isContainedBy(Type::string(),$receiving)) { $certificate['sourceBoundLiteralHeaderOutputContainedByReceivingType']=true;return true; }
        return false;
    }
    private function literalHeaderContract(IssueFilterContext $context,array $proof,array &$certificate):bool {
        $expression=$proof['expression'];$target=$certificate['target'];
        if($expression['kind']!=='method'||strcasecmp($expression['name'],'header')!==0||count($expression['arguments'])!==1||$expression['arguments'][0]['value']['kind']!=='literal'
            ||preg_match('/^([\x27\x22]).*\1$/sD',$expression['arguments'][0]['value']['text'])!==1
            ||$target['source']['bodySha256']!=='7a34848dadec29aab62fa9defd51112dd6bd809e353d82284934ab198c30fd01') { return false; }
        $scope=$proof['scope'];$owner=$this->className($context,$expression['receiver'],$scope,$certificate);if($owner===null) { return false; }
        $class=$context->codebase->getClassLike($owner);if($class===null||strcasecmp($owner,'Illuminate\\Http\\Request')!==0&&!in_array('illuminate\\http\\request',array_map('strtolower',$class->parentClasses),true)) { return false; }
        $helper=$context->codebase->getDeclaringMethod($owner,'retrieveItem');$bound=$helper===null?null:$this->functionBound($context,$helper);
        if($bound===null||$bound['source']['bodySha256']!=='02d349bf43cd4e9b0df47699502e5d437fab76c01a0e90f6999f94942b3ba2cd') { return false; }
        $headers=['kind'=>'property','receiver'=>$expression['receiver'],'name'=>'headers'];$field=$this->expressionBound($context,$headers,$scope,$certificate);
        if($field===null||strcasecmp(self::objectName($field['native']->declaredType?->type)??'','Symfony\\Component\\HttpFoundation\\HeaderBag')!==0) { return false; }
        $get=['kind'=>'method','receiver'=>$headers,'name'=>'get','arguments'=>[$expression['arguments'][0],['name'=>null,'value'=>['kind'=>'literal','text'=>'null']]]];
        $getBound=$this->expressionBound($context,$get,$scope,$certificate);
        if($getBound===null||$getBound['native']->declaredReturnType===null||!$context->types->equals($getBound['native']->declaredReturnType->type,Type::union(Type::string(),Type::null()))) { return false; }
        $certificate['literalHeaderPrimaryContract']=['nativeHeader'=>$target['native'],'nativeRetrieveItem'=>$helper,'nativeHeaderBagProperty'=>$field['native'],'nativeHeaderBagGet'=>$getBound['native'],
            'headerSourceHash'=>$target['sourceSha256'],'retrieveItemSourceHash'=>$bound['sourceSha256'],'headerBagSourceHash'=>$getBound['sourceSha256'],'literalKeyNotNull'=>true,'omittedDefaultNull'=>true];
        return true;
    }
    /** Source-bound Larastan zero-argument route contract; no provider return or native type is replaced. */
    private function zeroArgumentRequestRoute(IssueFilterContext $context,array $expression,array $bound,array $scope,array &$certificate):?string {
        if($expression['kind']!=='method'||strcasecmp($expression['name'],'route')!==0||($expression['arguments']??[])!==[]) { return null; }
        $native=$bound['native'];$source=$bound['source'];$owner='Illuminate\\Http\\Request';$route='Illuminate\\Routing\\Route';
        if(!$native instanceof FunctionLikeMetadata||strcasecmp($native->identifier->class??'',$owner)!==0||strcasecmp($native->identifier->name,'route')!==0
            ||$native->static||$native->abstract||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->declaredReturnType!==null
            ||$native->returnType===null||!$native->returnType->fromDocblock||$native->returnType->inferred||count($native->parameters)!==2
            ||$source['bodySha256']!=='cfc2bd85237561b497f9ae0e037a59eda510768d711fc3eedaee6b1d7472e113'
            ||preg_match('/@return\s+\(\$param is null \? \\\\Illuminate\\\\Routing\\\\Route : object\|string\|null\)/',$source['doc'])!==1) { return null; }
        foreach(['$param','$default'] as $index=>$name) {
            $formal=$native->parameters[$index];if($formal->name!==$name||!$formal->flags->contains(MetadataFlags::HAS_DEFAULT)||$formal->flags->contains(MetadataFlags::BY_REFERENCE)
                ||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->defaultType===null||!$context->types->equals($formal->defaultType->type,Type::null())) { return null; }
        }
        $parameter=$native->parameters[0];if($parameter->type===null||!$context->types->equals($parameter->type->type,Type::union(Type::string(),Type::null()))) { return null; }
        if($this->className($context,['kind'=>'new','class'=>$route],$scope,$certificate)===null) { return null; }
        $certificate['zeroArgumentRequestRoute']=['actualNativeDeclaration'=>$bound,'zeroSuppliedArguments'=>true,'sourceDefaultArgumentsNull'=>true,
            'larastanPrimaryContract'=>'RequestRouteExtension::getTypeFromMethodCall: zero arguments -> Route|null','ordinaryGuardedRouteDomain'=>$route];
        return $route;
    }
    /** The public provider's existing declaration reader is reused without a synthetic invocation. */
    private function literalConsoleInput(IssueFilterContext $context,array $proof,Type $receiving,array &$certificate):bool {
        $expression=$proof['expression'];if($expression['kind']!=='method'||!in_array(strtolower($expression['name']),['option','argument'],true)
            ||count($expression['arguments'])!==1||$expression['arguments'][0]['name']!==null||$expression['arguments'][0]['value']['kind']!=='literal') { return false; }
        $literal=$expression['arguments'][0]['value']['text'];if(preg_match('/^\'([A-Za-z][A-Za-z0-9_-]*)\'$/D',$literal,$match)!==1) { return false; }
        $owner=$this->className($context,$expression['receiver'],$proof['scope'],$certificate);if($owner===null) { return false; }
        $type=(new \Ichinya\Laramago\Analyzer\ConsoleOptionProvider($this->root))->readLiteralIssue($context->codebase,$owner,$expression['name'],$match[1]);
        if($type===null) { return false; }$parts=array_values(array_filter($type->atomicTypes,static fn($atomic):bool=>!$atomic instanceof SimpleAtomicType||$atomic->kind!==SimpleAtomicTypeKind::Null));
        if($parts===[]||!$context->types->isContainedBy(Type::fromAtomics(...$parts),$receiving)) { return false; }
        $certificate['reusedLiteralConsoleDeclaration']=['owner'=>$owner,'method'=>$expression['name'],'literalInput'=>$match[1],'declaredInputContract'=>$type,
            'existingProviderDeclarationGuardsUnchanged'=>true,'receivingTypeUnchanged'=>true,'refinedInputContainedByReceivingType'=>true];return true;
    }
    private function rememberable(array $bound):bool {
        $native=$bound['native'];$source=$bound['source'];
        if($native->flags->contains(MetadataFlags::BY_REFERENCE)||$source['ambient']||preg_match('/@(?:phpstan-|psalm-|mago-)?impure\b/',$source['doc'])===1
            ||strtolower($source['returnType']??'')==='void'||str_contains($source['doc'],'@return $this')||str_contains($source['doc'],'@return this')||in_array('this',$source['writes'],true)) { return false; }
        // Unknown effects are PHPStan's MAYBE contract. This does not certify runtime purity.
        return true;
    }
    private function effectsAdmissible(IssueFilterContext $context,array $proof,array &$certificate):bool {
        $scope=$proof['scope'];$root=self::root($proof['expression']);$root=self::alias($root,$scope);if($root===null) { return false; }
        $selected=[];
        foreach($proof['fact']['effects'] as $effect) {
            $receiverRoot=isset($effect['receiver'])?self::alias(self::root($effect['receiver']),$scope):null;
            $argumentRoots=[];foreach($effect['arguments']??[] as $index=>$argument) { $argumentRoots[$index]=self::alias(self::root($argument['value']),$scope); }
            $relevant=$receiverRoot===$root||in_array($root,$argumentRoots,true);
            if($effect['kind']==='dynamic') {
                // An actual carrier argument or an unproved callable alias exposes retained state.
                // Alias names cannot select current callee captures after an earlier by-value copy.
                if($relevant||isset($scope['aliases'][ltrim($effect['name'],'$')])) { return false; }
                $closure=$scope['closures'][ltrim($effect['name'],'$')]??null;
                if($closure===null) { if($relevant) { return false; }$selected[]=['kind'=>'dynamic','sourceKnown'=>false,'retainedRootPassed'=>false];continue; }
                foreach($closure['uses'] as $use) { if(self::alias($use['name'],$scope)===$root) { return false; } }
                $selected[]=['kind'=>'lexical-closure','sourceSpan'=>$closure['span'],'retainedRootCaptured'=>false];continue;
            }
            if(!$relevant) { $selected[]=['kind'=>$effect['kind'],'name'=>$effect['name']??$effect['class']??null,'retainedRootPassed'=>false];continue; }
            $bound=$this->expressionBound($context,$effect,$scope,$certificate);
            if($bound===null) {
                // Builtins have native declarations but no editable source. Never accept a reference-bearing formal.
                $native=$effect['kind']==='function'?$context->codebase->getFunction($effect['name']):null;
                if($native===null||!$native->flags->contains(MetadataFlags::BUILTIN)||!$this->argumentsBound($effect,$native)) { return false; }
                $selected[]=['kind'=>'builtin','native'=>$native,'retainedRootPassedByValue'=>true];continue;
            }
            $source=$bound['source'];$native=$bound['native'];
            if(!$native instanceof FunctionLikeMetadata||$source['ambient']) { return false; }
            if($receiverRoot===$root&&(!$this->rememberable($bound)||in_array('this',$source['writes'],true))) { return false; }
            foreach($argumentRoots as $index=>$argumentRoot) { if($argumentRoot!==$root) { continue; }
                $formal=$native->parameters[$index]??null;if($formal===null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->outType!==null) { return false; }
                // A property/getter result is a by-value result; only a passed carrier exposes that carrier to this body.
                if(($effect['arguments'][$index]['value']['kind']??null)==='variable'&&in_array(ltrim($formal->name,'$'),$source['writes'],true)) { return false; }
            }
            $selected[]=['kind'=>'source-bound call','native'=>$native,'sourceSha256'=>$bound['sourceSha256'],'retainedRootPassedByValue'=>true];
        }
        $certificate['boundedInterveningEffects']=$selected;return true;
    }
    private function queryCollection(IssueFilterContext $context,array $expression,array &$certificate):?string {
        if($expression['kind']!=='method'||strcasecmp($expression['name'],'get')!==0||count($expression['arguments'])>1) { return null; }
        $chain=[];$node=$expression['receiver'];
        while($node['kind']==='method'&&count($chain)<12&&in_array(strtolower($node['name']),['where','limit','take','orderby'],true)) { $chain[]=$node;$node=$node['receiver']; }
        if($node['kind']!=='static'||!in_array(strtolower($node['name']),['query','where'],true)) { return null; }
        if($this->className($context,['kind'=>'new','class'=>$node['class']],[],$certificate)===null) { return null; }
        $class=$context->codebase->getClassLike($node['class']);if($class===null||$class->hasIncompleteHierarchy()||!in_array('illuminate\\database\\eloquent\\model',array_map('strtolower',$class->parentClasses),true)) { return null; }
        $certificate['queryModel']=['native'=>$class,'sourceExpression'=>$expression,'closedEloquentQueryChain'=>true];return 'Illuminate\\Database\\Eloquent\\Collection';
    }
    public static function objectName(?Type $type):?string {
        if($type===null) { return null; }$names=[];foreach($type->atomicTypes as $atomic) { if($atomic instanceof NamedObjectType) { $names[]=$atomic->name; }elseif(!$atomic instanceof SimpleAtomicType||$atomic->kind!==SimpleAtomicTypeKind::Null) { return null; } }
        $names=array_unique($names);return count($names)===1?array_values($names)[0]:null;
    }
    public static function containsNull(Type $type):bool { foreach($type->atomicTypes as $atomic) { if($atomic instanceof SimpleAtomicType&&$atomic->kind===SimpleAtomicTypeKind::Null) { return true; } }return false; }
    public static function physicalType(string $syntax):?Type {
        $syntax=trim($syntax);if(str_starts_with($syntax,'?')) { $inner=self::physicalType(substr($syntax,1));return $inner===null?null:Type::union($inner,Type::null()); }
        if(str_contains($syntax,'|')) { $parts=[];foreach(explode('|',$syntax) as $part) { $type=self::physicalType($part);if($type===null) { return null; }$parts[]=$type; }return Type::union(...$parts); }
        return match(strtolower($syntax)) { 'string'=>Type::string(),'int'=>Type::int(),'float'=>Type::float(),'bool'=>Type::bool(),'mixed'=>Type::mixed(),'null'=>Type::null(),'void'=>Type::void(),'never'=>Type::never(),'true'=>Type::true(),'false'=>Type::false(),
            'array','object','callable','iterable','self','static','parent'=>null,
            default=>preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/D',$syntax)===1?Type::namedObject(ltrim($syntax,'\\')):null };
    }
    public static function root(array $expression):?string { while(isset($expression['receiver'])) { $expression=$expression['receiver']; }return $expression['kind']==='variable'?$expression['name']:null; }
    public static function alias(?string $root,array $scope):?string { $seen=[];while($root!==null&&isset($scope['aliases'][$root])&&!isset($seen[$root])) { $seen[$root]=true;$root=$scope['aliases'][$root]; }return $root; }
    public static function sameFile(?string $first,string $second):bool { if($first===null) { return false; }$normalize=static function(string $file):string { $file=str_replace('\\','/',$file);return strtolower(str_starts_with($file,'//?/')?substr($file,4):$file); };return $normalize($first)===$normalize($second); }
    public static function span(\Mago\Sdk\Span $span):array { return [$span->start,$span->end]; }
    private static function nodeSpan(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
}
