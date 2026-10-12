<?php

declare(strict_types=1);

require $argv[1];

use Ichinya\Laramago\Analyzer\ConfigurationProvider;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationWrites;
use Mago\Sdk\Analyzer\{BeforeAnalysisContext, BeforeAnalysisHook, FunctionReturnTypeProvider, InitializationContext, InitializationHook, Plugin, PluginDefinition, PluginRegistry, ReturnTypeProviderContext, Type};
use Mago\Sdk\Worker;

// Controlled yielding constructor tests the real provider's coordination;
// the native jobs separately exercise the real metadata constructor.
if (($argv[4] ?? null) === 'control') {
    eval(<<<'PHP'
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;
final class ConfigurationWrites {
    public static int $starts = 0;
    public static ?\Throwable $failure = null;
    public static ?\Closure $during = null;
    public static ?\Closure $afterResume = null;
    public function __construct(PhpSource $source, \Mago\Sdk\Analyzer\Codebase $codebase) {
        ++self::$starts;
        $wait = \Revolt\EventLoop::getSuspension();
        $failure = self::$failure;
        $during = self::$during;
        $afterResume = self::$afterResume;
        \Revolt\EventLoop::queue(static function () use ($wait, $during, $afterResume): void {
            if ($during !== null) { $during(); }
            $wait->resume();
            if ($afterResume !== null) { \Revolt\EventLoop::queue($afterResume); }
        });
        $wait->suspend();
        if ($failure !== null) { throw $failure; }
    }
}
PHP);
    $provider = new ConfigurationProvider($argv[2]);
    $method = new ReflectionMethod($provider, 'writeIndex');
    // The controlled constructor never uses metadata; native jobs use actual Codebase.
    $codebase = (new ReflectionClass(\Mago\Sdk\Analyzer\Codebase::class))->newInstanceWithoutConstructor();
    $initialization = (new ReflectionClass(InitializationContext::class))->newInstanceWithoutConstructor();
    $batch = static function () use ($provider, $method, $codebase): array {
        $results = [];
        for ($index = 0; $index < 6; ++$index) {
            \Revolt\EventLoop::queue(static function () use ($provider, $method, $codebase, &$results): void {
                try { $results[] = $method->invoke($provider, $codebase); }
                catch (Throwable $error) { $results[] = $error; }
            });
        }
        \Revolt\EventLoop::run();
        if (count($results) !== 6) { throw new RuntimeException('A waiter was left suspended.'); }

        return $results;
    };
    $provider->initialize($initialization);
    $results = $batch();
    if (ConfigurationWrites::$starts !== 1 || ! $results[0] instanceof ConfigurationWrites) {
        throw new RuntimeException('Concurrent callers started duplicate constructors.');
    }
    foreach ($results as $result) {
        if ($result !== $results[0]) { throw new RuntimeException('Waiters received different indexes.'); }
    }
    $provider->initialize($initialization);
    ConfigurationWrites::$failure = new RuntimeException('controlled metadata failure');
    $results = $batch();
    if (ConfigurationWrites::$starts !== 2) { throw new RuntimeException('Failure launched duplicate constructors.'); }
    foreach ($results as $result) {
        if ($result !== ConfigurationWrites::$failure) { throw new RuntimeException('Failure was not passed to every waiter.'); }
    }
    ConfigurationWrites::$failure = null;
    $results = $batch();
    if (ConfigurationWrites::$starts !== 3 || ! $results[0] instanceof ConfigurationWrites) {
        throw new RuntimeException('Failed preparation was retained.');
    }
    $provider->initialize($initialization);
    ConfigurationWrites::$during = static function () use ($provider, $initialization): void { $provider->initialize($initialization); };
    $results = $batch();
    foreach ($results as $result) {
        if (! $result instanceof RuntimeException) { throw new RuntimeException('A stale generation completed successfully.'); }
    }
    ConfigurationWrites::$during = null;
    $results = $batch();
    if (ConfigurationWrites::$starts !== 5 || ! $results[0] instanceof ConfigurationWrites) {
        throw new RuntimeException('A stale generation polluted the fresh index.');
    }
    $provider->initialize($initialization);
    ConfigurationWrites::$afterResume = static function () use ($provider, $initialization): void { $provider->initialize($initialization); };
    $results = $batch();
    $successes = count(array_filter($results, static fn ($value): bool => $value instanceof ConfigurationWrites));
    $failures = count(array_filter($results, static fn ($value): bool => $value instanceof RuntimeException));
    if ($successes !== 1 || $failures !== 5) {
        throw new RuntimeException('Queued waiters returned an already invalidated index.');
    }
    ConfigurationWrites::$afterResume = null;
    $results = $batch();
    if (ConfigurationWrites::$starts !== 7 || ! $results[0] instanceof ConfigurationWrites) {
        throw new RuntimeException('A delivered stale result polluted the next generation.');
    }
    echo "PASS: one construction, identical results, shared failure, retry, reset and stale-generation veto.\n";
    return;
}

$plugin = new class($argv[2], $argv[3]) implements Plugin, InitializationHook, BeforeAnalysisHook, FunctionReturnTypeProvider {
    private readonly ConfigurationProvider $provider;
    private ?ConfigurationWrites $ready = null;
    private readonly ReflectionProperty $writes;

    public function __construct(private readonly string $root, private readonly string $output)
    {
        $this->provider = new ConfigurationProvider($root);
        $this->writes = new ReflectionProperty($this->provider, 'writes');
    }

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('fixture/configuration-initialization', 'Configuration initialization', 'Observe actual native lifecycle and concurrent callbacks.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerInitializationHook($this);
        $registry->registerBeforeAnalysisHook($this);
        $registry->registerFunctionReturnTypeProvider($this);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->provider->initialize($context);
        $this->ready = null;
        if ($this->writes->getValue($this->provider) !== null) {
            throw new RuntimeException('Configuration writes survived a new initialization.');
        }
    }

    public function beforeAnalysis(BeforeAnalysisContext $context): void
    {
        $this->provider->beforeAnalysis($context);
        $index = $this->writes->getValue($this->provider);
        if (! $index instanceof ConfigurationWrites) {
            throw new RuntimeException('Configuration writes are not ready before concurrent callbacks.');
        }
        $this->ready = $index;
        file_put_contents($this->output, json_encode(['pid' => getmypid(), 'stage' => 'ready', 'origin' => 'lifecycle'], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }

    public function getTargets(): array
    {
        return $this->provider->getTargets();
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        if ($this->ready !== null && $this->writes->getValue($this->provider) !== $this->ready) {
            throw new RuntimeException('Concurrent callback rebuilt the write index.');
        }
        $type = $this->provider->getReturnType($context);
        if ($this->ready === null) {
            $index = $this->writes->getValue($this->provider);
            if (! $index instanceof ConfigurationWrites) {
                throw new RuntimeException('Callback completed without a prepared index.');
            }
            $this->ready = $index;
            file_put_contents($this->output, json_encode(['pid' => getmypid(), 'stage' => 'ready', 'origin' => 'fallback'], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
        if ($this->writes->getValue($this->provider) !== $this->ready) {
            throw new RuntimeException('Callback changed the write index.');
        }
        file_put_contents($this->output, json_encode(['pid' => getmypid(), 'stage' => 'callback', 'refined' => $type !== null], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

        return $type;
    }
};

(new Worker(new \Mago\Sdk\Extension(
    identifier: 'fixture/configuration-initialization',
    name: 'Configuration initialization',
    version: '1',
    analyzerPlugins: [$plugin],
)))->run();
