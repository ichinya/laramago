<?php
declare(strict_types=1);
namespace Observation\OrdinaryStorage\Case6;
use Observation\OrdinaryStorage\{ValueSource,TypedStorage};
use function Observation\OrdinaryStorage\{rawInput,consumeString,rawRows,tupleRows,rawMap};
if(!defined('ORDINARY_STORAGE_PARSE_ONLY')){file_put_contents(__DIR__.'/executed.marker',__FILE__);throw new \RuntimeException('Fixture body execution trap.');}
final class LocalWriter{public function collect(mixed $input):void{$stored=$input;consumeString($stored);}}
