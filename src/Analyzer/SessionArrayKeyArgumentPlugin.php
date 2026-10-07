<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class SessionArrayKeyArgumentPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('laramago/session-array-key-arguments','Session array-key arguments','Declared Session array-key source and physical scalar-key normalization.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new SessionArrayKeyArgumentFilter($this->root)); }
}
