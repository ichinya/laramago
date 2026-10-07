<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use PhpParser\{Node,NodeFinder};
require_once __DIR__.'/FactoryFakerRegistrationReceivers.php';

/** Compact offline declaration coverage, independent of analyzer source-path inclusion. */
final class FactoryFakerInstalledSources
{
    private array $hashes=[];
    private array $files=[];
    private array $hazards=[];
    private array $directories=[];
    private int $bytes=0;
    private bool $complete=false;
    private string $stage='current-composer-root';
    private array $defaultFormatterFiles=[];
    private array $defaultHelperFiles=[];
    private readonly string $root;
    private readonly FactoryFakerRegistrationReceivers $registrationReceivers;
    public function __construct(string $root,?FactoryFakerRegistrationReceivers $receivers=null){$this->root=str_replace('\\','/',realpath($root)?:$root);$this->registrationReceivers=$receivers??new FactoryFakerRegistrationReceivers($this->root);}

    public function discover():array
    {
        $this->hashes=$this->files=$this->hazards=$this->directories=$this->defaultFormatterFiles=$this->defaultHelperFiles=[];$this->bytes=0;$this->complete=false;
        try{
            $root=$this->json($this->root.'/composer.json');
            $vendor=$root['config']['vendor-dir']??'vendor';
            if(!is_string($vendor)||!self::relative($vendor)){throw new \RuntimeException('Unknown vendor directory declaration.');}
            $vendor=$this->directory($this->root.'/'.$vendor);
            $this->stage='current-installed-package-declarations';
            $installed=$this->json($vendor.'/composer/installed.json');$packages=$installed['packages']??$installed;
            if(!is_array($packages)||!array_is_list($packages)||count($packages)>2048){throw new \RuntimeException('Unknown installed package catalogue.');}
            $ignored=$this->list($root['extra']['laravel']['dont-discover']??[]);
            foreach($packages as $entry){if(!is_array($entry)||!is_string($entry['name']??null)){throw new \RuntimeException('Unknown package entry.');}$ignored=[...$ignored,...$this->list($entry['extra']['laravel']['dont-discover']??[])];}
            $maps=[['base'=>$this->root,'autoload'=>$this->autoload($root['autoload']??[]),'root'=>true]];
            if(isset($root['autoload-dev'])){$maps[]=['base'=>$this->root,'autoload'=>$this->autoload($root['autoload-dev']),'root'=>true];}
            $allMaps=$maps;
            $seen=[];
            foreach($packages as $entry){
                $name=$entry['name'];if(isset($seen[$name])||preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D',$name)!==1){throw new \RuntimeException('Ambiguous package name.');}$seen[$name]=true;
                $autoload=$this->autoload($entry['autoload']??[]);$providers=$this->list($entry['extra']['laravel']['providers']??[]);
                $allMaps[]=['entry'=>$entry,'autoload'=>$autoload,'root'=>false];
                $selected=$autoload['files']!==[]||$autoload['classmap']!==[]||$providers!==[]||self::fakerPrefix($autoload['psr-4']);
                if(!$selected){continue;}
                $install=$entry['install-path']??'../'.$name;
                if(!is_string($install)||str_contains($install,"\0")||str_contains($install,'\\')||str_starts_with($install,'/')||preg_match('~^[a-z]:~i',$install)){throw new \RuntimeException('Unknown selected package installation.');}
                $base=$this->directory($vendor.'/composer/'.$install);
                if(!self::inside($base,$vendor)){throw new \RuntimeException('Selected package is outside the installed vendor directory.');}
                $declared=$this->json($base.'/composer.json');
                if(($declared['name']??null)!==$name||$this->autoload($declared['autoload']??[])!==$autoload||$this->list($declared['extra']['laravel']['providers']??[])!==$providers){throw new \RuntimeException('Selected installed declarations disagree with package source metadata.');}
                if($name==='fakerphp/faker'){$this->defaultFormatterFiles=['faker\\provider\\lorem::words'=>$this->file($base.'/src/Faker/Provider/Lorem.php'),'faker\\provider\\base::randomelement'=>$this->file($base.'/src/Faker/Provider/Base.php'),'faker\\provider\\base::randomelements'=>$this->file($base.'/src/Faker/Provider/Base.php')];}
                if($name==='laravel/framework'&&in_array('src/Illuminate/Foundation/helpers.php',$autoload['files'],true)){$this->defaultHelperFiles[$this->file($base.'/src/Illuminate/Foundation/helpers.php')]=true;}
                $maps[]=['base'=>$base,'autoload'=>$autoload,'root'=>false];
                if(!in_array('*',$ignored,true)&&!in_array($name,$ignored,true)){
                    foreach($providers as $provider){$path=$this->resolveClass($base,$autoload,$provider);$this->add($path,'composer-discovered-provider',$provider);}
                }
            }
            $providerList=$this->root.'/bootstrap/providers.php';
            if(is_file($providerList)){
                $this->stage='current-static-bootstrap-provider-declarations';$nodes=Source::parse($this->read($this->file($providerList)));$returns=[];
                foreach($nodes as $node){if($node instanceof Node\Stmt\Declare_||$node instanceof Node\Stmt\Use_||$node instanceof Node\Stmt\GroupUse||$node instanceof Node\Stmt\Nop){continue;}if(!$node instanceof Node\Stmt\Return_||!$node->expr instanceof Node\Expr\Array_){throw new \RuntimeException('Unknown static provider list.');}$returns[]=$node;}
                if(count($returns)!==1||count($returns[0]->expr->items)>128){throw new \RuntimeException('Unknown static provider catalogue.');}
                foreach($returns[0]->expr->items as $item){if($item===null||$item->key!==null||$item->byRef||$item->unpack||!$item->value instanceof Node\Expr\ClassConstFetch||!$item->value->class instanceof Node\Name||!$item->value->name instanceof Node\Identifier||strcasecmp($item->value->name->name,'class')!==0){throw new \RuntimeException('Unknown provider class declaration.');}$provider=$item->value->class->toString();$this->add($this->resolveStaticProvider($provider,$allMaps,$vendor),'composer-discovered-provider',$provider);}
                unset($nodes,$returns,$node,$item);
            }
            $this->stage='current-declared-provider-and-autoload-sources';
            foreach($maps as $map){
                foreach($map['autoload']['files'] as $relative){if(!self::relative($relative)){throw new \RuntimeException('Unknown Composer autoload file.');}$this->add($this->file($map['base'].'/'.$relative),'composer-autoload-file');}
                foreach($map['autoload']['classmap'] as $relative){
                    if(!self::relative($relative,true)||strpbrk($relative,'*?[]{}')!==false){throw new \RuntimeException('Unknown selected classmap source.');}$path=$map['base'].'/'.$relative;
                    if(is_dir($path)){$this->formatterDirectory($this->directory($path));}
                    elseif(is_file($path)&&strtolower(pathinfo($path,PATHINFO_EXTENSION))==='php'){$this->formatterFile($this->file($path));}
                    else{throw new \RuntimeException('Unknown selected classmap file.');}
                }
                foreach($map['autoload']['psr-4'] as $prefix=>$paths){
                    if(!str_starts_with(strtolower($prefix),'faker\\')&&!str_starts_with('faker\\provider\\',strtolower($prefix))){continue;}
                    foreach($paths as $relative){if(!self::relative($relative,true)){throw new \RuntimeException('Unknown Faker provider source root.');}$this->formatterDirectory($this->directory($map['base'].'/'.$relative));}
                }
            }
            $inspected=[];
            while(true){
                $pending=array_filter($this->files,static fn(array $selected,string $path):bool=>($inspected[$path]??null)!==$selected,ARRAY_FILTER_USE_BOTH);
                if($pending===[]){break;}
                foreach($pending as $path=>$selected){$inspected[$path]=$selected;$this->inspect($path,$selected,$allMaps,$vendor);}
            }
            $this->complete=true;$this->stage='current-registered-source-declarations';
        }catch(\Throwable){$this->complete=false;}
        return $this->certificate();
    }

    public function current():bool
    {
        if(!$this->complete){return false;}
        foreach($this->hashes as $path=>$hash){if(!is_file($path)||@hash_file('sha256',$path)!==$hash){return false;}}
        foreach($this->directories as $directory=>$expected){$members=[];try{foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS)) as $entry){if($entry->isFile()&&strtolower($entry->getExtension())==='php'){$members[]=$this->file($entry->getPathname());}}}catch(\Throwable){return false;}sort($members);if($members!==$expected){return false;}}
        return true;
    }
    public function certificate():array{return ['complete'=>$this->complete,'current'=>$this->current(),'stage'=>$this->stage,'declaredSources'=>count($this->files),'hashedInputs'=>count($this->hashes),'sourceBytes'=>$this->bytes,'hazards'=>array_keys($this->hazards),'applicationExecuted'=>false,'effectiveRuntimeContainerClaimed'=>false];}

    private function inspect(string $path,array $selected,array $maps,string $vendor):void
    {
        $bytes=$this->read($path);$nodes=Source::parse($bytes);$finder=new NodeFinder;
        $this->registrationReceivers->observeSource($nodes,$path);
        if($selected['class']!==null){
            $classes=$finder->find($nodes,static fn(Node $n):bool=>$n instanceof Node\Stmt\ClassLike&&strcasecmp($n->namespacedName?->toString()??'',$selected['class'])===0);
            if(count($classes)!==1||($selected['kind']==='composer-discovered-provider'&&!$classes[0] instanceof Node\Stmt\Class_)){throw new \RuntimeException('Declared provider has no unique physical source.');}
            $provider=$classes[0];$dependencies=[];
            if($provider instanceof Node\Stmt\Class_&&$provider->extends!==null){$dependencies[]=$provider->extends->toString();}
            foreach($provider->stmts as $statement){if($statement instanceof Node\Stmt\TraitUse){foreach($statement->traits as $trait){$dependencies[]=$trait->toString();}}}
            foreach($dependencies as $dependency){$this->add($this->resolveStaticProvider($dependency,$maps,$vendor),'composer-registered-provider-dependency',$dependency);}
            foreach($provider->getProperties() as $property){foreach($property->props as $item){if(in_array($item->name->name,['bindings','singletons'],true)){$this->bindingMap($item->default,$item->name->name);}}}
        }
        foreach($finder->findInstanceOf($nodes,Node\Stmt\ClassLike::class) as $class){$name=$class->namespacedName?->toString()??'';
            if(!str_starts_with(strtolower($name),'faker\\provider\\')){continue;}
            foreach($class->getMethods() as $method){$symbol=strtolower($name).'::'.strtolower($method->name->name);
                if(in_array(strtolower($method->name->name),['words','randomelement','randomelements'],true)&&($this->defaultFormatterFiles[$symbol]??null)!==$path){$this->hazards['custom-formatter-declaration']=true;}
            }
        }
        // Exact selected formatter bodies and their delegation profiles are independently
        // bound by FactoryFakerContracts. A different method in a formatter declaration
        // is not a registered provider entrypoint or an autoload-file registration.
        if($selected['kind']==='composer-faker-formatter-declaration'){return;}
        foreach($finder->find($nodes,static fn(Node $n):bool=>$n instanceof Node\Expr\MethodCall||$n instanceof Node\Expr\StaticCall) as $call){
            if($call->isFirstClassCallable()){continue;}if(!$call->name instanceof Node\Identifier){if(self::mentionsFaker($nodes)){$this->hazards['unknown-selected-registration']=true;}continue;}$name=strtolower($call->name->name);
            if($name==='addprovider'){$this->hazards['provider-registration']=true;}
            if(!in_array($name,['bind','singleton','scoped','instance','extend','alias','bindif','singletonif','swap'],true)){continue;}
            if($name==='singleton'&&isset($this->defaultHelperFiles[$path])&&self::defaultLocaleHelperRegistration($nodes,$call,$bytes)){continue;}
            if($this->registrationReceivers->unrelated($call,$nodes,$path)){continue;}
            $keys=$this->registrationReceivers->binding($call,$path,$nodes);
            if($keys!==null){foreach($keys as $key){if(strcasecmp($key,'Faker\\Generator')===0){$this->hazards['generator-binding']=true;}}continue;}
            $targets=$name==='alias'?[$call->args[0]??null,$call->args[1]??null]:[$call->args[0]??null];
            foreach($targets as $arg){
                if(!$arg instanceof Node\Arg||$arg->byRef||$arg->unpack||$arg->name!==null){if($name==='alias'||self::mentionsFaker($nodes)){$this->hazards['unknown-selected-binding-target']=true;}continue;}
                $target=$arg->value;$literal=null;
                if($target instanceof Node\Expr\ClassConstFetch&&$target->class instanceof Node\Name&&$target->name instanceof Node\Identifier&&strcasecmp($target->name->name,'class')===0){$literal=$target->class->toString();}
                elseif($target instanceof Node\Scalar\String_){$literal=ltrim($target->value,'\\');}
                if($literal!==null&&strcasecmp($literal,'Faker\\Generator')===0){$this->hazards['generator-binding']=true;}
                elseif($literal===null&&($name==='alias'||self::mentionsFaker($nodes))){$this->hazards['unknown-selected-binding-target']=true;}
            }
        }
        unset($nodes,$finder,$classes,$class,$method,$call,$arg,$target,$bytes,$keys,$key);
    }
    /** Laravel Application::register also consumes declared provider binding arrays. */
    private function bindingMap(?Node\Expr $value,string $kind):void
    {
        if(!$value instanceof Node\Expr\Array_){$this->hazards['unknown-selected-binding-map']=true;return;}
        foreach($value->items as $item){
            if($item===null||$item->byRef||$item->unpack){$this->hazards['unknown-selected-binding-map']=true;continue;}
            $target=$item->key;
            $integerKey=$target instanceof Node\Scalar\Int_||($target instanceof Node\Scalar\String_&&(string)(int)$target->value===$target->value);
            if($kind==='singletons'&&($target===null||$integerKey)){$target=$item->value;}
            $this->registrationReceivers->declaredBinding($target);
            if($target instanceof Node\Scalar\Int_){continue;}
            $literal=$target instanceof Node\Scalar\String_?ltrim($target->value,'\\'):null;
            if($target instanceof Node\Expr\ClassConstFetch&&$target->class instanceof Node\Name&&$target->name instanceof Node\Identifier&&strcasecmp($target->name->name,'class')===0){$literal=$target->class->toString();}
            if($literal===null){$this->hazards['unknown-selected-binding-map']=true;}
            elseif(strcasecmp($literal,'Faker\\Generator')===0){$this->hazards['generator-binding']=true;}
        }
    }
    /** The measured standard fake() source registers only its literal Generator:locale key. */
    private static function defaultLocaleHelperRegistration(array $nodes,Node $call,string $bytes):bool
    {
        $span=Source::span($call);$functions=(new NodeFinder)->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\Function_&&strcasecmp($node->namespacedName?->toString()??'','fake')===0&&Source::span($node)[0]<=$span[0]&&Source::span($node)[1]>=$span[1]);
        if(count($functions)!==1||!$call instanceof Node\Expr\MethodCall||!$call->var instanceof Node\Expr\FuncCall||!$call->var->name instanceof Node\Name||strcasecmp($call->var->name->toString(),'app')!==0||$call->var->args!==[]){return false;}
        $function=$functions[0];$own=Source::span($function);
        if(hash('sha256',substr($bytes,$own[0],$own[1]-$own[0]))!=='e5b3aa47b433bfc9b55ff05f4f433c04cad09bc80da96c6d10349f956ffd2580'){return false;}
        $args=$call->args;if(count($args)!==2){return false;}foreach($args as $arg){if(!$arg instanceof Node\Arg||$arg->byRef||$arg->unpack||$arg->name!==null){return false;}}
        return $args[0]->value instanceof Node\Expr\Variable&&$args[0]->value->name==='abstract'&&$args[1]->value instanceof Node\Expr\ArrowFunction;
    }
    private static function mentionsFaker(array $nodes):bool{return (new NodeFinder)->findFirst($nodes,static fn(Node $n):bool=>$n instanceof Node\Name&&str_starts_with(strtolower($n->toString()),'faker\\')||$n instanceof Node\Scalar\String_&&str_starts_with(strtolower(ltrim($n->value,'\\')),'faker\\'))!==null;}
    private function resolveClass(string $base,array $autoload,string $class):string
    {
        if(preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$~D',$class)!==1){throw new \RuntimeException('Unknown discovered provider name.');}
        $matches=[];
        foreach($autoload['psr-4'] as $prefix=>$paths){if(!str_starts_with($class,$prefix)){continue;}foreach($paths as $relative){$path=$base.'/'.$relative.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path)){$matches[]=$this->file($path);}}}
        $matches=array_values(array_unique($matches));if(count($matches)!==1){throw new \RuntimeException('A selected provider needs one declared PSR-4 physical source.');}return $matches[0];
    }
    private function resolveStaticProvider(string $class,array $maps,string $vendor):string
    {
        $found=[];
        foreach($maps as $map){
            $matching=array_filter(array_keys($map['autoload']['psr-4']),static fn(string $prefix):bool=>str_starts_with($class,$prefix));if($matching===[]){continue;}
            if($map['root']){$base=$map['base'];}
            else{
                $entry=$map['entry'];$install=$entry['install-path']??'../'.$entry['name'];if(!is_string($install)||str_contains($install,"\0")||str_contains($install,'\\')||str_starts_with($install,'/')||preg_match('~^[a-z]:~i',$install)){throw new \RuntimeException('Unknown static provider installation.');}$base=$this->directory($vendor.'/composer/'.$install);if(!self::inside($base,$vendor)){throw new \RuntimeException('Foreign static provider installation.');}
                $declared=$this->json($base.'/composer.json');if(($declared['name']??null)!==$entry['name']||$this->autoload($declared['autoload']??[])!==$map['autoload']){throw new \RuntimeException('Static provider mapping disagrees with installed source.');}
            }
            foreach($matching as $prefix){foreach($map['autoload']['psr-4'][$prefix] as $relative){$path=$base.'/'.$relative.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path)){$found[]=$this->file($path);}}}
        }
        $found=array_values(array_unique($found));if(count($found)!==1){throw new \RuntimeException('Static provider needs one current Composer source mapping.');}return $found[0];
    }
    private function autoload(mixed $value):array
    {
        if(!is_array($value)){throw new \RuntimeException('Unknown Composer autoload declaration.');}
        $files=$this->list($value['files']??[]);$classmap=$this->list($value['classmap']??[]);$psr=$value['psr-4']??[];if(!is_array($psr)){throw new \RuntimeException('Unknown PSR-4 catalogue.');}$result=[];
        $psr0=$value['psr-0']??[];if(!is_array($psr0)){throw new \RuntimeException('Unknown PSR-0 catalogue.');}foreach($psr0 as $prefix=>$paths){if(!is_string($prefix)||$prefix===''||str_starts_with(strtolower($prefix),'faker')){throw new \RuntimeException('Unsupported Faker PSR-0 source catalogue.');}}
        foreach($psr as $prefix=>$paths){if(!is_string($prefix)||$prefix===''||!str_ends_with($prefix,'\\')){throw new \RuntimeException('Unknown PSR-4 prefix.');}$paths=is_string($paths)?[$paths]:$this->list($paths);foreach($paths as $path){if(!self::relative($path,true)){throw new \RuntimeException('Unknown PSR-4 source directory.');}}$result[$prefix]=$paths;}
        ksort($result);return ['files'=>$files,'classmap'=>$classmap,'psr-4'=>$result];
    }
    private function formatterDirectory(string $directory):void
    {
        $members=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS)) as $entry){if(!$entry->isFile()||strtolower($entry->getExtension())!=='php'){continue;}$members[]=$this->file($entry->getPathname());if(count($members)>4096){throw new \RuntimeException('Formatter source directory budget exceeded.');}}
        sort($members);$this->directories[$directory]=$members;foreach($members as $path){$this->formatterFile($path);}
    }
    private function formatterFile(string $path):void{$bytes=$this->read($path);if(preg_match('/function\s+(?:words|randomElement|randomElements)\s*\(/i',$bytes)===1){$this->add($path,'composer-faker-formatter-declaration');}}
    private static function fakerPrefix(array $psr):bool{foreach($psr as $prefix=>$paths){if(str_starts_with(strtolower($prefix),'faker\\')||str_starts_with('faker\\provider\\',strtolower($prefix))){return true;}}return false;}
    private function list(mixed $value):array{if(!is_array($value)||!array_is_list($value)||count($value)>4096||count(array_filter($value,'is_string'))!==count($value)){throw new \RuntimeException('Unknown static declaration list.');}return $value;}
    private function json(string $path):array{$value=json_decode($this->read($this->file($path)),true,flags:JSON_THROW_ON_ERROR);if(!is_array($value)){throw new \RuntimeException('Unknown JSON metadata.');}return $value;}
    private function add(string $path,string $kind,?string $class=null):void
    {
        if(count($this->files)>4096){throw new \RuntimeException('Declared source budget exceeded.');}
        $existing=$this->files[$path]??null;
        if($existing!==null){
            if($existing['class']!==null&&$class!==null&&$existing['class']!==$class){throw new \RuntimeException('Ambiguous declared source.');}
            $class??=$existing['class'];$rank=['composer-discovered-provider'=>4,'composer-registered-provider-dependency'=>3,'composer-autoload-file'=>2,'composer-faker-formatter-declaration'=>1];
            if($rank[$existing['kind']]>$rank[$kind]){$kind=$existing['kind'];}
        }
        $this->files[$path]=['kind'=>$kind,'class'=>$class];$this->read($path);
    }
    private function read(string $path):string{$bytes=@file_get_contents($path);if($bytes===false||strlen($bytes)>4000000){throw new \RuntimeException('Selected input unavailable or oversized.');}if(!isset($this->hashes[$path])){$this->bytes+=strlen($bytes);if($this->bytes>32000000){throw new \RuntimeException('Registered source budget exceeded.');}$this->hashes[$path]=hash('sha256',$bytes);}elseif(hash('sha256',$bytes)!==$this->hashes[$path]){throw new \RuntimeException('Registered input changed while reading.');}return $bytes;}
    private function file(string $path):string{$path=realpath($path);if($path===false||!is_file($path)||!self::inside(str_replace('\\','/',$path),$this->root)){throw new \RuntimeException('Selected file is outside the current project.');}return str_replace('\\','/',$path);}
    private function directory(string $path):string{$path=realpath($path);if($path===false||!is_dir($path)||!self::inside(str_replace('\\','/',$path),$this->root)){throw new \RuntimeException('Selected directory is outside the current project.');}return str_replace('\\','/',$path);}
    private static function inside(string $path,string $parent):bool{return strtolower($path)===strtolower($parent)||str_starts_with(strtolower($path),rtrim(strtolower($parent),'/').'/');}
    private static function relative(string $path,bool $empty=false):bool{return ($empty||$path!=='')&&!str_contains($path,"\0")&&!str_contains($path,'\\')&&!str_starts_with($path,'/')&&preg_match('~^[a-z]:|(?:^|/)\.\.(?:/|$)~i',$path)!==1;}
}
