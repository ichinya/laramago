<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\Type\Variance;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Current declared native-array storage/read contracts, not a lifetime or existence theorem. */
final class NullableCollectionOffsetContract
{
    public array $stages = [];
    public function __construct(private readonly string $root, private readonly string $support) {}
    public function current(IssueFilterContext $context, string $receiver): bool
    {
        $this->stages = ['receiver' => $receiver, 'classes' => []];
        $ancestors = $context->codebase->getClassAncestors($receiver);
        if (strcasecmp($receiver, $this->support) !== 0 && ! in_array(strtolower($this->support), array_map('strtolower', $ancestors), true)) {
            $this->stages['receiverFrameworkAncestor'] = false; return false;
        }
        $this->stages['receiverFrameworkAncestor'] = true;
        $known = [$receiver, ...$context->codebase->getClassDescendants($receiver)];
        if (count($known) > 128) { $this->stages['boundedDescendants'] = false; return false; }
        $classes = [$this->support, ...array_filter($ancestors, fn (string $name): bool => $context->codebase->getClass($name)?->kind === ClassLikeKind::Class_), ...$known];
        foreach (array_unique($classes) as $class) {
            if (! $this->readClass($context, $class)) { return false; }
        }
        return true;
    }

    private function readClass(IssueFilterContext $context, string $class): bool
    {
        $metadata = $context->codebase->getClass($class);
        $file = $metadata === null ? null : $this->source($metadata->location->file);
        $syntax = $file === null ? null : $this->classNode($file['nodes'], $class);
        $checks = ['classPresent' => $metadata !== null, 'ordinaryClass' => $metadata?->kind === ClassLikeKind::Class_,
            'nativeClassNameMatches' => $metadata !== null && strcasecmp($metadata->name, $class) === 0,
            'hierarchyComplete' => $metadata !== null && ! $metadata->hasIncompleteHierarchy(),
            'noMixinsOrAliases' => $metadata !== null && $metadata->mixins === [] && $metadata->typeAliases === [],
            'ordinarySourceClassFlags' => $metadata !== null && ! $metadata->flags->contains(MetadataFlags::BUILTIN)
                && ! $metadata->flags->contains(MetadataFlags::READONLY),
            'sourceClass' => $syntax instanceof Node\Stmt\Class_,
            'sourceClassLocated' => $metadata !== null && $syntax !== null && $file !== null && $this->located($metadata->location, $syntax, $file),
            'sourceClassNameLocated' => $metadata !== null && $syntax?->name !== null && $file !== null && $this->located($metadata->nameLocation, $syntax->name, $file),
            'finalFlagMatches' => $metadata !== null && $syntax !== null && $metadata->flags->contains(MetadataFlags::FINAL) === $syntax->isFinal()];
        $this->stages['classes'][$class] = ['guards' => $checks];
        if (in_array(false, $checks, true)) { return false; }
        if (strcasecmp($syntax->extends?->toString() ?? '', $metadata->directParentClass ?? '') !== 0) {
            $this->stages['classes'][$class]['directParentBound'] = false; return false;
        }
        if (strcasecmp($class, $this->support) === 0 && ! $this->templates($context, $metadata, $syntax)) {
            $this->stages['classes'][$class]['sourceNativeTemplates'] = false; return false;
        }
        if (! $this->visibleStorageEffects($context, $syntax, $metadata->usedTraits, [])) {
            $this->stages['classes'][$class]['sourceKnownStorageEffects'] = false; return false;
        }
        // Native concrete receiver still admits its merged-codebase descendants. Audit effective declarations separately.
        foreach (['offsetGet', 'offsetExists'] as $method) {
            if (! $this->reader($context, $class, $method)) { return false; }
        }
        if (! $this->setter($context, $class, $syntax, $metadata)) { return false; }
        $directProperty = $context->codebase->getProperty($class, '$items');
        $declaring = $context->codebase->getDeclaringProperty($class, '$items');
        $ownerProperty = $context->codebase->getProperty($this->support, '$items');
        $ownerDeclaringProperty = $context->codebase->getDeclaringProperty($this->support, '$items');
        // GET_PROPERTIES is a direct slot query. Inherited storage is independently
        // bound by its declaring query and the current source/native absence of a shadow.
        $inheritedProperty = strcasecmp($class, $this->support) !== 0 && $directProperty === null
            && $this->noOwnMember($context, $syntax, $metadata, '$items', true, []);
        $property = $declaring;
        $owner = $context->codebase->getDeclaringMethod($class, 'offsetGet')?->identifier->class;
        $ownerMetadata = $owner === null ? null : $context->codebase->getClass($owner);
        $ownerFile = $ownerMetadata === null ? null : $this->source($ownerMetadata->location->file);
        $ownerSyntax = $ownerFile === null || $owner === null ? null : $this->classNode($ownerFile['nodes'], $owner);
        $field = null; $item = null;
        foreach ($ownerSyntax?->getProperties() ?? [] as $candidate) {
            foreach ($candidate->props as $value) { if ($value->name->name === 'items') { if ($field !== null) { return false; } $field = $candidate; $item = $value; } }
        }
        $array = $property?->type?->type->atomicTypes[0] ?? null;
        $default = $property?->defaultType?->type->atomicTypes[0] ?? null;
        $checks = ['nativeFieldPresent' => $property !== null && $ownerProperty !== null && $ownerDeclaringProperty !== null,
            'currentOwnerFieldEqualsDeclaring' => $ownerProperty !== null && $ownerProperty == $ownerDeclaringProperty,
            'declaringFieldEqualsCurrentOwner' => $property !== null && $property == $ownerProperty,
            'directOrSourceCertifiedInheritedField' => $directProperty !== null ? $directProperty == $property : $inheritedProperty,
            'physicalProtectedField' => $property !== null && $property->name === '$items' && $property->readVisibility === Visibility::Protected
                && $property->writeVisibility === Visibility::Protected && ! $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
                && ! $property->flags->contains(MetadataFlags::STATIC) && ! $property->flags->contains(MetadataFlags::READONLY)
                && ! $property->flags->contains(MetadataFlags::ASYMMETRIC_PROPERTY) && ! $property->flags->contains(MetadataFlags::WRITEONLY)
                && ! $property->flags->contains(MetadataFlags::BY_REFERENCE) && $property->flags->contains(MetadataFlags::HAS_DEFAULT)
                && $property->hooks === [] && $property->writeType === null && $property->location === null,
            'sourceField' => $field !== null && $item !== null && $field->isProtected() && ! $field->isStatic() && count($field->props) === 1 && $field->type === null,
            'sourceFieldNameLocated' => $property !== null && $item !== null && $ownerFile !== null && $this->located($property->nameLocation, $item->name, $ownerFile),
            'untypedSourceFieldMetadata' => $property !== null && $property->declaredType === null,
            'nativeArrayDoc' => $property?->type !== null && $property->type->fromDocblock && ! $property->type->inferred
                && ! $property->type->type->flags->byReference && ! $property->type->type->flags->possiblyUndefined
                && count($property->type->type->atomicTypes) === 1 && $array instanceof KeyedArrayType && $array->knownItems === null && ! $array->nonEmpty,
            'nativeArrayGenericKey' => $array instanceof KeyedArrayType && $this->generic($context, $array->keyType, 'TKey', $this->support),
            'nativeArrayGenericValue' => $array instanceof KeyedArrayType && $this->generic($context, $array->valueType, 'TValue', $this->support),
            'sourceEmptyArrayDefault' => $item?->default instanceof Node\Expr\Array_ && $item->default->items === [],
            'nativeEmptyArrayDefault' => $property?->defaultType !== null && count($property->defaultType->type->atomicTypes) === 1
                && ! $property->defaultType->fromDocblock && $property->defaultType->inferred
                && ($default instanceof ListType && $default->knownCount === 0 && $default->knownElements === [] && ! $default->nonEmpty
                    || $default instanceof KeyedArrayType && $default->knownItems === [] && ! $default->nonEmpty),
            'sourceDefaultLocated' => $property?->defaultType !== null && $item?->default !== null && $ownerFile !== null
                && $this->located($property->defaultType->location, $item->default, $ownerFile)];
        $this->stages['classes'][$class]['storageGuards'] = $checks;
        if (in_array(false, $checks, true)) { return false; }
        $doc = $field->getDocComment();
        $located = $property->type->location;
        if ($doc === null || $located === null || $ownerFile === null || ! $this->docType($located, $doc, $ownerFile, 'array<TKey,TValue>')) {
            $this->stages['classes'][$class]['arrayDocCurrentSource'] = false; return false;
        }
        // Preserve concrete source contradictions/ref escapes, including a known descendant's storage replacement.
        foreach ((new NodeFinder)->findInstanceOf($syntax->stmts, Node\Expr\Assign::class) as $assign) {
            if ($this->items($assign->var) && $assign->expr instanceof Node\Expr\New_) { $this->stages['classes'][$class]['sourceObjectStorageReplacement'] = true; return false; }
        }
        if ((new NodeFinder)->findFirst($syntax->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Expr\PropertyFetch && ! $node->name instanceof Node\Identifier
            || $node instanceof Node\Stmt\ClassMethod && $node->byRef) !== null) {
            $this->stages['classes'][$class]['sourceAliasOrDynamicStorage'] = true; return false;
        }
        return true;
    }

