<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/** Extracts bounded literal view and translation references without compiling Blade. */
final class BladeReferenceParser
{
    private const MAX_BYTES = 262_144;

    private ?Parser $phpParser = null;

    public function parse(string $source): ?BladeReferenceScan
    {
        $length = strlen($source);
        if ($length > self::MAX_BYTES || str_contains($source, "\0")) {
            return null;
        }

        $references = [];
        $complete = true;
        for ($offset = 0; $offset < $length;) {
            if (
                substr_compare($source, '{{--', $offset, 4) === 0
                || substr_compare($source, '<!--', $offset, 4) === 0
            ) {
                $blade = substr_compare($source, '{{--', $offset, 4) === 0;
                $end = strpos($source, $blade ? '--}}' : '-->', $offset + 4);
                if ($end === false) {
                    $complete = false;
                    break;
                }
                $offset = $end + ($blade ? 4 : 3);
                continue;
            }

            if (
                substr_compare($source, '@verbatim', $offset, 9) === 0
                && $this->directiveBoundary($source, $offset, 9)
            ) {
                $end = strpos($source, '@endverbatim', $offset + 9);
                if ($end === false) {
                    $complete = false;
                    break;
                }
                $offset = $end + 12;
                continue;
            }

            if (substr_compare($source, '@@', $offset, 2) === 0) {
                $escapedMatch = [];
                if (preg_match('/\G@@[A-Za-z_][A-Za-z0-9_]*/A', $source, $escapedMatch, 0, $offset) === 1) {
                    $afterEscapedName = $offset + strlen($escapedMatch[0]);
                    $skip = $this->skipDirectiveArguments($source, $afterEscapedName, $complete);
                    if ($skip === null) {
                        break;
                    }
                    $offset = $skip;
                } else {
                    $offset += 2;
                }
                continue;
            }

            if ($source[$offset] === '@' && ($escapedEcho = $this->echoPrefix($source, $offset + 1)) !== null) {
                [$open, $close] = $escapedEcho;
                $end = $this->closingDelimiter($source, $offset + 1 + strlen($open), $close);
                if ($end === null) {
                    $complete = false;
                    break;
                }
                $offset = $end + strlen($close);
                continue;
            }

            if (
                substr_compare($source, '<?=', $offset, 3) === 0
                || strncasecmp(substr($source, $offset, 5), '<?php', 5) === 0
            ) {
                $phpEnd = $this->phpClosingTag($source, $offset);
                if ($phpEnd === null) {
                    $complete = false;
                    break;
                }
                $this->phpReferences(
                    substr($source, $offset, $phpEnd - $offset),
                    $offset,
                    'php',
                    $references,
                    $complete,
                );
                $offset = $phpEnd;
                continue;
            }

            $echo = $this->echoPrefix($source, $offset);
            if ($echo !== null) {
                [$open, $close] = $echo;
                $end = $this->closingDelimiter($source, $offset + strlen($open), $close);
                if ($end === null) {
                    $complete = false;
                    break;
                }
                $inner = substr($source, $offset + strlen($open), $end - $offset - strlen($open));
                $this->echoReferences(
                    '<?php echo '.$inner.';',
                    $offset + strlen($open) - strlen('<?php echo '),
                    $references,
                    $complete,
                );
                $offset = $end + strlen($close);
                continue;
            }

            if ($source[$offset] !== '@' || $offset > 0 && preg_match('/[A-Za-z0-9_@]/', $source[$offset - 1]) === 1) {
                $offset++;
                continue;
            }
            $match = [];
            if (preg_match('/\G@([A-Za-z_][A-Za-z0-9_]*)/A', $source, $match, 0, $offset) !== 1) {
                $offset++;
                continue;
            }
            $directive = $match[1];
            $afterName = $offset + strlen($match[0]);
            $afterWhitespace = $afterName;
            while (
                $afterWhitespace < $length
                && ($source[$afterWhitespace] === ' '
                || $source[$afterWhitespace] === "\t")
            ) {
                $afterWhitespace++;
            }
            if ($directive === 'php' && ($source[$afterWhitespace] ?? '') !== '(') {
                $end = $this->phpBlockEnd($source, $afterName);
                if ($end === null) {
                    $complete = false;
                    break;
                }
                $parsed = $this->phpReferences(
                    '<?php '.substr($source, $afterName, $end - $afterName),
                    $afterName - strlen('<?php '),
                    'php',
                    $references,
                    $complete,
                );
                if (! $parsed) {
                    break;
                }
                $offset = $end + 7;
                continue;
            }
            $arguments = $this->directiveArguments($directive);
            if ($arguments === null && $directive !== 'php') {
                $skip = $this->skipDirectiveArguments($source, $afterName, $complete);
                if ($skip === null) {
                    break;
                }
                if ($skip !== $afterName) {
                    $complete = false;
                }
                $offset = $skip;
                continue;
            }
            $open = $afterWhitespace;
            if (($source[$open] ?? '') !== '(') {
                $complete = false;
                $offset = $open;
                continue;
            }
            $close = $this->closingParenthesis($source, $open);
            if ($close === null) {
                $complete = false;
                break;
            }
            $inner = substr($source, $open + 1, $close - $open - 1);
            if ($directive === 'php') {
                $this->expressionReferences(
                    '<?php '.$inner.';',
                    $open + 1 - strlen('<?php '),
                    'php',
                    $references,
                    $complete,
                );
            } else {
                $this->directiveReferences($directive, $inner, $open + 1, $references, $complete);
            }
            $offset = $close + 1;
        }

        usort(
            $references,
            static fn (BladeReference $left, BladeReference $right): int => $left->start <=> $right->start,
        );

        return new BladeReferenceScan($references, $complete);
    }

