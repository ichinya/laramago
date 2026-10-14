<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

/** Export cast identities and supported builtin arguments without secret parameters. */
final class CollectionCasts
{
    public static function sanitize(CollectionState $state, mixed $loader, array $casts): array
    {
        $safe = [];
        foreach ($casts as $attribute => $cast) {
            if (!CollectionPaths::identifier($attribute) || !is_string($cast) || strlen($cast) > 1024) {
                throw new RuntimeException('Invalid model casts.');
            }
            $safe[$attribute] = self::declaration($cast);
            $class = explode(':', $cast, limit: 2)[0];
            CollectionAutoloadSources::capture($state, $loader, ltrim($class, characters: '\\'));
        }
        ksort($safe, SORT_STRING);

        return $safe;
    }

    private static function declaration(string $cast): string
    {
        [$base, $arguments] = array_pad(explode(':', $cast, limit: 2), length: 2, value: null);
        if ($base === '' || preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\z/', $base) !== 1) {
            throw new RuntimeException('Invalid cast declaration.');
        }
        if ($base === 'decimal' && is_string($arguments) && preg_match('/\A[0-9]{1,3}\z/', $arguments) === 1) {
            return $base . ':' . $arguments;
        }
        if ($base === 'encrypted' && in_array($arguments, ['array', 'collection', 'object', 'json'], strict: true)) {
            return $base . ':' . $arguments;
        }

        return $base;
    }
}
