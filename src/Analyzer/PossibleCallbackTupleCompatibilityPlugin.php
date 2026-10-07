<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class PossibleCallbackTupleCompatibilityPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('laramago/possible-callback-tuples','Possible callback tuples','Closed literal row shapes and physical typed captures under PHPStan possible-write scope merging.'); }
    public function register(PluginRegistry $registry):void
    {
        $registry->registerIssueFilterHook(new PossibleCallbackTupleShapeFilter($this->root));
        $registry->registerIssueFilterHook(new PossibleCallbackTupleArgumentFilter($this->root));
    }
}
