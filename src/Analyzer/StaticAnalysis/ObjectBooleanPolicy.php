<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ParenthesizedExpressionSpans;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Source-bound object boolean advisory policy; never changes types or assumes true. */
final class ObjectBooleanPolicy
{
    private array $files = [];
    public function proposal(IssueFilterContext $context): ?array
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Warning || $issue->code !== 'invalid-operand'
            || strlen($context->contents) > 1024 * 1024 || $issue->link !== null || $issue->edits !== [] || count($issue->annotations) !== 1
            || preg_match('/^(Left|Right) operand in `&&` operation is an `object`\.$/D', $issue->message, $match) !== 1
            || $issue->notes !== ['Objects generally coerce to `true` in boolean contexts. Ensure this is the intended behavior.']
            || $issue->help !== 'If specific truthiness is required, implement a method on the object or cast explicitly.') { return null; }
        $annotation = $issue->annotations[0];
        if ($annotation->kind !== AnnotationKind::Primary || $annotation->message !== 'This is an `object`'
            || ($annotation->file !== null && $annotation->file !== '') || $annotation->span->start < 0
            || $annotation->span->end <= $annotation->span->start || $annotation->span->end > strlen($context->contents)) { return null; }
        $hash = hash('sha256',$context->file."\0".$context->contents);
        if (! array_key_exists($hash,$this->files)) { if (count($this->files) >= 2) { $this->files = []; } $this->files[$hash] = self::sites($context->contents); }
        $site = $this->files[$hash][strtolower($match[1]).'|'.$annotation->span->start.':'.$annotation->span->end] ?? null;
        return $site === null ? null : $site + ['sourceSha256'=>hash('sha256',$context->contents)];
    }
    public static function sites(string $contents): array
    {
        if (strlen($contents) > 1024*1024) { return []; }
        try { $parser=(new ParserFactory)->createForNewestSupportedVersion(); $nodes=$parser->parse($contents)??[]; }
        catch (\PhpParser\Error) { return []; }
        $all=(new NodeFinder)->find($nodes,static fn(Node $node):bool=>true);
        if (count($all)>20000) { return []; }
        $sites=[]; $tokens=$parser->getTokens();
        foreach ($all as $node) {
            if (! $node instanceof Node\Expr\BinaryOp\BooleanAnd) { continue; }
            $operatorSpan=null;
            for($index=$node->left->getEndTokenPos()+1;$index<$node->right->getStartTokenPos();++$index) {
                if($tokens[$index]->text==='&&'){ $operatorSpan=[$tokens[$index]->pos,$tokens[$index]->pos+2];break; }
            }
            foreach(['left'=>[$node->left,$node->right],'right'=>[$node->right,$node->left]] as $side=>[$operand,$peer]) {
                foreach(ParenthesizedExpressionSpans::collect($operand,$node,$tokens) as $span) {
                    $sites[$side.'|'.$span]=['kind'=>'boolean-object','operator'=>'&&','side'=>$side,'operatorSpan'=>$operatorSpan,
                        'operation'=>[$node->getStartFilePos(),$node->getEndFilePos()+1],
                        'operand'=>[$operand->getStartFilePos(),$operand->getEndFilePos()+1],
                        'peer'=>[$peer->getStartFilePos(),$peer->getEndFilePos()+1]];
                }
            }
        }
        return $sites;
    }
}
