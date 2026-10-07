<?php
declare(strict_types=1);
return ['cases.php'=><<<'PHP'
<?php
namespace ResponseGuardFixture;
class Owner {
    private function props(\Laratesto\Testing\LaravelResponse $response): array {
        $page=$response->inertiaPage();
        $props=is_array($page)?($page['props']??null):null;
        if(!is_array($props)){throw new \RuntimeException('The response has no props.');}
        needInteger($props); return $props;
    }
    private function ordinary(\Laratesto\Testing\LaravelResponse $response): array {
        $page=$response->inertiaPage();
        $props=is_array($page)?($page['props']??null):null;
        if(!is_array($props)){needInteger(1);}
        return $props??[];
    }
    private function rebound(\Laratesto\Testing\LaravelResponse $response): array {
        $page=$response->inertiaPage();$page=[];
        $props=is_array($page)?($page['props']??null):null;
        if(!is_array($props)){throw new \RuntimeException('Missing props.');}return $props;
    }
    private function nullable(?\Laratesto\Testing\LaravelResponse $response): array {
        $page=$response->inertiaPage();
        $props=is_array($page)?($page['props']??null):null;
        if(!is_array($props)){throw new \RuntimeException('Missing props.');}return $props;
    }
    /** @param object $response */
    private function documented(\Laratesto\Testing\LaravelResponse $response): array {
        $page=$response->inertiaPage();
        $props=is_array($page)?($page['props']??null):null;
        if(!is_array($props)){throw new \RuntimeException('Missing props.');}return $props;
    }
    private function otherField(\Laratesto\Testing\LaravelResponse $response): array {
        $page=$response->inertiaPage();
        $props=is_array($page)?($page['flash']??null):null;
        if(!is_array($props)){throw new \RuntimeException('Missing props.');}return $props;
    }
}
function needInteger(int $value): void {}
PHP,
'composer.json'=>'{}',
'bootstrap/app.php'=>"<?php throw new RuntimeException('Application execution is forbidden.');\n",
];
