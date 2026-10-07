<?php
declare(strict_types=1);
$wrap=static fn(string $body,string $members=''):string=>'<?php namespace Example\\FormatterFixture; class ParcelFactory extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { '.$members.'public function definition():array { '.$body.' } }';
$words='return ["label"=>$this->faker->words(2,true)." Parcel"];';
$elements='$minute=$this->faker->randomElement([0,15,30,45]);$value=sprintf("%02d",$minute);return ["label"=>$value];';
return [
    'words-text'=>$wrap($words),
    'words-true-peer-left'=>$wrap('return ["label"=>"Parcel ".$this->faker->words(2,true)];'),
    'elements-integers'=>$wrap($elements),
    'elements-scalars'=>$wrap(str_replace('[0,15,30,45]','["short",3,1.5,true,null]',$elements)),
    'empty-array-retains-null'=>$wrap(str_replace('[0,15,30,45]','[]',$elements)),
    'unrelated-first-class-callable'=>$wrap('$maker=\\strlen(...);'.$elements),
    'words-false'=>$wrap(str_replace('2,true','2,false',$words)),
    'words-unknown-flag'=>$wrap(str_replace('2,true','2,$flag',$words)),
    'words-no-flag'=>$wrap(str_replace('2,true','2',$words)),
    'words-named-argument'=>$wrap(str_replace('2,true','nb:2,asText:true',$words)),
    'words-unknown-peer'=>$wrap(str_replace('" Parcel"','$suffix',$words)),
    'elements-unknown'=>$wrap(str_replace('[0,15,30,45]','$values',$elements)),
    'elements-object'=>$wrap(str_replace('[0,15,30,45]','[new \\stdClass()]',$elements)),
    'elements-reassigned'=>$wrap(str_replace('$value=sprintf','$minute="other";$value=sprintf',$elements)),
    'elements-escaped'=>$wrap(str_replace('$value=sprintf','consume($minute);$value=sprintf',$elements)),
    'elements-reference'=>$wrap(str_replace('$value=sprintf','$alias=&$minute;$value=sprintf',$elements)),
    'elements-conditional-origin'=>$wrap(str_replace('$minute=$this->faker->randomElement([0,15,30,45]);','if(rand()){ $minute=$this->faker->randomElement([0,15,30,45]); }',$elements)),
    'custom-with-faker'=>$wrap($words,'protected function withFaker(){return new \\Faker\\Generator();}'),
    'custom-constructor'=>$wrap($words,'public function __construct(){}'),
    'custom-property'=>$wrap($words,'protected $faker;'),
    'custom-trait'=>$wrap($words,'use FormatterTrait;'),
    'provider-mutation'=>$wrap('$this->faker->addProvider(new Provider());'.$words),
    'field-replacement'=>$wrap('$this->faker=new \\Faker\\Generator();'.$words),
    'generator-escape'=>$wrap('configure($this->faker);'.$words),
    'unknown-producer'=>$wrap(str_replace('$this->faker->words','$generator->words',$words)),
];
