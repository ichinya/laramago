<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

/** Selected genuine native builtin contracts; all parameter and conditional profiles stay native. */
final class DefensiveBoundaryBuiltinProfiles
{
    /** Complete genuine profiles from Mago 1.59.0, including current prelude locations. */
    public const HASHES_159 = array('array_is_list' => '83593550049079ec0ba80d3ad95bbce6ce52b44b5e65d45dac820ab9163076e8', 'array_slice' => '57650cefd5717451dac9ff138f9749726443756cd9d7e301c1d1f8c557e8bb08');
    public const HASHES = array('array_is_list' => 'e2bdccf6fbf7b21977db6b8e5d61e581aa6fb97cb6f1b6fc23ab278f3a6b15f5', 'array_slice' => '237251e152954166bd038adec66e4a0f054a994a088e32ab5cc14aea3c4cd550');
}
