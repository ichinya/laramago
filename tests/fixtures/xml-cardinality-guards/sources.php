<?php
declare(strict_types=1);
$positive=<<<'PHP'
    public static function checked(string $xml): array {
        $document=simplexml_load_string($xml,\SimpleXMLElement::class,LIBXML_NONET);
        if($document===false){throw new \RuntimeException('Invalid XML.');}
        $files=$document->xpath('/root//file');
        if(!is_array($files)||$files===[]){throw new \RuntimeException('No files.');}
        $names=[];
        foreach($files as $file){
            $name=(string)$file['name'];
            if($name===''||isset($names[$name])||count($file->metrics)!==1){throw new \RuntimeException('Invalid metrics.');}
            $names[$name]=true;
            $observedChildren=count($file->metrics);
        }
        needInteger('bad');return [];
    }
PHP;
$ordinary=str_replace(['function checked','throw new \RuntimeException(\'Invalid metrics.\');'],['function ordinary','needInteger(1);'],$positive);
$rebound=str_replace(['function checked',"        \$files=\$document->xpath"],['function rebound',"        \$document=new \\SimpleXMLElement('<root/>');\n        \$files=\$document->xpath"],$positive);
$foreign=str_replace(['function checked','\SimpleXMLElement::class'],['function foreign','\stdClass::class'],$positive);
$mutating=str_replace(['function checked',"            \$name=(string)\$file['name'];"],['function mutating',"            change(\$file);\n            \$name=(string)\$file['name'];"],$positive);
$differentCount=str_replace(['function checked','count($file->metrics)!==1'],['function twoChildren','count($file->metrics)!==2'],$positive);
$sideEffect=str_replace(['function checked',"\$name===''||isset(\$names[\$name])||"],['function sideEffect',"change(\$file)||"],$positive);
return ['cases.php'=>"<?php\nnamespace XmlCardinalityFixture;\nclass Owner {\n".implode("\n",[$positive,$ordinary,$rebound,$foreign,$mutating,$differentCount,$sideEffect])."\n}\nfunction needInteger(int \$value):void {}\nfunction change(object \$value):bool {return false;}\n",
    'composer.json'=>'{}','bootstrap/app.php'=>"<?php throw new RuntimeException('Application execution is forbidden.');\n"];
