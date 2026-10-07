<?php
declare(strict_types=1);
namespace Observation\OrdinaryStorage\Case28;
use Observation\OrdinaryStorage\{ValueSource,TypedStorage};
use function Observation\OrdinaryStorage\{rawInput,consumeString,rawRows,tupleRows,rawMap};
if(!defined('ORDINARY_STORAGE_PARSE_ONLY')){file_put_contents(__DIR__.'/executed.marker',__FILE__);throw new \RuntimeException('Fixture body execution trap.');}
TypedStorage::$shared=rawInput();consumeString(TypedStorage::$shared);
