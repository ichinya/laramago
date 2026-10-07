<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class FactoryModelDeclarationPlugin implements Plugin
{
    public function __construct(private readonly string $root, private readonly string $factory = 'Illuminate\\Database\\Eloquent\\Factories\\Factory', private readonly string $model = 'Illuminate\\Database\\Eloquent\\Model') {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('laramago/factory-model-declaration', 'Factory model declaration compatibility', 'Source/native literal factory model declaration agreement; all types retained'); }
    public function register(PluginRegistry $registry): void { $registry->registerIssueFilterHook(new FactoryModelDeclarationIssueFilter($this->root, $this->factory, $this->model)); }
}
