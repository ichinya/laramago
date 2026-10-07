<?php
declare(strict_types=1);
$wrap=static fn(string $body):string=>"<?php\nnamespace Example\\CollectionFixture;\nclass Document extends \\Illuminate\\Database\\Eloquent\\Model {}\nclass Suite { public function check(array \$rows): array { ".$body." } }\n";
$local='$records=Document::query()->where("active",1)->orderBy("id")->get();$indexed=$records->keyBy("id");$result=[];foreach($rows as $row){$item=$indexed[$row["id"]];if(!$item instanceof Document){throw new \\LogicException("Missing item.");}$result[]=$item;}return $result;';
$captured='$indexed=Document::query()->where("active",1)->get(["id","label"])->keyBy("id");return Document::query()->get()->map(function(Document $event)use($indexed):array{return ["label"=>$indexed[$event->subject_id]->label??null];})->all();';
return [
    'local'=>$wrap($local),
    'captured'=>$wrap($captured),
    'arbitrary-array'=>$wrap(str_replace('$indexed=$records->keyBy("id")','$indexed=[]',$local)),
    'reassigned-receiver'=>$wrap(str_replace('$result=[];','$indexed=[];$result=[];',$local)),
    'by-reference-iteration'=>$wrap(str_replace('as $row','as &$row',$local)),
    'by-reference-capture'=>$wrap(str_replace('use($indexed)','use(&$indexed)',$captured)),
    'other-key'=>$wrap(str_replace('keyBy("id")','keyBy("label")',$local)),
    'conditional-origin'=>$wrap(str_replace('$indexed=$records->keyBy("id");','if($rows!==[]){$indexed=$records->keyBy("id");}',$local)),
    'unknown-receiver-use'=>$wrap(str_replace('$result=[];','$indexed->replace([]);$result=[];',$local)),
    'offset-write'=>$wrap(str_replace('$item=$indexed[$row["id"]]','$indexed[$row["id"]]=new Document()',$local)),
    'unknown-query-operation'=>$wrap(str_replace('->where("active",1)','->tap(fn($query)=>null)',$local)),
    'unrelated-first-class-callable'=>$wrap('$factory=Document::query(...);'.$local),
];
