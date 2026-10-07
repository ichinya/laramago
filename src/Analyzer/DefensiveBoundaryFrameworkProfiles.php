<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

/** Supported public library source profiles; no runtime registration is promised. */
final class DefensiveBoundaryFrameworkProfiles
{
    public const PROFILES = array('config-helper' => array('path' => 'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php', 'class' => NULL, 'method' => 'config', 'fingerprint' => 'ecbc279ab1de1aa323a96aa3dd82ecec0eacac8a7a80aac3cb177b95a7588d6b'), 'config-repository' => array('path' => 'vendor/laravel/framework/src/Illuminate/Config/Repository.php', 'class' => 'Illuminate\Config\Repository', 'method' => 'get', 'fingerprint' => 'abf6a6760353ce2234389f7f73bd560151860fb26fe2310f2226b364d8179e6c'), 'request-dispatch' => array('path' => 'vendor/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php', 'class' => 'Illuminate\Support\Traits\Macroable', 'method' => '__call', 'fingerprint' => '2a721785446e796c30a3acc2a562f1773e136e8367fdf7bb548917cff42e2145'), 'request-registration' => array('path' => 'vendor/laravel/framework/src/Illuminate/Foundation/Providers/FoundationServiceProvider.php', 'class' => 'Illuminate\Foundation\Providers\FoundationServiceProvider', 'method' => 'registerRequestValidation', 'fingerprint' => '08f468f01332f68f217ca474dc937eb63c1770a3bbf9b8c69e25794fc549ffff'), 'validation-exception' => array('path' => 'vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php', 'class' => 'Illuminate\Validation\ValidationException', 'method' => 'withMessages', 'fingerprint' => '88c721126e0fec9a1075bc695acd663a55583abf19e40b23ac3d4163701e4e0b'));
}
