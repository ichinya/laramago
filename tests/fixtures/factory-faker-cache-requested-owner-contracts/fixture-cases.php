<?php
declare(strict_types=1);
return <<<'PHP'
<?php
namespace Example\FakerBoundary;
class ParcelFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    public function definition():array { needInteger('bad');return ['label'=>$this->faker->words(2,true).' Parcel']; }
}
class TimeFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    public function definition():array { $minute=$this->faker->randomElement([0,15,30,45]);$value=sprintf('%02d',$minute);return ['label'=>$value]; }
}
class UnknownFlagFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    public function definition():array { return ['label'=>$this->faker->words(2,false).' Parcel']; }
}
class CustomFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    protected function withFaker(){return new \Faker\Generator();}
    public function definition():array { return ['label'=>$this->faker->words(2,true).' Parcel']; }
}
class ReassignedFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    public function definition():array { $minute=$this->faker->randomElement([0,15,30,45]);$minute=[];$value=sprintf('%02d',$minute);return ['label'=>$value]; }
}
class UnknownElementsFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    public function definition():array { $minute=$this->faker->randomElement([new \stdClass]);$value=sprintf('%02d',$minute);return ['label'=>$value]; }
}
function needInteger(int $value):void {}
PHP;
