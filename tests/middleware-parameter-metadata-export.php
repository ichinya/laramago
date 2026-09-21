<?php

declare(strict_types=1);

namespace Illuminate\Contracts\Pipeline {
    interface Pipeline {}
}

namespace Illuminate\Contracts\Container {
    interface Container {}
}

namespace Illuminate\Support\Traits {
    trait Conditionable {}

    trait Macroable {}
}

namespace {
    use Ichinya\Laramago\Metadata\MiddlewareParameterMetadataExport;
    use Illuminate\Pipeline\Pipeline;

    require __DIR__.'/../vendor/autoload.php';
    require __DIR__.'/fixtures/analysis/pipeline-native.php.stub';

    final class Item072NativePipeline extends Pipeline
    {
        /** @return array{string, list<string>} */
        public function parseLiteral(string $pipe): array
        {
            /** @var array{string, list<string>} */
            return $this->parsePipeString($pipe);
        }
    }

    $fixture = sys_get_temp_dir().'/laramago-middleware-parameters-'.bin2hex(random_bytes(8));
    if (! mkdir($fixture.'/src', 0777, true) && ! is_dir($fixture.'/src')) {
        throw new RuntimeException('Unable to create fixture.');
    }

    $source = <<<'PHP'
        <?php

        namespace Example;

        use Illuminate\Pipeline\Pipeline as NativePipeline;
        use Vendor\Pipeline;

        (new NativePipeline)->through([
            'auth',
            'auth:',
            'auth:0',
            'auth:a:b,c,,',
            'auth: a, b',
            '',
            'CallablePipe::run',
        ])->via('handle')->pipe('role:admin', 'tail:,');

        (new \Illuminate\Pipeline\Pipeline)->send($request)->through('single:x');
        (new NativePipeline)->through(['array:first'], $ignored);
        (new NativePipeline)->through($dynamic);
        (new NativePipeline)->through(['known:y', $dynamic]);
        (new NativePipeline)->through(['key' => 'known:z']);
        (new NativePipeline)->custom()->through('unknown-chain:value');
        (new Pipeline)->through('custom:value');
        $pipeline->through('variable:value');
        PHP;

    file_put_contents($fixture.'/src/pipeline.php', $source);
    file_put_contents($fixture.'/src/broken.php', '<?php broken(');

    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void {
        $checks++;
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };

    try {
        $export = (new MiddlewareParameterMetadataExport)->export($fixture, [
            'src/pipeline.php',
            'src/./pipeline.php',
        ]);
        $check($export['schemaVersion'] === 1, 'Schema version.');
        $check(
            $export['scope'] === [
                'kind' => 'middleware-parameters',
                'evidence' => 'source-only',
                'consumer' => 'direct-native-pipeline-chain',
                'frameworkContract' => 'required-by-consumer',
                'parseCondition' => 'pipe-is-not-callable',
                'resolution' => 'unresolved-container-target',
            ],
            'Scope states source and dispatch assumptions.',
        );
        $expected = [
            ['auth', 'auth', []],
            ['auth:', 'auth', ['']],
            ['auth:0', 'auth', ['0']],
            ['auth:a:b,c,,', 'auth', ['a:b', 'c', '', '']],
            ['auth: a, b', 'auth', [' a', ' b']],
            ['', '', []],
            ['CallablePipe::run', 'CallablePipe', [':run']],
            ['role:admin', 'role', ['admin']],
            ['tail:,', 'tail', ['', '']],
            ['single:x', 'single', ['x']],
            ['array:first', 'array', ['first']],
        ];
        $actual = array_map(
            static fn (array $reference): array => [
                $reference['raw'],
                $reference['name'],
                $reference['parameters'],
            ],
            $export['references'],
        );
        $check(
            $actual === $expected,
            'Exact first-colon and comma splitting in source order: '.json_encode($actual),
        );
        $check(
            array_column($export['references'], 'consumer') === [
                'through',
                'through',
                'through',
                'through',
                'through',
                'through',
                'through',
                'pipe',
                'pipe',
                'through',
                'through',
            ],
            'Consumer method is retained.',
        );
        $check(
            count($export['unresolvedConsumers']) === 3
            && array_unique(array_column($export['unresolvedConsumers'], 'reason')) === [
                'dynamic-or-unsupported-pipes',
            ],
            'Dynamic, mixed, and keyed pipe collections remain unresolved.',
        );
        $check($export['errors'] === [] && $export['truncated'] === false, 'Clean bounded export.');

        $native = new Item072NativePipeline;
        foreach ($export['references'] as $reference) {
            $check(
                $native->parseLiteral($reference['raw']) === [$reference['name'], $reference['parameters']],
                'Export agrees with native Pipeline parsePipeString.',
            );
            $literal = substr(
                $source,
                $reference['start'],
                $reference['end'] - $reference['start'],
            );
            $check(
                str_starts_with($literal, "'") && str_ends_with($literal, "'"),
                'Literal source byte span is preserved.',
            );
            foreach ($reference['argumentTokens'] as $token) {
                $check(
                    substr(
                        $reference['raw'],
                        $token['decodedStart'],
                        $token['decodedEnd'] - $token['decodedStart'],
                    ) === $token['value'],
                    'Decoded argument token offsets preserve empty and nonempty fields.',
                );
            }
            $check(
                $reference['contentHash'] === hash('sha256', $source)
                && $reference['parseCondition'] === 'pipe-is-not-callable',
                'Every result carries source identity and callable precedence.',
            );
        }

        $bad = (new MiddlewareParameterMetadataExport)->export($fixture, [
            'src/broken.php',
            'src/missing.php',
            '../outside.php',
            'php://filter.php',
            "bad\0.php",
        ]);
        $check(
            array_column($bad['errors'], 'code') === [
                'parse-failure',
                'unreadable-source',
                'invalid-source',
                'invalid-source',
                'invalid-source',
            ],
            'Parse, read, and containment failures are explicit.',
        );
        $check(
            ! str_contains(json_encode($bad, JSON_THROW_ON_ERROR), 'broken('),
            'Parse errors do not expose source text.',
        );

        $invalidList = false;
        try {
            (new MiddlewareParameterMetadataExport)->export($fixture, ['source' => 'src/pipeline.php']);
        } catch (InvalidArgumentException) {
            $invalidList = true;
        }
        $check($invalidList, 'Associative source selections are rejected.');

        file_put_contents($fixture.'/src/large.php', '<?php '.str_repeat(' ', 1024 * 1024));
        $large = (new MiddlewareParameterMetadataExport)->export($fixture, ['src/large.php']);
        $check(
            $large['truncated'] === true
            && $large['truncationReasons'] === ['file-byte-limit']
            && $large['errors'][0]['code'] === 'source-limit',
            'Per-file reads are bounded.',
        );
    } finally {
        foreach (glob($fixture.'/src/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($fixture.'/src');
        rmdir($fixture);
    }

    echo "Middleware parameter metadata export: {$checks} checks passed.\n";
}
