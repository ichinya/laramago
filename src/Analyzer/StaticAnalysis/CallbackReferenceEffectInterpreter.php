<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** A finite source interpreter for one fresh lexical boolean and its direct callback capability. */
final class CallbackReferenceEffectInterpreter
{
    private array $events = [];
    private int $steps = 0;
    private int $allocations = 0;
    private string $storage;

    public function __construct(
        private readonly DirectCallbackReferenceEffects $sources,
        private readonly Codebase $codebase,
        private readonly TypeComparator $types,
        private readonly array $proof,
    ) {
        $this->storage = 'tracked:'.$proof['initialization']->getStartFilePos();
    }

    /** @return list<bool> */
    public function values(): array
    {
        $state = ['vars' => [], 'heap' => [], 'objects' => [], 'flow' => 'normal', 'value' => null, 'owner' => $this->proof['owner'],
            'frame' => 'caller', 'depth' => 0, 'active' => false, 'invoked' => false, 'referenceInvocations' => 0];
        foreach ($this->proof['scope']->params as $parameter) {
            if ($parameter->byRef || ! is_string($parameter->var->name)) { $this->defer(); }
            $state['vars'][$parameter->var->name] = $this->unknown($parameter->type);
        }
        $state['vars']['this'] = ['kind' => 'object', 'class' => $this->proof['owner']];
        $states = [$state];
        foreach ($this->proof['scope']->params as $parameter) {
            if (! $parameter->type instanceof Node\Identifier || strtolower($parameter->type->name) !== 'bool') { continue; }
            $next = [];
            foreach ($states as $branch) {
                foreach ([false, true] as $value) { $copy = $branch; $copy['vars'][$parameter->var->name] = $value; $next[] = $copy; }
            }
            $states = $this->bounded($next);
        }
        $states = $this->statements($this->proof['scope']->stmts ?? [], $states);
        $values = [];
        foreach ($states as $state) {
            if ($state['flow'] !== 'target' || ! $state['active'] || ! $state['invoked']) { continue; }
            $value = $state['heap'][$this->storage] ?? null;
            if (! is_bool($value)) { $this->defer(); }
            $values[$value ? 1 : 0] = $value;
        }
        return array_values($values);
    }

    private function statements(array $nodes, array $states): array
    {
        foreach ($nodes as $node) {
            $next = [];
            foreach ($states as $state) {
                if ($state['flow'] !== 'normal') { $next[] = $state; continue; }
                foreach ($this->statement($node, $state) as $result) { $next[] = $result; }
            }
            $states = $this->bounded($next);
        }
        return $states;
    }

    private function statement(Node $node, array $state): array
    {
        if (++$this->steps > 20_000) { $this->defer(); }
        if ($node instanceof Node\Stmt\Nop) { return [$state]; }
        if ($node instanceof Node\Stmt\Expression) {
            if ($node->expr === $this->proof['assertion'] && $state['frame'] === 'caller') { $state['flow'] = 'target'; return [$state]; }
            return array_map(static fn (array $result): array => $result['state'], $this->evaluate($node->expr, $state));
        }
        if ($node instanceof Node\Stmt\Return_) {
            $results = $node->expr === null ? [['state' => $state, 'value' => null]] : $this->evaluate($node->expr, $state);
            foreach ($results as &$result) {
                if ($this->capability($result['value'])) { $this->defer(); }
                if ($result['state']['flow'] === 'normal') {
                    $result['state']['flow'] = 'return'; $result['state']['value'] = $result['value']; $result['state']['returnExpression'] = $node->expr !== null;
                }
            }
            return array_column($results, 'state');
        }
        if ($node instanceof Node\Stmt\If_) {
            $result = [];
            foreach ($this->condition($node->cond, $state) as [$branch, $truth]) {
                if ($truth) { $result = [...$result, ...$this->statements($node->stmts, [$branch])]; continue; }
                $else = $node->else?->stmts ?? [];
                if ($node->elseifs !== []) {
                    $nested = $node->else;
                    foreach (array_reverse($node->elseifs) as $elseif) { $nested = new Node\Stmt\Else_([new Node\Stmt\If_($elseif->cond, ['stmts' => $elseif->stmts, 'else' => $nested])]); }
                    $else = $nested?->stmts ?? [];
                }
                $result = [...$result, ...$this->statements($else, [$branch])];
            }
            return $this->bounded($result);
        }
        if ($node instanceof Node\Stmt\TryCatch) {
            $result = [];
            foreach ($this->statements($node->stmts, [$state]) as $exit) {
                if ($exit['flow'] === 'throw') {
                    foreach ($node->catches as $catch) {
                        if (! $this->caught($exit['value'], $catch->types)) { continue; }
                        $exit['flow'] = 'normal';
                        if ($catch->var !== null) { $exit['vars'][$catch->var->name] = ['kind' => 'object', 'class' => $exit['value']]; }
                        foreach ($this->statements($catch->stmts, [$exit]) as $caught) { $result[] = $caught; }
                        continue 2;
                    }
                }
                $result[] = $exit;
            }
            if ($node->finally === null) { return $this->bounded($result); }
            $final = [];
            foreach ($result as $exit) {
                // A target inside this try is observed before its enclosing finally executes.
                if ($exit['flow'] === 'target') { $final[] = $exit; continue; }
                $prior = [$exit['flow'], $exit['value']];
                $exit['flow'] = 'normal';
                foreach ($this->statements($node->finally->stmts, [$exit]) as $after) {
                    if ($after['flow'] === 'normal') { [$after['flow'], $after['value']] = $prior; }
                    $final[] = $after;
                }
            }
            return $this->bounded($final);
        }
        if ($node instanceof Node\Stmt\Foreach_) {
            if ($node->byRef || ! $node->valueVar instanceof Node\Expr\Variable || ! is_string($node->valueVar->name)
                || $node->keyVar !== null && (! $node->keyVar instanceof Node\Expr\Variable || ! is_string($node->keyVar->name))) { $this->defer(); }
            $result = [];
            foreach ($this->evaluate($node->expr, $state) as $input) {
                $array = $input['value'];
                if (! is_array($array) || ($array['kind'] ?? '') !== 'array' || ! isset($array['items']) || count($array['items']) > 32) { $this->defer(); }
                $states = [$input['state']];
                foreach ($array['items'] as $key => $item) {
                    foreach ($states as &$branch) {
                        if ($branch['flow'] !== 'normal') { continue; }
                        $branch['vars'][$node->valueVar->name] = $item;
                        if ($node->keyVar !== null) { $branch['vars'][$node->keyVar->name] = $key; }
                    }
                    unset($branch);
                    $states = $this->statements($node->stmts, $states);
                }
                $result = [...$result, ...$states];
            }
            return $this->bounded($result);
        }
        if ($node instanceof Node\Stmt\Throw_) {
            return array_column($this->throwExpression($node->expr, $state), 'state');
        }
        $this->defer();
    }

