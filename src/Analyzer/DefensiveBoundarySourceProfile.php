<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use PhpParser\{Node, NodeFinder};
use PhpParser\PrettyPrinter\Standard;
/** Exact supported public library bodies; formatting and ordinary comments are irrelevant. */
final class DefensiveBoundarySourceProfile
{
    public static function fingerprint(Node $node): string
    {
        $tokens = '';
        foreach (token_get_all('<?php ' . (new Standard())->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $tokens .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $tokens .= $token;
            }
        }
        return hash('sha256', $tokens);
    }
    public static function selected(array $nodes, ?string $class, string $method): ?Node
    {
        $finder = new NodeFinder();
        if ($class === null) {
            return $finder->findFirst($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\Function_ && strcasecmp($node->namespacedName->toString(), $method) === 0);
        }
        $owner = $finder->findFirst($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\ClassLike && strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0);
        return $owner?->getMethod($method);
    }
    public static function path(string $path): string
    {
        return str_replace('\\', '/', str_replace('\\\\?\\', '', $path));
    }
}
