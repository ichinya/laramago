<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\ViewNamespaceCatalog;

require dirname(__DIR__).'/vendor/autoload.php';
$root = sys_get_temp_dir().'/laramago namespace hints '.bin2hex(random_bytes(8));
mkdir($root.'/override', 0777, true);
mkdir($root.'/package views/nested', 0777, true);
file_put_contents($root.'/override/home.blade.php', '<?php throw new Exception("Never execute");');
file_put_contents($root.'/package views/nested/Page.php', 'Present');
$hints = ['billing' => ['override', 'package views']];
$checks = [
    [$hints, 'billing::home', false],
    [$hints, 'billing::nested.Page', false],
    [$hints, 'billing::nested/Page', false],
    [$hints, 'billing::nested.page', false],
    [$hints, 'billing::missing', true],
    [$hints, 'other::missing', false],
    [$hints, 'Billing::missing', false],
    [$hints, 'billing::../missing', false],
    [$hints, 'billing::a::b', false],
    [null, 'billing::missing', false],
    [['billing' => []], 'billing::missing', false],
    [['billing' => ['missing']], 'billing::missing', false],
    [['billing' => ['../outside']], 'billing::missing', false],
    [['billing' => ['package views', "bad\0path"]], 'billing::missing', false],
    [['billing' => ['package views'], 'invalid' => 'override'], 'billing::missing', false],
    [['billing' => ['package views'], 0 => ['override']], 'billing::missing', false],
];
foreach ($checks as [$configuration, $name, $expected]) {
    if ((new ViewNamespaceCatalog($root, $configuration))->missing($name) !== $expected) {
        throw new RuntimeException('Unexpected namespace resolution for '.$name);
    }
}
// The selected directories are checked again at lookup time, including removals.
$catalog = new ViewNamespaceCatalog($root, $hints);
unlink($root.'/override/home.blade.php');
rmdir($root.'/override');
if ($catalog->missing('billing::missing')) {
    throw new RuntimeException('Unreadable selected paths must defer.');
}
unlink($root.'/package views/nested/Page.php');
rmdir($root.'/package views/nested');
rmdir($root.'/package views');
rmdir($root);
echo 'PASS: '.(count($checks) + 1)." namespace catalog checks\n";
