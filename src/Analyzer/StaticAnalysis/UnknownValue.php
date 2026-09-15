<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A value that cannot be obtained without executing application code. */
enum UnknownValue
{
    case Value;
}
