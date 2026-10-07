<?php
declare(strict_types=1);
/** Compare actual SDK observations to complete raw reports. Never create a positive DTO. */
function modelDiscoveryAbsolute(string $path,string $root):string {
    $path=str_replace('\\','/',$path);if(str_starts_with($path,'//?/')){$path=substr($path,4);}
    if(!str_starts_with($path,'/')&&preg_match('~^[A-Za-z]:/~',$path)!==1){$path=rtrim(str_replace('\\','/',$root),'/').'/'.$path;}
    if(in_array('..',explode('/',$path),true)||in_array('.',explode('/',$path),true)){throw new RuntimeException('Unclosed source file identity.');}
    return PHP_OS_FAMILY==='Windows'?strtolower($path):$path;
}
function modelDiscoveryWholeJoin(array $row,array $issue,string $root):bool {
    $sdk=$row['genuineIssue'];
    $levels=[\Mago\Sdk\Reporting\Level::Note->value=>'Note',\Mago\Sdk\Reporting\Level::Help->value=>'Help',
        \Mago\Sdk\Reporting\Level::Warning->value=>'Warning',\Mago\Sdk\Reporting\Level::Error->value=>'Error'];
    $kinds=[\Mago\Sdk\Reporting\AnnotationKind::Primary->value=>'Primary',\Mago\Sdk\Reporting\AnnotationKind::Secondary->value=>'Secondary'];
    if(($levels[$sdk['level']]??null)!==$issue['level']||$sdk['code']!==$issue['code']||$sdk['message']!==$issue['message']
        ||$sdk['notes']!==($issue['notes']??[])||$sdk['help']!==($issue['help']??null)||$sdk['link']!==($issue['link']??null)
        ||$sdk['edits']!==[]||($issue['edits']??[])!==[]||count($sdk['annotations'])!==count($issue['annotations'])){return false;}
    foreach($sdk['annotations'] as $number=>$annotation){$raw=$issue['annotations'][$number];
        if(($kinds[$annotation['kind']]??null)!==$raw['kind']||$annotation['message']!==($raw['message']??null)
            ||[$annotation['span']['start'],$annotation['span']['end']]!==[$raw['span']['start']['offset'],$raw['span']['end']['offset']]
            ||modelDiscoveryAbsolute($annotation['file']??$row['file'],$root)!==modelDiscoveryAbsolute($raw['span']['file_id']['path'],$root)){return false;}
    }
    return true;
}
