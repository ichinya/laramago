<?php
declare(strict_types=1);
$sourceProofs=Ichinya\Laramago\Analyzer\XmlCardinalitySource::compile(Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource::parse($sources['cases.php']));
if(count($sourceProofs)!==1||array_values($sourceProofs)[0]['scope']['name']!=='checked'){throw new RuntimeException('One invented XML source positive and six source near misses are required.');}
return ['cases'=>7,'positiveCount'=>1,'negativeMethods'=>['ordinary','rebound','foreign','mutating','twoChildren','sideEffect']];
