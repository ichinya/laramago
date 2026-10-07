<?php
declare(strict_types=1);
namespace Observation\OrdinaryStorage;
if(!defined('ORDINARY_STORAGE_PARSE_ONLY')){file_put_contents(__DIR__.'/executed.marker',__FILE__);throw new \RuntimeException('Fixture body execution trap.');}
function rawInput():mixed{return null;}
function consumeString(string $value):void{}
/** @return list<mixed> */
function rawRows():array{return [];}
/** @return list<array{mixed,mixed,mixed}> */
function tupleRows():array{return [];}
/** @return array<string,mixed> */
function rawMap():array{return [];}
final class ValueSource{public function read():mixed{return null;}}
final class TypedStorage{public string $value;public static string $shared;}