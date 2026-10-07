<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Tests\ObjectBoolean;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ObjectBooleanPolicy as ObjectBooleanDraft;

use Mago\Sdk\Analyzer\AfterFileAnalysisContext;
use Mago\Sdk\Analyzer\AfterFileAnalysisHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\Span;

/** Always Keep in the default mode; controls begin only from a genuine native IssueFilterContext. */
final class NativeObjectBooleanObserver implements Plugin, IssueFilterHook, AfterFileAnalysisHook
{
    private ObjectBooleanDraft $draft;

    public function __construct(private readonly string $output, private readonly bool $remove = false, private readonly array $files = ['focus.php'])
    {
        $this->draft = new ObjectBooleanDraft;
    }

    public function getDefinition(): PluginDefinition { return new PluginDefinition('audit/object-boolean', 'Object boolean operands', 'Ignored native observation and controls.'); }
    public function register(PluginRegistry $registry): void { $registry->registerIssueFilterHook($this); $registry->registerAfterFileAnalysisHook($this); }
    public function getCodes(): array { return ['invalid-operand']; }
    public function getRequirements(): array { return []; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $proposal = $this->draft->proposal($context);
        $this->write('issues', ['file' => $context->file, 'sourceSha256' => hash('sha256', $context->contents),
            'issue' => $context->issue, 'proposal' => $proposal]);
        if ($proposal !== null) { $this->nativeControls($context, $proposal); }
        return $this->remove && $proposal !== null ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    public function afterFileAnalysis(AfterFileAnalysisContext $context): void
    {
        $path = str_replace('\\', '/', $context->analysis->file); $allowed = false;
        foreach ($this->files as $file) { if ($path === $file || str_ends_with($path, '/'.$file)) { $allowed = true; break; } }
        if (! $allowed) { return; }
        $source = $context->analysis->getSourceFile();
        $records = [];
        foreach (ObjectBooleanDraft::sites($source->contents) as $site) {
            $key = implode(':', $site['operation']).'|'.implode(':', $site['operand']);
            if (isset($records[$key])) { continue; }
            $spans = [new Span(...$site['operation']), new Span(...$site['operand'])];
            if ($site['peer'] !== null) { $spans[] = new Span(...$site['peer']); }
            $types = $context->analysis->getMultipleExpressionTypes($spans);
            $records[$key] = $site + ['targetType' => self::typeRecord($types[0]),
                'operandType' => self::typeRecord($types[1]), 'peerType' => isset($types[2]) ? self::typeRecord($types[2]) : null];
        }
        if ($records !== []) { $this->write('types', ['file' => $source->path, 'sourceSha256' => hash('sha256', $source->contents), 'sites' => array_values($records)]); }
    }

    private static function typeRecord(?\Mago\Sdk\Analyzer\Type $type): ?array
    {
        return $type === null ? null : ['description' => (string) $type, 'completeDtoSha256' => hash('sha256', serialize($type))];
    }

    private function nativeControls(IssueFilterContext $context, array $proposal): void
    {
        $issue = $context->issue; $primary = $issue->annotations[0]; $checks = [];
        $alter = static fn (array $changes): ReportedIssue => new ReportedIssue(...array_replace(get_object_vars($issue), $changes));
        $annotation = static fn (array $changes): Annotation => new Annotation(...array_replace(get_object_vars($primary), $changes));
        $with = static fn (ReportedIssue $report, ?string $bytes = null): IssueFilterContext => new IssueFilterContext(
            $context->phpVersion, $context->codebase, $context->types, $context->cancellation, $context->file, $bytes ?? $context->contents, $report);
        $expectKeep = function (string $label, IssueFilterContext $input) use (&$checks): void {
            if ($this->draft->proposal($input) !== null) { throw new \RuntimeException('Object boolean operand native negative failed: '.$label); }
            $checks[$label] = true;
        };
        foreach ([
            'Error severity' => ['level' => Level::Error], 'Help severity' => ['level' => Level::Help],
            'foreign code' => ['code' => 'possibly-false-operand'], 'unknown message' => ['message' => 'Unknown operand.'],
            'missing note' => ['notes' => []], 'extra note' => ['notes' => [...$issue->notes, 'Unrelated note.']],
            'changed note' => ['notes' => ['Different semantics.']], 'changed help' => ['help' => null],
            'foreign link' => ['link' => 'https://example.invalid'], 'suggested edit' => ['edits' => [TextEdit::delete($primary->span)]],
            'no annotation' => ['annotations' => []], 'duplicate annotation' => ['annotations' => [$primary, $primary]],
            'secondary annotation' => ['annotations' => [$annotation(['kind' => AnnotationKind::Secondary])]],
            'foreign annotation file' => ['annotations' => [$annotation(['file' => 'foreign.php'])]],
            'different annotation message' => ['annotations' => [$annotation(['message' => 'This is `false`'])]],
            'nearby start' => ['annotations' => [$annotation(['span' => new Span($primary->span->start + 1, $primary->span->end)])]],
            'nearby end' => ['annotations' => [$annotation(['span' => new Span($primary->span->start, $primary->span->end - 1)])]],
        ] as $label => $changes) { $expectKeep($label, $with($alter($changes))); }
        foreach (['mixed','array','null','resource','bool','string','SimpleXMLElement','Route','object|null','object|false'] as $type) {
            $expectKeep('foreign printed object type '.$type, $with($alter(['message'=>str_replace('`object`','`'.$type.'`',$issue->message)])));
        }
        foreach (['||','and','or','xor','+','>','.'] as $operator) {
            $expectKeep('foreign operator message '.$operator, $with($alter(['message'=>str_replace('`&&`','`'.$operator.'`',$issue->message)])));
        }
        $expectKeep('malformed current source', $with($issue, '<?php function invalid( {'));
        $expectKeep('oversized current source', $with($issue, $context->contents.str_repeat(' ', 1024 * 1024)));
        $expectKeep('same path shifted current source', $with($issue, "<?php /* shift */ ".substr($context->contents, 5)));
        if ($proposal['operatorSpan'] !== null) {
            $bytes = substr_replace($context->contents, '+', $proposal['operatorSpan'][0], $proposal['operatorSpan'][1] - $proposal['operatorSpan'][0]);
            $expectKeep('same path arithmetic operator', $with($issue, $bytes));
        }
        $this->write('controls', ['file' => $context->file, 'primary' => [$primary->span->start, $primary->span->end],
            'genuineNativePositive' => true, 'checks' => $checks]);
    }

    private function write(string $kind, array $record): void
    {
        if (! is_dir($this->output)) { throw new \RuntimeException('Parent must create observer output directory.'); }
        file_put_contents($this->output.'/'.$kind.'-'.getmypid().'.jsonl', json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}
