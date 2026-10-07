<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeMetadata,MetadataFlags,PropertyMetadata,TypeMetadata};
use Mago\Sdk\Analyzer\Type\{ClassLikeStringKind,ClassLikeStringType,ClassLikeStringVariant,GenericParentKind,NamedObjectType,ScalarType,ScalarTypeKind,Variance};
use PhpParser\{Node,NodeFinder,NodeTraverser,NodeVisitorAbstract,ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** Fresh physical effective factory model default; SDK caches cannot certify changed initializers. */
final class PhysicalFactoryModelDefault
{
    public function __construct(private readonly string $root) {}

    public function admits(IssueFilterContext $context,string $factory,string $model,array &$receipt):bool
    {
        $reader=new NullFlowNativeContracts($this->root);$classes=[];$seen=[];$selected=null;$name=$factory;$budget=24;
        $stages=['effectivePhysicalOwner'=>false,'currentNativeTemplateProjection'=>false,'currentLiteralDefaultMatchesNative'=>false,'physicalDeclaredTypeMatchesNative'=>false,'currentSourcesAfterQueries'=>false];
        // Resolve current syntax first. The first physical declaration in the parent/trait
        // chain must be the declaration returned by the genuine effective lookup.
        while($name!==null) {
            if(--$budget<0||isset($seen[strtolower($name)])) { $receipt=['stages'=>$stages];return false; }
            $seen[strtolower($name)]=true;$bound=$this->classBound($context,$reader,$name,$classes);
            if($bound===null||$bound['source']['kind']!=='Class') { $receipt=['stages'=>$stages,'classes'=>$classes];return false; }
            $properties=[];if(isset($bound['source']['properties']['model'])) { $properties[]=['owner'=>$name,'class'=>$bound,'source'=>$bound['source']['properties']['model']]; }
            $traits=[];
            foreach($bound['source']['traits'] as $trait) {
                if(!$this->traitProperties($context,$reader,$trait,$classes,$traits,$properties,$budget)) { $receipt=['stages'=>$stages,'classes'=>$classes];return false; }
            }
            if(count($properties)>1) { $receipt=['stages'=>$stages,'classes'=>$classes];return false; }
            if($selected===null&&$properties!==[]) { $selected=$properties[0]; }
            $name=$bound['source']['parent'];
        }
        $native=$context->codebase->getDeclaringProperty($factory,'$model');
        // These are distinct installed SDK lookup roles. An inherited field can have
        // a genuine null child lookup while its physical declaring lookup is present.
        $appearing=$context->codebase->getProperty($factory,'$model');
        $ownerProperty=$selected===null?null:$context->codebase->getProperty($selected['owner'],'$model');
        $own=$selected!==null&&strcasecmp($selected['owner'],$factory)===0;
        $receipt=['stages'=>$stages,'classes'=>$classes,'physical'=>$selected,'native'=>$native,'appearingNative'=>$appearing,'ownerNative'=>$ownerProperty,
            'childLookupRole'=>$own?'own physical property':'inherited declaring property with absent child property'];
        if($selected===null||!$native instanceof PropertyMetadata||!$ownerProperty instanceof PropertyMetadata
            ||!$this->propertyBound($native,$selected)||!$this->propertyBound($ownerProperty,$selected)
            ||($own?(!$appearing instanceof PropertyMetadata||!$this->propertyBound($appearing,$selected)):$appearing!==null)) { return false; }
        $stages['effectivePhysicalOwner']=true;$physical=$selected['source'];
        if(!$physical['ordinary']||$physical['typeSpan']!==null) { $receipt['stages']=$stages;return false; }
        $stages['physicalDeclaredTypeMatchesNative']=true;
        $parent=[];
        if(!$this->parentTemplateBound($context,$reader,$classes,$model,$parent)) { $receipt['parentTemplate']=$parent;$receipt['stages']=$stages;return false; }
        $receipt['parentTemplate']=$parent;$stages['currentNativeTemplateProjection']=true;
        foreach(array_filter([$native,$appearing,$ownerProperty],static fn($property):bool=>$property instanceof PropertyMetadata) as $property) {
            if(!$this->defaultBound($context,$property,$selected,$model)
                ||!$this->valueTypeBound($context,$property->type,$selected,$parent,$model)) { $receipt['stages']=$stages;return false; }
        }
        $stages['currentLiteralDefaultMatchesNative']=true;
        // SDK RPC may reenter the hook. No earlier or mutable receipt decides this call.
        foreach($classes as $class) {
            $bytes=$reader->bytes($class['file']);
            if($bytes===null||hash('sha256',$bytes)!==$class['sourceSha256']) { $receipt['stages']=$stages;return false; }
        }
        $stages['currentSourcesAfterQueries']=true;$receipt['stages']=$stages;
        $receipt['defaultKind']=$physical['default']['kind'];$receipt['effectiveTypeAbsent']=['declaring'=>$native->type===null,'appearing'=>$appearing?->type===null,'owner'=>$ownerProperty->type===null];
        $receipt['unknownEffectiveTypeRefined']=false;$receipt['providerReturnChanged']=false;return true;
    }

    private function classBound(IssueFilterContext $context,NullFlowNativeContracts $reader,string $name,array &$classes):?array
    {
        $key=strtolower($name);if(isset($classes[$key])) { return $classes[$key]; }
        $native=$context->codebase->getClassLike($name);
        if(!$native instanceof ClassLikeMetadata||$native->hasIncompleteHierarchy()||strcasecmp($native->name,$name)!==0
            ||$native->nameLocation===null||$native->location->file===null||$native->flags->contains(MetadataFlags::BUILTIN)) { return null; }
        $file=$native->location->file;$bytes=$reader->bytes($file);$source=$bytes===null?null:(self::source($bytes)[$key]??null);
        if($source===null||$source['kind']!==($native->kind->name==='Class_'?'Class':$native->kind->name)
            ||!NullFlowNativeContracts::sameFile($native->nameLocation->file,$file)
            ||[$native->nameLocation->span->start,$native->nameLocation->span->end]!==$source['nameSpan']
            ||$native->location->span->start>$source['span'][0]||$native->location->span->end!==$source['span'][1]
            ||strcasecmp($native->directParentClass??'',$source['parent']??'')!==0||!$reader->current($file,$bytes)) { return null; }
        return $classes[$key]=['native'=>$native,'source'=>$source,'file'=>$file,'sourceSha256'=>hash('sha256',$bytes)];
    }

    private function traitProperties(IssueFilterContext $context,NullFlowNativeContracts $reader,string $name,array &$classes,array &$seen,array &$properties,int &$budget):bool
    {
        $key=strtolower($name);if(--$budget<0||isset($seen[$key])) { return false; }$seen[$key]=true;
        $bound=$this->classBound($context,$reader,$name,$classes);
        if($bound===null||$bound['source']['kind']!=='Trait') { return false; }
        if(isset($bound['source']['properties']['model'])) { $properties[]=['owner'=>$name,'class'=>$bound,'source'=>$bound['source']['properties']['model']]; }
        foreach($bound['source']['traits'] as $trait) { if(!$this->traitProperties($context,$reader,$trait,$classes,$seen,$properties,$budget)) { return false; } }
        return true;
    }

    private function propertyBound(PropertyMetadata $native,array $selected):bool
    {
        $source=$selected['source'];$file=$selected['class']['file'];
        // Installed genuine factory declaration metadata uses location for a physical
        // type declaration. An untyped field has location/declaredType/writeType null;
        // nameLocation and defaultType.location bind its storage and initializer.
        return ltrim($native->name,'$')==='model'&&$native->location===null&&$native->declaredType===null&&$native->writeType===null&&$native->nameLocation!==null
            &&NullFlowNativeContracts::sameFile($native->nameLocation->file,$file)
            &&[$native->nameLocation->span->start,$native->nameLocation->span->end]===$source['nameSpan']
            &&$native->readVisibility->name===$source['visibility']&&$native->writeVisibility===$native->readVisibility
            &&$native->hooks===[]&&$native->attributes===[]
            &&($native->flags->bits&(MetadataFlags::STATIC|MetadataFlags::VIRTUAL_PROPERTY|MetadataFlags::PROMOTED_PROPERTY|MetadataFlags::WRITEONLY|MetadataFlags::BY_REFERENCE|MetadataFlags::READONLY|MetadataFlags::ASYMMETRIC_PROPERTY))===0
            &&$native->flags->contains(MetadataFlags::HAS_DEFAULT)===($source['default']['kind']!=='uninitialized');
    }

    private function defaultBound(IssueFilterContext $context,PropertyMetadata $native,array $selected,string $model):bool
    {
        $default=$selected['source']['default'];$metadata=$native->defaultType;
        if($default['kind']==='uninitialized') {
            // An untyped uninitialized declaration is Laravel's absent override; a
            // concrete default replacement must not survive this certificate.
            return $metadata===null;
        }
        if($metadata===null||$metadata->fromDocblock||!$metadata->inferred||$metadata->type->flags->byReference||$metadata->type->flags->possiblyUndefined
            ||!NullFlowNativeContracts::sameFile($metadata->location->file,$selected['class']['file'])
            ||[$metadata->location->span->start,$metadata->location->span->end]!==$default['span']) { return false; }
        if($default['kind']==='null') { return $context->types->equals($metadata->type,Type::null()); }
        $atom=$metadata->type->atomicTypes[0]??null;$string=$atom instanceof ScalarType?$atom->refinement:null;
        return $default['kind']==='class'&&strcasecmp($default['class'],$model)===0&&count($metadata->type->atomicTypes)===1
            &&$atom instanceof ScalarType&&$atom->kind===ScalarTypeKind::ClassLikeString&&$string instanceof ClassLikeStringType
            &&$string->variant===ClassLikeStringVariant::Literal&&$string->kind===null&&strcasecmp($string->literal??'',$default['class'])===0
            &&$string->parameterName===null&&$string->definingEntity===null&&$string->constraint===null;
    }

    private function valueTypeBound(IssueFilterContext $context,?TypeMetadata $metadata,array $selected,array $parent,string $model):bool
    {
        $default=$selected['source']['default'];$unannotated=preg_match('/@(?:phpstan-|psalm-)?var\b/',$selected['source']['doc'])!==1;
        // No @var on an own literal field need not have an effective SDK type. Its
        // unknown input type remains unknown; native default and declared output
        // contracts are independent evidence, never a fabricated provider value.
        if($metadata===null) { return $default['kind']==='class'&&$unannotated; }
        $type=$metadata->type;
        if($type->flags->byReference||$type->flags->possiblyUndefined||count($type->atomicTypes)!==1) { return false; }
        if(!$metadata->fromDocblock) {
            return $default['kind']==='class'&&$unannotated
                &&(strcasecmp($type->getLiteralClassString()??'',$default['class'])===0||$context->types->equals($type,Type::mixed()));
        }
        $origin=$parent['native']->type?->location;
        if($origin===null||!NullFlowNativeContracts::sameFile($metadata->location->file,$origin->file)
            ||[$metadata->location->span->start,$metadata->location->span->end]!==[$origin->span->start,$origin->span->end]) { return false; }
        $atom=$type->atomicTypes[0];$refinement=$atom instanceof ScalarType&&$atom->kind===ScalarTypeKind::ClassLikeString?$atom->refinement:null;
        if(!$refinement instanceof ClassLikeStringType) { return false; }
        if($refinement->variant===ClassLikeStringVariant::Generic) {
            return !$metadata->inferred&&$refinement->kind===ClassLikeStringKind::Class_&&$refinement->literal===null&&$refinement->parameterName==='TModel'&&$refinement->definingEntity?->kind===GenericParentKind::ClassLike
                &&strcasecmp($refinement->definingEntity->name,FactoryReflection::FACTORY)===0
                &&$refinement->definingEntity->member===null&&$this->plainObject($refinement->constraint,ModelReflection::MODEL);
        }
        $generic=$refinement->constraint;
        if($refinement->variant===ClassLikeStringVariant::OfType&&$generic instanceof NamedObjectType) {
            // An inferred OfType projection must agree with the
            // inherited doc projection. Its exact source model binding is checked
            // independently from the literal initializer and original base template.
            return $metadata->inferred&&$refinement->kind===ClassLikeStringKind::Class_&&$refinement->literal===null
                &&$refinement->parameterName===null&&$refinement->definingEntity===null&&$this->plainObject($generic,$model);
        }
        return false;
    }

    private function parentTemplateBound(IssueFilterContext $context,NullFlowNativeContracts $reader,array $classes,string $model,array &$receipt):bool
    {
        $base=$classes[strtolower(FactoryReflection::FACTORY)]??null;$source=$base['source']??null;$physical=$source['properties']['model']??null;
        $native=$context->codebase->getProperty(FactoryReflection::FACTORY,'$model');
        $declaring=$context->codebase->getDeclaringProperty(FactoryReflection::FACTORY,'$model');
        $receipt=['sourceClass'=>$base,'native'=>$native,'declaringNative'=>$declaring,'modelBindings'=>[]];
        $selected=$base===null||$physical===null?null:['owner'=>FactoryReflection::FACTORY,'class'=>$base,'source'=>$physical];
        if($selected===null||$source['genericTemplate']!==ModelReflection::MODEL||$physical['default']['kind']!=='uninitialized'
            ||!$physical['ordinary']||$physical['typeSpan']!==null||!$native instanceof PropertyMetadata||!$declaring instanceof PropertyMetadata
            ||!$this->propertyBound($native,$selected)||!$this->propertyBound($declaring,$selected)||$native!=$declaring
            ||$native->defaultType!==null||$native->type===null||!$native->type->fromDocblock||$native->type->inferred
            ||count($native->type->type->atomicTypes)!==1||$native->type->type->flags->byReference||$native->type->type->flags->possiblyUndefined) { return false; }
        $bytes=$reader->bytes($base['file']);$location=$native->type->location;$docSpan=$physical['docSpan'];
        if($bytes===null||$docSpan===null||!NullFlowNativeContracts::sameFile($location->file,$base['file'])
            ||$location->span->start<$docSpan[0]||$location->span->end>$docSpan[1]
            ||substr($bytes,$location->span->start,$location->span->end-$location->span->start)!=='class-string<TModel>'
            ||preg_match_all('/@(?:phpstan-|psalm-)?var\b/',$physical['doc'])!==1) { return false; }
        $atom=$native->type->type->atomicTypes[0];$string=$atom instanceof ScalarType&&$atom->kind===ScalarTypeKind::ClassLikeString?$atom->refinement:null;
        $template=$base['native']->templates[0]??null;
        if(!$string instanceof ClassLikeStringType||$string->variant!==ClassLikeStringVariant::Generic||$string->kind!==ClassLikeStringKind::Class_
            ||$string->literal!==null||$string->parameterName!=='TModel'||$string->definingEntity?->kind!==GenericParentKind::ClassLike
            ||strcasecmp($string->definingEntity->name,FactoryReflection::FACTORY)!==0||$string->definingEntity->member!==null
            ||!$this->plainObject($string->constraint,ModelReflection::MODEL)||count($base['native']->templates)!==1||$template?->name!=='TModel'
            ||$template->definingEntity->kind!==GenericParentKind::ClassLike||strcasecmp($template->definingEntity->name,FactoryReflection::FACTORY)!==0
            ||$template->definingEntity->member!==null||$template->default!==null||$template->variance!==Variance::Invariant
            ||count($template->constraint->atomicTypes)!==1||!$this->plainObject($template->constraint->atomicTypes[0],ModelReflection::MODEL)
            ||$template->constraint->flags->byReference||$template->constraint->flags->possiblyUndefined) { return false; }
        foreach($classes as $class) {
            $syntax=$class['source'];if($syntax['kind']!=='Class'||strcasecmp($syntax['name'],FactoryReflection::FACTORY)===0) { continue; }
            if($syntax['genericTemplate']!==null||$class['native']->templates!==[]||$class['native']->mixins!==[]||$class['native']->typeAliases!==[]) { return false; }
            $binding=$syntax['genericExtends'];if($binding===null) { continue; }
            if(!is_array($binding)||strcasecmp($binding[0],FactoryReflection::FACTORY)!==0||strcasecmp($binding[1],$model)!==0) { return false; }
            $receipt['modelBindings'][$syntax['name']]=$binding;
        }
        return $receipt['modelBindings']!==[]&&$reader->current($base['file'],$bytes);
    }

    private function plainObject(mixed $atom,string $name):bool
    {
        return $atom instanceof NamedObjectType&&strcasecmp($atom->name,$name)===0&&!$atom->static&&!$atom->isThis&&!$atom->remappedParameters
            &&($atom->parameters??[])===[]&&($atom->variances??[])===[]&&($atom->intersections??[])===[];
    }

    /** Source-only compact declaration projection; no AST or whole SDK cache is retained. */
    public static function source(string $bytes):array
    {
        if(strlen($bytes)>2_000_000) { return []; }
        try {
            $resolver=new NameResolver;
            $docs=new class($resolver) extends NodeVisitorAbstract {
                public function __construct(private readonly NameResolver $resolver) {}
                public function enterNode(Node $node):?Node
                {
                    if(!$node instanceof Node\Stmt\ClassLike) { return null; }
                    $doc=$node->getDocComment()?->getText()??'';$identifier='[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*';
                    $resolve=function(string $name):?string {
                        if(in_array(strtolower($name),['self','static','parent'],true)) { return null; }
                        $qualified=str_starts_with($name,'\\');$plain=$qualified?substr($name,1):$name;
                        // Doc tokens are untrusted strings, unlike parser-owned PHP
                        // names. Reject empty/repeated/trailing segments before the
                        // Name constructors, which throw for an empty class token.
                        if($plain===''||!array_all(explode('\\',$plain),static fn(string $segment):bool=>preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$segment)===1)) { return null; }
                        $syntax=$qualified?new Node\Name\FullyQualified($plain):new Node\Name($plain);
                        return $this->resolver->getNameContext()->getResolvedClassName($syntax)->toString();
                    };
                    $extends=null;$count=preg_match_all('/@(?:phpstan-|psalm-)?(?:extends|template-extends)\b/',$doc);
                    if($count>0) { $extends=false;if($count===1&&preg_match('/@extends\s+('.$identifier.')\s*<\s*('.$identifier.')\s*>\s*(?:\*\/|\r?\n)/',$doc,$match)===1) {
                        $parent=$resolve($match[1]);$model=$resolve($match[2]);if($parent!==null&&$model!==null) { $extends=[$parent,$model]; }
                    } }
                    $template=null;$count=preg_match_all('/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\b/',$doc);
                    if($count>0) { $template=false;if($count===1&&preg_match('/@template\s+TModel\s+(?:of|as)\s+('.$identifier.')\s*(?:\*\/|\r?\n)/',$doc,$match)===1) { $template=$resolve($match[1])??false; } }
                    $node->setAttribute('physicalModelGenericExtends',$extends);$node->setAttribute('physicalModelGenericTemplate',$template);return null;
                }
            };
            $nodes=(new NodeTraverser($resolver,$docs))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]);
        }
        catch(\PhpParser\Error) { return []; }
        $result=[];
        foreach((new NodeFinder)->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\ClassLike&&$node->name!==null&&isset($node->namespacedName)) as $class) {
            if(!$class instanceof Node\Stmt\Class_&&!$class instanceof Node\Stmt\Trait_) { continue; }
            $traits=[];$ordinary=true;
            // PHP trait adaptations rename/select methods; they do not rename or
            // select properties. Every used trait's physical model field is still read.
            foreach($class->getTraitUses() as $use) { foreach($use->traits as $trait) { $traits[]=$trait->toString(); } }
            $properties=[];
            foreach($class->getProperties() as $property) { foreach($property->props as $item) {
                if($item->name->name!=='model') { continue; }if(isset($properties['model'])) { $ordinary=false; }
                $default=['kind'=>'unknown','span'=>$item->default===null?null:self::span($item->default)];
                if($item->default===null) { $default['kind']='uninitialized'; }
                elseif($item->default instanceof Node\Expr\ConstFetch&&strtolower($item->default->name->toString())==='null') { $default['kind']='null'; }
                elseif($item->default instanceof Node\Expr\ClassConstFetch&&$item->default->class instanceof Node\Name\FullyQualified
                    &&$item->default->name instanceof Node\Identifier&&strtolower($item->default->name->name)==='class') { $default['kind']='class';$default['class']=$item->default->class->toString(); }
                $doc=$property->getDocComment();
                $properties['model']=['span'=>self::span($property),'nameSpan'=>self::span($item->name),'typeSpan'=>$property->type===null?null:self::span($property->type),
                    'ordinary'=>$ordinary&&$property->hooks===[]&&$property->attrGroups===[]&&!$property->isStatic()&&!$property->isAbstract()&&!$property->isReadonly(),
                    'visibility'=>$property->isPrivate()?'Private':($property->isProtected()?'Protected':'Public'),
                    'doc'=>$doc?->getText()??'','docSpan'=>$doc===null?null:[$doc->getStartFilePos(),$doc->getEndFilePos()+1],'default'=>$default];
            } }
            foreach($class->getMethod('__construct')?->params??[] as $parameter) { if($parameter->isPromoted()&&$parameter->var->name==='model') { $ordinary=false; } }
            $name=$class->namespacedName->toString();
            $result[strtolower($name)]=['name'=>$name,'kind'=>$class instanceof Node\Stmt\Class_?'Class':'Trait','span'=>self::span($class),'nameSpan'=>self::span($class->name),
                'parent'=>$class instanceof Node\Stmt\Class_?$class->extends?->toString():null,'traits'=>$ordinary?$traits:['invalid duplicate or promoted model declaration'],
                'genericExtends'=>$class->getAttribute('physicalModelGenericExtends'),'genericTemplate'=>$class->getAttribute('physicalModelGenericTemplate'),
                'properties'=>$properties];
        }
        return $result;
    }
    private static function span(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
}
