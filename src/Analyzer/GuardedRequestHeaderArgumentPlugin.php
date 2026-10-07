<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class GuardedRequestHeaderArgumentPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('laramago/guarded-request-header-arguments','Guarded Request header arguments','Physical literal-header branch and successful stable lexical guard.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new GuardedRequestHeaderArgumentFilter($this->root)); }
}
