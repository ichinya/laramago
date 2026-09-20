<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Source/catalog evidence for one literal name; runtime validity is a separate contract. */
enum MetadataConfidence
{
    case KnownPositive;
    case CompleteAbsent;
    case Unknown;
}
