<?php

declare(strict_types=1);

require $argv[1];
$package = $argv[2];
$root = $argv[3];
spl_autoload_register(
    static function (string $class) use ($package): void {
        $prefix = 'Ichinya\\Laramago\\';
        if (str_starts_with($class, $prefix)) {
            require $package.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    },
    true,
    true,
);

$plugin = new class($root) implements \Mago\Sdk\Analyzer\Plugin, \Mago\Sdk\Analyzer\BeforeAnalysisHook {
    public function __construct(
        private readonly string $root,
    ) {}

    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition
    {
        return new \Mago\Sdk\Analyzer\PluginDefinition(
            'fixture/configuration-writes',
            'Configuration writes',
            'Real native fail-closed and bounded work controls.',
        );
    }

    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void
    {
        $registry->registerBeforeAnalysisHook($this);
    }

    public function beforeAnalysis(\Mago\Sdk\Analyzer\BeforeAnalysisContext $context): void
    {
        $cases = json_decode(file_get_contents($this->root.'/cases.json'), true, flags: JSON_THROW_ON_ERROR);
        $checks = [];
        foreach ($cases as $name => [$body, $unknown]) {
            // External native metadata retains absolute source identity.
            $metadataPath = match ($name) {
                'metadata-class' => $context->codebase->getClassLike('ConfigurationWriteMetadata')?->location->file,
                'metadata-function' => $context->codebase->getFunction('configurationWriteMetadata')?->location->file,
                default => null,
            };
            if ($metadataPath !== null) {
                unlink($this->root.'-sources/'.$name.'/extra/metadata.php');
            }
            $source = new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource(
                $metadataPath !== null ? dirname(dirname($metadataPath)) : $this->root.'/'.$name,
            );
            // Observe the genuine SDK cache without changing it: a local unknown
            // must not request the global class/function lists or hydrate them.
            $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
            $listsBefore = $cache->lists;
            $valuesBefore = $cache->values;
            $writes = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationWrites($source, $context->codebase);
            $checks[$name] =
                $writes->affects('unrelated') === $unknown
                && $writes->affects('changed') === ($unknown || $name !== 'empty')
                && $writes->affects('changed.child.leaf') === ($unknown || $name !== 'empty')
                && $writes->affects('later.child')
                && (
                    $metadataPath !== null
                        ? $source->warnings !== []
                        : ($source->contentHash('z.php') === null) === $unknown
                )
                && ($name !== 'dynamic' || $cache->lists === $listsBefore && $cache->values === $valuesBefore);
            if (! $checks[$name]) {
                file_put_contents($this->root.'/failure.json', json_encode([
                    'case' => $name,
                    'affected' => [
                        $writes->affects('unrelated'),
                        $writes->affects('changed'),
                        $writes->affects('later.child'),
                    ],
                    'sourceRoot' => $source->root,
                    'warnings' => $source->warnings,
                    'laterRead' => $source->contentHash('z.php'),
                    'classPath' => $context->codebase->getClassLike('ConfigurationWriteMetadata')?->location->file,
                    'functionPath' => $context->codebase->getFunction('configurationWriteMetadata')?->location->file,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                throw new \RuntimeException('Configuration write control failed: '.$name);
            }
        }
        file_put_contents($this->root.'/checks.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'fixture/writes',
    name: 'Write controls',
    version: '1',
    analyzerPlugins: [$plugin],
)))->run();
