<?php

declare(strict_types=1);

require $argv[1];
$package = $argv[5];
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
$root = $argv[2];
$snapshot = $argv[3] === '-' ? null : $argv[3];
$mode = $argv[4] ?? '';
$mutator = new class($root, $mode) implements \Mago\Sdk\Analyzer\Plugin, \Mago\Sdk\Analyzer\AfterAnalysisHook {
    public function __construct(
        private readonly string $root,
        private readonly string $mode,
    ) {}

    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition
    {
        return new \Mago\Sdk\Analyzer\PluginDefinition(
            'fixture/runtime',
            'Runtime control',
            'Synthetic final revision control.',
        );
    }

    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook($this);
    }

    public function afterAnalysis(\Mago\Sdk\Analyzer\AfterAnalysisContext $context): void
    {
        if ($this->mode === 'late-source') {
            file_put_contents($this->root . '/config/changed.php', '<?php return [];');
        } elseif ($this->mode === 'late-schema') {
            $pdo = new PDO('sqlite:' . $this->root . '/database/alpha.sqlite');
            $pdo->exec('ALTER TABLE records ADD COLUMN late TEXT');
        }
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'fixture/runtime',
    name: 'Runtime',
    version: '1',
    analyzerPlugins: [$mutator, new \Ichinya\Laramago\Analyzer\LaravelPlugin($root, $snapshot)],
)))->run();
