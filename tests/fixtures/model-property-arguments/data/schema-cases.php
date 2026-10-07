<?php
namespace Example;
use Illuminate\Support\Collection;

// These declarations are fixture source only; do not require this file.
                                                          
class CustomAttributeRecord extends SchemaRecord {
    public function getAttribute($key):object { return new \stdClass; }
}
class CustomMagicRecord extends SchemaRecord {
    public function __get($key):object { return new \stdClass; }
}
class ExplicitDateRecord extends SchemaRecord { public const CREATED_AT='context_id'; }
class CustomDatesRecord extends SchemaRecord {
    public function getDates():array { return ['context_id']; }
}
/** @property-read object $context_id */
class DirectionalRecord extends SchemaRecord {}

final class SchemaReader {
    /** @param Collection<int|string,mixed> $projects */
    public function schemaOnly(SchemaRecord $record,$projects):string {
        if($record->context_type!=='project'||$record->context_id===null){return '';}
        return $this->displayName($projects,$record->context_id);
    }
    public function getAttributeOverride(CustomAttributeRecord $record,Collection $projects):string {
        return $this->displayName($projects,$record->context_id);
    }
    public function magicOverride(CustomMagicRecord $record,Collection $projects):string {
        return $this->displayName($projects,$record->context_id);
    }
    public function actualDateDomain(ExplicitDateRecord $record,Collection $projects):string {
        return $this->displayName($projects,$record->context_id);
    }
    public function customDates(CustomDatesRecord $record,Collection $projects):string {
        return $this->displayName($projects,$record->context_id);
    }
    public function directionalRead(DirectionalRecord $record,Collection $projects):string {
        return $this->displayName($projects,$record->context_id);
    }
    /** @param Collection<int|string,mixed> $projects */
    public function displayName(Collection $projects,int|string|null $id):string { return (string)$id; }
}