    /** PHPStan ObjectType obtains accepted ArrayAccess keys from this first setter formal. */
    private function setter(IssueFilterContext $context,string $class,Node\Stmt\Class_ $classSyntax,object $metadata):bool
    {
        $direct=$context->codebase->getMethod($class,'offsetSet');$native=$context->codebase->getDeclaringMethod($class,'offsetSet');$owner=$context->codebase->getDeclaringMethod($this->support,'offsetSet');
        if($native===null||$owner===null||$native!=$owner||strcasecmp($native->identifier->class??'',$this->support)!==0||strcasecmp($native->identifier->name,'offsetSet')!==0
            ||$native->abstract||$native->static||$native->constructor||$native->flags->contains(MetadataFlags::BUILTIN)||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->flags->contains(MetadataFlags::MAGIC_METHOD)
            ||$native->kind!==\Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method||$native->identifier->kind!==\Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            ||$native->templates!==[]||$native->whereConstraints!==[]||$native->attributes!==[]
            ||$native->visibility!==Visibility::Public||count($native->parameters)!==2||($direct!==null?$direct!=$native:!$this->noOwnMember($context,$classSyntax,$metadata,'offsetSet',false,[]))) { return false; }
        $file=$this->source($native->location->file);$syntax=$file===null?null:$this->classNode($file['nodes'],$this->support)?->getMethod('offsetSet');
        if($file===null||$syntax===null||!$this->located($native->location,$syntax,$file)||!$this->located($native->nameLocation,$syntax->name,$file)
            ||!$syntax->isPublic()||$syntax->isStatic()||$syntax->byRef||$syntax->attrGroups!==[]||count($syntax->params)!==2||!$syntax->returnType instanceof Node\Identifier||strtolower($syntax->returnType->name)!=='void'
            ||$native->originalName!==$syntax->name->name||$native->hasDocblock!==($syntax->getDocComment()!==null)||$native->final!==$syntax->isFinal()
            ||$native->declaredReturnType===null||$native->declaredReturnType->fromDocblock||$native->declaredReturnType->inferred||!$this->located($native->declaredReturnType->location,$syntax->returnType,$file)
            ||!$context->types->equals($native->declaredReturnType->type,Type::void())||$native->returnType===null||$native->returnType->inferred||!$context->types->equals($native->returnType->type,Type::void())
            ||!($native->returnType->fromDocblock?$this->docType($native->returnType->location,$syntax->getDocComment(),$file,'void'):$this->located($native->returnType->location,$syntax->returnType,$file))) { return false; }
        $doc=$syntax->getDocComment();if($doc===null) { return false; }
        foreach(['offset','value'] as $index=>$name) { $formal=$native->parameters[$index];$param=$syntax->params[$index];
            if(!$param->var instanceof Node\Expr\Variable||$param->var->name!==$name||$param->type!==null||$param->byRef||$param->variadic||$param->default!==null||$formal->name!=='$'.$name
                ||$formal->declaredType!==null||$formal->outType!==null||$formal->closureThisType!==null||$formal->defaultType!==null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)
                ||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->flags->contains(MetadataFlags::HAS_DEFAULT)||$formal->type===null||!$formal->type->fromDocblock||$formal->type->inferred
                ||!$this->located($formal->location,$param,$file)||!$this->located($formal->nameLocation,$param->var,$file)||!$this->docType($formal->type->location,$doc,$file,$index===0?'TKey|null':'TValue')) { return false; }
        }
        $key=$native->parameters[0]->type->type;$parts=[];$nulls=0;
        foreach($key->atomicTypes as $atom) { if($atom instanceof \Mago\Sdk\Analyzer\Type\SimpleAtomicType&&$atom->kind===\Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind::Null) { $nulls++; }else { $parts[]=$atom; } }
        if($nulls!==1||count($parts)!==1||$key->flags->byReference||$key->flags->possiblyUndefined||!$this->generic($context,Type::fromAtomics(...$parts),'TKey',$this->support)
            ||!$this->generic($context,$native->parameters[1]->type->type,'TValue',$this->support)) { return false; }
        $expected='if (is_null($offset)) {' . "\n".'    $this->items[] = $value;' . "\n".'} else {' . "\n".'    $this->items[$offset] = $value;' . "\n".'}';
        if((new \PhpParser\PrettyPrinter\Standard)->prettyPrint($syntax->stmts??[])!==$expected) { return false; }
        $namespace=str_contains($this->support,'\\')?substr($this->support,0,strrpos($this->support,'\\')):'';
        if($namespace!==''&&$context->codebase->getFunction($namespace.'\\is_null')!==null) { return false; }$isNull=$context->codebase->getFunction('is_null');
        if($isNull===null||!$isNull->flags->contains(MetadataFlags::BUILTIN)||count($isNull->parameters)!==1||$isNull->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE)||$isNull->parameters[0]->outType!==null
            ||$file['hash']!==hash_file('sha256',$file['path'])) { return false; }
        $this->stages['classes'][$class]['setterCertificate']=['actualNativeSetter'=>$native,'sourceSha256'=>$file['hash'],'firstFormalTKeyOrNull'=>true,'valueFormalTValue'=>true,'primitiveNullAppendBranch'=>true,'nativeNullCheck'=>$isNull];return true;
    }
    private function reader(IssueFilterContext $context, string $class, string $name): bool
    {
        $direct = $context->codebase->getMethod($class, $name);
        $declaring = $context->codebase->getDeclaringMethod($class, $name);
        $ownerMethod = $context->codebase->getMethod($this->support, $name);
        $ownerDeclaringMethod = $context->codebase->getDeclaringMethod($this->support, $name);
        $metadata = $context->codebase->getClass($class);
        $classFile = $metadata === null ? null : $this->source($metadata->location->file);
        $classSyntax = $classFile === null ? null : $this->classNode($classFile['nodes'], $class);
        $inherited = strcasecmp($class, $this->support) !== 0 && $direct === null && $metadata !== null && $classSyntax !== null
            && $this->noOwnMember($context, $classSyntax, $metadata, $name, false, []);
        $method = $declaring;
        if ($method === null || $ownerMethod === null || $ownerDeclaringMethod === null
            || $ownerMethod != $ownerDeclaringMethod || $method != $ownerMethod
            || ($direct !== null ? $direct != $method : ! $inherited)
            || strcasecmp($method->identifier->class ?? '', $this->support) !== 0
            || $method->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            || $method->kind !== \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method || $method->constructor
            || strcasecmp($method->identifier->name, $name) !== 0 || strcasecmp($method->name, $name) !== 0
            || $method->abstract || $method->static || $method->visibility !== Visibility::Public || $method->templates !== [] || $method->whereConstraints !== []
            || $method->flags->contains(MetadataFlags::BY_REFERENCE) || $method->flags->contains(MetadataFlags::MAGIC_METHOD)
            || $method->flags->contains(MetadataFlags::BUILTIN) || count($method->parameters) !== 1) {
            $this->stages['classes'][$class][$name] = 'native current ordinary declaring binding refused'; return false;
        }
        $file = $this->source($method->location->file);
        $owner = $file === null ? null : $this->classNode($file['nodes'], $this->support);
        $syntax = $owner?->getMethod($name);
        if ($file === null || $syntax === null || ! $this->located($method->location, $syntax, $file)
            || ! $this->located($method->nameLocation, $syntax->name, $file) || ! $syntax->isPublic() || $syntax->isStatic() || $syntax->byRef
            || $method->originalName !== $syntax->name->name
            || $syntax->isAbstract() || $method->hasDocblock !== ($syntax->getDocComment() !== null)
            || $method->final !== $syntax->isFinal() || count($syntax->params) !== 1 || count($syntax->stmts ?? []) !== 1
            || ! $syntax->stmts[0] instanceof Node\Stmt\Return_) { $this->stages['classes'][$class][$name] = 'current physical read header refused'; return false; }
        $parameter = $method->parameters[0]; $param = $syntax->params[0];
        if (! $param->var instanceof Node\Expr\Variable || $param->var->name !== 'offset' || $param->type !== null || $param->byRef || $param->variadic || $param->default !== null
            || $parameter->name !== '$offset' || $parameter->declaredType !== null || $parameter->outType !== null || $parameter->closureThisType !== null
            || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
            || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) || $parameter->defaultType !== null
            || ! $this->located($parameter->location, $param, $file) || ! $this->located($parameter->nameLocation, $param->var, $file)
            || $parameter->type === null || ! $parameter->type->fromDocblock || $parameter->type->inferred
            || ! $this->generic($context, $parameter->type->type, 'TKey', $this->support)
            || $syntax->getDocComment() === null || ! $this->docType($parameter->type->location, $syntax->getDocComment(), $file, 'TKey')) {
            $this->stages['classes'][$class][$name] = 'current untyped generic native offset refused'; return false;
        }
        $read = $syntax->stmts[0]->expr;
        if ($name === 'offsetExists') {
            if (! $read instanceof Node\Expr\Isset_ || count($read->vars) !== 1) { return false; }
            $read = $read->vars[0];
            if (! $syntax->returnType instanceof Node\Identifier || strtolower($syntax->returnType->name) !== 'bool'
                || $method->declaredReturnType === null || $method->declaredReturnType->fromDocblock || $method->declaredReturnType->inferred
                || ! $context->types->equals($method->declaredReturnType->type, Type::bool()) || ! $this->located($method->declaredReturnType->location, $syntax->returnType, $file)
                || $method->returnType === null || $method->returnType->inferred || ! $context->types->equals($method->returnType->type, Type::bool())
                || ! ($method->returnType->fromDocblock
                    ? $this->docType($method->returnType->location, $syntax->getDocComment(), $file, 'bool')
                    : $this->located($method->returnType->location, $syntax->returnType, $file))) { return false; }
        } else {
            if (! $syntax->returnType instanceof Node\Identifier || strtolower($syntax->returnType->name) !== 'mixed'
                || $method->declaredReturnType === null || $method->declaredReturnType->fromDocblock || $method->declaredReturnType->inferred
                || ! $context->types->equals($method->declaredReturnType->type, Type::mixed()) || ! $this->located($method->declaredReturnType->location, $syntax->returnType, $file)
                || $method->returnType === null || ! $method->returnType->fromDocblock || $method->returnType->inferred
                || ! $this->generic($context, $method->returnType->type, 'TValue', $this->support)
                || ! $this->docType($method->returnType->location, $syntax->getDocComment(), $file, 'TValue')) { return false; }
        }
        $valid = $read instanceof Node\Expr\ArrayDimFetch && $this->items($read->var)
            && $read->dim instanceof Node\Expr\Variable && $read->dim->name === 'offset';
        $this->stages['classes'][$class][$name] = $valid ? 'current native ordinary array read bound' : 'current array body refused';
        return $valid;
    }

    /** Direct source/native member absence, including ordinary nested traits. */
    private function noOwnMember(IssueFilterContext $context, Node\Stmt\ClassLike $syntax, object $metadata, string $name, bool $property, array $visited): bool
    {
        $names = $property ? [...$metadata->properties, ...$metadata->magicProperties]
            : [...$metadata->methods, ...$metadata->pseudoMethods, ...$metadata->staticPseudoMethods];
        foreach ($names as $candidate) {
            if (! is_string($candidate) || ($property ? $candidate === $name : strcasecmp($candidate, $name) === 0)) { return false; }
        }
        $doc = $syntax->getDocComment()?->getText() ?? '';
        if ($property ? preg_match('/@property(?:-read|-write)?[^\r\n]*'.preg_quote($name, '/').'\b/', $doc) === 1
            : preg_match('/@method[^\r\n]*\b'.preg_quote($name, '/').'\s*\(/i', $doc) === 1) { return false; }
        foreach ($syntax->stmts as $member) {
            if (! $property && $member instanceof Node\Stmt\ClassMethod && strcasecmp($member->name->name, $name) === 0) { return false; }
            if ($property && $member instanceof Node\Stmt\Property) {
                foreach ($member->props as $field) { if ('$'.$field->name->name === $name) { return false; } }
            }
            if ($property && $member instanceof Node\Stmt\ClassMethod && strcasecmp($member->name->name, '__construct') === 0) {
                foreach ($member->params as $parameter) {
                    if ($parameter->flags !== 0 && $parameter->var instanceof Node\Expr\Variable && '$'.$parameter->var->name === $name) { return false; }
                }
            }
        }
        $sourceTraits = [];
        foreach ($syntax->stmts as $member) {
            if (! $member instanceof Node\Stmt\TraitUse) { continue; }
            if ($member->adaptations !== []) { return false; }
            foreach ($member->traits as $trait) { $sourceTraits[] = strtolower($trait->toString()); }
        }
        $closure = $this->mergedTraitClosure($context, $syntax, $metadata, []);
        $nativeTraits = array_values(array_unique(array_map('strtolower', $metadata->usedTraits))); sort($sourceTraits); sort($nativeTraits);
        if ($closure === null || $closure !== $nativeTraits || count($sourceTraits) > 32 || count($visited) > 32) { return false; }
        foreach ($sourceTraits as $trait) {
            if (in_array($trait, $visited, true)) { return false; }
            $traitMetadata = $context->codebase->getClassLike($trait);
            $file = $traitMetadata === null ? null : $this->source($traitMetadata->location->file);
            $nodes = $file === null ? [] : (new NodeFinder)->find($file['nodes'], static fn (Node $node): bool => $node instanceof Node\Stmt\Trait_
                && strcasecmp($node->namespacedName?->toString() ?? '', $trait) === 0);
            if ($traitMetadata?->kind !== ClassLikeKind::Trait || $traitMetadata->hasIncompleteHierarchy() || count($nodes) !== 1
                || ! $this->located($traitMetadata->location, $nodes[0], $file) || ! $this->located($traitMetadata->nameLocation, $nodes[0]->name, $file)
                || ! $this->noOwnMember($context, $nodes[0], $traitMetadata, $name, $property, [...$visited, $trait])) { return false; }
        }
        return true;
    }

    private function generic(IssueFilterContext $context, ?Type $type, string $name, string $owner): bool
    {
        if ($type === null || count($type->atomicTypes) !== 1) { return false; }
        $atom = $type->atomicTypes[0];
        if (! $atom instanceof GenericParameterType || $atom->name !== $name || ($atom->intersections ?? []) !== []
            || $atom->definingEntity->kind !== GenericParentKind::ClassLike || strcasecmp($atom->definingEntity->name, $owner) !== 0
            || $atom->definingEntity->member !== null || $type->flags->byReference || $type->flags->possiblyUndefined) { return false; }
        $templates = array_values(array_filter($context->codebase->getClass($owner)?->templates ?? [], static fn ($template): bool => $template->name === $name));
        if (count($templates) !== 1 || ! $context->types->equals($atom->constraint, $templates[0]->constraint)) { return false; }
        if ($name === 'TKey') {
            $constraint = $atom->constraint->atomicTypes[0] ?? null;
            return count($atom->constraint->atomicTypes) === 1 && $constraint instanceof ScalarType && $constraint->kind === ScalarTypeKind::ArrayKey;
        }
        return true;
    }
    private function templates(IssueFilterContext $context, object $metadata, Node\Stmt\Class_ $syntax): bool
    {
        $doc = $syntax->getDocComment()?->getText();
        if ($doc === null || count($metadata->templates) !== 2
            || preg_match_all('/@template(?:-(covariant|contravariant))?\s+(TKey|TValue)(?:\s+(?:of|as)\s+([^\s*]+))?/', $doc, $matches, PREG_SET_ORDER) !== 2) { return false; }
        foreach ($matches as $index => $match) {
            $template = $metadata->templates[$index];
            $expected = $match[2] === 'TKey' ? Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)) : Type::mixed();
            $variance = match ($match[1]) { 'covariant' => Variance::Covariant, 'contravariant' => Variance::Contravariant, default => Variance::Invariant };
            if ($template->name !== $match[2] || $template->definingEntity->kind !== GenericParentKind::ClassLike
                || strcasecmp($template->definingEntity->name, $this->support) !== 0 || $template->definingEntity->member !== null
                || $template->default !== null || $template->variance !== $variance || ! $context->types->equals($template->constraint, $expected)
                || ($match[2] === 'TKey' ? ($match[3] ?? '') !== 'array-key' : isset($match[3]) && $match[3] !== '')) { return false; }
        }
        return true;
    }
    private function visibleStorageEffects(IssueFilterContext $context, Node\Stmt\ClassLike $syntax, array $nativeTraits, array $visited): bool
    {
        $finder = new NodeFinder;
        if ($finder->findFirst($syntax->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Expr\PropertyFetch && ! $node->name instanceof Node\Identifier
            || $node instanceof Node\Stmt\ClassMethod && $node->byRef
            || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\Expr\Eval_
            || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Expr\Variable && ! is_string($node->name)) !== null) { return false; }
        foreach($finder->findInstanceOf($syntax->stmts,Node\Param::class) as $parameter) {
            if(!$parameter->byRef) { continue; }$sourceBorrow=ScopedAccumulatorBorrow::sourceWitness($syntax,$parameter);
            $nativeBorrow=$sourceBorrow===null?null:ScopedAccumulatorBorrow::nativeWitness($context,$sourceBorrow,$this->path(...));
            if($nativeBorrow===null) { return false; }$this->stages['scopedLocalBorrow'][]=$nativeBorrow;
        }
        foreach ($finder->findInstanceOf($syntax->stmts, Node\Expr\Assign::class) as $assign) {
            if (! $this->items($assign->var)) { continue; }
            if ($assign->expr instanceof Node\Expr\New_ || $assign->expr instanceof Node\Scalar
                || $assign->expr instanceof Node\Expr\ConstFetch || $assign->expr instanceof Node\Expr\Cast\Object_) { return false; }
            if ($assign->expr instanceof Node\Expr\Variable) {
                $methods = $finder->find($syntax->stmts, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                    && $node->getStartFilePos() <= $assign->getStartFilePos() && $node->getEndFilePos() >= $assign->getEndFilePos());
                $parameters = count($methods) === 1 ? array_values(array_filter($methods[0]->params,
                    static fn (Node\Param $param): bool => $param->var instanceof Node\Expr\Variable && $param->var->name === $assign->expr->name
                        && $param->type instanceof Node\Identifier && strtolower($param->type->name) === 'array')) : [];
                if (count($parameters) !== 1) { return false; }
                if ($finder->findFirst($methods[0]->stmts ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\Assign
                    && $node->var instanceof Node\Expr\Variable && $node->var->name === $assign->expr->name) !== null) { return false; }
            }
        }
        $sourceTraits = [];
        foreach ($syntax->stmts as $member) {
            if (! $member instanceof Node\Stmt\TraitUse) { continue; }
            if ($member->adaptations !== []) { return false; }
            foreach ($member->traits as $trait) { $sourceTraits[] = strtolower($trait->toString()); }
        }
        $name = $syntax->namespacedName?->toString(); $owner = $name === null ? null : $context->codebase->getClassLike($name);
        $closure = $owner === null ? null : $this->mergedTraitClosure($context, $syntax, $owner, []);
        $nativeTraits = array_values(array_unique(array_map('strtolower', $nativeTraits))); sort($nativeTraits); sort($sourceTraits);
        if ($closure === null || $nativeTraits !== $closure || count($sourceTraits) > 32) { return false; }
        foreach ($sourceTraits as $name) {
            if (in_array($name, $visited, true) || count($visited) > 32) { return false; }
            $metadata = $context->codebase->getClassLike($name);
            $file = $metadata === null ? null : $this->source($metadata->location->file);
            $traits = $file === null ? [] : $finder->find($file['nodes'], static fn (Node $node): bool => $node instanceof Node\Stmt\Trait_
                && strcasecmp($node->namespacedName?->toString() ?? '', $name) === 0);
            if ($metadata?->kind !== ClassLikeKind::Trait || count($traits) !== 1 || $metadata->hasIncompleteHierarchy()
                || ! $this->located($metadata->location, $traits[0], $file) || ! $this->located($metadata->nameLocation, $traits[0]->name, $file)
                || ! $this->visibleStorageEffects($context, $traits[0], $metadata->usedTraits, [...$visited, $name])) { return false; }
        }
        return true;
    }
    /** Current merged declaration closure only; every source-owned trait still receives the original storage-effect veto. */
    private function mergedTraitClosure(IssueFilterContext $context, Node\Stmt\ClassLike $syntax, object $metadata, array $visited): ?array
    {
        $name = strtolower($syntax->namespacedName?->toString() ?? '');
        if ($name === '' || in_array($name, $visited, true) || count($visited) >= 64 || $metadata->hasIncompleteHierarchy()
            || strtolower($metadata->name) !== $name || $metadata->flags->contains(MetadataFlags::BUILTIN)) { return null; }
        $file = $this->source($metadata->location->file);
        if ($file === null || ! $this->located($metadata->location, $syntax, $file) || ! $this->located($metadata->nameLocation, $syntax->name, $file)) { return null; }
        if ($syntax instanceof Node\Stmt\Class_) {
            if ($metadata->kind !== ClassLikeKind::Class_ || strcasecmp($syntax->extends?->toString() ?? '', $metadata->directParentClass ?? '') !== 0
                || $metadata->flags->contains(MetadataFlags::FINAL) !== $syntax->isFinal() || $metadata->flags->contains(MetadataFlags::ABSTRACT) !== $syntax->isAbstract()) { return null; }
        } elseif (! $syntax instanceof Node\Stmt\Trait_ || $metadata->kind !== ClassLikeKind::Trait || $metadata->directParentClass !== null) { return null; }
        $visited[] = $name; $merged = [];
        if ($syntax instanceof Node\Stmt\Class_ && $syntax->extends !== null) {
            $parent = $context->codebase->getClass($syntax->extends->toString());
            $parentFile = $parent === null ? null : $this->source($parent->location->file);
            $parentNode = $parentFile === null ? null : $this->classNode($parentFile['nodes'], $syntax->extends->toString());
            $inherited = $parentNode === null ? null : $this->mergedTraitClosure($context, $parentNode, $parent, $visited);
            if ($inherited === null) { return null; } $merged = $inherited;
        }
        foreach ($syntax->stmts as $member) {
            if (! $member instanceof Node\Stmt\TraitUse) { continue; }
            foreach ($member->traits as $trait) {
                $traitName = strtolower($trait->toString()); $traitMetadata = $context->codebase->getTrait($traitName);
                $traitFile = $traitMetadata === null ? null : $this->source($traitMetadata->location->file);
                $matches = $traitFile === null ? [] : (new NodeFinder)->find($traitFile['nodes'], static fn (Node $node): bool => $node instanceof Node\Stmt\Trait_
                    && strtolower($node->namespacedName?->toString() ?? '') === $traitName);
                $nested = count($matches) !== 1 ? null : $this->mergedTraitClosure($context, $matches[0], $traitMetadata, $visited);
                if ($nested === null) { return null; } $merged = [...$merged, $traitName, ...$nested];
            }
        }
        $merged = array_values(array_unique($merged)); sort($merged);
        $native = array_values(array_unique(array_map('strtolower', $metadata->usedTraits))); sort($native);
        return $merged === $native && count($merged) <= 64 ? $merged : null;
    }
    private function items(Node $node): bool
    {
        return $node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier && $node->name->name === 'items';
    }
    private function docType(?SourceLocation $location, \PhpParser\Comment\Doc $doc, array $file, string $expected): bool
    {
        return $location !== null && $location->file !== null && $this->path($location->file) === $file['path']
            && $location->span->start >= $doc->getStartFilePos() && $location->span->end <= $doc->getEndFilePos() + 1
            && preg_replace('/\s+/', '', substr($file['contents'], $location->span->start, $location->span->length())) === $expected;
    }
    private function source(?string $path): ?array
    {
        if ($path === null) { return null; }
        $path = $this->path($path); $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > 1024 * 1024) { return null; }
        try { $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes); }
        catch (\PhpParser\Error) { return null; }
        return ['path' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents), 'nodes' => $nodes];
    }
    private function classNode(array $nodes, string $name): ?Node\Stmt\Class_
    {
        $matches = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $node) {
            if ($node->namespacedName !== null && strcasecmp($node->namespacedName->toString(), $name) === 0) { $matches[] = $node; }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }
    private function located(?SourceLocation $location, Node $node, array $file): bool
    {
        return $location !== null && $location->file !== null && $this->path($location->file) === $file['path']
            && [$location->span->start, $location->span->end] === [$node->getStartFilePos(), $node->getEndFilePos() + 1]
            && $file['hash'] === hash_file('sha256', $file['path']);
    }
    public function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if (! str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) { $path = $this->root.'/'.$path; }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
