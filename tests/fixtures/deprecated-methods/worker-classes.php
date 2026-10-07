<?php
declare(strict_types=1);
use Example\DeprecatedMethods\{ControlledCache, MethodDeprecationCompatibilityPlugin};
use Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof as MethodDeprecationCompatibilityProof;
use Mago\Sdk\Analyzer\{IssueFilterContext, Plugin, PluginDefinition, PluginRegistry, Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\{Extension, Worker};

if (! defined('DEPRECATED_PREP_CLASSES_ONLY')) { $package = $argv[1]; $root = $argv[2]; $mode = $argv[3]; $output = $argv[4]; require $package.'/vendor/autoload.php'; }

/** Test-only control wrappers use the genuine selected native method before mutation. */

require __DIR__.'/ControlledCache.php';require __DIR__.'/observer-plugin.php';
final class DeprecatedDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root, private readonly string $mode, private readonly string $output) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('example/method-deprecation-draft', 'Method deprecation draft', 'Native observers and genuine cache mutation tests for a bounded reporting policy.'); }
    public function register(PluginRegistry $registry): void
    {
        $mode = $this->mode; $output = $this->output;
        $control = str_starts_with($mode, 'cache-') ? static function (IssueFilterContext $context, MethodDeprecationCompatibilityProof $proof, array $before) use ($mode, $output): void {
            if (! $before['remove'] || $mode === 'cache-builtin-since' && ! ($before['nativeMethod']['builtin'] ?? false)) { return; }
            $method = $context->codebase->getDeclaringMethod($before['receiver']['class'], $before['nativeMethod']['originalName']);
            if ($method === null) { throw new RuntimeException('Genuine admitted method disappeared.'); }
            if ($mode === 'cache-deprecated-flag') { $replacement = ControlledCache::copy($method, ['flags' => new MetadataFlags($method->flags->bits & ~MetadataFlags::DEPRECATED)]); }
            elseif ($mode === 'cache-method-return') {
                if ($method->declaredReturnType === null || $method->returnType === null) { throw new RuntimeException('No witnessed native return to change.'); }
                $replacement = ControlledCache::copy($method, ['declaredReturnType' => ControlledCache::copy($method->declaredReturnType, ['type' => Type::string()]), 'returnType' => ControlledCache::copy($method->returnType, ['type' => Type::string()])]);
            }
            elseif ($mode === 'cache-builtin-since') {
                $attributes = $method->attributes; $changed = 0;
                foreach ($attributes as $index => $attribute) { if ($attribute->name !== 'Deprecated') { continue; } $arguments = $attribute->arguments;
                    foreach ($arguments as $argumentIndex => $argument) { if ($argument->name !== 'since') { continue; } $arguments[$argumentIndex] = ControlledCache::copy($argument, ['valueType' => Type::literalString('8.4')]); ++$changed; }
                    $attributes[$index] = ControlledCache::copy($attribute, ['arguments' => $arguments]);
                }
                if ($changed !== 1) { throw new RuntimeException('No genuine builtin since argument changed.'); }
                $replacement = ControlledCache::copy($method, ['attributes' => $attributes]);
            } else { throw new RuntimeException('Unknown cache control.'); }
            $slots = ControlledCache::replace($context->codebase, $method, $replacement);
            try { $after = $proof->prove($context); } finally { ControlledCache::restore($context->codebase, $method, $slots); }
            if ($after['remove']) { throw new RuntimeException('Changed native method contract was still accepted.'); }
            $restored = $proof->prove($context);
            if (! $restored['remove'] || $restored['nativeMethod'] !== $before['nativeMethod']) { throw new RuntimeException('Exact native method profile was not restored.'); }
            file_put_contents($output, json_encode(['stage' => 'real-native-cache-control', 'role' => $mode, 'file' => $context->file,
                'span' => [$context->issue->annotations[0]->span->start, $context->issue->annotations[0]->span->end], 'method' => $method->identifier->class.'::'.$method->originalName,
                'genuinePositiveBefore' => true, 'slotsChanged' => count($slots), 'deferredAfter' => true, 'exactProfileRestored' => true], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        } : null;
        (new MethodDeprecationCompatibilityPlugin($this->root, $mode === 'preserve', $mode !== 'draft', $this->output, $control))->register($registry);
    }
}
if (! defined('DEPRECATED_PREP_CLASSES_ONLY')) { (new Worker(new Extension(identifier: 'example/method-deprecation-draft', name: 'Method deprecation draft', version: '0.0.1', analyzerPlugins: [new DeprecatedDraftPlugin($root, $mode, $output)])))->run(); }
