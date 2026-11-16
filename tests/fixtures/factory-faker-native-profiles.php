<?php
declare(strict_types=1);

/** Independent closed fixture references; never derived from production acceptance. */
function factoryFakerNativeReference(string $fixture, array $observed): array
{
    $legacy=json_decode(file_get_contents($fixture.'/native-profile-hashes.json'),true,flags:JSON_THROW_ON_ERROR);
    $current=array_replace($legacy,[
        'Faker\\Factory::create'=>'d7a1be86ec00840290d8cb0f0bc14328c9d18855b62845c628ad23582cf237fc',
        'Faker\\Generator::getFormatter'=>'a30e3d7760e3d32291ba553e3f527323938506754ef0e55a4e2a839358423580',
        'selected-receiving'=>'c56bb63bce57ee355e0657980661de87b5b560573264a50f49c049b89b4747e1',
    ]);
    ksort($observed);ksort($legacy);ksort($current);
    if(count($observed)!==18||!in_array($observed,[$legacy,$current],true)){
        throw new RuntimeException('Every genuine context must match one complete closed 18-profile SDK reference.');
    }
    return $observed===$legacy?$legacy:$current;
}
