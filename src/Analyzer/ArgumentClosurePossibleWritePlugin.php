<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class ArgumentClosurePossibleWritePlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('ichinya/laramago-argument-closure-possible-writes','Argument closure possible writes','A source-bound PHPStan boolean reference scope policy.'); }
    public function register(PluginRegistry $registry): void { $registry->registerIssueFilterHook(new ArgumentClosurePossibleWriteFilter($this->root)); }
}