    /** @return array{kind: string, index: int, requirement: string}|null */
    private function directiveArguments(string $directive): ?array
    {
        return match ($directive) {
            'extends', 'include', 'includeIsolated', 'component' => [
                'kind' => 'view',
                'index' => 0,
                'requirement' => 'required',
            ],
            'includeIf' => ['kind' => 'view', 'index' => 0, 'requirement' => 'optional'],
            'includeWhen', 'includeUnless' => ['kind' => 'view', 'index' => 1, 'requirement' => 'conditional'],
            'each' => ['kind' => 'view', 'index' => 0, 'requirement' => 'conditional'],
            'lang', 'choice' => ['kind' => 'translation', 'index' => 0, 'requirement' => 'conditional'],
            default => null,
        };
    }

    /** @param list<BladeReference> $references */
    private function directiveReferences(
        string $directive,
        string $inner,
        int $innerStart,
        array &$references,
        bool &$complete,
    ): void {
        $spec = $this->directiveArguments($directive);
        if ($spec === null) {
            return;
        }
        $prefix = '<?php __blade_reference(';
        $snippet = $prefix.$inner.');';
        $nodes = $this->phpNodes($snippet);
        if (
            $nodes === null
            || count($nodes) !== 1
            || ! $nodes[0] instanceof Expression
            || $nodes[0]->getEndFilePos() !== (strlen($snippet) - 1)
        ) {
            $complete = false;

            return;
        }
        $call = $nodes[0]->expr;
        if (! $call instanceof FuncCall || $call->getEndFilePos() !== (strlen($snippet) - 2)) {
            $complete = false;

            return;
        }
        $this->walkPhp($call, $snippet, $innerStart - strlen($prefix), 'directive', $references, $complete);
        $argument = $call->args[$spec['index']] ?? null;
        if (
            $directive === 'component'
            && $argument instanceof Arg
            && $argument->value instanceof ClassConstFetch
            && $argument->value->name instanceof Identifier
            && $argument->value->name->toLowerString() === 'class'
        ) {
            return;
        }
        if (! $argument instanceof Arg || ! $argument->value instanceof String_) {
            $complete = false;

            return;
        }
        $this->appendLiteral(
            $argument->value,
            $snippet,
            $innerStart - strlen($prefix),
            $spec['kind'],
            '@'.$directive,
            $spec['requirement'],
            $references,
            $complete,
        );

        // The fourth @each argument names a view used only for an empty collection.
        if ($directive === 'each' && isset($call->args[3])) {
            $empty = $call->args[3];
            if (
                $empty instanceof Arg
                && $empty->value instanceof String_
                && ! str_starts_with($empty->value->value, 'raw|')
            ) {
                $this->appendLiteral(
                    $empty->value,
                    $snippet,
                    $innerStart - strlen($prefix),
                    'view',
                    '@each:empty',
                    'conditional',
                    $references,
                    $complete,
                );
            } elseif (! $empty instanceof Arg || ! $empty->value instanceof String_) {
                $complete = false;
            }
        }
    }

