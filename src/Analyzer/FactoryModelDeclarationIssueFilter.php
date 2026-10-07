<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Narrow native PHPDoc declaration compatibility, never a property type provider. */
final class FactoryModelDeclarationIssueFilter implements IssueFilterHook
{
    public readonly FactoryModelDeclarationProof $proof;
    public function __construct(string $root, string $factory = 'Illuminate\\Database\\Eloquent\\Factories\\Factory', string $model = 'Illuminate\\Database\\Eloquent\\Model')
    {
        $this->proof = new FactoryModelDeclarationProof($root, $factory, $model);
    }
    public function getCodes(): array { return ['incompatible-property-type']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Error || $issue->code !== 'incompatible-property-type'
            || $issue->edits !== [] || $issue->link !== null || count($issue->annotations) !== 2
            || preg_match('/^Property `([^`]+)::\$model` has an incompatible type declaration from docblock\.$/D', $issue->message, $class) !== 1
            || preg_match('/^Change the type of `\$model` to `class-string<([^`<>|?]+)>` to match the parent property\.$/D', $issue->help ?? '', $model) !== 1
            || $issue->notes !== ['PHP requires property types to be invariant, meaning the type declaration in a child class must be exactly the same as in the parent class.']) { return IssueFilterDecision::Keep; }
        [$primary, $secondary] = $issue->annotations;
        if ($primary->kind !== AnnotationKind::Primary || $primary->file !== null
            || $primary->message !== "This type `string` is incompatible with the parent's type."
            || $secondary->kind !== AnnotationKind::Secondary || $secondary->file === null
            || $secondary->message !== 'The parent property is defined with type `class-string<'.$model[1].'>` here.') { return IssueFilterDecision::Keep; }
        return $this->proof->current($context, $class[1], $model[1]) ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
