<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\GuardedStringCastCompatibilityFilter;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Observes only genuine pre-filter SDK contexts; never claims a constructed DTO is acceptance. */
final class GuardedStringCastNativeObservation implements Plugin
{
    public function __construct(private readonly string $root, private readonly string $output, private readonly bool $apply = false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('draft/string-cast-native-observation','Native cast observation','Bounded genuine SDK receipts without cache dumps'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerIssueFilterHook(new class($this->root,$this->output,$this->apply) implements IssueFilterHook {
            private GuardedStringCastCompatibilityFilter $filter;
            public function __construct(string $root, private readonly string $output, private readonly bool $apply) { $this->filter = new GuardedStringCastCompatibilityFilter($root); }
            public function getCodes(): array { return ['invalid-type-cast']; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $decision = $this->filter->filterIssue($context);
                $snapshot = static function (mixed $value) use (&$snapshot): mixed {
                    if ($value instanceof \BackedEnum) { return $value->value; }
                    if ($value instanceof \UnitEnum) { return $value->name; }
                    if (is_object($value)) { return $snapshot(get_object_vars($value)); }
                    if (is_array($value)) { return array_map($snapshot, $value); }
                    return $value;
                };
                // Exactly seven known framework/builtin queries. No universal metadata enumeration.
                $request = $context->codebase->getClass('Illuminate\\Http\\Request');
                $route = $context->codebase->getMethod('Illuminate\\Http\\Request','route');
                $declaring = $context->codebase->getDeclaringMethod('Illuminate\\Http\\Request','route');
                $object = $context->codebase->getFunction('is_object'); $callable = $context->codebase->getFunction('is_callable');
                $invoke = $context->codebase->getFunction('call_user_func'); $null = $context->codebase->getFunction('is_null');
                $record = ['genuineIssueFilterContext'=>true,'sourceSha256'=>hash('sha256',$context->contents),'file'=>$context->file,
                    'issue'=>$snapshot($context->issue),'decision'=>$decision->name,'apply'=>$this->apply,'stages'=>$this->filter->stages,'proofReceipts'=>$this->filter->proofReceipts(),
                    'dependencies'=>$snapshot($this->filter->dependencies),'request'=>$snapshot($request),'route'=>$snapshot($route),'declaring_route'=>$snapshot($declaring),
                    'is_object'=>$snapshot($object),'is_callable'=>$snapshot($callable),
                    'call_user_func'=>$snapshot($invoke),'is_null'=>$snapshot($null)];
                file_put_contents($this->output.'/native-casts.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
                return $this->apply ? $decision : IssueFilterDecision::Keep;
            }
        });
    }
}
