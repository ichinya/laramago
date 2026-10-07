<?php
declare(strict_types=1);
/** Complete read-only joins to the genuine current native fixture records. */
final class LocalCacheNativeEventJoin
{
    public static function sameEnvelope(array $sdk,array $raw,string $workspace,string $fixture):void
    {
        if($sdk['objectClass']!=='Mago\\Sdk\\Reporting\\ReportedIssue'||$sdk['level']['enum']!=='Mago\\Sdk\\Reporting\\Level'){
            throw new \RuntimeException('The genuine SDK envelope representation differs.');
        }
        foreach(['code','message','notes','help'] as $field){if(($sdk[$field]??null)!==($raw[$field]??null)){throw new \RuntimeException('The complete shared envelope field differs: '.$field);}}
        if($sdk['level']['name']!==$raw['level']||($sdk['link']??null)!==($raw['link']??null)||($sdk['edits']??[])!==($raw['edits']??[])
            ||count($sdk['annotations'])!==count($raw['annotations'])){throw new \RuntimeException('The native envelope level/link/edits/annotations differ.');}
        foreach($sdk['annotations'] as $index=>$annotation){$native=$raw['annotations'][$index];
            if($annotation['objectClass']!=='Mago\\Sdk\\Reporting\\Annotation'||$annotation['kind']['enum']!=='Mago\\Sdk\\Reporting\\AnnotationKind'
                ||$annotation['span']['objectClass']!=='Mago\\Sdk\\Span'||$annotation['kind']['name']!==$native['kind']
                ||$annotation['message']!==$native['message']){throw new \RuntimeException('A complete annotation kind/message differs.');}
            // The actual SDK annotation has null file, meaning the current proven cases.php source.
            $file=$annotation['file']??'cases.php';if($file!=='cases.php'||$native['span']['file_id']['name']!==$file
                ||self::path($native['span']['file_id']['path'])!==self::path($workspace.'/'.$file)
                ||$native['span']['file_id']['size']!==strlen($fixture)||$native['span']['file_id']['file_type']!=='Host'){
                throw new \RuntimeException('The annotation does not bind the current frozen source file.');
            }
            foreach(['start','end'] as $edge){$offset=$annotation['span'][$edge];
                if(!is_int($offset)||$offset<0||$offset>strlen($fixture)||$native['span'][$edge]['offset']!==$offset
                    ||$native['span'][$edge]['line']!==substr_count(substr($fixture,0,$offset),"\n")){
                    throw new \RuntimeException('The genuine annotation physical offset/line differs.');
                }
            }
        }
    }
    private static function path(string $path):string
    {
        $path=str_replace('\\','/',$path);if(str_starts_with($path,'//?/')){$path=substr($path,4);}return strtolower($path);
    }
}
