<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};

final class SourceArgumentContractPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('laramago/source-argument-contracts','Source argument contracts','Bounded decimal conversion and the declared native process directory contract.'); }
    public function register(PluginRegistry $registry): void { $registry->registerIssueFilterHook(new SourceArgumentContractFilter($this->root)); }
}
