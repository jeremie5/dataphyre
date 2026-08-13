<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Executes adapter contracts without coupling production code to TestKit. */
final class PanelAdapterConformanceRunner {
	/** @param array<string,mixed> $options */
	public function run(PanelAdapterConformanceSuite $suite, object $adapter, array $options=[]): PanelAdapterConformanceReport {
		if(!$adapter instanceof ($suite->contract())){ throw new \InvalidArgumentException('Adapter '.get_debug_type($adapter).' does not implement '.$suite->contract().'.'); }
		$capabilities=$this->capabilities($adapter, $options);
		$results=[];
		foreach($suite->cases() as $case){
			$missing=array_values(array_filter($case->capabilities(), fn(string $capability):bool=>!$this->capability($capabilities,$capability)));
			if($missing!==[]){
				$status=$case->optional()?'skipped':'failed';
				$issue=$status==='failed'?[['code'=>'capability_missing','message'=>'Adapter is missing required capabilities.','expected'=>$missing,'actual'=>[]]]:[];
				$results[]=new PanelAdapterConformanceResult($case,$status,0,0.0,$issue,[],'Missing: '.implode(', ',$missing)); continue;
			}
			if($case->destructive() && ($options['allow_destructive'] ?? false)!==true){ $results[]=new PanelAdapterConformanceResult($case,'skipped',0,0.0,[],[],'Destructive probes were not authorized.'); continue; }
			$context=new PanelAdapterConformanceContext($options,$capabilities); $started=microtime(true);
			try{ $case->run($adapter,$context); }catch(\Throwable $exception){ $context->exception($exception); }
			$duration=(microtime(true)-$started)*1000;
			if($duration>$case->maxMillis()){ $context->check(false,'duration_exceeded','Adapter probe exceeded its duration budget.',$case->maxMillis(),round($duration,3)); }
			$status=$context->issues()!==[] ? 'failed' : ($context->skipped() ? 'skipped' : 'passed');
			$results[]=new PanelAdapterConformanceResult($case,$status,$context->assertions(),$duration,$context->issues(),$context->evidence(),$context->skipReason());
		}
		$meta=is_array($options['meta'] ?? null)?$options['meta']:[];
		return new PanelAdapterConformanceReport($suite,$adapter::class,$results,$capabilities,$meta);
	}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	private function capabilities(object $adapter,array $options): array {
		if(is_array($options['capabilities'] ?? null)){ return $options['capabilities']; }
		if(method_exists($adapter,'capabilities')){ $value=$adapter->capabilities(); return is_array($value)?$value:[]; }
		if(method_exists($adapter,'manifest')){ $manifest=$adapter->manifest(); return is_array($manifest)&&is_array($manifest['capabilities']??null)?$manifest['capabilities']:[]; }
		return [];
	}

	/** @param array<string,mixed> $capabilities */
	private function capability(array $capabilities,string $path): bool {
		$value=$capabilities;
		foreach(explode('_',$path) as $segment){
			if(!is_array($value)||!array_key_exists($segment,$value)){ return $this->capabilityValue($capabilities[$path]??false); }
			$value=$value[$segment];
		}
		return $this->capabilityValue($value);
	}

	private function capabilityValue(mixed $value): bool { return $value===true || (is_int($value)&&$value>0) || (is_string($value)&&$value!=='') || (is_array($value)&&$value!==[]); }
}