    /** @param list<BladeReference> $references */
    private function echoReferences(string $php, int $sourceStart, array &$references, bool &$complete): void
    {
        $nodes = $this->phpNodes($php);
        if (
            $nodes === null
            || count($nodes) !== 1
            || ! $nodes[0] instanceof Echo_
            || count($nodes[0]->exprs) !== 1
            || $nodes[0]->getEndFilePos() !== (strlen($php) - 1)
        ) {
            $complete = false;

            return;
        }
        $this->walkPhp($nodes[0], $php, $sourceStart, 'echo', $references, $complete);
    }

    /** @param list<BladeReference> $references */
    private function expressionReferences(
        string $php,
        int $sourceStart,
        string $origin,
        array &$references,
        bool &$complete,
    ): void {
        $nodes = $this->phpNodes($php);
        if (
            $nodes === null
            || count($nodes) !== 1
            || ! $nodes[0] instanceof Expression
            || $nodes[0]->getEndFilePos() !== (strlen($php) - 1)
        ) {
            $complete = false;

            return;
        }
        $this->walkPhp($nodes[0], $php, $sourceStart, $origin, $references, $complete);
    }

    /** @param list<BladeReference> $references */
    private function phpReferences(
        string $php,
        int $sourceStart,
        string $origin,
        array &$references,
        bool &$complete,
    ): bool {
        $nodes = $this->phpNodes($php);
        if ($nodes === null) {
            $complete = false;

            return false;
        }
        foreach ($nodes as $node) {
            $this->walkPhp($node, $php, $sourceStart, $origin, $references, $complete);
        }

        return true;
    }

    /** @param list<BladeReference> $references */
    private function walkPhp(
        Node $node,
        string $php,
        int $sourceStart,
        string $origin,
        array &$references,
        bool &$complete,
    ): void {
        foreach ((new NodeFinder)->findInstanceOf($node, FuncCall::class) as $call) {
            if (! $call->name instanceof Name) {
                continue;
            }
            $function = $call->name->toString();
            if (in_array($function, ['__', 'trans', 'trans_choice'], true) && ! str_contains($function, '\\')) {
                $argument = $call->args[0] ?? null;
                if ($argument instanceof Arg && $argument->value instanceof String_) {
                    $this->appendLiteral(
                        $argument->value,
                        $php,
                        $sourceStart,
                        'translation',
                        $origin.':'.$function,
                        'conditional',
                        $references,
                        $complete,
                    );
                } else {
                    $complete = false;
                }
            }
        }
    }

    /** @param list<BladeReference> $references */
    private function appendLiteral(
        String_ $literal,
        string $snippet,
        int $sourceStart,
        string $kind,
        string $origin,
        string $requirement,
        array &$references,
        bool &$complete,
    ): void {
        $start = $literal->getStartFilePos();
        $end = $literal->getEndFilePos();
        if (
            $start < 0
            || $end <= $start
            || ! in_array($snippet[$start] ?? '', ["'", '"'], true)
            || ($snippet[$end] ?? '') !== $snippet[$start]
        ) {
            $complete = false;

            return;
        }
        $value = $literal->value;
        if (
            $kind === 'view'
            && $origin === '@component'
            && (str_contains($value, '\\')
            || str_contains($value, '::class'))
        ) {
            return;
        }
        if ($value === '') {
            $complete = false;

            return;
        }
        $references[] = new BladeReference(
            $kind,
            $value,
            $origin,
            $requirement,
            $sourceStart + $start + 1,
            $sourceStart + $end,
        );
    }

