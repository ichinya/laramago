<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

/** Validate the common versioned envelope without interpreting kind-specific metadata. */
final class MetadataEnvelope
{
    /** @param array<string, mixed> $metadata
     * @return list<array{code: string, file: null, message: string}>
     */
    public static function errors(array $metadata): array
    {
        $metadata += [
            'schemaVersion' => null,
            'projectRoot' => null,
            'scope' => null,
            'errors' => null,
            'truncated' => null,
        ];
        $version = $metadata['schemaVersion'];
        if ($version !== 1) {
            return (
                is_int($version)
                    ? [[
                        'code' => 'unsupported-metadata-version',
                        'file' => null,
                        'message' => 'Static metadata schema version is not supported.',
                    ]] : self::invalid()
            );
        }
        $scope = $metadata['scope'];
        $errors = $metadata['errors'];
        if (!is_array($scope)) {
            return self::invalid();
        }
        $scope += ['kind' => null, 'evidence' => null];
        $checks = [
            self::nonemptyString($metadata['projectRoot']),
            self::nonemptyString($scope['kind']),
            self::nonemptyString($scope['evidence']),
            is_array($errors),
            is_bool($metadata['truncated']),
        ];
        if (in_array(false, $checks, true) || !array_is_list($errors)) {
            return self::invalid();
        }
        foreach ($errors as $error) {
            if (!self::validError($error)) {
                return self::invalid();
            }
        }

        return [];
    }

    private static function nonemptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private static function validError(mixed $error): bool
    {
        if (!is_array($error)) {
            return false;
        }
        $hasFile = array_key_exists('file', $error);
        $error += ['code' => null, 'message' => null, 'file' => null];
        $file = $error['file'];

        return !in_array(
            false,
            [
                self::nonemptyString($error['code']),
                is_string($error['message']),
                $hasFile,
                $file === null || is_string($file),
            ],
            true,
        );
    }

    /** @return list<array{code: string, file: null, message: string}> */
    private static function invalid(): array
    {
        return [[
            'code' => 'invalid-metadata-envelope',
            'file' => null,
            'message' => 'Static metadata exporter returned an invalid versioned envelope.',
        ]];
    }
}
