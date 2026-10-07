<?php
declare(strict_types=1);
// Package autoload and source parsing only. No SDK context/hook/positive DTO or source body execution.
$package=dirname(__DIR__,3);require $package.'/vendor/autoload.php';
$root=__DIR__.'/data';$source=new \Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource($root);
$schema=new \Ichinya\Laramago\Analyzer\StaticAnalysis\SchemaIndex($source);$schema->load();$column=$schema->column('records','context_id');
if($column===null||$column->type!=='int'||!$column->nullable||!$source->isCurrent()||$source->warnings!==[]){throw new RuntimeException('The genuine Migration base must produce the source int|null column.');}
$bodies=(new ReflectionClass(\Ichinya\Laramago\Analyzer\StaticAnalysis\BoundedPrimitiveSchemaContract::class))->getConstant('BODIES');$checks=['genuine migration source int|null'=>true];
$files=['Model.php','Concerns/HasAttributes.php','Concerns/HasTimestamps.php'];$seen=[];
foreach($files as $relative){$file=$root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/'.$relative;$bytes=file_get_contents($file);
    $nodes=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($bytes);
    foreach((new \PhpParser\NodeFinder)->findInstanceOf($nodes,\PhpParser\Node\Stmt\ClassMethod::class) as $method){$name=$method->name->name;if(!isset($bodies[$name])){continue;}
        $start=strpos($bytes,'{',$method->name->getEndFilePos());$tokens='';
        foreach(token_get_all('<?php '.substr($bytes,$start+1,$method->getEndFilePos()-$start-1)) as $token){if(!is_array($token)){$tokens.=$token;}elseif(!in_array($token[0],[T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){$tokens.=$token[1];}}
        if($tokens!==$bodies[$name]){throw new RuntimeException('Standard source body grammar differs: '.$name.'; actual '.$tokens);}
        $checks['standard source body '.$name]=true;$seen[$name]=true;
    }
}
if(count($seen)!==count($bodies)){throw new RuntimeException('Every bounded standard method grammar must have primary source coverage.');}
return $checks;
