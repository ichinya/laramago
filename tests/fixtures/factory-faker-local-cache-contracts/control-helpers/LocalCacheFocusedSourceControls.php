<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

use Mago\Sdk\Analyzer\IssueFilterContext;

/** Four current-file controls, performed only after real native lookups are primed. */
final class LocalCacheFocusedSourceControls
{
    public const EXPECTED_PLANS = 4;

    public static function sourcePlans(IssueFilterContext $context, array $freshProof, string $fixtureRoot): array
    {
        $source = LocalCacheFocusedControlPlan::recipe($context, $freshProof);
        $marker = $source['markerSource']['markers']['markconfigcached'] ?? null;
        self::need(is_array($marker) && is_string($marker['path'] ?? null), 'A real current config marker source is required.');
        $markerPath = self::fixturePath($marker['path'], $fixtureRoot);
        $markerBytes = self::bytes($markerPath);
        self::need(hash('sha256', $markerBytes) === ($marker['hash'] ?? null), 'The primed marker source has changed.');
        $key = 'config_loaded_from_cache';
        self::need(substr_count($markerBytes, $key) === 1, 'The physical config marker key must be unique.');
        $plans = [
            'local-cache/source/missing-current-marker' => ['kind' => 'missing', 'family' => 'missing-current-marker-source',
                'path' => $markerPath, 'expectedBeforeSha256' => hash('sha256', $markerBytes)],
            'local-cache/source/marker-literal-key-drift' => ['kind' => 'bytes', 'family' => 'marker-source-body-or-doc-drift',
                'path' => $markerPath, 'expectedBeforeSha256' => hash('sha256', $markerBytes),
                'changedBytes' => str_replace($key, 'config_loaded_from_cachX', $markerBytes)],
        ];
        $interface = LocalCacheSelectedNativeAliases::select($context->codebase,
            ['kind' => 'method', 'class' => 'Illuminate\\Contracts\\Container\\Container', 'name' => 'make']);
        self::need(isset($interface->parameters[1]) && $interface->parameters[1]->type !== null,
            'The selected interface array type must be actual native metadata.');
        $plans['local-cache/source/interface-array-doc-drift'] = self::typeDocPlan(
            'nominal-interface-current-doc-drift', $interface->parameters[1]->type, $fixtureRoot, 'array', 'aXray');
        $callback = LocalCacheSelectedNativeAliases::select($context->codebase,
            ['kind' => 'method', 'class' => 'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration', 'name' => 'alwaysUse']);
        self::need(isset($callback->parameters[0]) && $callback->parameters[0]->type !== null,
            'The selected typed callback must be actual native metadata.');
        $plans['local-cache/source/typed-callback-doc-drift'] = self::typeDocPlan(
            'callback-effect-current-doc-drift', $callback->parameters[0]->type, $fixtureRoot, 'Closure', 'ClXsure');
        self::need(count($plans) === self::EXPECTED_PLANS, 'Every focused source control is required.');
        ksort($plans, SORT_STRING);
        return $plans;
    }

    /** Restore exact path and bytes in finally, including when the changed proof throws. */
    public static function control(array $plan, string $fixtureRoot, callable $whileChanged): array
    {
        $path = self::fixturePath($plan['path'] ?? '', $fixtureRoot);
        $before = self::bytes($path);
        self::need(hash('sha256', $before) === ($plan['expectedBeforeSha256'] ?? null), 'Source control baseline drifted.');
        $temporary = null;
        try {
            if (($plan['kind'] ?? null) === 'missing') {
                $temporary = $path.'.focused-missing-'.bin2hex(random_bytes(8));
                self::need(!file_exists($temporary) && rename($path, $temporary), 'Cannot isolate the selected fixture source.');
                self::need(!file_exists($path), 'The selected fixture source remains present.');
            } elseif (($plan['kind'] ?? null) === 'bytes') {
                $changed = $plan['changedBytes'] ?? null;
                self::need(is_string($changed) && $changed !== $before && strlen($changed) === strlen($before),
                    'A current-file control must make a meaningful same-length change.');
                self::need(file_put_contents($path, $changed) === strlen($changed)
                    && @hash_file('sha256', $path) === hash('sha256', $changed), 'Cannot bind the changed fixture bytes.');
            } else { throw new \RuntimeException('Unknown focused source-control operation.'); }
            $whileChanged();
        } finally {
            if ($temporary !== null) {
                self::need(!file_exists($path) && file_exists($temporary) && rename($temporary, $path),
                    'Cannot restore the selected fixture source path.');
            } else {
                self::need(file_put_contents($path, $before) === strlen($before), 'Cannot restore selected fixture bytes.');
            }
            self::need(@hash_file('sha256', $path) === hash('sha256', $before), 'Restored source bytes differ.');
        }
        return ['meaningfulChange' => true, 'currentSourceOnly' => true, 'sourcePathAndBytesRestored' => true,
            'beforeSha256' => hash('sha256', $before), 'kind' => $plan['kind']];
    }

    private static function typeDocPlan(string $family, object $metadata, string $root, string $needle, string $replacement): array
    {
        $location = $metadata->location ?? null;
        self::need($metadata->fromDocblock && $location !== null, 'A genuine documented source location is required.');
        $path = self::fixturePath($location->file, $root); $bytes = self::bytes($path);
        $start = $location->span->start; $end = $location->span->end;
        self::need($start >= 0 && $end > $start && $end <= strlen($bytes), 'The selected native doc span is invalid.');
        $tag = substr($bytes, $start, $end - $start);
        self::need(substr_count($tag, $needle) === 1 && strlen($needle) === strlen($replacement),
            'The actual selected closed type span must have one mutation target.');
        $changed = substr_replace($bytes, str_replace($needle, $replacement, $tag), $start, $end - $start);
        return ['kind' => 'bytes', 'family' => $family, 'path' => $path,
            'expectedBeforeSha256' => hash('sha256', $bytes), 'changedBytes' => $changed,
            'actualSelectedNativeTypeSpan' => [$start, $end]];
    }
    private static function fixturePath(string $path, string $root): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/'); $path = str_replace('\\', '/', $path);
        if (str_starts_with($root, '//?/')) { $root = substr($root, 4); }
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        self::need(preg_match('~/compatibility-factory-faker-contracts-[0-9a-f]{16}$~D', DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root) === 1,
            'Source controls require a newly prepared neutral fixture workspace.');
        if (!str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) { $path = $root.'/'.$path; }
        self::need(preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) !== 1, 'Relative traversal is forbidden.');
        $key = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $allowed = $root.'/vendor/laravel/framework/src/Illuminate/';
        $allowedKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($allowed) : $allowed;
        self::need(str_starts_with($key, $allowedKey),
            'Only freshly copied selected framework source may be changed.');
        return $path;
    }
    private static function bytes(string $path): string
    {
        $bytes = @file_get_contents($path); self::need(is_string($bytes), 'The selected current source is absent.'); return $bytes;
    }
    private static function need(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
}
