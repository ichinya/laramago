<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Fail closed when the native Pipeline dispatch bodies, defaults, or PHPDoc contracts differ. */
final class NativePipelineContract
{
    // Laravel framework 7c75fbf; fixture and its MIT license document the audited source.
    // Resolved AST printing ignores ordinary comments/formatting, but retains PHPDoc and imported names.
    private const MEMBERS = array(
        '$container' => 'c0882666facbced9342077d39d32796444adf6fb72f40d21e966eb5e6b65b09e',
        '$passable' => '30fd4757d5b7b727b55dab49287d4e5c046bdfffdf00cb01ff29d8008afcad9f',
        '$pipes' => 'c0e4325591c6f732bacab1d23c29ef9eb6d464c307d03f4866728184d68c3d2b',
        '$method' => '7ef076673f7de7fa664654ebfbafaea0eef3f19de2aae1c1ad4a59fe6ab20efc',
        '$finally' => '92928391e9b4fab59c9a904c7b1c071860133487a5dca9afb821ff11e78f9625',
        '$withinTransaction' => '4bef58bb98e46f73e243512e989b9c72062570ae522fb4f18c52d25995e975ff',
        '__construct' => '746e06066f22cafe45f666608d0d4996db424d9925f401ba494d6d49b5568da3',
        'send' => '944412939cb272a9cc68b35a7ed044696a173409ddd9aaee5616876a0e4f9a80',
        'through' => '0c3f04e3736cdd9657b3c9c4c26033e8c291e4b83bfba1776dd278ebffe6a29c',
        'then' => '819b36e513003967ac3703e8e56fd6fbb85b273efcae8981e3854aa16901b41b',
        'thenReturn' => '141a9e5e62cb14da2684442e630a5ea4156e8424a275a6a60a2ea6aa56f53da2',
        'prepareDestination' => '58812ff7ba3d21d0be1626c1e1598ebe0064373cdfa82d7f394f27d31f5aea40',
        'carry' => '7348aa0e84ce27ae544ed23bd1412e26c7327f3dc6c490e3f8614b8627d27c1a',
        'pipes' => '16c4eeb002f31f69764965841e878b0c0461157e9ee6912f98e664eff7309afe',
        'handleException' => '891aa16f1123b67575f89ddb771b3a39372d61c959f3aa01ae59e0eb6d3fdc06',
    );

    public function __construct(
        private readonly string $projectRoot = '.',
    ) {}

    public function matches(Codebase $codebase): bool
    {
        foreach ([
            'array_reverse',
            'array_reduce',
            'is_callable',
            'is_object',
            'is_array',
            'func_get_args',
            'method_exists',
        ] as $function) {
            if ($codebase->functionExists('Illuminate\\Pipeline\\'.$function)) {
                return false;
            }
        }
        $metadata = $codebase->getClass('Illuminate\\Pipeline\\Pipeline');
        $path = $metadata?->location->file;
        if (
            $path === null
            || $metadata->flags->contains(MetadataFlags::ABSTRACT)
            || $metadata->directParentClass !== null
            || $metadata->hasIncompleteHierarchy()
            || $metadata->pseudoMethods !== []
            || $metadata->staticPseudoMethods !== []
            || $metadata->mixins !== []
            || ! str_ends_with(
                '/'.str_replace('\\', '/', $path),
                '/laravel/framework/src/Illuminate/Pipeline/Pipeline.php',
            )
        ) {
            return false;
        }
        $nodes = (new PhpSource($this->projectRoot))->read($path);
        if ($nodes === null) {
            return false;
        }
        $class = (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\Class_::class);
        if (
            ! $class instanceof Node\Stmt\Class_
            || $class->namespacedName?->toString() !== 'Illuminate\\Pipeline\\Pipeline'
        ) {
            return false;
        }
        $seen = [];
        foreach ($class->stmts as $member) {
            if ($member instanceof Node\Stmt\TraitUse && $member->adaptations !== []) {
                return false;
            }
            $key = $member instanceof Node\Stmt\ClassMethod
                ? $member->name->toString()
                : (
                    $member instanceof Node\Stmt\Property && count($member->props) === 1
                        ? '$'.$member->props[0]->name->toString()
                        : ''
                );
            if (! isset(self::MEMBERS[$key])) {
                continue;
            }
            $text = (new Standard)->prettyPrint([$member]);
            $normalized = '';
            foreach (token_get_all('<?php '.$text) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true)) {
                        continue;
                    }
                    $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
                } else {
                    $normalized .= $token;
                }
            }
            if (hash('sha256', $normalized) !== self::MEMBERS[$key] || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }

        return count($seen) === count(self::MEMBERS);
    }
}
