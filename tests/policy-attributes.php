<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PolicyAttributeDeclarations;
use Ichinya\Laramago\Analyzer\StaticAnalysis\UnknownValue;

require dirname(__DIR__).'/vendor/autoload.php';

$root = sys_get_temp_dir().'/laramago-policy-attributes-'.bin2hex(random_bytes(8));
mkdir($root);
$cases = [
    'imported' => ['#[UsePolicy(Policy::class)] class Post {}', 'Policies\\PostPolicy'],
    'named' => ['#[UsePolicy(class: Policy::class)] class Post {}', 'Policies\\PostPolicy'],
    'literal' => ["#[UsePolicy('\\\\Policies\\\\PostPolicy')] class Post {}", '\\Policies\\PostPolicy'],
    'self' => ['#[UsePolicy(self::class)] class Post {}', 'Models\\Post'],
    'absent' => ['class Post {}', null],
    'inherited' => ['#[UsePolicy(Policy::class)] class Base {} class Post extends Base {}', null],
    'unrelated' => ['#[Other(Policy::class)] class Post {}', null],
    'method-only' => ['class Post { #[UsePolicy(Policy::class)] public function method() {} }', null],
    'duplicate' => ['#[UsePolicy(Policy::class), UsePolicy(Policy::class)] class Post {}', UnknownValue::Value],
    'unknown-constant' => ['#[UsePolicy(POLICY)] class Post {}', UnknownValue::Value],
    'class-constant' => ['#[UsePolicy(Policy::NAME)] class Post {}', UnknownValue::Value],
    'parent' => ['#[UsePolicy(parent::class)] class Post extends Base {}', UnknownValue::Value],
    'wrong-name' => ['#[UsePolicy(policy: Policy::class)] class Post {}', UnknownValue::Value],
    'extra' => ['#[UsePolicy(Policy::class, Policy::class)] class Post {}', UnknownValue::Value],
    'empty' => ["#[UsePolicy('')] class Post {}", UnknownValue::Value],
    'numeric' => ['#[UsePolicy(7)] class Post {}', UnknownValue::Value],
    'conditional' => ['if (unknown()) { #[UsePolicy(Policy::class)] class Post {} }', UnknownValue::Value],
    'duplicate-class' => ['class Post {} class Post {}', UnknownValue::Value],
    'missing-class' => ['class Other {}', UnknownValue::Value],
    'anonymous' => ["\$x = new #[UsePolicy(Policy::class)] class {};", UnknownValue::Value],
    'unreadable' => [null, UnknownValue::Value],
    'invalid-php' => ['class {', UnknownValue::Value],
];
try {
    foreach ($cases as $name => [$body, $expected]) {
        $file = $name.'.php';
        if ($body !== null) {
            file_put_contents(
                $root.'/'.$file,
                "<?php namespace Models; use Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy; use Policies\\PostPolicy as Policy; "
                .$body
                ." throw new \\RuntimeException('Never execute application source');",
            );
        }
        $actual = (new PolicyAttributeDeclarations(new PhpSource($root)))->declaredPolicy($file, '\\models\\POST');
        if ($actual !== $expected) {
            throw new RuntimeException($name.': '.var_export($actual, true));
        }
        echo 'PASS: '.$name."\n";
    }
} finally {
    foreach (glob($root.'/*.php') ?: [] as $file) {
        unlink($file);
    }
    rmdir($root);
}
