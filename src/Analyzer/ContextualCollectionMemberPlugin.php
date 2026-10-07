<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** These property facts depend on a unique current source span and are never memoized. */
final class ContextualCollectionMemberPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-contextual-collection-members', 'Contextual collection members',
            'Preserve documented model item reads in verified native chunk callbacks.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new ContextualCollectionMemberProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->members);
        $registry->registerPropertyTypeProvider($provider);
        $registry->registerIssueFilterHook(new ContextualDocumentedPropertyIssueFilter($provider));
    }
}
