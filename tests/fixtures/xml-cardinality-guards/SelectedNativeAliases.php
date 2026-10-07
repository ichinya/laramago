<?php
declare(strict_types=1);
namespace Example\XmlCardinalityFixture;

/** Test-only changes to populated aliases of one genuinely observed native declaration. */
final class SelectedNativeAliases
{
    public static function copy(object $native,array $changes):object
    {
        $reflection=new \ReflectionClass($native);$arguments=[];
        foreach($reflection->getConstructor()->getParameters() as $formal){$name=$formal->getName();$arguments[]=array_key_exists($name,$changes)?$changes[$name]:$native->$name;}
        return $reflection->newInstanceArgs($arguments);
    }
    public static function control(object $codebase,object $native,object $replacement,array $bindings,callable $whileChanged):array
    {
        if($native::class!==$replacement::class||$native==$replacement||$bindings===[]){throw new \RuntimeException('A cache control requires a meaningful selected native change.');}
        $populated=0;
        foreach($bindings as $binding){
            $aliases=match($binding['kind']){
                'method'=>[$codebase->getMethod($binding['class'],$binding['name']),$codebase->getDeclaringMethod($binding['class'],$binding['name'])],
                'property'=>[$codebase->getDeclaringProperty($binding['class'],$binding['name']),$codebase->getProperty($binding['class'],$binding['name'])],
                'class'=>[$codebase->getClass($binding['name'])],
                'function'=>[$codebase->getFunction($binding['name'])],
                default=>throw new \RuntimeException('Unknown selected native alias operation.'),
            };
            $present=0;foreach($aliases as $alias){if($alias===null){continue;}if($alias::class!==$native::class||$alias!=$native){throw new \RuntimeException('Populated genuine aliases differ from the selected native declaration.');}$present++;$populated++;}
            if($present===0){throw new \RuntimeException('A selected native alias binding has no current declaration.');}
        }
        // Both public APIs are populated before snapshotting. Nothing is obtained from guessed cache keys.
        $cache=(new \ReflectionProperty($codebase,'cache'))->getValue($codebase);$values=$cache->values;$relations=$cache->relations;$slots=[];
        foreach($values as $operation=>$entries){foreach($entries as $key=>$entry){if(is_object($entry)&&$entry::class===$native::class&&$entry==$native){$cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key];}}}
        try{if($slots===[]){throw new \RuntimeException('No equivalent populated native cache slot changed.');}$whileChanged();}
        finally{$cache->values=$values;$cache->relations=$relations;}
        return ['actualPopulatedAliases'=>$populated,'slotsChanged'=>count($slots),'actualSlots'=>$slots,'meaningfulChange'=>true,'cacheValuesAndRelationsRestored'=>true];
    }
}
