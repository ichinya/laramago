<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\SourceLocation;

/** Compare the complete selected keyBy contract, with only its plain constraint population state normalized. */
final class CollectionOffsetNativeProfile
{
    public static function current(array $compact):bool
    {
        if(($compact['symbol']??null)!=='Illuminate\\Support\\Collection::keyBy'){return false;}
        $location=$compact['location']??null;
        if(!$location instanceof SourceLocation){return false;}
        $compact['location']=['file'=>$location->file,'span'=>['start'=>$location->span->start,'end'=>$location->span->end]];
        return self::semantic($compact)===self::semantic(CollectionOffsetKeyByProfile::REFERENCE);
    }
    /** Pure comparison projection; native DTOs are never rewritten. */
    public static function semantic(array $value):array
    {
        foreach($value as $key=>$item){if(is_array($item)){$value[$key]=self::semantic($item);}}
        if(($value['objectClass']??null)==='Mago\\Sdk\\Analyzer\\Type\\GenericParameterType'
            &&in_array($value['name']??null,['TValue','TKey'],true)
            &&($value['definingEntity']['objectClass']??null)==='Mago\\Sdk\\Analyzer\\Type\\GenericParent'
            &&($value['definingEntity']['kind']['name']??null)==='ClassLike'
            &&($value['definingEntity']['name']??null)==='illuminate\\support\\collection'
            &&($value['constraint']['objectClass']??null)==='Mago\\Sdk\\Analyzer\\Type'
            &&count($value['constraint']['atomicTypes']??[])===1
            &&($value['constraint']['flags']['objectClass']??null)==='Mago\\Sdk\\Analyzer\\Type\\TypeFlags'
            &&is_bool($value['constraint']['flags']['populated']??null)){
            $atom=$value['constraint']['atomicTypes'][0];
            $plainMixed=($atom['objectClass']??null)==='Mago\\Sdk\\Analyzer\\Type\\MixedType';
            $plainKey=($atom['objectClass']??null)==='Mago\\Sdk\\Analyzer\\Type\\ScalarType'&&($atom['kind']['name']??null)==='ArrayKey'&&($atom['refinement']??null)===null;
            if($plainMixed||$plainKey){$value['constraint']['flags']['populated']=false;}
        }
        if(!array_is_list($value)){ksort($value);}return $value;
    }
}
