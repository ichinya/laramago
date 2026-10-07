<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};

final class CapturedArrayPostconditionPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/captured-array-postconditions','Captured array postconditions','Keep source-bound local key facts from verified callback postconditions.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new CapturedArrayWarningFilter($this->root)); }
}
