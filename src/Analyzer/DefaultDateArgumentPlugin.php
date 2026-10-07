<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class DefaultDateArgumentPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('laramago/default-date-arguments','Default date arguments','Physical default facade, factory and late-static Carbon constructor proof.'); }
    public function register(PluginRegistry $registry): void
    {
        $filter=new DefaultDateArgumentFilter($this->root);
        $registry->registerCodebaseScanHook($filter);$registry->registerIssueFilterHook($filter);
    }
}