    /** @return list<array{state:array,value:mixed}> */
    private function evaluate(Node\Expr $node, array $state): array
    {
        if (++$this->steps > 20_000) { $this->defer(); }
        if ($state['flow'] !== 'normal') { return [['state' => $state, 'value' => null]]; }
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) { return [['state' => $state, 'value' => $node->value]]; }
        if ($node instanceof Node\Expr\ConstFetch) {
            $value = PhpSource::value($node);
            if ($value === UnknownValue::Value) { $this->defer(); }
            return [['state' => $state, 'value' => $value]];
        }
        if ($node instanceof Node\Expr\Variable) {
            if (! is_string($node->name)) { $this->defer(); }
            return [['state' => $state, 'value' => $this->read($state, $node->name)]];
        }
        if ($node instanceof Node\Expr\Assign) {
            $result = [];
            foreach ($this->evaluate($node->expr, $state) as $input) {
                $branch = $input['state'];
                if ($branch['flow'] === 'normal') {
                    if ($node === $this->proof['initialization'] && $branch['frame'] === 'caller') {
                        if ($branch['active'] || ! is_bool($input['value'])) { $this->defer(); }
                        $branch['active'] = true;
                        $branch['vars'][$this->proof['local']] = ['kind' => 'ref', 'id' => $this->storage];
                        $branch['heap'][$this->storage] = $input['value'];
                    } else { $this->write($branch, $node->var, $input['value']); }
                }
                $result[] = ['state' => $branch, 'value' => $input['value']];
            }
            return $result;
        }
        if ($node instanceof Node\Expr\Array_) {
            $states = [['state' => $state, 'value' => ['kind' => 'array', 'items' => []]]];
            foreach ($node->items as $item) {
                if ($item === null || $item->byRef || $item->unpack) { $this->defer(); }
                $next = [];
                foreach ($states as $prior) {
                    if ($item->key === null) {
                        $items = $prior['value']['items'];
                        if (array_key_exists(PHP_INT_MAX, $items)) { $this->defer(); }
                        $items[] = null;
                        $keys = [['state' => $prior['state'], 'value' => array_key_last($items)]];
                    } else { $keys = $this->evaluate($item->key, $prior['state']); }
                    foreach ($keys as $key) {
                        if (! is_int($key['value']) && ! is_string($key['value'])) { $this->defer(); }
                        foreach ($this->evaluate($item->value, $key['state']) as $value) {
                            if ($this->capability($value['value'])) { $this->defer(); }
                            $array = $prior['value'];
                            $array['items'][$key['value']] = $value['value'];
                            $next[] = ['state' => $value['state'], 'value' => $array];
                        }
                    }
                }
                $states = $next;
            }
            return $states;
        }
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            if (! DirectCallbackReferenceEffects::closedScope($node)) { $this->defer(); }
            $captures = [];
            $tracked = false;
            $uses = $node instanceof Node\Expr\Closure ? $node->uses : [];
            foreach ($uses as $use) {
                if (! is_string($use->var->name)) { $this->defer(); }
                $value = $this->read($state, $use->var->name);
                if ($use->byRef) {
                    $this->reference($state, $use->var->name);
                    $captures[$use->var->name] = $state['vars'][$use->var->name];
                    $tracked = $tracked || $captures[$use->var->name]['id'] === $this->storage;
                    if ($captures[$use->var->name]['id'] !== $this->storage && $state['active'] && (! is_array($value) || ($value['kind'] ?? '') !== 'array')) { $this->defer(); }
                } else {
                    if ($this->capability($value)) { $this->defer(); }
                    $captures[$use->var->name] = $value;
                }
            }
            if ($node instanceof Node\Expr\ArrowFunction) {
                foreach ((new NodeFinder)->findInstanceOf([$node->expr], Node\Expr\Variable::class) as $variable) {
                    if (! is_string($variable->name)) { $this->defer(); }
                    if (isset($state['vars'][$variable->name])) { $captures[$variable->name] = $this->read($state, $variable->name); }
                }
            }
            if (! $node->static && isset($state['vars']['this'])) { $captures['this'] = $state['vars']['this']; }
            return [['state' => $state, 'value' => ['kind' => 'callback', 'node' => $node, 'captures' => $captures,
                'tracked' => $tracked, 'owner' => $state['owner'], 'frame' => $state['frame'].':closure:'.$node->getStartFilePos()]]];
        }
        if ($node instanceof Node\Expr\BooleanNot || $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            return array_map(static fn (array $branch): array => ['state' => $branch[0], 'value' => $branch[1]], $this->condition($node, $state));
        }
        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            $result = [];
            foreach ($this->evaluate($node->left, $state) as $left) {
                if ($left['value'] === null) { $result = [...$result, ...$this->evaluate($node->right, $left['state'])]; }
                elseif (is_array($left['value']) && ($left['value']['kind'] ?? '') === 'unknown') { $this->defer(); }
                else { $result[] = $left; }
            }
            return $result;
        }
        if ($node instanceof Node\Expr\BinaryOp\Identical || $node instanceof Node\Expr\BinaryOp\NotIdentical || $node instanceof Node\Expr\BinaryOp\Concat) {
            $result = [];
            foreach ($this->evaluate($node->left, $state) as $left) {
                foreach ($this->evaluate($node->right, $left['state']) as $right) {
                    if ($node instanceof Node\Expr\BinaryOp\Concat) {
                        $leftString = $this->stringValue($left['value']); $rightString = $this->stringValue($right['value']);
                        $value = is_string($leftString) && is_string($rightString) ? $leftString.$rightString : ['kind' => 'unknown', 'type' => 'string'];
                        $result[] = ['state' => $right['state'], 'value' => $value];
                        continue;
                    }
                    if (is_array($left['value']) && ($left['value']['kind'] ?? '') === 'unknown' || is_array($right['value']) && ($right['value']['kind'] ?? '') === 'unknown') { $this->defer(); }
                    if (! $this->comparable($left['value']) || ! $this->comparable($right['value'])) { $this->defer(); }
                    $same = $left['value'] === $right['value'];
                    $result[] = ['state' => $right['state'], 'value' => $node instanceof Node\Expr\BinaryOp\Identical ? $same : ! $same];
                }
            }
            return $result;
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            $states = [['state' => $state, 'value' => '']];
            foreach ($node->parts as $part) {
                $next = [];
                foreach ($states as $prior) {
                    $values = $part instanceof Node\InterpolatedStringPart ? [['state' => $prior['state'], 'value' => $part->value]] : $this->evaluate($part, $prior['state']);
                    foreach ($values as $value) {
                        $prefix = $this->stringValue($prior['value']); $partString = $this->stringValue($value['value']);
                        $next[] = ['state' => $value['state'], 'value' => is_string($prefix) && is_string($partString)
                            ? $prefix.$partString : ['kind' => 'unknown', 'type' => 'string']];
                    }
                }
                $states = $next;
            }
            return $states;
        }
        if ($node instanceof Node\Expr\Throw_) { return $this->throwExpression($node->expr, $state); }
        if ($node instanceof Node\Expr\Exit_) { $state['flow'] = 'exit'; return [['state' => $state, 'value' => null]]; }
        if ($node instanceof Node\Expr\New_) {
            if (! $node->class instanceof Node\Name) { $this->defer(); }
            $name = $node->class->toString();
            if (strcasecmp($name, 'ReflectionProperty') === 0) { return $this->reflect($node, $state); }
            $construction = $this->sources->construction($this->codebase, $this->types, $name);
            if ($construction === null) { $this->defer(); }
            $result = [];
            foreach ($this->arguments($node->args, $state) as $arguments) {
                if ($arguments['state']['flow'] !== 'normal') { $result[] = ['state' => $arguments['state'], 'value' => null]; continue; }
                $result = [...$result, ...$this->construct($name, $construction, $arguments, $node)];
            }
            return $result;
        }
        if ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) {
            if (! $node->name instanceof Node\Identifier) { $this->defer(); }
            $result = [];
            foreach ($this->evaluate($node->var, $state) as $object) {
                if ($object['state']['flow'] !== 'normal') { $result[] = ['state' => $object['state'], 'value' => null]; continue; }
                if (! is_array($object['value']) || ($object['value']['kind'] ?? '') !== 'object') { $this->defer(); }
                $property = $this->codebase->getDeclaringProperty($object['value']['class'], '$'.$node->name->name);
                if ($property === null || $property->type === null || $property->flags->contains(MetadataFlags::STATIC) || $property->hooks !== []
                    || ! $this->fieldAccessible($property->readVisibility, $object['value']['class'], $object['state']['owner'])) { $this->defer(); }
                $stored = $object['state']['objects'][$object['value']['id'] ?? ''] ?? null;
                if ($stored === null || ! isset($stored['plan']['fields'][$node->name->name])
                    || ! array_key_exists($node->name->name, $stored['fields'])) { $this->defer(); }
                $value = $stored['fields'][$node->name->name];
                $actual = $this->valueType($value);
                if ($actual === null || ! $this->types->isContainedBy($actual, $property->type->type)) { $this->defer(); }
                $result[] = ['state' => $object['state'], 'value' => $value];
            }
            return $result;
        }
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            $result = [];
            foreach ($this->evaluate($node->var, $state) as $array) {
                if (! is_array($array['value']) || ($array['value']['kind'] ?? '') !== 'array' || $node->dim === null) { $this->defer(); }
                foreach ($this->evaluate($node->dim, $array['state']) as $key) {
                    if (! is_int($key['value']) && ! is_string($key['value'])) { $this->defer(); }
                    $result[] = ['state' => $key['state'], 'value' => $array['value']['items'][$key['value']] ?? null];
                }
            }
            return $result;
        }
        if ($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) { return $this->call($node, $state); }
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && strtolower($node->name->name) === 'class') {
            return [['state' => $state, 'value' => $node->class->toString()]];
        }
        if ($node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostDec || $node instanceof Node\Expr\PreDec) {
            if (! $node->var instanceof Node\Expr\Variable) { $this->defer(); }
            $value = $this->read($state, $node->var->name);
            if (! is_int($value)) { $this->defer(); }
            $new = $value + ($node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreInc ? 1 : -1);
            $this->write($state, $node->var, $new);
            return [['state' => $state, 'value' => $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec ? $value : $new]];
        }
        $this->defer();
    }

    /** Admit only a native, literal physical-field selector before the fresh boolean origin. */
    private function reflect(Node\Expr\New_ $node, array $state): array
    {
        if ($state['active'] || count($node->args) !== 2) { $this->defer(); }
        $bound = [];
        foreach ($node->args as $position => $argument) {
            if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack) { $this->defer(); }
            $name = $argument->name?->name ?? ($position === 0 ? 'class' : 'property');
            if (! in_array($name, ['class', 'property'], true) || isset($bound[$name])) { $this->defer(); }
            $bound[$name] = $argument->value;
        }
        $class = $bound['class'] ?? null; $property = $bound['property'] ?? null;
        if (! $class instanceof Node\Expr\ClassConstFetch || ! $class->class instanceof Node\Name || ! $class->name instanceof Node\Identifier
            || strcasecmp($class->name->name, 'class') !== 0 || ! $property instanceof Node\Scalar\String_) { $this->defer(); }
        $plan = $this->sources->reflection($this->codebase, $this->types, $class->class->toString(), $property->value);
        if ($plan === null) { $this->defer(); }
        $result = [];
        foreach ($this->arguments($node->args, $state) as $arguments) {
            if ($arguments['state']['flow'] !== 'normal') { $result[] = ['state' => $arguments['state'], 'value' => null]; continue; }
            $this->noEscape($arguments['args']);
            if (++$this->allocations > 64 || ! NativeCallbackReflection::current($plan)) { $this->defer(); }
            $result[] = ['state' => $arguments['state'], 'value' => ['kind' => 'reflection', 'plan' => $plan, 'id' => 'allocation:'.$this->allocations]];
        }
        return $result;
    }

    private function reflectionSet(array $object, array $arguments): array
    {
        $state = $arguments['state']; $plan = $object['plan'];
        if ($state['flow'] !== 'normal') { return [['state' => $state, 'value' => null]]; }
        if ($state['active'] || ! NativeCallbackReflection::current($plan)) { $this->defer(); }
        $this->noEscape($arguments['args']);
        $this->ordinaryArguments($plan['setValue'], $arguments['args']);
        $bound = [];
        foreach ($arguments['args'] as $position => $argument) {
            $name = $argument['name'] ?? ($position === 0 ? 'objectOrValue' : 'value');
            if (! in_array($name, ['objectOrValue', 'value'], true) || array_key_exists($name, $bound)) { $this->defer(); }
            $bound[$name] = $argument['value'];
        }
        if (count($bound) !== 2 || ! is_array($bound['objectOrValue']) || ($bound['objectOrValue']['kind'] ?? '') !== 'object') { $this->defer(); }
        $receiver = $this->valueType($bound['objectOrValue']); $value = $this->valueType($bound['value']);
        if ($receiver === null || $value === null || ! $this->types->isContainedBy($receiver, $plan['receiverType'])
            || ! $this->types->isContainedBy($value, $plan['writeType']) || ! NativeCallbackReflection::current($plan)) { $this->defer(); }
        $id = $bound['objectOrValue']['id'] ?? null;
        if ($id === null || ! isset($state['objects'][$id])) { $this->defer(); }
        $field = $state['objects'][$id]['plan']['fields'][$plan['property']] ?? null;
        if ($field === null || $field['readonly'] || ! $this->types->isContainedBy($value, $field['type'])
            || ! $this->types->isContainedBy($value, $field['writeType'])) { $this->defer(); }
        $state['objects'][$id]['fields'][$plan['property']] = $bound['value'];
        return [['state' => $state, 'value' => null]];
    }

    /** Execute only the verified finite physical initialization descriptor. */
    private function construct(string $class, array $plan, array $arguments, Node $call): array
    {
        $this->noEscape($arguments['args']);
        $caller = $arguments['state'];
        $source = ($plan['kind'] ?? null) === 'source';
        if (++$this->allocations > 64) { $this->defer(); }
        $object = ['kind' => 'object', 'class' => $class, 'id' => 'allocation:'.$this->allocations];
        if (! $source) {
            $frame = $caller; $frame['vars'] = []; $frame['owner'] = $class;
            $this->bind($plan['parameters'], $arguments['args'], $frame);
            return [['state' => $caller, 'value' => $object]];
        }
        $frame = $this->frame($caller, $class, $class.'::__construct:'.$call->getStartFilePos());
        $frame['vars']['this'] = $object;
        $frame = $this->bind($plan['parameters'], $arguments['args'], $frame, $plan['parameterMetadata']);
        $frame['objects'][$object['id']] = ['plan' => $plan, 'fields' => []];
        $states = [$frame];
        foreach ($plan['fields'] as $name => $field) {
            if (! $field['hasDefault']) { continue; }
            $next = [];
            foreach ($states as $state) {
                foreach ($this->evaluate($field['default'], $state) as $value) {
                    if ($value['state']['flow'] === 'normal') { $this->initializeField($value['state'], $object, $name, $value['value']); }
                    $next[] = $value['state'];
                }
            }
            $states = $this->bounded($next);
        }
        foreach ($plan['promotions'] as $promotion) {
            foreach ($states as &$state) {
                if ($state['flow'] === 'normal') { $this->initializeField($state, $object, $promotion['field'], $this->read($state, $promotion['parameter'])); }
            }
            unset($state);
        }
        foreach ($plan['steps'] as $step) {
            $next = [];
            foreach ($states as $state) {
                if ($state['flow'] !== 'normal') { $next[] = $state; continue; }
                if ($step['kind'] === 'assign') {
                    foreach ($this->evaluate($step['value'], $state) as $value) {
                        if ($value['state']['flow'] === 'normal') { $this->initializeField($value['state'], $object, $step['field'], $value['value']); }
                        $next[] = $value['state'];
                    }
                } elseif ($step['kind'] === 'parent-exception') {
                    foreach ($this->arguments($step['call']->args, $state) as $values) {
                        if ($values['state']['flow'] === 'normal') {
                            $this->noEscape($values['args']);
                            $this->bind($step['construction']['parameters'], $values['args'], $values['state']);
                        }
                        $next[] = $values['state'];
                    }
                } else { $this->defer(); }
            }
            $states = $this->bounded($next);
        }
        $result = [];
        foreach ($states as $state) {
            foreach (['vars', 'owner', 'frame', 'depth'] as $key) { $state[$key] = $caller[$key]; }
            $result[] = ['state' => $state, 'value' => $state['flow'] === 'normal' ? $object : null];
        }
        return $result;
    }

    private function initializeField(array &$state, array $object, string $name, mixed $value): void
    {
        $stored = $state['objects'][$object['id']] ?? null;
        $field = $stored['plan']['fields'][$name] ?? null;
        $actual = $this->valueType($value);
        if ($field === null || $actual === null || $this->capability($value) || strcasecmp($state['owner'], $object['class']) !== 0
            || $field['readonly'] && array_key_exists($name, $stored['fields'])
            || ! $this->types->isContainedBy($actual, $field['type']) || ! $this->types->isContainedBy($actual, $field['writeType'])) { $this->defer(); }
        $state['objects'][$object['id']]['fields'][$name] = $value;
    }

    private function fieldAccessible(?\Mago\Sdk\Analyzer\Type\Visibility $visibility, string $declaring, string $owner): bool
    {
        return $visibility === \Mago\Sdk\Analyzer\Type\Visibility::Public || strcasecmp($declaring, $owner) === 0;
    }

    private function call(Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\StaticCall $call, array $state): array
    {
        if ($call->isFirstClassCallable()) {
            if (! $call instanceof Node\Expr\MethodCall || ! $call->name instanceof Node\Identifier) { $this->defer(); }
            $result = [];
            foreach ($this->evaluate($call->var, $state) as $object) {
                if ($object['state']['flow'] !== 'normal') { $result[] = ['state' => $object['state'], 'value' => null]; continue; }
                if (! is_array($object['value']) || ($object['value']['kind'] ?? '') !== 'object') { $this->defer(); }
                $class = $object['value']['class'];
                $method = $this->sources->method($this->codebase, $this->types, $class, $call->name->name);
                if ($method === null || $method['metadata']->static || ! $this->sources->finalClass($this->codebase, $class)
                    || ! $this->accessible($method['metadata'], $state['owner'])) { $this->defer(); }
                $result[] = ['state' => $object['state'], 'value' => ['kind' => 'method-callback', 'method' => $method,
                    'object' => $object['value'], 'tracked' => false]];
            }
            return $result;
        }
        if ($call instanceof Node\Expr\FuncCall && ! $call->name instanceof Node\Name) {
            $result = [];
            foreach ($this->evaluate($call->name, $state) as $callable) {
                foreach ($this->arguments($call->args, $callable['state']) as $arguments) {
                    $value = $callable['value'];
                    if (is_array($value) && ($value['kind'] ?? '') === 'callback') {
                        $result = [...$result, ...$this->invokeClosure($value, $arguments, $call)];
                    } elseif (is_array($value) && ($value['kind'] ?? '') === 'method-callback') {
                        $result = [...$result, ...$this->invokeMethod($value['method'], $arguments, $call, $value['object'])];
                    } elseif (is_array($value) && ($value['kind'] ?? '') === 'unknown' && $value['type'] === 'callable') {
                        $this->noEscape($arguments['args']);
                        $this->noRetainedEscape($arguments['args']);
                        if (! isset($value['returnType']) || $value['returnType'] === null || (string) $value['returnType'] === 'never') { $this->defer(); }
                        $result = [...$result, ...$this->typed($value['returnType'], $arguments['state'])];
                    } else { $this->defer(); }
                }
            }
            return $result;
        }
        if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && strcasecmp($call->class->toString(), 'Closure') === 0
            && $call->name instanceof Node\Identifier && strcasecmp($call->name->name, 'fromCallable') === 0) {
            if (! $this->sources->nativeClosure($this->codebase)) { $this->defer(); }
            $result = [];
            foreach ($this->arguments($call->args, $state) as $arguments) {
                if (count($arguments['args']) !== 1 || ! is_array($arguments['args'][0]['value'])
                    || ! in_array($arguments['args'][0]['name'], [null, 'callback'], true)
                    || ! in_array($arguments['args'][0]['value']['kind'] ?? '', ['callback', 'method-callback'], true)) { $this->defer(); }
                $result[] = ['state' => $arguments['state'], 'value' => $arguments['args'][0]['value']];
            }
            return $result;
        }
        $objects = [['state' => $state, 'value' => null]];
        if ($call instanceof Node\Expr\MethodCall) {
            if (! $call->name instanceof Node\Identifier) { $this->defer(); }
            $objects = $this->evaluate($call->var, $state);
        }
        $result = [];
        foreach ($objects as $object) {
            if (is_array($object['value']) && ($object['value']['kind'] ?? '') === 'reflection') {
                if (! $call instanceof Node\Expr\MethodCall || ! $call->name instanceof Node\Identifier || strcasecmp($call->name->name, 'setValue') !== 0) { $this->defer(); }
                foreach ($this->arguments($call->args, $object['state']) as $arguments) {
                    $result = [...$result, ...$this->reflectionSet($object['value'], $arguments)];
                }
                continue;
            }
            if ($object['state']['flow'] !== 'normal') { $result[] = ['state' => $object['state'], 'value' => null]; continue; }
            $class = $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name ? $call->class->toString()
                : (is_array($object['value']) && ($object['value']['kind'] ?? '') === 'object' ? $object['value']['class'] : null);
            $name = $call instanceof Node\Expr\FuncCall ? $call->name->toString() : ($call->name instanceof Node\Identifier ? $call->name->name : null);
            if ($name === null) { $this->defer(); }
            $function = null;
            if ($call instanceof Node\Expr\FuncCall) {
                $namespaced = $call->name->getAttribute('namespacedName');
                if ($namespaced instanceof Node\Name) {
                    $function = $this->codebase->getFunction($namespaced->toString());
                    if ($function !== null) { $name = $namespaced->toString(); }
                }
                $function ??= $this->codebase->getFunction($name);
                if ($function === null || $function->identifier->class !== null || strcasecmp($function->identifier->name, $name) !== 0
                    || $function->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_
                    || $function->kind !== \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Function_) { $this->defer(); }
            }
            if (in_array(strtolower($class ?? ''), ['self', 'static'], true)) { $class = $state['owner']; }
            foreach ($this->arguments($call->args, $object['state']) as $arguments) {
                if ($arguments['state']['flow'] !== 'normal') { $result[] = ['state' => $arguments['state'], 'value' => null]; continue; }
                $hasCapability = (bool) array_filter($arguments['args'], fn (array $argument): bool => $this->capability($argument['value']));
                $hasRetainedArguments = $this->retainedArguments($arguments['args']);
                $hasRetainedReceiver = $this->retainsState($object['value']);
                if ($class !== null) {
                    $method = $this->sources->method($this->codebase, $this->types, $class, $name);
                    $thisCall = $call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\Variable && $call->var->name === 'this';
                    if ($method !== null && ($hasCapability || $hasRetainedArguments || $hasRetainedReceiver || $thisCall && $state['frame'] !== 'caller')) {
                        if (! $this->sources->finalClass($this->codebase, $class)
                            || $thisCall && ! $hasRetainedReceiver && ! $method['node']->isPrivate() || $method['metadata']->static !== ($call instanceof Node\Expr\StaticCall)
                            || ! $this->accessible($method['metadata'], $state['owner'])) { $this->defer(); }
                        $result = [...$result, ...$this->invokeMethod($method, $arguments, $call, $object['value'])];
                        continue;
                    }
                }
                if ($hasCapability) { $this->defer(); }
                $this->noEscape($arguments['args']);
                $this->noRetainedEscape($arguments['args'], $object['value']);
                if ($class !== null && strcasecmp($class, 'Testo\\Assert') === 0) {
                    if (! in_array(strtolower($name), ['true', 'false'], true) || count($arguments['args']) !== 1
                        || ! $this->sources->assertion($this->codebase, strtolower($name), $this->types)) { $this->defer(); }
                    $value = $arguments['args'][0]['value'];
                    if (! is_bool($value) && (! is_array($value) || ($value['kind'] ?? '') !== 'unknown' || $value['type'] !== 'bool')) { continue; }
                    foreach ($this->booleans($value, $arguments['state']) as [$branch, $truth]) {
                        if ($truth === (strtolower($name) === 'true')) { $result[] = ['state' => $branch, 'value' => null]; }
                    }
                    continue;
                }
                $metadata = $class !== null ? $this->codebase->getMethod($class, $name) : $function;
                if ($metadata === null || $metadata->returnType === null || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
                    || $metadata->globalsAccessed !== [] || ! $this->accessible($metadata, $state['owner'])) { $this->defer(); }
                foreach ($metadata->parameters as $parameter) {
                    if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)) { $this->defer(); }
                }
                $this->ordinaryArguments($metadata, $arguments['args']);
                if ((string) $metadata->returnType->type === 'never') {
                    $branch = $arguments['state']; $branch['flow'] = 'exit'; $result[] = ['state' => $branch, 'value' => null]; continue;
                }
                $result = [...$result, ...$this->typed($metadata->returnType->type, $arguments['state'])];
            }
        }
        return $result;
    }

    private function invokeClosure(array $closure, array $arguments, Node $call): array
    {
        $state = $arguments['state'];
        $this->event($state['frame'].':'.$call->getStartFilePos());
        if ($closure['tracked']) {
            if (++$state['referenceInvocations'] > 32) { $this->defer(); }
            $state['invoked'] = true;
        }
        $frame = $this->frame($state, $closure['owner'], $closure['frame']);
        $frame['vars'] = $closure['captures'];
        $frame = $this->bind($closure['node']->params, $arguments['args'], $frame);
        $body = $closure['node'] instanceof Node\Expr\Closure ? $closure['node']->stmts : [new Node\Stmt\Return_($closure['node']->expr)];
        return $this->restore($this->statements($body, [$frame]), $state, $closure['node']->returnType);
    }

    private function invokeMethod(array $method, array $arguments, Node $call, mixed $object): array
    {
        $state = $arguments['state'];
        $this->event($state['frame'].':'.$call->getStartFilePos());
        $frame = $this->frame($state, $method['owner'], $method['owner'].'::'.$method['node']->name->name);
        if (! $method['node']->isStatic()) { $frame['vars']['this'] = $object; }
        $frame = $this->bind($method['node']->params, $arguments['args'], $frame, $method['metadata']->parameters);
        return $this->restore($this->statements($method['node']->stmts, [$frame]), $state, $method['node']->returnType, $method['metadata']->returnType?->type);
    }

    private function bind(array $parameters, array $arguments, array $state, ?array $metadata = null): array
    {
        $used = [];
        foreach ($parameters as $position => $parameter) {
            $found = null;
            foreach ($arguments as $index => $argument) {
                if ($argument['name'] === $parameter->var->name || $argument['name'] === null && $index === $position) {
                    if ($found !== null || isset($used[$index])) { $this->defer(); }
                    $found = $argument; $used[$index] = true;
                }
            }
            if ($found === null) {
                if ($parameter->default === null) { $this->defer(); }
                $values = $this->evaluate($parameter->default, $state);
                if (count($values) !== 1 || $values[0]['state']['flow'] !== 'normal') { $this->defer(); }
                $found = ['value' => $values[0]['value'], 'ref' => null];
            }
            if ($parameter->variadic || ! is_string($parameter->var->name)) { $this->defer(); }
            if (! $this->accepts($parameter->type, $found['value'], $state['owner'])) { $this->defer(); }
            if ($metadata !== null && $metadata[$position]->type !== null) {
                $actual = $this->valueType($found['value']);
                if ($actual === null || ! $this->types->isContainedBy($actual, $metadata[$position]->type->type)) { $this->defer(); }
            }
            if ($parameter->byRef) {
                if (($found['ref'] ?? null) === null || $found['ref']['id'] === $this->storage
                    || ! is_array($found['value']) || ($found['value']['kind'] ?? '') !== 'array' || $this->capability($found['value'])) { $this->defer(); }
                $state['vars'][$parameter->var->name] = $found['ref'];
            } else { $state['vars'][$parameter->var->name] = $found['value']; }
        }
        if (count($used) !== count($arguments)) { $this->defer(); }
        return $state;
    }

    /** A rejected argument may throw before the callback body and cannot witness a reference write. */
    private function accepts(?Node $syntax, mixed $value, string $owner): bool
    {
        if ($syntax === null) { return true; }
        if ($syntax instanceof Node\NullableType) { return $value === null || $this->accepts($syntax->type, $value, $owner); }
        if ($syntax instanceof Node\UnionType) {
            foreach ($syntax->types as $part) { if ($this->accepts($part, $value, $owner)) { return true; } }
            return false;
        }
        if ($syntax instanceof Node\Name) {
            $name = $syntax->toString();
            if (strcasecmp($name, 'Closure') === 0) { return is_array($value) && in_array($value['kind'] ?? '', ['callback', 'method-callback'], true); }
            if (in_array(strtolower($name), ['self', 'static'], true)) { $name = $owner; }
            return is_array($value) && ($value['kind'] ?? '') === 'object'
                && $this->types->isContainedBy(Type::namedObject($value['class']), Type::namedObject($name));
        }
        if (! $syntax instanceof Node\Identifier) { return false; }
        $name = strtolower($syntax->name);
        if ($name === 'mixed') { return true; }
        if ($name === 'array') { return is_array($value) && ($value['kind'] ?? '') === 'array'; }
        if ($name === 'callable') { return is_array($value) && (in_array($value['kind'] ?? '', ['callback', 'method-callback'], true) || ($value['kind'] ?? '') === 'unknown' && ($value['type'] ?? '') === 'callable'); }
        if ($name === 'object') { return is_array($value) && in_array($value['kind'] ?? '', ['object', 'callback', 'method-callback'], true); }
        $expected = match ($name) { 'string' => Type::string(), 'int' => Type::int(), 'float' => Type::float(), 'bool' => Type::bool(),
            'true' => Type::true(), 'false' => Type::false(), 'null' => Type::null(), default => null };
        if ($expected === null) { return false; }
        $actual = match (true) { is_bool($value) => $value ? Type::true() : Type::false(), is_int($value) => Type::literalInt($value),
            is_string($value) => Type::literalString($value), is_float($value) => Type::float(), $value === null => Type::null(), default => null };
        if ($actual === null && is_array($value) && ($value['kind'] ?? '') === 'unknown') {
            $actual = $this->primitiveType($value['nativeType'] ?? null) ? $value['nativeType']
                : match ($value['type'] ?? '') { 'string' => Type::string(), 'int' => Type::int(), 'float' => Type::float(), 'bool' => Type::bool(), default => null };
        }
        return $actual !== null && $this->types->isContainedBy($actual, $expected);
    }

    private function frame(array $state, string $owner, string $name): array
    {
        if ($state['depth'] >= 8) { $this->defer(); }
        $state['vars'] = []; $state['owner'] = $owner; $state['frame'] = $name; ++$state['depth'];
        return $state;
    }

    private function restore(array $states, array $caller, ?Node $syntaxReturn = null, ?Type $effectiveReturn = null): array
    {
        $result = [];
        foreach ($states as $state) {
            $value = $state['flow'] === 'return' ? $state['value'] : null;
            if ($state['flow'] === 'normal' || $state['flow'] === 'return') {
                $void = $syntaxReturn instanceof Node\Identifier && strtolower($syntaxReturn->name) === 'void';
                if ($void && ($state['flow'] === 'return' && ($state['returnExpression'] ?? false) || $value !== null)) { $this->defer(); }
                if (! $void && ! $this->accepts($syntaxReturn, $value, $state['owner'])) { $this->defer(); }
                if ($effectiveReturn !== null && (string) $effectiveReturn !== 'void') {
                    $actual = $this->valueType($value);
                    if ($actual === null || ! $this->types->isContainedBy($actual, $effectiveReturn)) { $this->defer(); }
                }
            }
            if ($state['flow'] === 'return') { $state['flow'] = 'normal'; }
            unset($state['returnExpression']);
            foreach (['vars', 'owner', 'frame', 'depth'] as $key) { $state[$key] = $caller[$key]; }
            $result[] = ['state' => $state, 'value' => $value];
        }
        return $result;
    }

    private function arguments(array $arguments, array $state): array
    {
        $states = [['state' => $state, 'args' => []]];
        $names = []; $named = false;
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack) { $this->defer(); }
            $name = $argument->name?->name;
            if ($name !== null && isset($names[$name]) || $name === null && $named) { $this->defer(); }
            if ($name !== null) { $names[$name] = true; $named = true; }
            $next = [];
            foreach ($states as $prior) {
                foreach ($this->evaluate($argument->value, $prior['state']) as $value) {
                    $branch = $value['state'];
                    $ref = null;
                    if ($argument->value instanceof Node\Expr\Variable && is_string($argument->value->name)) {
                        $raw = $branch['vars'][$argument->value->name] ?? null;
                        if (is_array($raw) && ($raw['kind'] ?? '') === 'ref') { $ref = $raw; }
                        elseif (is_array($value['value']) && ($value['value']['kind'] ?? '') === 'array') {
                            $this->reference($branch, $argument->value->name); $ref = $branch['vars'][$argument->value->name];
                        }
                    }
                    $next[] = ['state' => $branch, 'args' => [...$prior['args'], ['name' => $name, 'value' => $value['value'], 'ref' => $ref]]];
                }
            }
            $states = $next;
        }
        return $states;
    }

    private function condition(Node\Expr $node, array $state): array
    {
        if ($node instanceof Node\Expr\BooleanNot) { return array_map(static fn (array $branch): array => [$branch[0], ! $branch[1]], $this->condition($node->expr, $state)); }
        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            $and = $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd;
            $result = [];
            foreach ($this->condition($node->left, $state) as [$branch, $truth]) {
                if ($truth !== $and) { $result[] = [$branch, $truth]; }
                else { $result = [...$result, ...$this->condition($node->right, $branch)]; }
            }
            return $result;
        }
        $result = [];
        foreach ($this->evaluate($node, $state) as $value) {
            foreach ($this->booleans($value['value'], $value['state']) as [$branch, $truth]) {
                if ($node instanceof Node\Expr\Variable && is_array($value['value']) && ($value['value']['kind'] ?? '') === 'unknown' && $value['value']['type'] === 'bool') {
                    $this->write($branch, $node, $truth);
                }
                $result[] = [$branch, $truth];
            }
        }
        return $result;
    }

    private function booleans(mixed $value, array $state): array
    {
        if (is_bool($value)) { return [[$state, $value]]; }
        if ($value === null || is_scalar($value)) { return [[$state, (bool) $value]]; }
        if (is_array($value) && ($value['kind'] ?? '') === 'array' && isset($value['items'])) { return [[$state, $value['items'] !== []]]; }
        if (is_array($value) && ($value['kind'] ?? '') === 'unknown' && $value['type'] === 'bool') { return [[$state, false], [$state, true]]; }
        $this->defer();
    }

    private function read(array $state, string $name): mixed
    {
        $value = $state['vars'][$name] ?? ['kind' => 'unknown', 'type' => 'ordinary'];
        return is_array($value) && ($value['kind'] ?? '') === 'ref' ? ($state['heap'][$value['id']] ?? throw new \UnexpectedValueException) : $value;
    }

    private function reference(array &$state, string $name): void
    {
        $value = $state['vars'][$name] ?? null;
        if (is_array($value) && ($value['kind'] ?? '') === 'ref') { return; }
        $id = $state['frame'].':local:'.$name;
        $state['heap'][$id] = $this->read($state, $name);
        $state['vars'][$name] = ['kind' => 'ref', 'id' => $id];
    }

    private function write(array &$state, Node\Expr $target, mixed $value): void
    {
        if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
            $old = $state['vars'][$target->name] ?? null;
            if (is_array($old) && ($old['kind'] ?? '') === 'ref') {
                if ($old['id'] === $this->storage && ! is_bool($value)) { $this->defer(); }
                $state['heap'][$old['id']] = $value;
            } else { $state['vars'][$target->name] = $value; }
            return;
        }
        if ($target instanceof Node\Expr\PropertyFetch && $target->var instanceof Node\Expr\Variable
            && is_string($target->var->name) && $target->name instanceof Node\Identifier) {
            $object = $this->read($state, $target->var->name);
            if (! is_array($object) || ($object['kind'] ?? '') !== 'object' || ! isset($object['id'])) { $this->defer(); }
            $stored = $state['objects'][$object['id']] ?? null;
            $field = $stored['plan']['fields'][$target->name->name] ?? null;
            $actual = $this->valueType($value);
            if ($field === null || $actual === null || $field['readonly'] || $this->capability($value)
                || ! $this->fieldAccessible($field['metadata']->writeVisibility, $object['class'], $state['owner'])
                || $field['metadata']->flags->contains(MetadataFlags::STATIC) || $field['metadata']->hooks !== []
                || ! $this->types->isContainedBy($actual, $field['writeType']) || ! $this->types->isContainedBy($actual, $field['type'])) { $this->defer(); }
            $state['objects'][$object['id']]['fields'][$target->name->name] = $value;
            return;
        }
        if ($target instanceof Node\Expr\ArrayDimFetch && $target->var instanceof Node\Expr\Variable && $target->dim === null) {
            if ($this->capability($value)) { $this->defer(); }
            $array = $this->read($state, $target->var->name);
            if (! is_array($array) || ($array['kind'] ?? '') !== 'array' || ! isset($array['items']) || count($array['items']) >= 64
                || array_key_exists(PHP_INT_MAX, $array['items'])) { $this->defer(); }
            $array['items'][] = $value;
            $this->write($state, $target->var, $array);
            return;
        }
        $this->defer();
    }

    private function throwExpression(Node\Expr $expression, array $state): array
    {
        $result = [];
        foreach ($this->evaluate($expression, $state) as $value) {
            if ($value['state']['flow'] !== 'normal') { $result[] = $value; continue; }
            if (! is_array($value['value']) || ($value['value']['kind'] ?? '') !== 'object') { $this->defer(); }
            $throwable = $this->codebase->getClassLike('Throwable');
            $actual = $this->valueType($value['value']);
            if ($throwable === null || $throwable->kind !== \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface
                || strcasecmp($throwable->name, 'Throwable') !== 0 || $throwable->hasIncompleteHierarchy()
                || ! $throwable->flags->contains(MetadataFlags::BUILTIN) || $throwable->flags->contains(MetadataFlags::USER_DEFINED)
                || $actual === null || ! $this->types->isContainedBy($actual, Type::namedObject('Throwable'))) { $this->defer(); }
            foreach ([$value['value']['class'], ...$this->codebase->getClassAncestors($value['value']['class'])] as $ancestor) {
                $metadata = $this->codebase->getClassLike($ancestor);
                if ($metadata === null || $metadata->hasIncompleteHierarchy() || strcasecmp($metadata->name, $ancestor) !== 0) { $this->defer(); }
            }
            $branch = $value['state']; $branch['flow'] = 'throw'; $branch['value'] = $value['value']['class'];
            $result[] = ['state' => $branch, 'value' => null];
        }
        return $result;
    }

    private function comparable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) { return true; }
        if (! is_array($value) || ($value['kind'] ?? '') !== 'array' || ! isset($value['items'])) { return false; }
        foreach ($value['items'] as $item) { if (! $this->comparable($item)) { return false; } }
        return true;
    }

    private function stringValue(mixed $value): string|array
    {
        if ($value === null || is_scalar($value)) { return (string) $value; }
        if (is_array($value) && ($value['kind'] ?? '') === 'unknown' && (in_array($value['type'] ?? '', ['string', 'int', 'float', 'bool'], true)
            || $this->primitiveType($value['nativeType'] ?? null))) {
            return ['kind' => 'unknown', 'type' => 'string'];
        }
        $this->defer();
    }

    private function caught(string $exception, array $types): bool
    {
        $metadata = $this->codebase->getClassLike($exception);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) { $this->defer(); }
        $ancestors = array_map('strtolower', [$exception, ...$metadata->parentClasses, ...$metadata->parentInterfaces]);
        foreach ($types as $type) { if (in_array(strtolower($type->toString()), $ancestors, true)) { return true; } }
        return false;
    }

    private function typed(Type $type, array $state): array
    {
        $bool = $type->getLiteralBool(); $int = $type->getLiteralInt(); $string = $type->getLiteralString();
        if ($bool !== null || $int !== null || $string !== null) { return [['state' => $state, 'value' => $bool ?? $int ?? $string]]; }
        $name = (string) $type;
        if ($name === 'bool') { return [['state' => $state, 'value' => false], ['state' => $state, 'value' => true]]; }
        if ($name === 'void' || $name === 'null') { return [['state' => $state, 'value' => null]]; }
        if ($name === 'never') { $state['flow'] = 'exit'; return [['state' => $state, 'value' => null]]; }
        $atoms = $type->atomicTypes;
        if (count($atoms) === 1 && $atoms[0] instanceof \Mago\Sdk\Analyzer\Type\CallableType) {
            $signature = $atoms[0]->signature;
            if ($atoms[0]->alias !== null || $signature === null || $signature->returnType === null || $signature->constraints !== []
                || ! DirectCallbackReferenceEffects::genericCallable($type, $signature->closure)) { $this->defer(); }
            return [['state' => $state, 'value' => ['kind' => 'unknown', 'type' => 'callable', 'returnType' => $signature->returnType]]];
        }
        if (count($atoms) === 1 && $atoms[0] instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType) { return [['state' => $state, 'value' => ['kind' => 'object', 'class' => $atoms[0]->name]]]; }
        if (str_contains($name, 'Closure') || $name === 'callable') { $this->defer(); }
        return [['state' => $state, 'value' => ['kind' => 'unknown', 'type' => $name, 'nativeType' => $type]]];
    }

    private function unknown(?Node $type): mixed
    {
        if ($type instanceof Node\Name) { return ['kind' => 'object', 'class' => $type->toString()]; }
        return ['kind' => 'unknown', 'type' => $type instanceof Node\Identifier ? $type->name : 'ordinary',
            'nativeType' => DirectCallbackReferenceEffects::syntaxType($type, $this->proof['owner'])];
    }

    private function capability(mixed $value, int $depth = 0): bool
    {
        if ($depth > 8) { $this->defer(); }
        if (! is_array($value)) { return false; }
        if (($value['kind'] ?? '') === 'ref') { return ($value['id'] ?? '') === $this->storage; }
        if (($value['kind'] ?? '') === 'callback') {
            if ($value['tracked']) { return true; }
            foreach ($value['captures'] as $capture) { if ($this->capability($capture, $depth + 1)) { return true; } }
        }
        if (($value['kind'] ?? '') === 'array') {
            foreach ($value['items'] as $item) { if ($this->capability($item, $depth + 1)) { return true; } }
        }
        return false;
    }

    private function noEscape(array $arguments): void
    {
        foreach ($arguments as $argument) {
            if ($this->capability($argument['value']) || ($argument['ref']['id'] ?? '') === $this->storage) { $this->defer(); }
        }
    }

    /** Metadata-only calls cannot preserve facts for storage that arbitrary code can mutate. */
    private function retainsState(mixed $value, int $depth = 0): bool
    {
        if ($depth > 8) { $this->defer(); }
        if (! is_array($value)) { return false; }
        $kind = $value['kind'] ?? '';
        if ($kind === 'ref' || $kind === 'reflection' || $kind === 'object' && isset($value['id'])) { return true; }
        if ($kind === 'method-callback') { return $this->retainsState($value['object'], $depth + 1); }
        if ($kind === 'callback') {
            foreach ($value['captures'] as $capture) { if ($this->retainsState($capture, $depth + 1)) { return true; } }
        }
        if ($kind === 'array') {
            foreach ($value['items'] as $item) { if ($this->retainsState($item, $depth + 1)) { return true; } }
        }
        return false;
    }

    private function retainedArguments(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($this->retainsState($argument['value']) || $this->retainsState($argument['ref'] ?? null)) { return true; }
        }
        return false;
    }

    private function noRetainedEscape(array $arguments, mixed $receiver = null): void
    {
        if ($this->retainsState($receiver) || $this->retainedArguments($arguments)) { $this->defer(); }
    }

    private function accessible(\Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata $method, string $owner): bool
    {
        return $method->visibility === null || $method->visibility === \Mago\Sdk\Analyzer\Type\Visibility::Public
            || strcasecmp($method->identifier->class ?? '', $owner) === 0;
    }

    private function ordinaryArguments(\Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata $method, array $arguments): void
    {
        $used = [];
        foreach ($method->parameters as $position => $parameter) {
            $matches = [];
            foreach ($arguments as $index => $argument) {
                if ($argument['name'] === ltrim($parameter->name, '$') || $argument['name'] === null && $index === $position) { $matches[$index] = $argument; }
            }
            if ($matches === []) {
                if (! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) || $parameter->defaultType === null) { $this->defer(); }
                continue;
            }
            if (count($matches) !== 1) { $this->defer(); }
            $index = array_key_first($matches);
            if (isset($used[$index])) { $this->defer(); }
            $used[$index] = true;
            $actual = $this->valueType($matches[$index]['value']);
            if ($parameter->type === null || $actual === null || ! $this->types->isContainedBy($actual, $parameter->type->type)) { $this->defer(); }
        }
        if (count($used) !== count($arguments)) { $this->defer(); }
    }

    private function valueType(mixed $value): ?Type
    {
        if (is_bool($value)) { return $value ? Type::true() : Type::false(); }
        if (is_int($value)) { return Type::literalInt($value); }
        if (is_string($value)) { return Type::literalString($value); }
        if (is_float($value)) { return Type::float(); }
        if ($value === null) { return Type::null(); }
        if (! is_array($value)) { return null; }
        if (($value['kind'] ?? '') === 'object') { return Type::namedObject($value['class']); }
        if (($value['kind'] ?? '') === 'reflection') { return $value['plan']['reflectionType']; }
        if (in_array($value['kind'] ?? '', ['callback', 'method-callback'], true)) { return $this->callableValueType($value); }
        if (($value['kind'] ?? '') === 'array' && isset($value['items'])) {
            if ($value['items'] === []) { return Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ListType(Type::never(), [], 0, false)); }
            $items = [];
            foreach ($value['items'] as $key => $item) {
                $type = $this->valueType($item);
                if ($type === null) { return null; }
                $items[] = new \Mago\Sdk\Analyzer\Type\ArrayItem(new \Mago\Sdk\Analyzer\Type\ArrayKey(
                    is_int($key) ? \Mago\Sdk\Analyzer\Type\ArrayKeyKind::Integer : \Mago\Sdk\Analyzer\Type\ArrayKeyKind::String, $key,
                ), false, $type);
            }
            return Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($items, null, null, $items !== []));
        }
        if (($value['kind'] ?? '') === 'unknown') {
            if (($value['nativeType'] ?? null) instanceof Type) { return $value['nativeType']; }
            return match ($value['type']) { 'bool' => Type::bool(), 'string' => Type::string(), 'int' => Type::int(), 'float' => Type::float(), default => null };
        }
        return null;
    }

    private function primitiveType(?Type $type): bool
    {
        $atoms = $type?->atomicTypes ?? [];
        return count($atoms) === 1 && $atoms[0] instanceof \Mago\Sdk\Analyzer\Type\ScalarType
            && in_array($atoms[0]->kind, [\Mago\Sdk\Analyzer\Type\ScalarTypeKind::String, \Mago\Sdk\Analyzer\Type\ScalarTypeKind::Integer,
                \Mago\Sdk\Analyzer\Type\ScalarTypeKind::Float, \Mago\Sdk\Analyzer\Type\ScalarTypeKind::Boolean], true);
    }

    /** Stored callable values keep their actual normal-return and argument contracts. */
    private function callableValueType(array $value): ?Type
    {
        $method = ($value['kind'] ?? '') === 'method-callback' ? $value['method'] : null;
        $node = $method['node'] ?? $value['node'];
        $owner = $method['owner'] ?? $value['owner'];
        $parameters = [];
        foreach ($node->params as $position => $parameter) {
            $type = $method['metadata']->parameters[$position]->type?->type ?? DirectCallbackReferenceEffects::syntaxType($parameter->type, $owner);
            if ($type === null && $parameter->type !== null) { return null; }
            $parameters[] = new \Mago\Sdk\Analyzer\Type\CallableParameter('$'.$parameter->var->name, $type ?? Type::mixed(), null,
                $parameter->byRef, $parameter->variadic, $parameter->default !== null);
        }
        $return = $method['metadata']->returnType?->type ?? DirectCallbackReferenceEffects::syntaxType($node->returnType, $owner);
        if ($return === null && $node->returnType !== null) { return null; }
        return Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\CallableType(new \Mago\Sdk\Analyzer\Type\CallableSignature(
            false, true, $parameters, $return ?? Type::mixed(), null, [],
        ), null));
    }

    private function event(string $key): void
    {
        $this->events[$key] = true;
        if (count($this->events) > 32) { $this->defer(); }
    }

    private function bounded(array $states): array
    {
        if (count($states) > 64) { $this->defer(); }
        return $states;
    }

    private function defer(): never { throw new \UnexpectedValueException('The direct callback reference effect is not statically closed.'); }
}
