<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Selected Request::header scalar branch plus an unchanged positive lexical guard. */
final class GuardedRequestHeaderArgumentFilter implements IssueFilterHook
{
    private const REQUEST='Illuminate\\Http\\Request';
    private const INPUT='Illuminate\\Http\\Concerns\\InteractsWithInput';
    private const BASE='Symfony\\Component\\HttpFoundation\\Request';
    private const BAG='Symfony\\Component\\HttpFoundation\\HeaderBag';
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes(): array { return ['possibly-invalid-argument']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);$this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;return $decision;
    }
    private function evaluate(IssueFilterContext $context): IssueFilterDecision
    {
        $contracts=new SourceArgumentDeclarationContracts($this->root);
        try {
            $receiving=$contracts->receiving($context,['possibly-invalid-argument']);if ($receiving===null) { return IssueFilterDecision::Keep; }
            ['source'=>$source,'file'=>$file,'call'=>$call,'argument'=>$argument]=$receiving;$header=$argument->value;
            if (! $contracts->stage('exact selected explode and one literal scalar header key',$receiving['target']==='explode' && $receiving['index']===1
                && $header instanceof Node\Expr\MethodCall && $header->var instanceof Node\Expr\Variable && is_string($header->var->name)
                && $header->name instanceof Node\Identifier && strtolower($header->name->name)==='header' && SourceArgumentDeclarationContracts::plain($header)
                && count($header->args)===1 && $header->args[0]->value instanceof Node\Scalar\String_ && $header->args[0]->value->value!=='')) { return IssueFilterDecision::Keep; }
            if(!$contracts->stage('native selected builtin argument signature',!$receiving['native']->static && count($receiving['native']->parameters)===3
                && $receiving['native']->parameters[0]->name==='$separator' && $receiving['native']->parameters[1]->name==='$string'
                && $receiving['native']->parameters[2]->name==='$limit'
                && $contracts->same($context,$receiving['native']->parameters[0]->declaredType?->type,Type::string())
                && $contracts->same($context,$receiving['native']->parameters[1]->declaredType?->type,Type::string()))) { return IssueFilterDecision::Keep; }
            $name=$header->var->name;$scope=null;$guard=null;
            foreach ($source->ancestors($call,$file) as $ancestor) {
                if ($guard===null && $ancestor instanceof Node\Stmt\If_ && $ancestor->getStartFilePos()<$call->getStartFilePos()) { $guard=$ancestor; }
                if ($ancestor instanceof Node\Stmt\ClassMethod) { $scope=$ancestor;break; }
            }
            $formal=$scope===null?[]:array_values(array_filter($scope->params,static fn(Node\Param $parameter):bool=>
                $parameter->var instanceof Node\Expr\Variable && $parameter->var->name===$name && $parameter->type instanceof Node\Name
                && ! $parameter->byRef && ! $parameter->variadic));
            $owner=$scope===null?null:$source->owner($context,$call,$file);$contracts->dependencies['owner']=$owner;
            if (! $contracts->stage('genuine named caller with selected Request formal',$owner!==null && count($formal)===1 && $guard!==null
                && $guard->elseifs===[] && $this->positive($guard->cond,$header,$source,$file) && $this->guardReadsOnly($guard->cond,$header->var->name))) { return IssueFilterDecision::Keep; }
            $parameter=$formal[0];$receiver=$parameter->type->toString();$index=array_search($parameter,$scope->params,true);
            $nativeFormal=$owner->parameters[$index]??null;
            $receiverNative=$context->codebase->getClass($receiver);$contracts->dependencies['selectedReceiver']=$receiverNative;
            $ancestors=$context->codebase->getClassAncestors($receiver);
            $physical=$receiverNative===null?null:$source->read($receiverNative->location->file);
            $classes=$physical===null?[]:(new NodeFinder)->findInstanceOf($physical['nodes'],Node\Stmt\Class_::class);
            $classes=array_values(array_filter($classes,static fn(Node\Stmt\Class_ $class):bool=>$class->namespacedName?->toString()===$receiver
                && $source->located($receiverNative->location,$class,$physical)));
            if (! $contracts->stage('physical selected Request subclass and unchanged native formal',$receiverNative!==null
                && strcasecmp($receiverNative->name,$receiver)===0 && $receiverNative->kind===\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Class_ && $receiverNative->unresolvedHierarchyDependencies===[]
                && count($classes)===1 && ($receiver===self::REQUEST || in_array(strtolower(self::REQUEST),array_map('strtolower',$ancestors),true))
                && $nativeFormal!==null && !$nativeFormal->flags->contains(MetadataFlags::BY_REFERENCE) && !$nativeFormal->flags->contains(MetadataFlags::VARIADIC)
                && $nativeFormal->outType===null && $contracts->same($context,$nativeFormal->declaredType?->type,Type::namedObject($receiver))
                && $contracts->same($context,$nativeFormal->type?->type,Type::namedObject($receiver)))) { return IssueFilterDecision::Keep; }
            if (!$contracts->stage('no stronger selected header property documentation',!$this->strongerHeaderDoc($classes[0]))) { return IssueFilterDecision::Keep; }
            foreach($ancestors as $ancestorName) {
                $ancestorNative=$context->codebase->getClass($ancestorName)??$context->codebase->getInterface($ancestorName);$contracts->dependencies['hierarchy:'.$ancestorName]=$ancestorNative;
                if($ancestorNative?->kind===\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface && $ancestorNative->flags->contains(MetadataFlags::BUILTIN)) {
                    if(!$contracts->stage('complete known builtin receiver interface '.$ancestorName,strcasecmp($ancestorNative->name,$ancestorName)===0
                        && in_array(strtolower($ancestorName),['arrayaccess','stringable'],true) && !$ancestorNative->hasIncompleteHierarchy()
                        && $ancestorNative->properties===[] && $ancestorNative->magicProperties===[] && $ancestorNative->directParentClass===null)) { return IssueFilterDecision::Keep; }
                    continue;
                }
                $ancestorFile=$ancestorNative===null?null:$source->read($ancestorNative->location->file);
                $ancestorClasses=$ancestorFile===null?[]:(new NodeFinder)->find($ancestorFile['nodes'],static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_);
                $ancestorClasses=array_values(array_filter($ancestorClasses,static fn(Node\Stmt\ClassLike $class):bool=>strcasecmp($class->namespacedName?->toString()??'',$ancestorName)===0
                    && $source->located($ancestorNative->location,$class,$ancestorFile)));
                if(!$contracts->stage('physical unchanged receiver hierarchy '.$ancestorName,$ancestorNative!==null
                    && strcasecmp($ancestorNative->name,$ancestorName)===0 && $ancestorNative->unresolvedHierarchyDependencies===[] && count($ancestorClasses)===1
                    && ($ancestorNative->kind===\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Class_ && $ancestorClasses[0] instanceof Node\Stmt\Class_
                        || $ancestorNative->kind===\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface && $ancestorClasses[0] instanceof Node\Stmt\Interface_)
                    && $source->located($ancestorNative->nameLocation,$ancestorClasses[0]->name,$ancestorFile)
                    && !$this->strongerHeaderDoc($ancestorClasses[0]))) { return IssueFilterDecision::Keep; }
            }
            $first=$guard->stmts[0]??null;
            if (! $contracts->stage('selected receiving call is first guarded statement',$first!==null && $first->getStartFilePos()<=$call->getStartFilePos()
                && $first->getEndFilePos()>=$call->getEndFilePos())) { return IssueFilterDecision::Keep; }
            if(!$contracts->stage('other receiving arguments have no intervening side effects',count($call->args)>=2 && count($call->args)<=3
                && $call->args[0]->value instanceof Node\Scalar\String_
                && (!isset($call->args[2]) || $call->args[2]->value instanceof Node\Scalar\Int_))) { return IssueFilterDecision::Keep; }
            foreach ((new NodeFinder)->findInstanceOf($scope->stmts,Node\Expr\Variable::class) as $use) {
                if ($use->name!==$name || $use->getStartFilePos()>$header->getEndFilePos()) { continue; }
                $parent=$file['parents'][spl_object_id($use)]??null;
                if ($parent instanceof Node\Expr\MethodCall && $parent->var===$use && $parent->name instanceof Node\Identifier
                    && strtolower($parent->name->name)==='header' && SourceArgumentDeclarationContracts::plain($parent)) { continue; }
                // Reads completed before the successful guard cannot invalidate its established fact.
                // Every earlier alias, reference, capture, argument escape or rebinding still refuses.
                if($use->getEndFilePos()<$guard->cond->getStartFilePos() && $parent instanceof Node\Expr\MethodCall && $parent->var===$use
                    && $parent->name instanceof Node\Identifier && SourceArgumentDeclarationContracts::plain($parent)) { continue; }
                $contracts->stage('no selected receiver mutation or escape between guard and read',false);return IssueFilterDecision::Keep;
            }
            $request=$context->codebase->getClass(self::REQUEST);$contracts->dependencies['request']=$request;
            $property=$context->codebase->getProperty($receiver,'$headers')??$context->codebase->getDeclaringProperty($receiver,'$headers');$contracts->dependencies['headers']=$property;
            if (! $contracts->stage('native physical HeaderBag field and Request hierarchy',$request!==null && strcasecmp($request->name,self::REQUEST)===0 && strcasecmp($request->directParentClass??'',self::BASE)===0
                && $property!==null && in_array(array_keys($property->hooks),[[],['set']],true) && ! $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
                && ! $property->flags->contains(MetadataFlags::STATIC) && $contracts->same($context,$property->declaredType?->type,Type::namedObject(self::BAG))
                && $contracts->same($context,$property->type?->type,Type::namedObject(self::BAG)))) { return IssueFilterDecision::Keep; }
            $fieldFile=$property===null?null:$source->read($property->location?->file??$property->nameLocation?->file);
            $fields=[];
            if($fieldFile!==null) {
                foreach((new NodeFinder)->findInstanceOf($fieldFile['nodes'],Node\Stmt\Property::class) as $declaration) {
                    foreach($declaration->props as $item) {
                        if($item->name->name==='headers' && $source->located($property->nameLocation,$item->name,$fieldFile)) { $fields[]=[$declaration,$item]; }
                    }
                }
            }
            $fieldOwner=count($fields)===1?array_values(array_filter($source->ancestors($fields[0][0],$fieldFile),static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_)):[];
            if(!$contracts->stage('selected genuine physical base HeaderBag field',count($fields)===1 && count($fieldOwner)>=1
                && $fieldOwner[0]->namespacedName?->toString()===self::BASE && $fields[0][0]->isPublic() && !$fields[0][0]->isStatic()
                && $fields[0][0]->type instanceof Node\Name && $fields[0][0]->type->toString()===self::BAG
                && $this->setOnlyHeaderField($context,$contracts,$source,$fieldFile,$fields[0][0],$property))) { return IssueFilterDecision::Keep; }
            $headerNative=$context->codebase->getMethod($receiver,'header')??$context->codebase->getDeclaringMethod($receiver,'header');
            $retrieve=$context->codebase->getMethod($receiver,'retrieveItem')??$context->codebase->getDeclaringMethod($receiver,'retrieveItem');
            $get=$context->codebase->getMethod(self::BAG,'get');$contracts->dependencies['header']=$headerNative;$contracts->dependencies['retrieve']=$retrieve;$contracts->dependencies['get']=$get;
            if (! $contracts->stage('native scalar HeaderBag get contract',$headerNative!==null && $retrieve!==null && $get!==null
                && $headerNative->identifier->class===self::INPUT && $retrieve->identifier->class===self::INPUT
                && count($get->parameters)===2 && $get->parameters[0]->name==='$key' && $get->parameters[1]->name==='$default'
                && ! $get->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE) && ! $get->parameters[1]->flags->contains(MetadataFlags::BY_REFERENCE)
                && $contracts->same($context,$get->parameters[0]->declaredType?->type,Type::string())
                && $contracts->same($context,$get->parameters[1]->defaultType?->type,Type::null())
                && $contracts->same($context,$get->declaredReturnType?->type,Type::union(Type::string(),Type::null()))
                && $contracts->same($context,$get->returnType?->type,Type::union(Type::string(),Type::null()))
                && !$get->static && !$get->flags->contains(MetadataFlags::BY_REFERENCE) && $get->parameters[0]->outType===null && $get->parameters[1]->outType===null)) { return IssueFilterDecision::Keep; }
            $getFile=$source->read($get->location->file);$getMethods=$getFile===null?[]:(new NodeFinder)->findInstanceOf($getFile['nodes'],Node\Stmt\ClassMethod::class);
            $getMethods=array_values(array_filter($getMethods,static fn(Node\Stmt\ClassMethod $method):bool=>$source->located($get->location,$method,$getFile)));
            $getSyntax=$getMethods[0]??null;
            if(!$contracts->stage('physical native HeaderBag get declaration',count($getMethods)===1 && $get->identifier->class===self::BAG && $get->identifier->name==='get'
                && !$getSyntax->byRef && !$getSyntax->isStatic() && count($getSyntax->params)===2 && $source->located($get->nameLocation,$getSyntax->name,$getFile)
                && $source->located($get->declaredReturnType?->location,$getSyntax->returnType,$getFile))) { return IssueFilterDecision::Keep; }
            foreach($getSyntax->params as $index=>$parameter) {
                $formal=$get->parameters[$index];
                if(!$contracts->stage('native HeaderBag get formal #'.($index+1),$parameter->var instanceof Node\Expr\Variable && !$parameter->byRef && !$parameter->variadic
                    && !$formal->flags->contains(MetadataFlags::BY_REFERENCE) && !$formal->flags->contains(MetadataFlags::VARIADIC) && $formal->outType===null
                    && $formal->flags->contains(MetadataFlags::HAS_DEFAULT)===($parameter->default!==null)
                    && $source->located($formal->location,$parameter,$getFile) && $source->located($formal->nameLocation,$parameter->var,$getFile)
                    && $source->located($formal->declaredType?->location,$parameter->type,$getFile))) { return IssueFilterDecision::Keep; }
            }
            $body=$source->read($headerNative->location->file);$finder=new NodeFinder;
            foreach ([$headerNative,$retrieve] as $native) {
                $methods=$body===null?[]:$finder->findInstanceOf($body['nodes'],Node\Stmt\ClassMethod::class);
                $methods=array_values(array_filter($methods,static fn(Node\Stmt\ClassMethod $node):bool=>$source->located($native->location,$node,$body)));
                if (count($methods)!==1) { $contracts->stage('current physical native input methods',false);return IssueFilterDecision::Keep; }
                $syntax=$methods[0];
                if(!$contracts->stage('native physical input method ABI '.$native->identifier->name,!$native->static && !$native->flags->contains(MetadataFlags::BY_REFERENCE)
                    && !$syntax->isStatic() && !$syntax->byRef && $native->declaredReturnType===null && $syntax->returnType===null
                    && count($native->parameters)===count($syntax->params) && $source->located($native->nameLocation,$syntax->name,$body))) { return IssueFilterDecision::Keep; }
                foreach($syntax->params as $position=>$inputFormal) {
                    $selectedFormal=$native->parameters[$position];
                    if(!$contracts->stage('unchanged native input formal '.$native->identifier->name.' #'.($position+1),$inputFormal->var instanceof Node\Expr\Variable
                        && $selectedFormal->name==='$'.$inputFormal->var->name && !$inputFormal->byRef && !$inputFormal->variadic && $inputFormal->type===null
                        && !$selectedFormal->flags->contains(MetadataFlags::BY_REFERENCE) && !$selectedFormal->flags->contains(MetadataFlags::VARIADIC)
                        && $selectedFormal->outType===null && $selectedFormal->declaredType===null
                        && $selectedFormal->flags->contains(MetadataFlags::HAS_DEFAULT)===($inputFormal->default!==null)
                        && $source->located($selectedFormal->location,$inputFormal,$body) && $source->located($selectedFormal->nameLocation,$inputFormal->var,$body)
                        && ($inputFormal->default===null || $contracts->same($context,$selectedFormal->defaultType?->type,Type::null())))) { return IssueFilterDecision::Keep; }
                }
                $selected=substr($body['contents'],$syntax->getStartFilePos(),$syntax->getEndFilePos()-$syntax->getStartFilePos()+1);
                $expected=$native===$headerNative?'public function header($key = null, $default = null) { return $this->retrieveItem(\'headers\', $key, $default); }':
                    'protected function retrieveItem($source, $key, $default) { if (is_null($key)) { return $this->$source->all(); } if ($this->$source instanceof InputBag) { return $this->$source->all()[$key] ?? $default; } return $this->$source->get($key, $default); }';
                if (! $contracts->stage('exact physical forwarding branch '.$native->identifier->name,$source->tokens($selected)===$source->tokens($expected))) { return IssueFilterDecision::Keep; }
            }
            if (! $contracts->admits($context,$receiving,Type::string())) { return IssueFilterDecision::Keep; }
            $this->certificate=['file'=>$context->file,'sourceSha256'=>$file['hash'],'argumentSpan'=>SourceArgumentDeclarationContracts::span($header),
                'guardSpan'=>SourceArgumentDeclarationContracts::span($guard->cond),'selectedReceiver'=>$receiver,'domain'=>'string','policy'=>'literal header scalar branch and stable successful lexical guard',
                'nativeTypesReplaced'=>false,'afterFileAuthority'=>false];return IssueFilterDecision::Remove;
        } finally { $this->stages=$contracts->stages;$this->dependencies=$contracts->dependencies; }
    }
    private function positive(Node\Expr $expression,Node\Expr\MethodCall $selected,GuardedStringCastContracts $source,array $file): bool
    {
        if ($expression instanceof Node\Expr\BinaryOp\BooleanAnd) { return $this->positive($expression->left,$selected,$source,$file) || $this->positive($expression->right,$selected,$source,$file); }
        return $expression instanceof Node\Expr\MethodCall && SourceArgumentDeclarationContracts::plain($expression)
            && $source->tokens(substr($file['contents'],$expression->getStartFilePos(),$expression->getEndFilePos()-$expression->getStartFilePos()+1))
                ===$source->tokens(substr($file['contents'],$selected->getStartFilePos(),$selected->getEndFilePos()-$selected->getStartFilePos()+1));
    }
    private function strongerHeaderDoc(Node\Stmt\ClassLike $class):bool
    {
        $doc=$class->getDocComment()?->getText()??'';
        return preg_match('/@(?:(?:phpstan|psalm)-)?(?:property|property-read|property-write)\s+[^\r\n]*\$headers\b/i',$doc)===1
            || preg_match('/@(?:(?:phpstan|psalm)-)?method\s+[^\r\n]*\bheader\s*\(/i',$doc)===1;
    }
    private function guardReadsOnly(Node\Expr $condition,string $receiver):bool
    {
        return (new NodeFinder)->findFirst([$condition],static function(Node $node)use($receiver):bool {
            if($node instanceof Node\Expr\CallLike) {
                return !$node instanceof Node\Expr\MethodCall || !$node->var instanceof Node\Expr\Variable || $node->var->name!==$receiver
                    || !$node->name instanceof Node\Identifier || strtolower($node->name->name)!=='header' || !SourceArgumentDeclarationContracts::plain($node)
                    || count($node->args)!==1 || !$node->args[0]->value instanceof Node\Scalar\String_;
            }
            return $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\StaticPropertyFetch || $node instanceof Node\Expr\ArrayDimFetch
                || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom
                || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec
                || $node instanceof Node\Expr\Variable && !is_string($node->name);
        })===null;
    }
    private function setOnlyHeaderField(IssueFilterContext $context,SourceArgumentDeclarationContracts $contracts,GuardedStringCastContracts $source,array $file,Node\Stmt\Property $syntax,object $property):bool
    {
        if($syntax->hooks===[]) { return $property->hooks===[]; }
        $hook=$syntax->hooks[0]??null;$native=$property->hooks['set']??null;
        $expected='set { trigger_deprecation(\'symfony/http-foundation\', \'8.1\', \'Directly setting property "headers" of "%s" is deprecated; pass header parameters as a constructor argument or call "initialize()" instead.\', static::class); $this->headers = $value; }';
        return $contracts->stage('current physical write-only Symfony HeaderBag setter',count($syntax->hooks)===1 && array_keys($property->hooks)===['set']
            && $hook instanceof Node\PropertyHook && strtolower($hook->name->name)==='set' && !$hook->byRef && $hook->params===[] && $hook->attrGroups===[]
            && $native!==null && $native->name==='set' && !$native->returnsByReference && !$native->abstract && !$native->flags->contains(MetadataFlags::BY_REFERENCE)
            && ($native->parameter===null || $native->parameter->name==='$value' && !$native->parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                && !$native->parameter->flags->contains(MetadataFlags::VARIADIC) && $native->parameter->outType===null)
            && $source->located($native->location,$hook,$file)
            && $source->tokens(substr($file['contents'],$hook->getStartFilePos(),$hook->getEndFilePos()-$hook->getStartFilePos()+1))===$source->tokens($expected));
    }
}
