<?php
namespace Example;
use Illuminate\Support\Collection;
final class Reader {
    /** @param Collection<int|string,mixed> $projects */
    public function positive(Record $record,$projects):string {
        if($record->context_type!=='project'||$record->context_id===null){return '';}
        return $this->name($projects,$record->context_id);
    }
    /** @param Collection<int|string,mixed> $projects */
    public function unknown(UnknownRecord $record,$projects):string { return $this->name($projects,$record->context_id); }
    public function typo(Record $record,Collection $projects):string { return $this->name($projects,$record->context_identifer); }
    public function getter(CustomGetterRecord $record,Collection $projects):string { return $this->name($projects,$record->context_id); }
    public function readDocument(ReadDocumentRecord $record,Collection $projects):string { return $this->name($projects,$record->context_id); }
    public function reference(Record &$record,Collection $projects):string { return $this->name($projects,$record->context_id); }
    public function wrongAssignment(Record $record):void { $record->context_id=new \stdClass; }
    public function independent(Record $record,Collection $projects):string { return $this->name($projects,new \stdClass); }
    public function effectful(Record $record,Collection $projects):string {
        if($record->context_id===null){return '';}
        return $this->name($this->effect($record),$record->context_id);
    }
    public function assignment(Record $record,Collection $projects):string {
        if($record->context_id===null){return '';}
        return $this->name(($projects=null),$record->context_id);
    }
    public function earlierReference(Record $record,Collection $projects):string {
        if($record->context_id===null){return '';}
        return $this->nameRef($projects,$record->context_id);
    }
    public function earlierOutput(Record $record,Collection $projects):string {
        if($record->context_id===null){return '';}
        return $this->nameOut($projects,$record->context_id);
    }
    public function unpack(Record $record,array $projects):string { return $this->name(...[$projects,$record->context_id]); }
    public function named(Record $record,Collection $projects):string { return $this->name(projects:$projects,id:$record->context_id); }
    public function propertyEffect(Record $record):string { return $this->name($this->projects,$record->context_id); }
    public function dynamicLocal(Record $record,string $key):string { return $this->name($$key,$record->context_id); }
    public function independentEarlier(Record $record,string $projects):string {
        if($record->context_type!=='project'||$record->context_id===null){return '';}
        return $this->name($projects,$record->context_id);
    }
    /** @param Collection<int|string,mixed> $projects */
    public function name(Collection $projects,int|string|null $id):string { return (string)$id; }
    public function nameRef(Collection &$projects,int|string|null $id):string { return (string)$id; }
    /** @param-out Collection<int|string,mixed> $projects */
    public function nameOut(Collection &$projects,int|string|null $id):string { return (string)$id; }
    private function effect(Record $record):Collection { $record->context_id=new \stdClass;return new Collection; }
}
