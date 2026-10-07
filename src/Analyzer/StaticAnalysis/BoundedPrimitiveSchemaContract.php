<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;
use Mago\Sdk\Analyzer\{IssueFilterContext,PropertyType,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\{Node,NodeFinder};

/** A selected non-temporal scalar column contract, with current default dispatch. */
final class BoundedPrimitiveSchemaContract
{
    private const MODEL='Illuminate\\Database\\Eloquent\\Model';
    private const ATTRIBUTES='Illuminate\\Database\\Eloquent\\Concerns\\HasAttributes';
    private const TIMESTAMPS='Illuminate\\Database\\Eloquent\\Concerns\\HasTimestamps';
    private const BODIES=[
        '__get'=>'return$this->getAttribute($key);',
        'getAttribute'=>'if(!$key){return;}if($this->hasAttribute($key)){return$this->getAttributeValue($key);}if(method_exists(self::class,$key)){return$this->throwMissingAttributeExceptionIfApplicable($key);}return$this->isRelation($key)||$this->relationLoaded($key)?$this->getRelationValue($key):$this->throwMissingAttributeExceptionIfApplicable($key);',
        'getAttributeValue'=>'return$this->transformModelValue($key,$this->getAttributeFromArray($key));',
        'getAttributeFromArray'=>'$this->mergeAttributeFromCachedCasts($key);return$this->attributes[$key]??null;',
        'getDates'=>'return$this->usesTimestamps()?[$this->getCreatedAtColumn(),$this->getUpdatedAtColumn(),]:[];',
        'usesTimestamps'=>'return$this->timestamps&&!static::isIgnoringTimestamps($this::class);',
        'getCreatedAtColumn'=>'returnstatic::CREATED_AT;',
        'getUpdatedAtColumn'=>'returnstatic::UPDATED_AT;',
        'getTable'=>'return$this->table??Str::snake(Str::pluralStudly(class_basename($this)));',
        'getConnectionName'=>'returnenum_value($this->connection);',
        'getKeyName'=>'return$this->primaryKey;',
        'getKeyType'=>'return$this->keyType;',
        'getIncrementing'=>'return$this->incrementing;',
    ];
    /** Return invocation-local evidence; no shared debug fields or AfterFile facts. */
    public static function resolve(IssueFilterContext $context,string $root,string $model,string $property):array
    {
        $stages=[];$dependencies=[];$route='unknown';$record=static function(string $name,bool $passed)use(&$stages):bool{$stages[]=['stage'=>$name,'passed'=>$passed];return $passed;};
        $finish=static function(?PropertyType $contract)use(&$stages,&$dependencies,&$route):array{return ['contract'=>$contract,'stages'=>$stages,'dependencies'=>$dependencies,'route'=>$route];};
        $source=new PhpSource($root);$composer=@hash_file('sha256',$root.'/composer.json');
        if(!$record('physical current model defaults',PhysicalModelDefaultContracts::admits($context,$source,$model))){return $finish(null);}
        $frames=PhysicalModelDefaultContracts::currentGraph($context,$source,$model);
        if(!$record('complete exact physical parent and trait graph',$frames!==null)){return $finish(null);}
        $reflection=new ModelReflection($context->codebase,$source);$casts=$reflection->casts($model);
        if(!$record('current physically bound selected cast map',is_array($casts))){return $finish(null);}
        if(array_key_exists($property,$casts)){$route='explicit-cast';return $finish(null);}
        $route='scalar-schema';
        foreach($frames as $frame){
            if(!$record('ordinary trait composition '.$frame['name'],!$frame['adaptations'])){return $finish(null);}
            // A real declared property or any selected directional/general tag
            // wins. No scalar is inferred from fillable, attributes or mixed.
            if(preg_match('/@(?:phpstan-|psalm-)?property(?:-read|-write)?[^\r\n]*\$'.preg_quote($property,'/').'\b/',$frame['doc'])===1){
                $record('selected source property documentation retains priority',false);return $finish(null);
            }
            foreach(['get'.ModelReflection::studly($property).'Attribute','set'.ModelReflection::studly($property).'Attribute',lcfirst(ModelReflection::studly($property))] as $accessor){
                if(isset($frame['methods'][strtolower($accessor)])){$record('physical accessor retains priority',false);return $finish(null);}
            }
            // Unknown overrides in the relevant dispatch cannot be hidden by a
            // stale native declaring lookup or inherited namespace heuristic.
            foreach(['__get','getAttribute','getAttributeValue','getAttributeFromArray','transformModelValue','hasAttribute','getDates','usesTimestamps',
                'getCreatedAtColumn','getUpdatedAtColumn','getCasts','casts','hasGetMutator','hasAttributeGetMutator','mergeAttributeFromCachedCasts',
                'getTable','getConnectionName','getKeyName','getKeyType','getIncrementing'] as $method){
                if(!isset($frame['methods'][strtolower($method)])){continue;}
                $owner=match($method){'__get','getTable','getConnectionName','getKeyName','getKeyType','getIncrementing'=>self::MODEL,
                    'usesTimestamps','getCreatedAtColumn','getUpdatedAtColumn'=>self::TIMESTAMPS,default=>self::ATTRIBUTES};
                if(strcasecmp($frame['name'],$owner)!==0){$record('physical default dispatch owner '.$method,false);return $finish(null);}
            }
        }
        foreach(['getDeclaringProperty','getProperty','getDeclaringMagicProperty','getMagicProperty'] as $query){
            if(!$record('native selected property priority '.$query,$context->codebase->$query($model,'$'.$property)===null)){return $finish(null);}
        }
        foreach(['get'.ModelReflection::studly($property).'Attribute','set'.ModelReflection::studly($property).'Attribute',lcfirst(ModelReflection::studly($property)),
            'getDeletedAtColumn','initializeSoftDeletes'] as $method){
            if(!$record('no unproved accessor or deleted-at path '.$method,$context->codebase->getMethod($model,$method)===null
                &&$context->codebase->getDeclaringMethod($model,$method)===null)){return $finish(null);}
        }
        foreach(self::BODIES as $name=>$body){
            $owner=match($name){'__get','getTable','getConnectionName','getKeyName','getKeyType','getIncrementing'=>self::MODEL,
                'usesTimestamps','getCreatedAtColumn','getUpdatedAtColumn'=>self::TIMESTAMPS,default=>self::ATTRIBUTES};
            $appearing=$context->codebase->getMethod($model,$name);$declaring=$context->codebase->getDeclaringMethod($model,$name);
            if(!$record('current default native/source method '.$name,$declaring!==null&&self::method($context,$source,$frames,$declaring,$owner,$name,$body)
                &&($appearing===null||self::method($context,$source,$frames,$appearing,$owner,$name,$body)))){return $finish(null);}
            $dependencies['declaring:'.$name]=$declaring;if($appearing!==null){$dependencies['appearing:'.$name]=$appearing;}
        }
        foreach(['CREATED_AT'=>'created_at','UPDATED_AT'=>'updated_at'] as $name=>$expected){
            $constant=$context->codebase->getClassConstant($model,$name);$frame=$frames[strtolower(self::MODEL)]??null;$physical=$frame['constants'][$name]??null;
            if(!$record('current inherited timestamp constant '.$name,$constant!==null&&$frame!==null&&$physical!==null&&$physical['ordinary']&&!$physical['typed']
                &&$physical['value']===$expected&&$property!==$expected&&$constant->name===$name&&$constant->visibility===Visibility::Public
                &&$constant->flags->bits===0&&$constant->attributes===[]&&$constant->declaredType===null
                &&self::sameFile($source,$constant->location->file,$frame['file'])
                &&[$constant->location->span->start,$constant->location->span->end]===$physical['span']
                &&$constant->inferredType!==null&&!$constant->inferredType->flags->byReference&&!$constant->inferredType->flags->possiblyUndefined
                &&$constant->inferredType->getLiteralString()===$expected&&self::constantDoc($context,$source,$constant,$frame['file'],$physical))){return $finish(null);}
            foreach($frames as $candidate){if(strcasecmp($candidate['name'],self::MODEL)!==0&&isset($candidate['constants'][$name])){
                $record('physical timestamp constant override',false);return $finish(null);
            }}
            $dependencies['constant:'.$name]=$constant;
        }
        $table=$reflection->table($model);
        if(!$record('no selected cast and literal default table',is_array($casts)&&!array_key_exists($property,$casts)&&is_string($table)&&$table!=='')){return $finish(null);}
        $schema=new SchemaIndex($source);$schema->load();$column=$schema->column($table,$property);
        if(!$record('current selected primitive migration column',$column!==null&&in_array($column->type,['int','string','float'],true))){return $finish(null);}
        $contract=AttributeTypes::column($column);
        if(!$record('current schema sources and unchanged configuration',$source->warnings===[]&&$source->isCurrent()
            &&$composer===@hash_file('sha256',$root.'/composer.json'))){return $finish(null);}
        return $finish($contract);
    }
    private static function method(IssueFilterContext $context,PhpSource $source,array $frames,FunctionLikeMetadata $native,string $owner,string $name,string $body):bool
    {
        $frame=$frames[strtolower($owner)]??null;$physical=$frame['methods'][strtolower($name)]??null;
        if($frame===null||$physical===null||strcasecmp($native->identifier->class??'',$owner)!==0||strcasecmp($native->identifier->name,$name)!==0
            ||$native->kind!==\Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method
            ||$native->originalName!==$name||$native->static||$native->abstract||$native->constructor||$native->attributes!==[]
            ||$native->flags->contains(MetadataFlags::BUILTIN)
            ||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->visibility!==($name==='getAttributeFromArray'?Visibility::Protected:Visibility::Public)
            ||!self::sameFile($source,$native->location->file,$frame['file'])||!self::sameFile($source,$native->nameLocation?->file,$frame['file'])
            ||[$native->location->span->start,$native->location->span->end]!==$physical['span']
            ||[$native->nameLocation->span->start,$native->nameLocation->span->end]!==$physical['nameSpan']){return false;}
        $nodes=$source->read($frame['file']);$methods=$nodes===null?[]:(new NodeFinder)->findInstanceOf($nodes,Node\Stmt\ClassMethod::class);
        $matches=array_values(array_filter($methods,static fn($node):bool=>[$node->getStartFilePos(),$node->getEndFilePos()+1]===$physical['span']));
        if(count($matches)!==1){return false;}$node=$matches[0];
        if($node->byRef||$node->isStatic()||$node->isAbstract()||$node->returnType!==null||$node->attrGroups!==[]||$node->stmts===null
            ||count($node->params)!==count($native->parameters)){return false;}
        foreach($node->params as $index=>$parameter){$formal=$native->parameters[$index];
            if(!$parameter->var instanceof Node\Expr\Variable||$parameter->var->name!=='key'||$parameter->type!==null||$parameter->byRef||$parameter->variadic
                ||$parameter->default!==null||$formal->name!=='$key'||$formal->declaredType!==null||$formal->outType!==null||$formal->closureThisType!==null
                ||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)
                ||$formal->type===null||$formal->type->type->flags->byReference||$formal->type->type->flags->possiblyUndefined
                ||!$context->types->equals($formal->type->type,Type::string())
                ||!self::typeDoc($source,$formal->type,$node,$frame['file'],'param','string','$key')
                ||!self::sameFile($source,$formal->location?->file,$frame['file'])||!self::sameFile($source,$formal->nameLocation?->file,$frame['file'])
                ||[$formal->location->span->start,$formal->location->span->end]!==[$parameter->getStartFilePos(),$parameter->getEndFilePos()+1]
                ||[$formal->nameLocation->span->start,$formal->nameLocation->span->end]!==[$parameter->var->getStartFilePos(),$parameter->var->getEndFilePos()+1]){return false;}
        }
        $bytes=@file_get_contents($source->path($frame['file']));if($bytes===false){return false;}
        $start=strpos($bytes,'{',$node->name->getEndFilePos());if($start===false||$start>$node->getEndFilePos()){return false;}
        $tokens='';foreach(token_get_all('<?php '.substr($bytes,$start+1,$node->getEndFilePos()-$start-1)) as $token){
            if(!is_array($token)){$tokens.=$token;}elseif(!in_array($token[0],[T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){$tokens.=$token[1];}
        }
        $expectedReturn=match($name){'usesTimestamps','getIncrementing'=>Type::bool(),'getCreatedAtColumn','getUpdatedAtColumn','getConnectionName'=>Type::union(Type::string(),Type::null()),
            'getTable','getKeyName','getKeyType'=>Type::string(),
            'getDates'=>Type::array(Type::int(),Type::union(Type::string(),Type::null())),default=>Type::mixed()};
        $expectedDoc=match($name){'usesTimestamps','getIncrementing'=>'bool',
            'getCreatedAtColumn','getUpdatedAtColumn','getConnectionName'=>'string|null',
            'getTable','getKeyName','getKeyType'=>'string','getDates'=>'array<int,string|null>',default=>'mixed'};
        return $tokens===$body&&$native->declaredReturnType===null&&$native->returnType!==null
            &&!$native->returnType->type->flags->byReference&&!$native->returnType->type->flags->possiblyUndefined
            &&self::typeDoc($source,$native->returnType,$node,$frame['file'],'return',$expectedDoc)
            &&$context->types->equals($native->returnType->type,$expectedReturn);
    }
    /** Only the closed primary documentation grammar is supported here. */
    private static function typeDoc(PhpSource $source,object $native,Node\Stmt\ClassMethod $method,string $file,string $tag,string $expected,?string $parameter=null):bool
    {
        $comment=$method->getDocComment();if($comment===null||!$native->fromDocblock||$native->inferred
            ||!self::sameFile($source,$native->location->file,$file)){return false;}
        $text=$comment->getText();
        // Stronger tool-specific annotations may replace the ordinary tag.
        if(preg_match('/@(?:phpstan-|psalm-)(?:param|return)\b|@param-out\b/',$text)===1){return false;}
        $pattern=$tag==='return'?'/@return[ \t]+([^\r\n]+?)[ \t]*(?:\r?\n|$)/'
            :'/@param[ \t]+(\S+)[ \t]+'.preg_quote($parameter??'','/').'\b/';
        if(preg_match_all($pattern,$text,$matches,PREG_OFFSET_CAPTURE)!==1){return false;}
        [$type,$offset]=$matches[1][0];
        return preg_replace('/\s+/','',$type)===$expected
            &&[$native->location->span->start,$native->location->span->end]===[$comment->getStartFilePos()+$offset,$comment->getStartFilePos()+$offset+strlen($type)];
    }
    private static function constantDoc(IssueFilterContext $context,PhpSource $source,object $native,string $file,array $physical):bool
    {
        $nodes=$source->read($file);$selected=[];
        foreach((new NodeFinder)->findInstanceOf($nodes??[],Node\Stmt\ClassConst::class) as $statement){foreach($statement->consts as $constant){
            if([$constant->getStartFilePos(),$constant->getEndFilePos()+1]===$physical['span']){$selected[]=$statement;}
        }}
        if(count($selected)!==1){return false;}$comment=$selected[0]->getDocComment();$type=$native->type;
        if($comment===null||preg_match('/@(?:phpstan-|psalm-)?var\b/',$comment->getText())!==1
            ||preg_match('/@(?:phpstan-|psalm-)var\b/',$comment->getText())===1
            ||preg_match_all('/@var\b/',$comment->getText())!==1
            ||$type===null||!$type->fromDocblock||$type->inferred||$type->type->flags->byReference||$type->type->flags->possiblyUndefined
            ||!self::sameFile($source,$type->location->file,$file)
            ||preg_match('/@var\s+(string\|null|null\|string)(?=\s|\*\/|$)/',$comment->getText(),$match,PREG_OFFSET_CAPTURE)!==1){return false;}
        return [$type->location->span->start,$type->location->span->end]===[$comment->getStartFilePos()+$match[1][1],$comment->getStartFilePos()+$match[1][1]+strlen($match[1][0])]
            &&$context->types->equals($type->type,Type::union(Type::string(),Type::null()));
    }
    private static function sameFile(PhpSource $source,?string $a,?string $b):bool
    {return $a!==null&&$b!==null&&strcasecmp(str_replace('\\','/',$source->path($a)),str_replace('\\','/',$source->path($b)))===0;}
}