    /** @return list<Node\Stmt>|null */
    private function phpNodes(string $source): ?array
    {
        try {
            $nodes = ($this->phpParser ??= (new ParserFactory)->createForNewestSupportedVersion())->parse($source);

            return $nodes === null ? null : array_values($nodes);
        } catch (\PhpParser\Error) {
            return null;
        }
    }

    private function directiveBoundary(string $source, int $offset, int $length): bool
    {
        return (
            ($offset === 0 || preg_match('/[A-Za-z0-9_@]/', $source[$offset - 1]) !== 1)
            && preg_match('/[A-Za-z0-9_]/', $source[$offset + $length] ?? '') !== 1
        );
    }

    private function skipDirectiveArguments(string $source, int $afterName, bool &$complete): ?int
    {
        $open = $afterName;
        for (
            $length = strlen($source);
            $open < $length && ($source[$open] === ' ' || $source[$open] === "\t");
            $open++
        ) {}
        if (($source[$open] ?? '') !== '(') {
            return $afterName;
        }
        $close = $this->closingParenthesis($source, $open);
        if ($close === null) {
            $complete = false;

            return null;
        }

        return $close + 1;
    }

    /** @return array{string, string}|null */
    private function echoPrefix(string $source, int $offset): ?array
    {
        foreach ([['{!!', '!!}'], ['{{{', '}}}'], ['{{', '}}']] as $pair) {
            if (substr_compare($source, $pair[0], $offset, strlen($pair[0])) === 0) {
                return $pair;
            }
        }

        return null;
    }

    private function closingDelimiter(string $source, int $offset, string $delimiter): ?int
    {
        for ($length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '"' || $source[$offset] === "'") {
                $end = $this->quotedEnd($source, $offset);
                if ($end === null) {
                    return null;
                }
                $offset = $end - 1;
            } elseif (substr_compare($source, $delimiter, $offset, strlen($delimiter)) === 0) {
                return $offset;
            }
        }

        return null;
    }

    private function closingParenthesis(string $source, int $open): ?int
    {
        $depth = 0;
        for ($offset = $open, $length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '"' || $source[$offset] === "'") {
                $end = $this->quotedEnd($source, $offset);
                if ($end === null) {
                    return null;
                }
                $offset = $end - 1;
            } elseif ($source[$offset] === '(') {
                $depth++;
            } elseif ($source[$offset] === ')' && --$depth === 0) {
                return $offset;
            }
        }

        return null;
    }

    private function quotedEnd(string $source, int $start): ?int
    {
        $quote = $source[$start];
        for ($offset = $start + 1, $length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '\\') {
                $offset++;
            } elseif ($source[$offset] === $quote) {
                return $offset + 1;
            }
        }

        return null;
    }

    private function phpClosingTag(string $source, int $start): ?int
    {
        $tokens = token_get_all(substr($source, $start));
        $offset = $start;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $offset += strlen($text);
            if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                return $offset;
            }
        }

        return strlen($source);
    }

    private function phpBlockEnd(string $source, int $start): ?int
    {
        $prefix = '<?php ';
        $tokens = token_get_all($prefix.substr($source, $start));
        $offset = $start - strlen($prefix);
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '@' && isset($tokens[$index + 1])) {
                $next = $tokens[$index + 1];
                if (is_array($next) && $next[0] === T_STRING && $next[1] === 'endphp') {
                    // Blade's raw-block regex stops at the first textual delimiter.
                    // If PHP tokenization disagrees, the template is ambiguous.
                    return strpos($source, '@endphp', $start) === $offset ? $offset : null;
                }
            }
            $offset += strlen($text);
        }

        return null;
    }
}
