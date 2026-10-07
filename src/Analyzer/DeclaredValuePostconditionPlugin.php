<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class DeclaredValuePostconditionPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/declared-value-postconditions','Declared value postconditions','Current generic assertion and declared collection element contracts.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new AssertedDeclaredValueWarningFilter($this->root)); }
}
