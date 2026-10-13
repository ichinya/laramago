<?php

declare(strict_types=1);

require $argv[1];
$mode = $argv[2];
if (str_starts_with($mode, 'response-')) {
    /** Corrupt a response only after the capability exchange has succeeded. */
    class FixtureResponseFault extends php_user_filter
    {
        private bool $changed = false;

        public function filter($in, $out, &$consumed, bool $closing): int
        {
            while ($bucket = stream_bucket_make_writeable($in)) {
                $consumed += $bucket->datalen;
                $bytes = $bucket->data;
                if (!$this->changed && strlen($bytes) >= 40 && substr($bytes, 0, 4) === 'MAGO') {
                    $id = unpack('Nhigh/Nlow', substr($bytes, 12, 8));
                    if ($id['high'] === 0 && $id['low'] >= 2) {
                        $bytes = match ($this->params) {
                            'response-magic' => 'XXXX' . substr($bytes, 4),
                            'response-empty' => substr($bytes, 0, 28) . pack('N', 0),
                            'response-version' => substr($bytes, 0, 36) . pack('N', 0xffff0000) . substr($bytes, 40),
                        };
                        $bucket->data = $bytes;
                        $bucket->datalen = strlen($bytes);
                        $this->changed = true;
                    }
                }
                stream_bucket_append($out, $bucket);
            }

            return PSFS_PASS_ON;
        }
    }

    stream_filter_register('fixture.responsefault', FixtureResponseFault::class);
    stream_filter_append(STDOUT, 'fixture.responsefault', STREAM_FILTER_WRITE, $mode);
}
if (in_array($mode, ['exit', 'malformed', 'truncated', 'bad-version'], true)) {
    // Connect and consume the real request so Windows tests reach frame decoding.
    $input = \Mago\Sdk\Internal\Io\InputTransport::connect() ?? STDIN;
    $request = (new \Mago\Sdk\Internal\Protocol\FrameCodec())->read(new \Mago\Sdk\Internal\Io\ResourceReader($input));
    if ($mode === 'exit') {
        exit(7);
    }
    $bytes = match ($mode) {
        'malformed' => str_repeat('X', 32),
        'truncated' => pack('a4nnCCnJJN', 'MAGO', 1, 0, 2, 0, 0, $request->id, 0, 8) . 'abc',
        'bad-version' => pack('a4nnCCnJJN', 'MAGO', 65535, 0, 2, 0, 0, $request->id, 0, 0),
    };
    fwrite(STDOUT, $bytes);
    fflush(STDOUT);
    exit(0);
}
$plugin = new class($mode) implements \Mago\Sdk\Analyzer\Plugin, \Mago\Sdk\Analyzer\BeforeAnalysisHook {
    public function __construct(
        private readonly string $mode,
    ) {}

    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition
    {
        return new \Mago\Sdk\Analyzer\PluginDefinition(
            'fixture/faults',
            'Faults',
            'Synthetic worker failure controls.',
        );
    }

    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void
    {
        $registry->registerBeforeAnalysisHook($this);
    }

    public function beforeAnalysis(\Mago\Sdk\Analyzer\BeforeAnalysisContext $context): void
    {
        if ($this->mode === 'timeout') {
            usleep(2_000_000);
        }
        if ($this->mode === 'throw') {
            throw new RuntimeException('Synthetic worker failure');
        }
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'fixture/faults',
    name: 'Faults',
    version: '1',
    analyzerPlugins: [$plugin],
)))->run();
