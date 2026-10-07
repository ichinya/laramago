<?php
declare(strict_types=1);

$cases=[
    'imported-parent'=>['<?php namespace Example;use Illuminate\\Database\\Eloquent\\Model;/** @mixin Model */class Parcel extends Model {}',true],
    'aliased-parent'=>['<?php namespace Example;use Illuminate\\Database\\Eloquent\\Model as Base;/** @mixin Base */class Parcel extends Base {}',true],
    'fully-qualified-parent'=>['<?php namespace Example;/** @mixin \\Illuminate\\Database\\Eloquent\\Model */class Parcel extends \\Illuminate\\Database\\Eloquent\\Model {}',true],
    'unknown-mixin'=>['<?php namespace Example;use Illuminate\\Database\\Eloquent\\Model;/** @mixin SomethingElse */class Parcel extends Model {}',false],
    'generic-mixin'=>['<?php namespace Example;use Illuminate\\Database\\Eloquent\\Model;/** @mixin Model<mixed> */class Parcel extends Model {}',false],
    'union-mixin'=>['<?php namespace Example;use Illuminate\\Database\\Eloquent\\Model;/** @mixin Model|Other */class Parcel extends Model {}',false],
    'intersection-mixin'=>['<?php namespace Example;use Illuminate\\Database\\Eloquent\\Model;/** @mixin Model&Other */class Parcel extends Model {}',false],
    'duplicate-mixin'=>["<?php namespace Example;use Illuminate\\Database\\Eloquent\\Model;/**\n * @mixin Model\n * @mixin Other\n */class Parcel extends Model {}",false],
    'shadowed-import'=>['<?php namespace Example;use Example\\Other as Model;/** @mixin Model */class Parcel extends Model {}',false],
    'same-name-in-namespace'=>['<?php namespace Example;/** @mixin Model */class Parcel extends Model {}',false],
    'inherited-custom-parent'=>['<?php namespace Example;/** @mixin \\Illuminate\\Database\\Eloquent\\Model */class Parcel extends Other {}',false],
];$results=[];
foreach($cases as $name=>[$contents,$positive]){$nodes=Ichinya\Laramago\Analyzer\CollectionOffsetModelMixin::parse($contents);$owner=(new PhpParser\NodeFinder)->findFirst($nodes,static fn(PhpParser\Node $n):bool=>$n instanceof PhpParser\Node\Stmt\Class_);$actual=$owner!==null&&Ichinya\Laramago\Analyzer\CollectionOffsetModelMixin::lexical($owner)!==null;if($actual!==$positive){throw new RuntimeException('Wrong current-source mixin policy '.$name);}$results[$name]=['expected'=>$positive,'actual'=>$actual];}
return ['cases'=>count($cases),'positiveCount'=>3];
