<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Maps signed fabric events to bounded, payload-free compliance evidence. */
final class PanelComplianceFabricProjector implements \JsonSerializable {
	/** @var array<string,array{control_id:string,status:string,source:string}> */
	private array $mappings=[];

	/** @param array<string,array<string,mixed>> $mappings */
	public function __construct(private readonly PanelComplianceLedger $compliance,array $mappings){
		if($mappings===[]||count($mappings)>256){throw new \InvalidArgumentException('Fabric compliance mappings require between 1 and 256 entries.');}
		foreach($mappings as$pattern=>$definition){
			$pattern=$this->pattern((string)$pattern);if(!is_array($definition)||($definition!==[]&&array_is_list($definition))){throw new \InvalidArgumentException('Fabric compliance mappings must be object-like maps.');}
			$unknown=array_diff(array_keys($definition),['control_id','status','source']);if($unknown!==[]){throw new \InvalidArgumentException('Fabric compliance mapping contains unsupported fields.');}
			$this->mappings[$pattern]=[
				'control_id'=>PanelOperationsGuard::name((string)($definition['control_id']??''),'fabric compliance control id'),
				'status'=>PanelOperationsGuard::name((string)($definition['status']??'satisfied'),'fabric compliance status'),
				'source'=>PanelOperationsGuard::name((string)($definition['source']??'command_fabric'),'fabric compliance source'),
			];
		}
	}

	public function __invoke(PanelEventEnvelope $event):void {
		foreach($this->mappings as$pattern=>$mapping){
			if(!$this->matches($pattern,$event->eventType())){continue;}
			if($this->recorded($mapping['control_id'],$event->id())){continue;}
			$this->compliance->record($mapping['control_id'],$mapping['status'],[
				'fabric_event_id'=>$event->id(),'fabric_sequence'=>$event->sequence(),'event_type'=>$event->eventType(),
				'aggregate_type'=>$event->aggregateType(),'aggregate_id'=>$event->aggregateId(),
				'tenant_digest'=>hash('sha256',$event->tenantId()),'command_digest'=>$event->commandFingerprint(),
				'event_hash'=>$event->hash(),'payload_digest'=>PanelOperationsGuard::digest($event->payload()),
				'metadata_digest'=>PanelOperationsGuard::digest($event->metadata()),
			],$event->actorId(),$mapping['source']);
		}
	}

	public function jsonSerialize():array{return [
		'type'=>'panel_compliance_fabric_projector','version'=>1,'mappings'=>$this->mappings,
		'raw_event_payload_persisted'=>false,'delivery'=>'at_least_once','duplicate_suppression'=>'retention_bound',
	];}

	private function recorded(string $controlId,string $eventId):bool {
		$payload=$this->compliance->pack([$controlId])->payload();
		foreach($payload['evidence']??[]as$evidence){if(is_array($evidence)&&is_array($evidence['evidence']??null)&&hash_equals((string)($evidence['evidence']['fabric_event_id']??''),$eventId)){return true;}}
		return false;
	}

	private function pattern(string $pattern):string {
		$pattern=strtolower(trim($pattern));if($pattern!=='*'&&preg_match('/^[a-z][a-z0-9_.-]*(?:\.\*)?$/D',$pattern)!==1){throw new \InvalidArgumentException('Fabric compliance event pattern is invalid.');}return$pattern;
	}

	private function matches(string $pattern,string $event):bool {return$pattern==='*'||$pattern===$event||(str_ends_with($pattern,'.*')&&str_starts_with($event,substr($pattern,0,-1)));}
}
