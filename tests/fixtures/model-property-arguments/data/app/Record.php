<?php
namespace Example;
/** @mixin \Illuminate\Database\Eloquent\Model */
class Record extends \Illuminate\Database\Eloquent\Model {
    protected $table='records';
    protected $casts=['context_id'=>'integer'];
}
class CustomGetterRecord extends Record {
    public function getContextIdAttribute(mixed $value): object { return new \stdClass; }
}
/** @property-read object $context_id */
class ReadDocumentRecord extends Record {}
class UnknownRecord extends Record { protected $table='unknown_records'; protected $casts=[]; }
class SchemaRecord extends Record { protected $casts=['zeta'=>'string','alpha'=>'integer']; }
