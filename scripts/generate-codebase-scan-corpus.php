<?php

declare(strict_types=1);

// Generated sources and manifests belong outside the package checkout.
$options = getopt('', ['seed:', 'file-counts:', 'output:', 'complexity:']);
$seed = filter_var($options['seed'] ?? 9, FILTER_VALIDATE_INT);
$complexity = filter_var($options['complexity'] ?? 24, FILTER_VALIDATE_INT);
$counts = explode(',', (string) ($options['file-counts'] ?? '768,1152'));
$output = $options['output'] ?? null;
if ($seed === false || $complexity === false || $complexity < 1 || $complexity > 256 || ! is_string($output)) {
    throw new InvalidArgumentException('Require --output, integer --seed and --complexity=1..256.');
}
$template = file_get_contents(__DIR__.'/../tests/fixtures/codebase-scan-benchmark/service.php.stub');
$controls = file_get_contents(__DIR__.'/../tests/fixtures/codebase-scan-benchmark/controls.php.stub');
foreach ($counts as $count) {
    if (! ctype_digit($count) || (int) $count < 2 || (int) $count > 4096) {
        throw new InvalidArgumentException('File counts must be 2..4096 (including the control file).');
    }
    $root = rtrim($output, '/\\').'/'.$count;
    if (file_exists($root)) {
        throw new RuntimeException('Refusing to overwrite an existing corpus: '.$root);
    }
    mkdir($root.'/app', 0777, true);
    $files = [];
    for ($i = 0; $i < (int) $count - 1; ++$i) {
        $id = sprintf('%04d', $i);
        $methods = '';
        for ($j = 0; $j < $complexity; ++$j) {
            $tag = substr(hash('sha256', $seed.':'.$i.':'.$j), 0, 16);
            $methods .= <<<PHP
                /** @return array{label: string, count: int, values: list<string>} */
                public function transform{$j}(string \$json, string \$raw): array
                {
                    \$input = json_decode(\$json, true);
                    \$label = is_array(\$input) ? (\$input['label'] ?? null) : null;
                    if (!is_string(\$label)) { \$label = '{$tag}'; }
                    \$count = filter_var(\$raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    if (!is_int(\$count)) { \$count = {$j}; }
                    \$values = array_map(static fn(string \$value): string => strtoupper(\$value), [\$label, '{$tag}']);
                    return ['label' => \$label, 'count' => \$count, 'values' => \$values];
                }

            PHP;
        }
        $source = str_replace(['{{ID}}', '{{METHODS}}'], [$id, $methods], $template);
        $path = 'app/Service'.$id.'.php';
        file_put_contents($root.'/'.$path, $source);
        $files[$path] = hash('sha256', $source);
    }
    file_put_contents($root.'/app/Controls.php', $controls);
    $files['app/Controls.php'] = hash('sha256', $controls);
    ksort($files);
    $manifest = ['format' => 1, 'seed' => $seed, 'complexity' => $complexity, 'php_files' => (int) $count,
        'files' => $files, 'controls' => ['positive' => ['unsafeReturn', 'unsafeArgument'], 'negative' => ['safeReturn', 'safeArgument']]];
    file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo $root.' '.hash_file('sha256', $root.'/manifest.json')."\n";
}
