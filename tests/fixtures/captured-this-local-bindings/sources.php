<?php
declare(strict_types=1);
return [
'cases.php'=><<<'PHP'
<?php
namespace LocalCaptureFixture;
class Owner {
    public function localNew(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function localRequire(): Lifecycle { $app=require __DIR__.'/opaque.php'; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function priorStoredCallback(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} $app->booting(function()use($app):void{}); if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function priorGuardedMethod(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'priorHook')){$this->priorHook($app);} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function priorBoth(array $state): Lifecycle { $app=require __DIR__.'/opaque.php'; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} $app->booting(function()use($app):void{}); if(isset($state['first'])&&method_exists($this,'priorHook')){$this->priorHook($app);} if(isset($state['last'])&&method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    /** @return Lifecycle */
    public function documentedLocal(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function formal(Lifecycle $app): void { if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} }
    public function formalIsset(Lifecycle $app,array $state): void { if(isset($state['ready'])&&method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} }
    public function rebound(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} $app=new Lifecycle; if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function alias(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} $alias=&$app; if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function unknownArgument(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} consume($app); if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function capturedByReference(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} $app->booting(function()use(&$app):void{}); if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function returningGuard(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){return new Lifecycle;} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function wrongReturn(): object { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function wrongName(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'anotherHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function staticArrow(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'futureHook')){$app->booting(static fn()=> $this->futureHook($app));} return $app; }
    public function unsupportedProducer(): Lifecycle { $app=makeLifecycle(); if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function knownReferenceMethod(): Lifecycle { $app=new Lifecycle; if(!$app instanceof Lifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'knownHook')){$this->knownHook($app);} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function knownHook(Lifecycle &$app): void { $app=new Lifecycle; }
    public function wrongStorage(): RebindingLifecycle { $app=new RebindingLifecycle; if(!$app instanceof RebindingLifecycle){throw new \UnexpectedValueException('Invalid lifecycle.');} if(method_exists($this,'futureHook')){$app->booting(fn()=> $this->futureHook($app));} return $app; }
    public function wrongArgument(Lifecycle $app): void { if(method_exists($this,'futureHook')){$app->booting('not a closure');} missingFunction(); }
}
PHP,
'contracts.php'=><<<'PHP'
<?php
namespace LocalCaptureFixture;
class Lifecycle {
    protected array $callbacks=[];
    public function booting($callback): void { $this->callbacks[]=$callback; }
}
class RebindingLifecycle {
    protected array $callbacks=[];
    public function booting($callback): void { $callback->bindTo($this); }
}
function consume(object $value): void {}
function makeLifecycle(): Lifecycle { return new Lifecycle; }
PHP,
'opaque.php'=><<<'PHP'
<?php
return new \LocalCaptureFixture\Lifecycle;
PHP,
'bootstrap/app.php'=>"<?php throw new RuntimeException('Application execution is forbidden.');\n",
];
