<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

use Mago\Sdk\Analyzer\IssueFilterContext;

/** Four stale-current-source controls in freshly copied physical facade files. */
final class FacadeFocusedSourceControls
{
    public const EXPECTED_PLANS = 4;
    public static function sourcePlans(IssueFilterContext $context, array $freshProof, string $fixtureRoot): array
    {
        $certificate = FacadeFocusedControlPlan::recipe($context, $freshProof);
        $files = []; $windows = DIRECTORY_SEPARATOR === '\\';
        $cacheName = self::fileKey('Cache.php', $windows);
        $facadeName = self::fileKey('Facade.php', $windows);
        $initializerName = self::fileKey('RegisterFacades.php', $windows);
        foreach ($certificate['sourceHashes'] as $path => $hash) {
            $name = self::fileKey(basename(str_replace('\\', '/', $path)), $windows);
            self::need(!isset($files[$name]), 'The selected physical facade files must be unique.');
            $bytes = @file_get_contents($path);
            self::need(is_string($bytes) && hash('sha256', $bytes) === $hash, 'The selected facade source changed.');
            $files[$name] = ['path' => $path, 'hash' => $hash, 'bytes' => $bytes];
        }
        self::need(isset($files[$cacheName], $files[$facadeName], $files[$initializerName]),
            'The genuine Cache, Facade and standard initializer files must all be bound.');
        $cache = $files[$cacheName];
        $plans = ['cache-facade/source/missing-current-accessor' => ['kind' => 'missing',
            'family' => 'missing-current-accessor', 'path' => $cache['path'], 'expectedBeforeSha256' => $cache['hash']]];
        self::need(substr_count($cache['bytes'], "return 'cache';") === 1,
            'Only the current literal cache accessor is supported.');
        $plans['cache-facade/source/literal-accessor-key'] = ['kind' => 'bytes', 'family' => 'literal-accessor-key',
            'path' => $cache['path'], 'expectedBeforeSha256' => $cache['hash'],
            'changedBytes' => str_replace("return 'cache';", "return 'cachX';", $cache['bytes'])];
        $cached = LocalCacheSelectedNativeAliases::select($context->codebase,
            ['kind' => 'property', 'class' => 'Illuminate\\Support\\Facades\\Facade', 'name' => '$cached']);
        self::need($cached->defaultType !== null && $cached->defaultType->inferred && !$cached->defaultType->fromDocblock,
            'The selected literal true default must be actual inferred metadata.');
        $plans['cache-facade/source/cached-literal-default'] = self::spanPlan($files[$facadeName],
            $cached->defaultType->location, 'true', 'truX', 'cached-literal-default');
        $app = LocalCacheSelectedNativeAliases::select($context->codebase,
            ['kind' => 'property', 'class' => 'Illuminate\\Support\\Facades\\Facade', 'name' => '$app']);
        self::need($app->type !== null && $app->type->fromDocblock && !$app->type->inferred,
            'The selected Application|null doc must be actual native metadata.');
        $plans['cache-facade/source/application-doc-domain'] = self::spanPlan($files[$facadeName],
            $app->type->location, 'Foundation', 'FoundatioX', 'application-doc-domain');
        self::need(count($plans) === self::EXPECTED_PLANS, 'All four exact facade source changes are required.');
        ksort($plans, SORT_STRING);
        return $plans;
    }
    private static function fileKey(string $name, bool $windows): string
    {
        return $windows ? strtolower($name) : $name;
    }
    private static function spanPlan(array $file, ?\Mago\Sdk\SourceLocation $location,
        string $needle, string $replacement, string $family): array
    {
        self::need($location !== null && $location->file !== null && strlen($needle) === strlen($replacement),
            'A real finite source span and same-length selected edit are required.');
        $start = $location->span->start; $end = $location->span->end;
        self::need($start >= 0 && $end > $start && $end <= strlen($file['bytes']), 'The actual source span is outside its file.');
        $selected = substr($file['bytes'], $start, $end - $start);
        self::need(substr_count($selected, $needle) === 1, 'The genuine selected type/default span must contain one edit target.');
        return ['kind' => 'bytes', 'family' => $family, 'path' => $file['path'], 'expectedBeforeSha256' => $file['hash'],
            'changedBytes' => substr_replace($file['bytes'], str_replace($needle, $replacement, $selected), $start, $end - $start),
            'actualSelectedNativeSpan' => [$start, $end]];
    }
    private static function need(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
}
