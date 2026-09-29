<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\EnvironmentValueProvider;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Reads the current analysis environment as data for literal auth config values.
 * It never loads dotenv, Composer, configuration or the application.
 */
final class AuthEnvironment
{
    /** Recreated by auth providers for each Mago initialization generation. */
    private ?bool $safeFiles = null;
    private ?bool $native = null;

    public function __construct(
        private readonly string $root,
        private readonly PhpSource $source,
    ) {}

    public function string(?Node $node, ReturnTypeProviderContext $context): ?string
    {
        if (! $node instanceof Node\Expr\FuncCall || ! $node->name instanceof Node\Name) {
            return null;
        }
        $name = $node->name->getAttribute('resolvedName') ?? $node->name;
        if (
            ! $name instanceof Node\Name
            || strcasecmp($name->toString(), 'env') !== 0
            || count($node->args) < 1
            || count($node->args) > 2
            || ! ($this->native ??= EnvironmentValueProvider::nativeSource($context->codebase, $this->source))
        ) {
            return null;
        }
        foreach ($node->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack) {
                return null;
            }
        }
        $key = PhpSource::value(PhpSource::argument($node->args, 0, 'key'));
        if (! is_string($key) || $key === '' || str_contains($key, "\0")) {
            return null;
        }
        $defaultNode = PhpSource::argument($node->args, 1, 'default');
        $default = $defaultNode === null ? null : PhpSource::value($defaultNode);
        if ($defaultNode !== null && ! is_string($default)) {
            return null;
        }
        if (! $this->hasStableFiles()) {
            return null;
        }

        $value = $this->currentValue($key);
        if ($value === null) {
            return $default;
        }

        return $this->normalizedString($value);
    }

    /** Current Laravel repository order: ServerConst, EnvConst, Putenv. */
    private function currentValue(string $key): ?string
    {
        foreach ([$_SERVER, $_ENV] as $values) {
            if (! isset($values[$key]) || ! is_scalar($values[$key])) {
                continue;
            }
            $value = $values[$key];
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return (string) $value;
        }
        $value = function_exists('getenv') && function_exists('putenv') ? getenv($key) : false;

        return is_string($value) ? $value : null;
    }

    private function normalizedString(string $value): ?string
    {
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
            case 'false':
            case '(false)':
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }
        if (preg_match('/\A([\'\"])(.*)\1\z/', $value, $matches)) {
            return $matches[2];
        }

        return $value;
    }

    private function hasStableFiles(): bool
    {
        $root = realpath($this->root);
        if ($root === false) {
            return false;
        }
        $root = str_replace('\\', '/', $root);
        if ($this->safeFiles !== null) {
            return $this->safeFiles;
        }

        // Laravel may bypass config/auth.php entirely when its cache exists.
        if (file_exists($root.'/bootstrap/cache/config.php')) {
            return $this->safeFiles = false;
        }
        if ($this->currentValue('APP_CONFIG_CACHE') !== null) {
            // Its path can be outside the project or use a customized base path.
            return $this->safeFiles = false;
        }
        if (file_exists($root.'/.env')) {
            return $this->safeFiles = false;
        }
        $appEnv = $this->currentValue('APP_ENV');
        if ($appEnv !== null) {
            $appEnv = $this->normalizedString($appEnv);
            if ($appEnv === null) {
                return $this->safeFiles = false;
            }
            if ($appEnv !== '' && file_exists($root.'/.env.'.$appEnv)) {
                return $this->safeFiles = false;
            }
        }
        if ($this->currentValue('APP_BASE_PATH') !== null) {
            return $this->safeFiles = false;
        }

        foreach (['bootstrap', 'app', 'routes', 'config'] as $directory) {
            $path = $root.'/'.$directory;
            if (! is_dir($path)) {
                continue;
            }
            try {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                    $path,
                    \FilesystemIterator::SKIP_DOTS,
                ));
                foreach ($files as $file) {
                    if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                        continue;
                    }
                    $nodes = $this->source->read($file->getPathname());
                    if ($nodes === null || (new NodeFinder)->findFirst($nodes, self::isEnvironmentMutation(...)) !== null) {
                        return $this->safeFiles = false;
                    }
                }
            } catch (\RuntimeException) {
                return $this->safeFiles = false;
            }
        }

        return $this->safeFiles = true;
    }

    private static function isEnvironmentMutation(Node $node): bool
    {
        if (
            $node instanceof Node\Expr\Assign
            || $node instanceof Node\Expr\AssignOp
            || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PreDec
            || $node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PostDec
        ) {
            if (self::containsEnvironmentVariable($node->var)) {
                return true;
            }
        }
        if (
            $node instanceof Node\Expr\AssignRef
            && (self::containsEnvironmentVariable($node->var)
                || self::containsEnvironmentVariable($node->expr))
        ) {
            return true;
        }
        if ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $var) {
                if (self::containsEnvironmentVariable($var)) {
                    return true;
                }
            }
        }
        if ($node instanceof Node\Arg && self::containsEnvironmentVariable($node->value)) {
            // A callee may take the array by reference even without call-site syntax.
            return true;
        }
        if ($node instanceof Node\ClosureUse && $node->byRef && self::containsEnvironmentVariable($node->var)) {
            return true;
        }
        if ($node instanceof Node\Expr\ArrayItem && $node->byRef && self::containsEnvironmentVariable($node->value)) {
            return true;
        }
        if ($node instanceof Node\Stmt\Foreach_ && $node->byRef && self::containsEnvironmentVariable($node->expr)) {
            return true;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->getAttribute('resolvedName') ?? $node->name;
            if ($name instanceof Node\Name && strcasecmp($name->getLast(), 'putenv') === 0) {
                return true;
            }
        }
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            return in_array(strtolower($node->name->toString()), ['useenvironmentpath', 'loadenvironmentfrom'], true);
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $name = $node->class->getAttribute('resolvedName') ?? $node->class;
            if ($name instanceof Node\Name && strcasecmp($name->toString(), 'Illuminate\\Support\\Env') === 0) {
                return ! $node->name instanceof Node\Identifier
                    || in_array(strtolower($node->name->toString()), ['extend', 'enableputenv', 'disableputenv', 'getrepository'], true);
            }
            if ($name instanceof Node\Name && strcasecmp($name->toString(), 'Dotenv\\Dotenv') === 0) {
                return true;
            }
        }

        return false;
    }

    private static function containsEnvironmentVariable(Node $node): bool
    {
        return (new NodeFinder)->findFirst([$node], static fn (Node $part): bool => $part instanceof Node\Expr\Variable
            && in_array($part->name, ['_SERVER', '_ENV'], true)) !== null;
    }
}
