<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\{Node,NodeTraverser,ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** Source-only advisory certificate. It never infers or replaces an expression or storage type. */
final class OrdinaryMixedBindings
{
    private int $nodes=0;
    private bool $failed=false;
    private array $bindings=[];
    public function inspect(string $contents):?array
    {
        $this->nodes=0;$this->failed=false;$this->bindings=[];
        if(strlen($contents)>1024*1024){return null;}
        try{$nodes=(new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($contents)??[]);}
        catch(\PhpParser\Error){return null;}
        $this->frame($nodes,[],[],false,$contents);
        return$this->failed?null:$this->bindings;
    }
    private function frame(array $nodes,array $parameters,array $captures,bool $referenceReturn,string $contents):void
    {
        $forbidden=[];$uncertain=$referenceReturn;$pending=$nodes;$candidates=[];$children=[];
        foreach([...$parameters,...$captures]as$binding){if($binding->var instanceof Node\Expr\Variable&&is_string($binding->var->name)){$forbidden[$binding->var->name]=true;}else{$uncertain=true;}}
        while($pending!==[]){
            $node=array_pop($pending);if(!$node instanceof Node){continue;}
            if(++$this->nodes>20_000){$this->failed=true;return;}
            foreach($node->getComments()as$comment){if(preg_match('/@(?:phpstan-|psalm-|mago-)?var\b/i',$comment->getText())===1){$uncertain=true;}}
            if($node instanceof Node\Stmt\ClassLike){
                foreach($node->getMethods()as$method){$children[]=$method;}continue;
            }
            if($node instanceof Node\FunctionLike){
                if($node instanceof Node\Expr\Closure){foreach($node->uses as$use){if($use->byRef){$this->forbid($use->var,$forbidden,$uncertain);}}}
                $children[]=$node;continue;
            }
            if($node instanceof Node\Expr\Variable&&!is_string($node->name)||$node instanceof Node\Expr\Eval_){$uncertain=true;}
            if($node instanceof Node\Expr\FuncCall&&$node->name instanceof Node\Name&&in_array(strtolower($node->name->getLast()),['extract','parse_str'],true)){$uncertain=true;}
            if($node instanceof Node\Stmt\Global_){foreach($node->vars as$var){$this->forbid($var,$forbidden,$uncertain);}}
            if($node instanceof Node\Stmt\Static_){foreach($node->vars as$var){$this->forbid($var->var,$forbidden,$uncertain);}}
            if($node instanceof Node\Expr\AssignRef){$this->forbid($node->var,$forbidden,$uncertain);$this->forbid($node->expr,$forbidden,$uncertain);}
            if($node instanceof Node\Arg&&$node->byRef){$this->forbid($node->value,$forbidden,$uncertain);}
            if($node instanceof Node\Stmt\Foreach_&&$node->byRef){$this->forbid($node->valueVar,$forbidden,$uncertain);}
            if($node instanceof Node\Stmt\Expression&&$node->expr instanceof Node\Expr\Assign&&$node->expr->var instanceof Node\Expr\Variable){
                $assign=$node->expr;
                // Unsupported alias/chained/indirect storage is retained; ordinary RHS domain stays native.
                if(!$assign->expr instanceof Node\Expr\Assign&&!$assign->expr instanceof Node\Expr\AssignRef){$candidates[]=[$assign->var,$assign->expr,'ordinary-statement-assignment'];}
            }
            if($node instanceof Node\Stmt\Foreach_&&!$node->byRef){
                if($node->valueVar instanceof Node\Expr\Variable){$candidates[]=[$node->valueVar,null,'ordinary-by-value-foreach'];}
                elseif($node->valueVar instanceof Node\Expr\List_||$node->valueVar instanceof Node\Expr\Array_){
                    $valid=true;$leaves=[];
                    foreach($node->valueVar->items as$item){
                        if($item===null||$item->byRef||$item->unpack||$item->key!==null||!$item->value instanceof Node\Expr\Variable){$valid=false;break;}
                        $leaves[]=$item->value;
                    }
                    $names=array_map(static fn(Node\Expr\Variable$v):mixed=>$v->name,$leaves);
                    if($valid&&$leaves!==[]&&count($leaves)<=16&&count(array_unique($names,SORT_REGULAR))===count($names)){
                        foreach($leaves as$leaf){$candidates[]=[$leaf,null,'ordinary-by-value-flat-list-leaf'];}
                    }
                }
            }
            foreach($node->getSubNodeNames()as$name){$value=$node->$name;if($value instanceof Node){$pending[]=$value;}elseif(is_array($value)){foreach($value as$item){if($item instanceof Node){$pending[]=$item;}}}}
        }
        if(!$uncertain){foreach($candidates as[$variable,$rhs,$kind]){
            $name=$variable->name;if(!is_string($name)||preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$name)!==1||isset($forbidden[$name])
                ||in_array($name,['this','GLOBALS','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV'],true)){continue;}
            $start=$variable->getStartFilePos();$end=$variable->getEndFilePos()+1;
            if($start<0||$end<=$start||substr($contents,$start,$end-$start)!=='$'.$name){continue;}
            $key=$start.':'.$end;if(isset($this->bindings[$key])){$this->failed=true;return;}
            $this->bindings[$key]=['variable'=>$name,'kind'=>$kind,'primary'=>[$start,$end],
                'rhs'=>$rhs===null?null:[$rhs->getStartFilePos(),$rhs->getEndFilePos()+1],'rhsKind'=>$rhs?->getType(),
                'sourceSha256'=>hash('sha256',$contents),'nativeTypeChanged'=>false];
        }}
        foreach($children as$child){$childNodes=$child->getStmts()??[];$captures=$child instanceof Node\Expr\Closure?$child->uses:[];
            // Physical parameters and captures remain shared/contract-bearing bindings, regardless of body narrowing.
            $this->frame($childNodes,$child->getParams(),$captures,$child->byRef,$contents);
            if($this->failed){return;}
        }
    }
    private function forbid(Node\Expr $expression,array &$forbidden,bool &$uncertain):void
    {
        if($expression instanceof Node\Expr\Variable&&is_string($expression->name)){$forbidden[$expression->name]=true;return;}
        if($expression instanceof Node\Expr\PropertyFetch||$expression instanceof Node\Expr\ArrayDimFetch){$this->forbid($expression->var,$forbidden,$uncertain);return;}
        if($expression instanceof Node\Expr\StaticPropertyFetch){if($expression->class instanceof Node\Expr){$this->forbid($expression->class,$forbidden,$uncertain);}return;}
        $uncertain=true;
    }
}
