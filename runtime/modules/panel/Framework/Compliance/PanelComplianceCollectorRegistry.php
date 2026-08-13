<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Instance-owned collector registry with explicit provenance and replacement. */
final class PanelComplianceCollectorRegistry implements \JsonSerializable {
	/** @var array<string,array{collector:PanelComplianceCollector,contributor:string,priority:int}> */ private array $entries=[];
	private int $revision=0;

	public function register(PanelComplianceCollector $collector,string $contributor='host',int $priority=0,bool $replace=false):self {
		$id=PanelOperationsGuard::name($collector->id(),'compliance collector id');
		$fingerprint=$collector->fingerprint();if(preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1){throw new \InvalidArgumentException('Compliance collector fingerprint is invalid.');}
		$contributor=PanelOperationsGuard::name($contributor,'compliance collector contributor');$priority=max(-100000,min(100000,$priority));
		$current=$this->entries[$id]??null;
		if(is_array($current)&&hash_equals($current['collector']->fingerprint(),$fingerprint)){return$this;}
		if(is_array($current)&&!$replace){throw new \LogicException('Compliance collector id is already registered with a different fingerprint.');}
		$this->entries[$id]=['collector'=>$collector,'contributor'=>$contributor,'priority'=>$priority];ksort($this->entries,SORT_STRING);$this->revision++;return$this;
	}

	public function forget(string $id):self {$id=PanelOperationsGuard::name($id,'compliance collector id');if(isset($this->entries[$id])){unset($this->entries[$id]);$this->revision++;}return$this;}
	public function has(string $id):bool{return isset($this->entries[PanelOperationsGuard::name($id,'compliance collector id')]);}
	public function get(string $id):PanelComplianceCollector {$id=PanelOperationsGuard::name($id,'compliance collector id');$entry=$this->entries[$id]??null;if(!is_array($entry)){throw new \OutOfBoundsException('Compliance collector is not registered.');}return$entry['collector'];}
	/** @return array<string,PanelComplianceCollector> */ public function collectors():array {$out=[];foreach($this->entries as$id=>$entry){$out[$id]=$entry['collector'];}return$out;}
	public function revision():int{return$this->revision;}
	public function fingerprint():string{return PanelOperationsGuard::digest($this->snapshot());}
	/** @return array<string,array<string,mixed>> */ public function snapshot():array {
		$out=[];foreach($this->entries as$id=>$entry){$out[$id]=['id'=>$id,'fingerprint'=>$entry['collector']->fingerprint(),'contributor'=>$entry['contributor'],'priority'=>$entry['priority'],'capabilities'=>PanelOperationsGuard::safeMetadata($entry['collector']->capabilities(),128)];}return$out;
	}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp([
		'type'=>'panel_compliance_collector_registry','version'=>1,'revision'=>$this->revision,'collector_count'=>count($this->entries),
		'fingerprint'=>$this->fingerprint(),'collectors'=>$this->snapshot(),'credentials_serialized'=>false,
	]);}
}
