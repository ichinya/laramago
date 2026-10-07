<?php
declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{CodebaseScanContext,CodebaseScanHook,InitializationContext,InitializationHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeMetadata,FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** Remove a source/native declaration-name advisory while preserving every call/signature Error. */
final class RenamedParameterAdvisoryFilter implements Plugin,InitializationHook,CodebaseScanHook,IssueFilterHook
{
    private array $sources=[];
    private array $scanned=[];
    private array $namedCalls=[];
    private bool $scanComplete=false;
    private bool $scanFailed=false;
    private bool $scanStarted=false;
    // Observational only: never read to choose a decision. Tests inspect the genuine selected certificate.
    private ?array $lastProof=null;
    public function __construct(private readonly string $root='.') {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/renamed-parameter-advisories','Renamed parameter advisories','Match PHPStan declaration-name policy without changing call diagnostics.'); }
    public function register(PluginRegistry $registry):void { $registry->registerInitializationHook($this);$registry->registerCodebaseScanHook($this);$registry->registerIssueFilterHook($this); }
    public function initialize(InitializationContext $context):void { $this->sources=[];$this->lastProof=null;$this->scanned=$this->namedCalls=[];$this->scanComplete=$this->scanFailed=$this->scanStarted=false; }
    public function getTargets():array { return ['**']; }
    public function scan(CodebaseScanContext $context):void {
        if($context->firstBatch) { $this->scanned=$this->namedCalls=[];$this->scanFailed=false;$this->scanStarted=true; }
        if(!$this->scanStarted) { $this->scanFailed=true; }
        $this->scanComplete=false;
        foreach($context->files as $file) {
            $context->cancellation->throwIfCancelled();$path=self::path($file->path);
            if($this->scanFailed||isset($this->scanned[$path])||count($this->scanned)>=100_000||strlen($file->contents)>2_000_000) { $this->scanFailed=true;break; }
            $this->scanned[$path]=hash('sha256',$file->contents);
            // Most source files have no named method calls. Tokenize candidate bytes without building another AST collection.
            if(preg_match('/(?:->|\?->|::)/',$file->contents)!==1||preg_match('/\b[A-Za-z_][A-Za-z0-9_]*\s*:(?!:)|\.\.\./',$file->contents)!==1) { continue; }
            if(strlen($file->contents)>524_288) { $this->scanFailed=true;break; }
            foreach($this->namedArgumentSites($file->contents) as $site) {
                $key=$site['method'].'\0'.$site['parameter'];
                $this->namedCalls[$key]??=$site+['file'=>$file->path,'sourceSha256'=>$this->scanned[$path]];
                if(count($this->namedCalls)>100_000) { $this->scanFailed=true;break; }
            }
        }
        if($this->scanFailed) { $this->scanned=$this->namedCalls=[]; }
        $this->scanComplete=$context->lastBatch&&$this->scanStarted&&!$this->scanFailed;
    }
    public function getCodes():array { return ['incompatible-parameter-name']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $this->lastProof=null;$issue=$context->issue;
        if($issue->level->name!=='Warning'||$issue->code!=='incompatible-parameter-name'||$issue->edits!==[]||$issue->link!==null
            ||$issue->notes!==['Parameter name changes can break code using named arguments.']||count($issue->annotations)!==3
            ||strlen($issue->message)>4096||strlen($context->contents)>2_000_000||!$this->scanComplete||$this->scanFailed
            ||($this->scanned[self::path($context->file)]??null)!==hash('sha256',$context->contents)
            ||!$this->current($context->file,$context->contents)) { return IssueFilterDecision::Keep; }
        if(preg_match('/^Parameter #([1-9][0-9]*) of `([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\(\)` is named `(\$[A-Za-z_][A-Za-z0-9_]*)` but parent `([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\(\)` names it `(\$[A-Za-z_][A-Za-z0-9_]*)`$/D',$issue->message,$match)!==1) { return IssueFilterDecision::Keep; }
        [, $number,$childName,$method,$childParameter,$parentName,$parentMethod,$parentParameter]=$match;
        $index=(int)$number-1;
        if($index<0||$index>=64||$childParameter===$parentParameter||strcasecmp($method,$parentMethod)!==0||in_array(strtolower($method),['__construct','__destruct'],true)
            ||$issue->help!=='Consider renaming the parameter to `'.$parentParameter.'` to match the parent method.') { return IssueFilterDecision::Keep; }
        // Without inventing a receiver type, keep the advisory whenever an analyzed named method call could use the parent name.
        if(isset($this->namedCalls[strtolower($method).'\0'.$parentParameter])||isset($this->namedCalls['*\0'.$parentParameter])
            ||isset($this->namedCalls[strtolower($method).'\0*'])||isset($this->namedCalls['*\0*'])) { return IssueFilterDecision::Keep; }
        [$primary,$parentAnnotation,$childAnnotation]=$issue->annotations;
        if($primary->kind!==AnnotationKind::Primary||$parentAnnotation->kind!==AnnotationKind::Secondary||$childAnnotation->kind!==AnnotationKind::Secondary
            ||$primary->message!=='Parameter named `'.$childParameter.'` but parent uses `'.$parentParameter.'`'
            ||$parentAnnotation->message!=='Parent method `'.$parentName.'::'.$parentMethod.'()` parameter `'.$parentParameter.'` defined here'
            ||$childAnnotation->message!=='In class `'.$childName.'`'
            ||!$this->sameFile($primary->file,$context->file,$context->file)||!$this->sameFile($childAnnotation->file,$context->file,$context->file)) { return IssueFilterDecision::Keep; }
        $codebase=$context->codebase;
        $childClass=$codebase->getClassLike($childName);$parentClass=$codebase->getClassLike($parentName);
        $child=$codebase->getDeclaringMethod($childName,$method);$parent=$codebase->getDeclaringMethod($parentName,$parentMethod);
        if($childClass===null||$parentClass===null||$child===null||$parent===null||$childClass->hasIncompleteHierarchy()||$parentClass->hasIncompleteHierarchy()
            ||strcasecmp($childClass->name,$childName)!==0||strcasecmp($parentClass->name,$parentName)!==0
            ||!in_array(strtolower($parentName),array_map('strtolower',[...$childClass->parentClasses,...$childClass->parentInterfaces]),true)
            ||!$this->sameFile($parentAnnotation->file,$context->file,$parentClass->location->file)
            ||$childClass->nameLocation===null||$parentClass->nameLocation===null||$child->nameLocation===null
            ||self::span($primary->span)!==self::span($child->nameLocation->span)
            ||self::span($parentAnnotation->span)!==self::span($parentClass->nameLocation->span)
            ||self::span($childAnnotation->span)!==self::span($childClass->nameLocation->span)) { return IssueFilterDecision::Keep; }
        $childBytes=$this->bytes($child->location->file);$parentBytes=$this->bytes($parent->location->file);
        if($childBytes===null||$parentBytes===null||!$this->sameFile($child->location->file,$context->file,$context->file)
            ||hash('sha256',$childBytes)!==hash('sha256',$context->contents)) { return IssueFilterDecision::Keep; }
        $childSource=$this->source($child->location->file,$childBytes)[strtolower($childName)]??null;
        $parentSource=$this->source($parent->location->file,$parentBytes)[strtolower($parentName)]??null;
        if($childSource===null||$parentSource===null||!$this->classBound($childClass,$childSource)||!$this->classBound($parentClass,$parentSource)
            ||!$this->methodBound($child,$childSource,$method)||!$this->methodBound($parent,$parentSource,$parentMethod)
            ||count($child->parameters)!==count($parent->parameters)||!isset($child->parameters[$index],$parent->parameters[$index])
            ||$child->parameters[$index]->name!==$childParameter||$parent->parameters[$index]->name!==$parentParameter
            ||$child->static!==$parent->static||$child->visibility!==$parent->visibility
            ||$child->flags->contains(MetadataFlags::BY_REFERENCE)!==$parent->flags->contains(MetadataFlags::BY_REFERENCE)) { return IssueFilterDecision::Keep; }
        $admissibility=[];
        foreach($parent->parameters as $position=>$formal) {
            $implementation=$child->parameters[$position];
            if($formal->outType!==null||$implementation->outType!==null
                ||$formal->closureThisType!==null||$implementation->closureThisType!==null
                ||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$implementation->flags->contains(MetadataFlags::BY_REFERENCE)
                ||$formal->flags->contains(MetadataFlags::VARIADIC)||$implementation->flags->contains(MetadataFlags::VARIADIC)
                ||$formal->flags->contains(MetadataFlags::HAS_DEFAULT)&&!$implementation->flags->contains(MetadataFlags::HAS_DEFAULT)) { return IssueFilterDecision::Keep; }
            $syntax=$childSource['methods'][strtolower($method)]['parameters'][$position];
            if($implementation->type===null&&$implementation->declaredType===null&&$syntax['untyped']&&!$syntax['documentedParameter']) {
                // A current source-bound untyped PHP formal accepts these inputs without fabricating a mixed DTO.
                $admissibility[]=['index'=>$position,'policy'=>'physical untyped by-value formal without a local parameter type'];
            } elseif($formal->type!==null&&$implementation->type!==null&&$context->types->isContainedBy($formal->type->type,$implementation->type->type)) {
                $admissibility[]=['index'=>$position,'policy'=>'native parameter contravariance'];
            } else { return IssueFilterDecision::Keep; }
        }
        // The advisory does not assert an omitted return declaration. Known physical contradictions still defer.
        if($child->declaredReturnType!==null&&$parent->declaredReturnType!==null&&!$context->types->isContainedBy($child->declaredReturnType->type,$parent->declaredReturnType->type)) { return IssueFilterDecision::Keep; }
        if(!$this->current($child->location->file,$childBytes)||!$this->current($parent->location->file,$parentBytes)) { return IssueFilterDecision::Keep; }
        $certificate=['childClass'=>$childClass,'parentClass'=>$parentClass,'childMethod'=>$child,'parentMethod'=>$parent,
            'parameterIndex'=>$index,'childSourceHash'=>hash('sha256',$childBytes),'parentSourceHash'=>hash('sha256',$parentBytes),
            'sourceAndNativeDeclarationsBound'=>true,'parameterAdmissibility'=>$admissibility,'nativeArityAndReferenceContract'=>true,'completeBeforeIssueNamedCallIndexClear'=>true,
            'physicalReturnComparisonAvailable'=>$child->declaredReturnType!==null&&$parent->declaredReturnType!==null,'actualNamedCallDiagnosticsUnchanged'=>true];
        $this->lastProof=$certificate;
        return IssueFilterDecision::Remove;
    }
    private function classBound(ClassLikeMetadata $native,array $source):bool {
        return $native->nameLocation!==null&&self::span($native->nameLocation->span)===$source['nameSpan']
            &&$native->location->span->start<=$source['span'][0]&&$native->location->span->end===$source['span'][1];
    }
    private function methodBound(FunctionLikeMetadata $native,array $class,string $name):bool {
        $source=$class['methods'][strtolower($name)]??null;
        if($source===null||strcasecmp($native->identifier->class??'',$class['name'])!==0||strcasecmp($native->identifier->name,$name)!==0
            ||$native->nameLocation===null||self::span($native->nameLocation->span)!==$source['nameSpan']
            ||$native->location->span->start>$source['span'][0]||$native->location->span->end!==$source['span'][1]
            ||count($native->parameters)!==count($source['parameters'])) { return false; }
        foreach($source['parameters'] as $index=>$parameter) {
            $formal=$native->parameters[$index];
            if($formal->name!==$parameter['name']||self::span($formal->nameLocation->span)!==$parameter['nameSpan']
                ||$formal->flags->contains(MetadataFlags::BY_REFERENCE)!==$parameter['byReference']
                ||$formal->flags->contains(MetadataFlags::VARIADIC)!==$parameter['variadic']
                ||$formal->flags->contains(MetadataFlags::HAS_DEFAULT)!==$parameter['hasDefault']) { return false; }
        }
        return true;
    }
    /** @return list<array<string,mixed>> Potential calls only; no receiver/type identity is asserted. */
    private function namedArgumentSites(string $bytes):array {
        $tokens=[];
        foreach(\PhpToken::tokenize($bytes) as $token) {
            if(!in_array($token->id,[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)) { $tokens[]=$token; }
        }
        $sites=[];$count=count($tokens);
        for($index=0;$index<$count;$index++) {
            $operator=$tokens[$index];
            if(!in_array($operator->id,[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true)) { continue; }
            $name=$tokens[$index+1]??null;$open=$index+2;
            if($name===null) { continue; }
            $method=$name->id===T_STRING?strtolower($name->text):'*';
            if($name->text==='{') {
                $depth=1;$open=$index+2;
                for(;$open<$count&&$depth>0;$open++) { if($tokens[$open]->text==='{') { $depth++; }elseif($tokens[$open]->text==='}') { $depth--; } }
                if($depth!==0) { continue; }
            }
            if(($tokens[$open]->text??null)!=='(') { continue; }
            $paren=1;$bracket=$brace=0;$argumentStart=true;
            for($position=$open+1;$position<$count&&$paren>0;$position++) {
                $token=$tokens[$position];$text=$token->text;
                if($paren===1&&$bracket===0&&$brace===0&&$argumentStart) {
                    if($token->id===T_ELLIPSIS) { $sites[]=['method'=>$method,'parameter'=>'*','operatorSpan'=>[$operator->pos,$operator->pos+strlen($operator->text)],'namedLabelSpan'=>[$token->pos,$token->pos+strlen($text)]]; }
                    if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$text)===1&&($tokens[$position+1]->text??null)===':') {
                        $sites[]=['method'=>$method,'parameter'=>'$'.$text,'operatorSpan'=>[$operator->pos,$operator->pos+strlen($operator->text)],
                            'namedLabelSpan'=>[$token->pos,$tokens[$position+1]->pos+1]];
                        if(count($sites)>100_000) { $this->scanFailed=true;return []; }
                    }
                    $argumentStart=false;
                }
                if($text==='(') { $paren++; } elseif($text===')') { $paren--; }
                elseif($text==='[') { $bracket++; } elseif($text===']') { $bracket--; }
                elseif($text==='{') { $brace++; } elseif($text==='}') { $brace--; }
                elseif($text===','&&$paren===1&&$bracket===0&&$brace===0) { $argumentStart=true; }
            }
        }
        return $sites;
    }
    private function source(string $file,string $bytes):array {
        $key=hash('sha256',$file."\0".$bytes);
        if(isset($this->sources[$key])) { return $this->sources[$key]; }
        if(count($this->sources)>=8) { unset($this->sources[array_key_first($this->sources)]); }
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }
        catch(\PhpParser\Error) { return $this->sources[$key]=[]; }
        $result=[];
        foreach((new NodeFinder)->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_||$node instanceof Node\Stmt\Interface_) as $class) {
            if($class->name===null||!isset($class->namespacedName)||count($result)>=512) { continue; }
            $methods=[];
            foreach($class->getMethods() as $method) {
                $parameters=[];
                foreach($method->params as $parameter) {
                    if(!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)) { continue 2; }
                    $documented=preg_match('/@(?:phpstan-|psalm-|mago-)?param(?:-out)?\b[^\r\n]*\$'.preg_quote($parameter->var->name,'/').'\b/',$method->getDocComment()?->getText()??'')===1;
                    $parameters[]=['name'=>'$'.$parameter->var->name,'nameSpan'=>self::nodeSpan($parameter->var),'byReference'=>$parameter->byRef,'variadic'=>$parameter->variadic,'hasDefault'=>$parameter->default!==null,
                        'untyped'=>$parameter->type===null,'documentedParameter'=>$documented];
                }
                $methods[strtolower($method->name->name)]=['nameSpan'=>self::nodeSpan($method->name),'span'=>self::nodeSpan($method),'parameters'=>$parameters];
            }
            $name=$class->namespacedName->toString();
            $result[strtolower($name)]=['name'=>$name,'nameSpan'=>self::nodeSpan($class->name),'span'=>self::nodeSpan($class),'methods'=>$methods];
        }
        return $this->sources[$key]=$result;
    }
    private function sameFile(?string $candidate,string $fallback,string $expected):bool { return self::path($candidate===null||$candidate===''?$fallback:$candidate)===self::path($expected); }
    private function bytes(string $file):?string {
        $normalized=str_replace('\\','/',$file);if(str_starts_with($normalized,'//?/')) { $normalized=substr($normalized,4); }
        $disk=str_starts_with($normalized,'/')||preg_match('~^[a-z]:/~i',$normalized)===1?$normalized:rtrim($this->root,'/\\').'/'.$normalized;
        $size=@filesize($disk);$bytes=$size!==false&&$size<=2_000_000?@file_get_contents($disk):false;return $bytes===false?null:$bytes;
    }
    private function current(string $file,string $bytes):bool { $current=$this->bytes($file);return $current!==null&&hash('sha256',$current)===hash('sha256',$bytes); }
    private static function path(string $file):string { $file=str_replace('\\','/',$file);return strtolower(str_starts_with($file,'//?/')?substr($file,4):$file); }
    private static function span(\Mago\Sdk\Span $span):array { return [$span->start,$span->end]; }
    private static function nodeSpan(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
}
