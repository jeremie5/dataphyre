<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Chooses pushdown or an explicitly allowed deterministic fallback without hiding runtime failures. */
final class PanelSemanticExecutor implements \JsonSerializable {
	public function __construct(private readonly PanelSemanticCatalog $catalog,private readonly PanelSemanticBackend $primary,private readonly ?PanelSemanticBackend $fallback=null){}
	public function execute(PanelSemanticQuery $query):PanelSemanticQueryResult{
		$plan=$this->catalog->plan($query);$missing=$this->primary->unsupported($plan);
		if($missing===[]){try{return$this->primary->execute($plan);}catch(PanelSemanticUnsupported $error){$missing=$error->features();}}
		if(!$query->allowsFallback()||!$this->fallback instanceof PanelSemanticBackend){throw new PanelSemanticUnsupported($missing);}
		$fallbackMissing=$this->fallback->unsupported($plan);if($fallbackMissing!==[]){throw new PanelSemanticUnsupported(array_values(array_unique([...$missing,...$fallbackMissing])));}
		return$this->fallback->execute($plan)->withExecutionMetadata(['fallback'=>true,'fallback_from'=>$this->primary->name(),'fallback_features'=>$missing]);
	}
	public function catalog():PanelSemanticCatalog{return$this->catalog;}public function primary():PanelSemanticBackend{return$this->primary;}public function fallback():?PanelSemanticBackend{return$this->fallback;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_semantic_executor_manifest','version'=>1,'catalog_fingerprint'=>$this->catalog->fingerprint(),'primary'=>['name'=>$this->primary->name(),'capabilities'=>$this->primary->capabilities()],'fallback'=>$this->fallback===null?null:['name'=>$this->fallback->name(),'capabilities'=>$this->fallback->capabilities()],'runtime_failures_trigger_fallback'=>false,'unsupported_features_trigger_fallback'=>true];}
}
