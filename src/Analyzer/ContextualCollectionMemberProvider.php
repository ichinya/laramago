<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContextualCollectionMemberContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContextualCollectionMembers;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Restore only an independently documented property read lost by a bare chunk formal. */
final class ContextualCollectionMemberProvider implements PropertyTypeProvider, InitializationHook
{
    public readonly ContextualCollectionMembers $members;
    private ContextualCollectionMemberContract $contract;

    public function __construct(private readonly string $root = '.')
    {
        $this->members = new ContextualCollectionMembers($root);
        $this->contract = new ContextualCollectionMemberContract($root, $this->members);
    }

    public function getTargets(): array { return [PropertyTarget::allProperties('Illuminate\\Database\\Eloquent\\Model')]; }

    public function initialize(InitializationContext $context): void
    {
        $this->members->reset();
        $this->contract = new ContextualCollectionMemberContract($this->root, $this->members);
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $access = $context->access;
        $receiver = $access->receiverType;
        $atom = $receiver->atomicTypes[0] ?? null;
        if ($access->kind !== PropertyAccessKind::Read || $access->class !== 'Illuminate\\Database\\Eloquent\\Model'
            || count($receiver->atomicTypes) !== 1 || ! $atom instanceof NamedObjectType
            || $atom->name !== $access->class || ($atom->parameters ?? []) !== [] || ($atom->intersections ?? []) !== []
            || $atom->static || $atom->isThis || $atom->remappedParameters || ($atom->variances ?? []) !== []
            || $receiver->flags->possiblyUndefined || $receiver->flags->possiblyUndefinedFromTry || $receiver->flags->nullsafeNull) { return null; }
        $proof = $this->members->member($access->span, $access->property);
        $type = $proof === null ? null : $this->contract->read($context, $proof);
        return $type === null ? null : new PropertyType(readType: $type);
    }

    /** Read only a current candidate obtained from this provider's shared exact-file source index.
     * @param array<string, mixed> $proof
     */
    public function readIssue(IssueFilterContext $context, array $proof): ?Type
    {
        return $this->contract->readIssue($context, $proof);
    }
}
