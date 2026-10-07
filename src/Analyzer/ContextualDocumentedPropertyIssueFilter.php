<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use PhpParser\Node;

/** A candidate declaration advisory only; it supplies no receiver type or expression replacement. */
final class ContextualDocumentedPropertyIssueFilter implements IssueFilterHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    public array $stages = [];
    public ?array $selected = null;

    public function __construct(private readonly ContextualCollectionMemberProvider $provider) {}
    public function getCodes(): array { return ['non-documented-property']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $this->stages = []; $this->selected = null;
        $issue = $context->issue;
        $matched = preg_match('/^Ambiguous property access: \$([A-Za-z_][A-Za-z0-9_]*) on class `([^`]+)`\.$/D', $issue->message, $match) === 1;
        $envelope = ! $context->cancellation->isCancelled() && $issue->level === Level::Warning
            && $issue->code === 'non-documented-property' && count($issue->annotations) === 2
            && $issue->edits === [] && $issue->link === null && $matched && $match[2] === self::MODEL
            && $issue->notes === ['While this read from might be handled by `__get()`, Mago cannot determine its type without a corresponding `@property` docblock tag.']
            && $issue->help === 'To enable type checking, add a `@property`, `@property-read`, or `@property-write` tag to the docblock of the `'.self::MODEL.'` class. For example: `/** @property string $'.$match[1].' */`';
        $this->stages['exactNativeWarningEnvelope'] = $envelope;
        if (! $envelope) { return IssueFilterDecision::Keep; }
        [$primary, $secondary] = $issue->annotations;
        $spans = $primary->kind === AnnotationKind::Primary && $secondary->kind === AnnotationKind::Secondary
            && $primary->file === null && $secondary->file === null
            && $primary->message === 'This property is not explicitly defined'
            && $secondary->message === 'On an object of type `'.self::MODEL.'`'
            && $secondary->span->start >= 0 && $secondary->span->end > $secondary->span->start
            && $primary->span->start >= $secondary->span->end && $primary->span->end > $primary->span->start
            && $primary->span->end <= strlen($context->contents)
            && substr($context->contents, $primary->span->start, $primary->span->length()) === $match[1];
        $this->stages['exactAnnotationSpans'] = $spans;
        if (! $spans) { return IssueFilterDecision::Keep; }
        $members = $this->provider->members;
        $proof = $members->fileMember($context->file, $context->contents,
            new Span($secondary->span->start, $primary->span->end), $match[1]);
        $this->stages['currentBeforeIssueFileCandidate'] = $proof !== null;
        if ($proof === null) { return IssueFilterDecision::Keep; }
        $fetch = $proof['fetch'];
        $sourceSite = $fetch instanceof Node\Expr\PropertyFetch && $fetch->name instanceof Node\Identifier
            && $fetch->var instanceof Node\Expr\Variable && $fetch->var->name === $proof['local']
            && [$fetch->name->getStartFilePos(), $fetch->name->getEndFilePos() + 1] === [$primary->span->start, $primary->span->end]
            && [$fetch->var->getStartFilePos(), $fetch->var->getEndFilePos() + 1] === [$secondary->span->start, $secondary->span->end];
        $this->stages['exactSourceFetchAndReceiver'] = $sourceSite;
        if (! $sourceSite) { return IssueFilterDecision::Keep; }
        // The provider remains global-collision-safe and keeps its real native receiver flags.
        $this->stages['providerNameCandidate'] = $members->member($primary->span, $match[1]) !== null;
        $this->stages['providerFullFetchCandidate'] = $members->member(new Span($secondary->span->start, $primary->span->end), $match[1]) !== null;
        $read = $this->provider->readIssue($context, $proof);
        $this->stages['sourceNativeRead'] = $read === null ? null : (string) $read;
        $this->selected = ['owner' => $proof['owner'], 'scope' => $proof['scope']->name->name,
            'model' => $proof['model'], 'producer' => $proof['call']->name->name, 'query' => $proof['query'],
            'collectionStorage' => $proof['input'], 'itemStorage' => $proof['local'],
            'loop' => [$proof['loop']->getStartFilePos(), $proof['loop']->getEndFilePos() + 1],
            'origin' => [$proof['origin']->getStartFilePos(), $proof['origin']->getEndFilePos() + 1],
            'fetch' => [$fetch->getStartFilePos(), $fetch->getEndFilePos() + 1],
            'concretePhysical' => $context->codebase->getDeclaringProperty($proof['model'], '$'.$match[1]),
            'concreteMagic' => $context->codebase->getDeclaringMagicProperty($proof['model'], '$'.$match[1])];
        return $read === null ? IssueFilterDecision::Keep : IssueFilterDecision::Remove;
    }
}
