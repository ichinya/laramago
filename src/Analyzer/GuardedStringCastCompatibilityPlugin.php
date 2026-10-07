<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
final class GuardedStringCastCompatibilityPlugin implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('laramago/guarded-string-casts','Guarded string casts','Explicit source and native cast compatibility policies'); }
    public function register(PluginRegistry $registry): void { $registry->registerIssueFilterHook(new GuardedStringCastCompatibilityFilter($this->root)); }
}
