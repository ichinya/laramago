<?php
declare(strict_types=1);
$base=<<<'PHP'
<?php
namespace Example\SelectedChunkWarnings;
use Illuminate\Database\Eloquent\Collection;
class Parcel extends \Illuminate\Database\Eloquent\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where('enabled',1);$query->with(['owner']);$total=(clone $query)->count();
        $query->with(['owner'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
PHP;
$change=static function(string $from,string $to)use($base):string{$value=str_replace($from,$to,$base,$count);if($count!==1){throw new RuntimeException('Source mutation must change exactly one selected source occurrence.');}return $value;};
return [
    'selected-read-only-helper'=>['source'=>$base,'positive'=>true],
    'read-only-property-format'=>['source'=>$change('$ids[]=$item->id;','$ids[]=$item->day->format("Y-m");'),'positive'=>true],
    'helper-reference-formal'=>['source'=>$change('readBatch(Collection $batch)','readBatch(Collection &$batch)'),'positive'=>false],
    'helper-reference-iteration'=>['source'=>$change('foreach($batch as $item)','foreach($batch as &$item)'),'positive'=>false],
    'helper-collection-escape'=>['source'=>$change('$ids=[];foreach','unknown($batch);$ids=[];foreach'),'positive'=>false],
    'callback-reassigned'=>['source'=>$change('$cache=$this->readBatch($batch);','$cache=$this->readBatch($batch);$batch=[];'),'positive'=>false],
    'callback-alias'=>['source'=>$change('$cache=$this->readBatch($batch);','$cache=$this->readBatch($batch);$copy=$batch;'),'positive'=>false],
    'callback-reference-formal'=>['source'=>$change('function(Collection $batch)','function(Collection &$batch)'),'positive'=>false],
    'callback-collection-mutator'=>['source'=>$change('$cache=$this->readBatch($batch);','$cache=$this->readBatch($batch);$batch->push(new Parcel);'),'positive'=>false],
    'earlier-unknown-collection-call'=>['source'=>$change('$cache=$this->readBatch($batch);','$this->replace($batch);$cache=$this->readBatch($batch);'),'positive'=>false],
    'loop-receiver-reassigned'=>['source'=>$change('$old=$record->id;','$record=new Parcel;$old=$record->id;'),'positive'=>false],
    'helper-return-contract-changed'=>['source'=>$change('readBatch(Collection $batch):array','readBatch(Collection $batch):object'),'positive'=>false],
    'helper-collection-class-changed'=>['source'=>$change('readBatch(Collection $batch)','readBatch(\\Illuminate\\Support\\Collection $batch)'),'positive'=>false],
    'first-class-selected-call'=>['source'=>$change('$record->adjust();','$callback=$record->adjust(...);'),'positive'=>false],
    'callback-type-replacement'=>['source'=>$change('$cache=$this->readBatch($batch);','/** @var Collection<int,Parcel> $batch */ $cache=$this->readBatch($batch);'),'positive'=>false],
    'loop-receiver-escapes'=>['source'=>$change('$old=$record->id;','unknown($record);$old=$record->id;'),'positive'=>false],
];
