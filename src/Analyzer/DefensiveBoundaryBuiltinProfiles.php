<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

/** Selected genuine native builtin contracts; all parameter and conditional profiles stay native. */
final class DefensiveBoundaryBuiltinProfiles
{
    public const HASHES = array('array_is_list' => 'e2bdccf6fbf7b21977db6b8e5d61e581aa6fb97cb6f1b6fc23ab278f3a6b15f5', 'array_slice' => '237251e152954166bd038adec66e4a0f054a994a088e32ab5cc14aea3c4cd550');
}
