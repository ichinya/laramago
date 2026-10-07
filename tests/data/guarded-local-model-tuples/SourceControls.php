<?php
declare(strict_types=1);
namespace Example\LocalModelTests;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;

/** Physical negative changes only; the genuine context supplies every origin. */
final class LocalModelSourceControls
{
    /** @return array<string,array{file:string,original:string,replacement:string,sameLength:bool}> */
    public static function plan(string $root,IssueFilterContext $context,array $contracts,array $sourceHashes):array
    {
        $plans=[];
        $file=self::path($root,$context->file,$sourceHashes);$caller=null;
        foreach($contracts as $contract){
            $metadata=$contract['metadata'];
            if($metadata instanceof FunctionLikeMetadata&&$metadata->identifier->name==='guardedLocal'){$caller=$metadata;break;}
        }
        if($caller===null){throw new \RuntimeException('Selected genuine guardedLocal caller is absent.');}
        self::method($plans,'local model rebound before append',$root,$sourceHashes,$caller,
            '                if($denied){','if($denied){$invoice=null;',true);
        self::method($plans,'local reference alias before append',$root,$sourceHashes,$caller,
            '$this->inspect($invoice);','$alias=&$invoice;',true);
        self::add($plans,'selected helper reference formal',$file,
            'private function inspect(?Invoice $invoice)','private function inspect(?Invoice&$invoice)',true);
        self::method($plans,'selected row container captured by value',$root,$sourceHashes,$caller,
            'use($denied,&$rows)','use($denied, $rows)',true);
        self::method($plans,'selected projected local rebound',$root,$sourceHashes,$caller,
            'foreach($rows as [$value]){$this->acceptInvoice($value);}',
            'foreach($rows as [$value]){$value=null;$this->acceptInvoice($value);}',false);
        self::method($plans,'selected container reference escape',$root,$sourceHashes,$caller,
            '        try {','$alias=&$rows;try {',false);
        $comment='/** Current invoice model. */';
        self::add($plans,'stronger current concrete model template',$file,
            $comment,'/** @template TInvoice */',true);

        $builder=self::classFile($root,$contracts,$sourceHashes,'Illuminate\\Database\\Eloquent\\Builder');
        $queries=self::classFile($root,$contracts,$sourceHashes,'Illuminate\\Database\\Concerns\\BuildsQueries');
        $collection=self::classFile($root,$contracts,$sourceHashes,'Illuminate\\Database\\Eloquent\\Collection');
        $support=self::classFile($root,$contracts,$sourceHashes,'Illuminate\\Support\\Collection');
        $model=self::classFile($root,$contracts,$sourceHashes,'Illuminate\\Database\\Eloquent\\Model');
        self::add($plans,'current Builder generic owner changed',$builder,'@template TModel of','@template TOther of',true);
        self::add($plans,'current Builder trait template binding changed',$builder,'BuildsQueries<TModel>','BuildsQueries<TOther>',true);
        self::add($plans,'current Builder ordinary mixin changed',$builder,
            '@mixin \\Illuminate\\Database\\Query\\Builder','@mixin \\Illuminate\\Database\\Query\\Unknown',true);
        self::add($plans,'current first return doc changed',$queries,'@return TValue|null','@return TRogue|null',true);
        self::add($plans,'stronger current first return doc',$queries,'@return TValue|null','@phpstan-return TValue|null',false);
        self::add($plans,'current collection parent import changed',$collection,
            'use Illuminate\\Support\\Collection as BaseCollection;','use Illuminate\\Support\\Allocation as BaseCollection;',true);
        self::add($plans,'current collection extends generic changed',$collection,
            'Collection<TKey, TModel>','Collection<TKey, TOther>',true);
        self::add($plans,'current Support Collection acquires parent',$support,
            'class Collection','class Collection extends \\stdClass',false);
        self::add($plans,'current Support trait binding changed',$support,
            'use EnumeratesValues, Macroable, TransformsToResourceCollection;',
            'use EnumeratesValues, Otherable, TransformsToResourceCollection;',true);
        self::add($plans,'current default builder class changed',$model,'Builder::class','Unknown::class',true);
        self::add($plans,'current default collection class changed',$model,'Collection::class','Allocation::class',true);

        foreach($contracts as $contract){
            $metadata=$contract['metadata'];
            if(!$metadata instanceof FunctionLikeMetadata){continue;}
            $owner=$metadata->identifier->class;$name=$metadata->identifier->name;
            if(strcasecmp($owner??'','Illuminate\\Database\\Eloquent\\Builder')===0&&$name==='where'){
                self::method($plans,'current where body changed',$root,$sourceHashes,$metadata,
                    'return $this;','return null;',true);
            }
            if(strcasecmp($owner??'','Illuminate\\Database\\Eloquent\\Builder')===0&&$name==='get'){
                self::method($plans,'current get body changed',$root,$sourceHashes,$metadata,
                    'applyAfterQueryCallbacks','applyOtherQueryCallbacks',true);
            }
            if(strcasecmp($owner??'','Illuminate\\Database\\Concerns\\BuildsQueries')===0&&$name==='first'){
                self::method($plans,'current first body changed',$root,$sourceHashes,$metadata,'limit(1)','limit(2)',true);
                self::defaultOperator($plans,'current first initializer operator changed',$root,$sourceHashes,$metadata,0);
            }
            if(strcasecmp($owner??'','Illuminate\\Support\\Collection')===0&&$name==='first'){
                self::method($plans,'current Support first body changed',$root,$sourceHashes,$metadata,
                    'Arr::first','Arr::other',true);
                self::defaultOperator($plans,'current collection callback initializer operator changed',$root,$sourceHashes,$metadata,0);
            }
        }
        $needle='use BuildsQueries, \\Illuminate\\Support\\Traits\\ForwardsCalls, \\Illuminate\\Database\\Eloquent\\Concerns\\QueriesRelationships;';
        self::add($plans,'current selected first trait adaptation',$builder,$needle,
            rtrim($needle,';').' { first as selectedFirst; }',false);
        foreach(['current where body changed','current get body changed','current first body changed',
            'current Support first body changed','current first initializer operator changed',
            'current collection callback initializer operator changed'] as $required){
            if(!isset($plans[$required])){throw new \RuntimeException('Required genuine physical control is absent: '.$required);}
        }
        return $plans;
    }
    private static function classFile(string $root,array $contracts,array $hashes,string $name):string
    {
        foreach($contracts as $contract){
            $binding=$contract['binding'];
            if($binding['kind']==='class'&&strcasecmp($binding['name'],$name)===0){
                return self::path($root,$contract['metadata']->nameLocation?->file??$contract['metadata']->location->file,$hashes);
            }
        }
        throw new \RuntimeException('Current physical class binding is absent: '.$name);
    }
    private static function method(array &$plans,string $label,string $root,array $hashes,FunctionLikeMetadata $metadata,
        string $needle,string $replacement,bool $sameLength):void
    {
        $file=self::path($root,$metadata->location->file,$hashes);
        $contents=file_get_contents($file);$span=$metadata->location->span;
        $segment=substr($contents,$span->start,$span->end-$span->start);
        if(substr_count($segment,$needle)!==1){throw new \RuntimeException('Selected physical method needle is absent or ambiguous: '.$label);}
        self::add($plans,$label,$file,$needle,$replacement,$sameLength,$span->start,$span->end);
    }
    private static function defaultOperator(array &$plans,string $label,string $root,array $hashes,FunctionLikeMetadata $metadata,int $index):void
    {
        $default=$metadata->parameters[$index]->defaultType??null;
        if($default===null){throw new \RuntimeException('Selected native default is absent: '.$label);}
        $file=self::path($root,$default->location->file,$hashes);$contents=file_get_contents($file);
        $span=$default->location->span;$segment=substr($contents,$span->start,$span->end-$span->start);
        if(!str_starts_with($segment,'=')){throw new \RuntimeException('Actual native initializer does not begin with equals: '.$label);}
        $replacement=substr_replace($contents,'>',$span->start,1);
        self::record($plans,$label,$file,$contents,$replacement,true);
    }
    private static function add(array &$plans,string $label,string $file,string $needle,string $replacement,bool $sameLength,
        ?int $start=null,?int $end=null):void
    {
        $contents=file_get_contents($file);$start??=0;$end??=strlen($contents);
        $segment=substr($contents,$start,$end-$start);
        if(substr_count($segment,$needle)!==1){throw new \RuntimeException('Physical source control is absent or ambiguous: '.$label);}
        if($sameLength){
            if(strlen($replacement)>strlen($needle)){throw new \RuntimeException('Expected equal-length physical control cannot fit: '.$label);}
            $replacement=str_pad($replacement,strlen($needle),' ');
        }
        $changed=substr_replace($contents,str_replace($needle,$replacement,$segment),$start,$end-$start);
        self::record($plans,$label,$file,$contents,$changed,$sameLength);
    }
    private static function record(array &$plans,string $label,string $file,string $original,string $replacement,bool $sameLength):void
    {
        if(isset($plans[$label])||$replacement===$original||$sameLength&&strlen($replacement)!==strlen($original)){
            throw new \RuntimeException('Physical source control is duplicate or meaningless: '.$label);
        }
        $plans[$label]=['file'=>$file,'original'=>$original,'replacement'=>$replacement,'sameLength'=>$sameLength];
    }
    private static function path(string $root,?string $file,array $hashes):string
    {
        if($file===null||$file===''){throw new \RuntimeException('A current physical source file is required.');}
        $resolved=realpath($file);if($resolved===false){$resolved=realpath($root.'/'.$file);}
        $base=realpath($root);$normalize=static function(string $path):string{
            $path=str_replace('\\','/',$path);return PHP_OS_FAMILY==='Windows'?strtolower($path):$path;
        };
        if($resolved===false||$base===false||!str_starts_with($normalize($resolved),$normalize($base).'/')){
            throw new \RuntimeException('Source controls must remain inside the invented fixture workspace.');
        }
        $held=null;foreach($hashes as $path=>$hash){if($normalize($path)===$normalize($resolved)){$held=$hash;break;}}
        if($held===null||hash_file('sha256',$resolved)!==$held){throw new \RuntimeException('Selected physical source is not held by the genuine certificate.');}
        return $resolved;
    }
}
