<?php

declare(strict_types=1);

require $argv[1];
$root = $argv[2];
$mode = $argv[3];
$package = $argv[4];
spl_autoload_register(
    static function (string $class) use ($package): void {
        $prefix = 'Ichinya\\Laramago\\';
        if (str_starts_with($class, $prefix)) {
            require $package . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    },
    true,
    true,
);

$plugin = new class($root, $mode) implements
    \Mago\Sdk\Analyzer\Plugin,
    \Mago\Sdk\Analyzer\BeforeAnalysisHook,
    \Mago\Sdk\Analyzer\AfterAnalysisHook {
    private \Ichinya\Laramago\Analyzer\ConfigurationProvider $provider;

    public function __construct(
        private readonly string $root,
        private readonly string $mode,
    ) {
        $this->provider = new \Ichinya\Laramago\Analyzer\ConfigurationProvider($root);
    }

    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition
    {
        return new \Mago\Sdk\Analyzer\PluginDefinition(
            'fixture/revision',
            'Revision',
            'Synthetic configuration source revisions.',
        );
    }

    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void
    {
        if (!str_starts_with($this->mode, 'full-')) {
            $registry->registerFunctionReturnTypeProvider($this->provider);
            $registry->registerInitializationHook($this->provider);
            $registry->registerBeforeAnalysisHook($this->provider);
            $registry->registerAfterAnalysisHook($this);
            $registry->registerAfterAnalysisHook($this->provider);
        }
        $registry->registerBeforeAnalysisHook($this);
    }

    public function beforeAnalysis(\Mago\Sdk\Analyzer\BeforeAnalysisContext $context): void
    {
        $mode = preg_replace('/^full-/', '', $this->mode);
        $path = $this->root . '/config/example.php';
        if (in_array($mode, ['changed', 'native-control'], true)) {
            file_put_contents($path, '<?php return ["value" => "changed"];');
        } elseif ($mode === 'deleted') {
            unlink($path);
        } elseif ($mode === 'broken') {
            file_put_contents($path, '<?php return ["value" => ;');
        } elseif ($mode === 'added') {
            file_put_contents($this->root . '/config/added.php', '<?php return ["value" => 2];');
        } elseif ($mode === 'manifest') {
            file_put_contents($this->root . '/composer.json', '{"extra":{"laramago":{"binding-files":false}}}');
        } elseif ($mode === 'lock') {
            file_put_contents($this->root . '/composer.lock', '{}');
        } elseif ($mode === 'binding') {
            file_put_contents($this->root . '/bootstrap/bindings.php', '<?php app()->bind("config", "CustomConfig");');
        }
    }

    public function afterAnalysis(\Mago\Sdk\Analyzer\AfterAnalysisContext $context): void
    {
        if (in_array($this->mode, ['late-change', 'multiple-files-late-change'], true)) {
            file_put_contents($this->root . '/config/example.php', '<?php return ["value" => "changed"];');
        }
    }
};
$plugins = str_starts_with($mode, 'full-') ? [new \Ichinya\Laramago\Analyzer\LaravelPlugin($root), $plugin] : [$plugin];
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'fixture/revision',
    name: 'Revision',
    version: '1',
    analyzerPlugins: $plugins,
)))->run();
