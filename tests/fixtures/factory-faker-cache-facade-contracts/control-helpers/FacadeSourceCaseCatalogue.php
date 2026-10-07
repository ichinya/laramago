<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

/** Added registered source-priority negatives, independent of the legacy 26 cases. */
final class FacadeSourceCaseCatalogue
{
    public const EXPECTED_CASES = 11;
    public static function cases(): array
    {
        $call = "\\Illuminate\\Support\\Facades\\Cache::extend('example', static function () {});";
        $cache = '\\Illuminate\\Support\\Facades\\Cache'; $facade = '\\Illuminate\\Support\\Facades\\Facade';
        $mutations = [
            'selected-cache-alias-before' => ["\\class_alias(\\stdClass::class, $cache::class);\n".$call,
                'The selected static facade name has a higher-priority alias before the call.'],
            'selected-cache-alias-after' => [$call."\n\\class_alias(\\stdClass::class, $cache::class);",
                'Scan the complete registered source, including aliases after the selected call.'],
            'owning-facade-alias-before' => ["\\class_alias(\\stdClass::class, $facade::class);\n".$call,
                'The physical forwarding owner is aliased.'],
            'owning-facade-alias-after' => [$call."\n\\class_alias(\\stdClass::class, $facade::class);",
                'The forwarding owner alias remains a priority hazard after the selected call.'],
            'cache-swap-priority' => ["$cache::swap(new \\stdClass());\n".$call,
                'swap may replace the resolved cache root with an unrelated object.'],
            'cache-should-receive-priority' => ["$cache::shouldReceive('get');\n".$call,
                'A mock receiving root cannot borrow the untouched default cache binding.'],
            'cache-spy-priority' => ["$cache::spy();\n".$call,
                'spy is an explicit resolved-root override.'],
            'cache-partial-mock-priority' => ["$cache::partialMock();\n".$call,
                'A partially mocked root has higher priority than the default nominal proof.'],
            'facade-application-reset-priority' => ["$facade::setFacadeApplication(new \\Illuminate\\Foundation\\Application());\n".$call,
                'A newly supplied application requires its own certified binding; do not skip a literal setter by framework name.'],
            'descendant-resolved-instance-write' => [
                'final class RootOverride extends '.$cache.' { public static function seed(): void { static::$resolvedInstance[\'cache\'] = new \\stdClass(); } }'
                ."\nRootOverride::seed();\n".$call,
                'A source-visible descendant writes the inherited resolved root before nominal resolution.'],
            'descendant-application-write' => [
                'final class RootOverride extends '.$cache.' { public static function seed(): void { static::$app = new \\Illuminate\\Foundation\\Application(); } }'
                ."\nRootOverride::seed();\n".$call,
                'A direct inherited application slot write must not inherit an earlier binding certificate.'],
        ];
        if (count($mutations) !== self::EXPECTED_CASES) { throw new \RuntimeException('Every focused facade priority negative is required.'); }
        $cases = [];
        foreach ($mutations as $id => [$body, $reason]) {
            $source = "<?php\nnamespace Example\\CacheFacadeRegistration;\n".$body."\n";
            $cases[$id] = ['id' => $id, 'source' => $source, 'sha256' => hash('sha256', $source),
                'reason' => $reason, 'expectedTargetCount' => 2,
                'expectedRoleDecisions' => ['words-concatenation' => 'Keep', 'sprintf-argument' => 'Keep'],
                'requireIndependentNativeCoverage' => true, 'requireWholeResidualDtoEquality' => true];
        }
        return $cases;
    }
}
