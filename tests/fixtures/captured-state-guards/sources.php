<?php
declare(strict_types=1);
return [
'cases.php'=><<<'PHP'
<?php
namespace CapturedWarningFixture;
class Owner {
    public function threshold(): void { $reads=0; configure(function(Carrier $box)use(&$reads):void{$box->reader=static function(string $key,\Closure $next)use(&$reads){if(++$reads<3){return $next();}return null;};}); }
    public function equality(): void { $reads=0; configure(function(Carrier $box)use(&$reads):void{$box->reader=static function(string $key,\Closure $next)use(&$reads){if(++$reads===1){return $next();}return null;};}); }
    public function nestedInitialization(): void { configure(function(Carrier $box):void{$reads=0;$box->reader=static function(string $key,\Closure $next)use(&$reads){if(++$reads===1){return $next();}return null;};}); }
    public function writtenValue(): void { $cell=null; configure(function(Carrier $box)use(&$cell):void{$box->reader=static function(string $key,\Closure $next)use(&$cell){if(!is_int($cell)){throw new \LogicException('An integer is required.');}return $cell+1;};$box->writer=static function(string $key,mixed $value,int $seconds,\Closure $next)use(&$cell):bool{$cell=$value;return $next($value)===true;};}); requireInteger('bad'); }
    public function byValue(): void { $reads=0; configure(function(Carrier $box)use($reads):void{$box->reader=static function(string $key,\Closure $next)use($reads){if(++$reads<3){return $next();}return null;};}); }
    public function nonStatic(): void { $reads=0; configure(function(Carrier $box)use(&$reads):void{$box->reader=function(string $key,\Closure $next)use(&$reads){if(++$reads<3){return $next();}return null;};}); }
    public function wrongInitial(): void { $reads=1; configure(function(Carrier $box)use(&$reads):void{$box->reader=static function(string $key,\Closure $next)use(&$reads){if(++$reads<3){return $next();}return null;};}); }
    public function noWriter(): void { $cell=null; configure(function(Carrier $box)use(&$cell):void{$box->reader=static function(string $key,\Closure $next)use(&$cell){if(!is_int($cell)){throw new \LogicException('An integer is required.');}return $cell;};}); }
    public function businessBranch(): void { $reads=0; configure(function(Carrier $box)use(&$reads):void{$box->reader=static function(string $key,\Closure $next)use(&$reads){if(++$reads<3){return 'ordinary business';}return null;};}); }
    public function unknownStorage(): void { $reads=0; configure(function(object $box)use(&$reads):void{$box->reader=static function(string $key,\Closure $next)use(&$reads){if(++$reads<3){return $next();}return null;};}); }
    public function mixedField(): void { $reads=0; configure(function(MixedCarrier $box)use(&$reads):void{$box->reader=static function(string $key,\Closure $next)use(&$reads){if(++$reads<3){return $next();}return null;};}); }
}
function visited(): void { $seen=[];$walk=null;$walk=function(object $value)use(&$walk,&$seen):bool{$id=spl_object_id($value);if(isset($seen[$id])){return false;}$seen[$id]=true;return $walk($value);}; }
function visitedNoWrite(): void { $seen=[];$walk=null;$walk=function(object $value)use(&$walk,&$seen):bool{$id=spl_object_id($value);if(isset($seen[$id])){return false;}return $walk($value);}; }
function visitedWrongId(): void { $seen=[];$walk=null;$walk=function(object $value)use(&$walk,&$seen):bool{$id=ordinaryId($value);if(isset($seen[$id])){return false;}$seen[$id]=true;return $walk($value);}; }
PHP,
'contracts.php'=><<<'PHP'
<?php
namespace CapturedWarningFixture;
class Carrier {
    /** @var (Closure(string, Closure(): mixed): mixed)|null */
    public ?\Closure $reader=null;
    /** @var (Closure(string, mixed, int, Closure(mixed): bool): bool)|null */
    public ?\Closure $writer=null;
}
class MixedCarrier { public mixed $reader=null; }
function configure(\Closure $callback): void {}
function requireInteger(int $value): void {}
function ordinaryId(object $value): int { return 1; }
PHP,
];
