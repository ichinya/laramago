<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeDirectiveReferenceChecker;
use Ichinya\Laramago\Analyzer\StaticAnalysis\BladeNativeDirectiveCatalog;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago blade directives '.bin2hex(random_bytes(8));
$compiler = $workspace.'/vendor/laravel/framework/src/Illuminate/View/Compilers';
mkdir($compiler.'/Concerns', 0777, true);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: '.$message."\n";
};

file_put_contents($compiler.'/Compiler.php', '<?php namespace Illuminate\\View\\Compilers; abstract class Compiler {}');

file_put_contents($compiler.'/BladeCompiler.php', <<<'PHP'
    <?php
    namespace Illuminate\View\Compilers;
    class BladeCompiler extends Compiler {
        use Concerns\CompilesConditionals;
        protected function compileStatement($match) {}
    }
    PHP);
file_put_contents($compiler.'/Concerns/CompilesConditionals.php', <<<'PHP'
    <?php
    namespace Illuminate\View\Compilers\Concerns;
    trait CompilesConditionals {
        protected function compileIf($expression) {}
        protected function compileEndif() {}
    }
    PHP);

$native = new BladeNativeDirectiveCatalog($workspace);
$assert(
    $native->names() !== null
    && in_array('if', $native->names(), true)
    && in_array('endif', $native->names(), true)
    && in_array('verbatim', $native->names(), true),
    'native methods and raw blocks come from installed compiler source',
);

$customVendor = $workspace.'/dependencies with spaces/laravel/framework/src/Illuminate/View/Compilers';
mkdir($customVendor.'/Concerns', 0777, true);
copy($compiler.'/BladeCompiler.php', $customVendor.'/BladeCompiler.php');
copy($compiler.'/Compiler.php', $customVendor.'/Compiler.php');
copy($compiler.'/Concerns/CompilesConditionals.php', $customVendor.'/Concerns/CompilesConditionals.php');
file_put_contents($workspace.'/composer.json', json_encode(['config' => [
    'vendor-dir' => 'dependencies with spaces',
]], JSON_THROW_ON_ERROR));
$assert(
    (new BladeNativeDirectiveCatalog($workspace))->names() === $native->names(),
    'relative Composer vendor-dir with spaces locates native source',
);
file_put_contents($workspace.'/composer.json', json_encode(['config' => [
    'vendor-dir' => $workspace.'/dependencies with spaces',
]], JSON_THROW_ON_ERROR));
$assert(
    (new BladeNativeDirectiveCatalog($workspace))->names() === $native->names(),
    'absolute Composer vendor-dir locates native source',
);

$custom = array_fill_keys(['staff', 'unlessstaff', 'elsestaff', 'endstaff', 'if'], true);
$checker = new BladeDirectiveReferenceChecker($native->names(), $custom, true);
$source = <<<'BLADE'
    @IF($ok)
    @staff @unlessstaff @elsestaff @endstaff
    @missing('nested(@ghost)')
    @@missing
    {{-- @ghost --}}
    <!-- @ghost -->
    @verbatim @ghost @endverbatim
    @verbatimExtra @ghost @endverbatim
    @php echo '@ghost'; @endphp
    @phpExtra @ghost @endphp
    <?php echo '@ghost'; ?>
    <?php $value = "?> @ghost"; ?>
    {{ '@ghost' }} {!! "@ghost" !!}
    <x-card :value="'@ghost'">@missing</x-card>
    mail@example.com
    @endif @missing
    BLADE;
$references = $checker->check($source);
$assert($references !== null && $checker->isComplete(), 'complete effective registry scans bounded Blade source');
$names = array_map(static fn ($reference): string => $reference->name, $references);
$statuses = array_map(static fn ($reference): string => $reference->status, $references);
$assert(
    $names === ['IF', 'staff', 'unlessstaff', 'elsestaff', 'endstaff', 'missing', 'missing', 'endif', 'missing']
    && $statuses === ['known', 'known', 'known', 'known', 'known', 'missing', 'missing', 'known', 'missing'],
    'native, custom, if expansions and missing references classify without opaque or escaped text',
);
foreach ($references as $reference) {
    $assert(
        substr($source, $reference->start, $reference->end - $reference->start) === '@'.$reference->name,
        'reference '.$reference->name.' retains its original source byte span',
    );
}

$incomplete = new BladeDirectiveReferenceChecker($native->names(), $custom, false);
$partial = $incomplete->check('@if @missing');
$assert(
    $partial !== null && $partial[0]->status === 'known' && $partial[1]->status === 'unknown',
    'an incomplete effective registry defers only absent names',
);
$numeric = new BladeDirectiveReferenceChecker($native->names(), [123 => true], true);
$numericReferences = $numeric->check('@123');
$assert(
    $numericReferences !== null && $numericReferences[0]->status === 'known' && $numeric->isComplete(),
    'numeric custom names survive PHP array-key coercion',
);
$noNative = new BladeDirectiveReferenceChecker(null, $custom, true);
$missingNative = $noNative->check('@missing');
$assert(
    $missingNative !== null && $missingNative[0]->status === 'unknown',
    'absent native source defers missing-name conclusions',
);
$assert(
    $checker->check('@if(') === null && $checker->check('@verbatim @if') === null,
    'malformed expressions and unclosed raw blocks defer the document',
);
$assert(
    $checker->check('@php $x="@endphp @notADirective"; @endphp') === null
    && $checker->check('@php /* @endphp @notADirective */ @endphp') === null,
    'raw PHP terminators inside strings and comments defer instead of exposing fake directives',
);
$safeRawPhp = $checker->check('@php $x="safe"; @endphp @missing');
$assert(
    $safeRawPhp !== null && count($safeRawPhp) === 1 && $safeRawPhp[0]->status === 'missing',
    'a closed raw PHP block preserves subsequent directive checks',
);
$spacedTag = $checker->check('< x-card @notADirective="x">@missing</ x-card>');
$assert(
    $spacedTag !== null && count($spacedTag) === 1 && $spacedTag[0]->name === 'missing',
    'whitespace-allowed component tag markup remains opaque',
);
$colonTag = $checker->check('<x:card @notADirective="x">@missing</x:card>');
$assert(
    $colonTag !== null && count($colonTag) === 1 && $colonTag[0]->name === 'missing',
    'colon component tag markup remains opaque',
);
$suffix = $checker->check('@verbatimExtra @ghost');
$assert(
    $suffix !== null && $suffix[0]->status === 'missing' && $suffix[1]->status === 'missing',
    'a raw-block prefix without a terminator remains ordinary source',
);
$assert($checker->check(str_repeat('x', 262145)) === null, 'oversized source defers the document');

file_put_contents($customVendor.'/BladeCompiler.php', <<<'PHP'
    <?php
    namespace Illuminate\View\Compilers;
    class BladeCompiler extends Compiler {
        use UnknownCompilerTrait;
    }
    PHP);
$assert(
    (new BladeNativeDirectiveCatalog($workspace))->names() === null,
    'unresolved compiler traits prevent a complete native catalog',
);
