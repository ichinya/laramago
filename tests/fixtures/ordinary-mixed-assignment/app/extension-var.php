<?php
declare(strict_types=1);
namespace Observation\OrdinaryStorage\Case34;
use Observation\OrdinaryStorage\{ValueSource,TypedStorage};
use function Observation\OrdinaryStorage\{rawInput,consumeString,rawRows,tupleRows,rawMap};
if(!defined('ORDINARY_STORAGE_PARSE_ONLY')){file_put_contents(__DIR__.'/executed.marker',__FILE__);throw new \RuntimeException('Fixture body execution trap.');}
/** @phpstan-var string $stored */ $stored=rawInput();$stored->missing();
