<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Advisory overlaps in selected Pest declarations, without asserting runtime execution. */
final class PestDeclaredContextConflicts
{
    /**
     * An unknown class/trait kind makes later registrations uncertain for that file.
     * PHPUnit's default TestCase does not occupy Pest's single custom-class slot.
     *
     * @param callable(string): ('class'|'trait'|null) $classify
     * @return list<array{source: string, sourceHash: string, callStart: int, start: int, end: int, file: string, firstClass: string, secondClass: string}>
     */
    public static function find(PestUsesCatalog $catalog, callable $classify): array
    {
        $declarations = $catalog->declarations();
        if ($declarations === null) {
            return [];
        }

        /** @var array<string, array{first: ?string, uncertain: bool}> $states */
        $states = [];
        $conflicts = [];
        foreach ($declarations as $declaration) {
            foreach ($declaration['files'] as $file) {
                $states[$file] ??= ['first' => null, 'uncertain' => false];
                foreach ($declaration['names'] as $index => $name) {
                    $kind = $classify($name);
                    if ($kind === null) {
                        $states[$file]['uncertain'] = true;
                        continue;
                    }
                    if (
                        $kind !== 'class'
                        || strcasecmp($name, 'PHPUnit\\Framework\\TestCase') === 0
                    ) {
                        continue;
                    }
                    if ($states[$file]['uncertain']) {
                        continue;
                    }
                    if ($states[$file]['first'] === null) {
                        $states[$file]['first'] = $name;
                        continue;
                    }
                    $span = $declaration['nameSpans'][$index];
                    $conflicts[] = [
                        'source' => $declaration['source'],
                        'sourceHash' => $declaration['sourceHash'],
                        'callStart' => $declaration['callStart'],
                        'start' => $span['start'],
                        'end' => $span['end'],
                        'file' => $file,
                        'firstClass' => $states[$file]['first'],
                        'secondClass' => $name,
                    ];
                }
            }
        }

        return $conflicts;
    }
}
