<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class ClosedConfigurationArgumentPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('laramago/closed-configuration-arguments','Closed configuration arguments','Exact nonescaping lexical callback array-key projection.'); }
    public function register(PluginRegistry $registry): void { $registry->registerIssueFilterHook(new ClosedConfigurationArgumentFilter($this->root)); }
}
