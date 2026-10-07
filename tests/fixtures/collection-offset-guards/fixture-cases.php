<?php
declare(strict_types=1);
return <<<'PHP'
<?php
namespace Example\CollectionBoundary;
/** @mixin \Illuminate\Database\Eloquent\Model */
class Parcel extends \Illuminate\Database\Eloquent\Model {}
class Event extends \Illuminate\Database\Eloquent\Model { public ?int $subject_id; }
class Owner {
    /** @param list<array{id:int|null}> $rows */
    public function local(array $rows):array {
        $records=Parcel::query()->where('enabled',1)->orderBy('id')->get();
        $indexed=$records->keyBy('id');$result=[];
        foreach($rows as $row){$item=$indexed[$row['id']];if(!$item instanceof Parcel){throw new \LogicException('Missing parcel.');}$result[]=$item;}
        needInteger('bad');return $result;
    }
    public function captured():array {
        $indexed=Parcel::query()->where('enabled',1)->get(['id','label'])->keyBy('id');
        return Event::query()->get()->map(function(Event $event)use($indexed):array{return ['label'=>$indexed[$event->subject_id]->label??null];})->all();
    }
    /** @param list<array{id:int|null}> $rows */
    public function unsupported(array $rows):array {
        $indexed=[];$result=[];
        foreach($rows as $row){$item=$indexed[$row['id']];if(!$item instanceof Parcel){throw new \LogicException('Missing parcel.');}$result[]=$item;}
        return $result;
    }
}
function needInteger(int $value):void {}
PHP;
