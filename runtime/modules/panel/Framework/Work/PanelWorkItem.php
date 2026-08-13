<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable case/task/incident projection in the universal work graph. */
final class PanelWorkItem implements \JsonSerializable {
	/** @param list<string> $tags @param array<string,mixed> $data */
	private function __construct(
		private readonly string $id,
		private readonly string $tenantId,
		private readonly string $type,
		private readonly string $title,
		private readonly string $state,
		private readonly int $priority,
		private readonly ?string $queue,
		private readonly ?string $assignee,
		private readonly ?string $subjectType,
		private readonly ?string $subjectId,
		private readonly ?string $dueAt,
		private readonly array $tags,
		private readonly array $data,
		private readonly int $version,
		private readonly string $createdAt,
		private readonly string $updatedAt,
	){
		PanelOperationsGuard::identifier($id,'work item id');PanelOperationsGuard::identifier($tenantId,'work tenant id');PanelOperationsGuard::name($type,'work item type');PanelOperationsGuard::label($title,'work item title',512);PanelOperationsGuard::name($state,'work item state');if($priority<0||$priority>100){throw new \InvalidArgumentException('Work item priority must be between 0 and 100.');}if($queue!==null){PanelOperationsGuard::name($queue,'work queue');}if($assignee!==null){PanelOperationsGuard::identifier($assignee,'work assignee');}if(($subjectType===null)!==($subjectId===null)){throw new \InvalidArgumentException('Work item subject metadata is incomplete.');}if($subjectType!==null){PanelOperationsGuard::name($subjectType,'work subject type');PanelOperationsGuard::identifier((string)$subjectId,'work subject id');}if($dueAt!==null&&PanelOperationsGuard::instant($dueAt)!==$dueAt){throw new \InvalidArgumentException('Work item due instant must be canonical UTC.');}PanelOperationsGuard::names($tags,'work tag');PanelOperationsGuard::safeMetadata($data,1024);if($version<1){throw new \InvalidArgumentException('Work item version must be positive.');}foreach([$createdAt,$updatedAt]as$at){if(PanelOperationsGuard::instant($at)!==$at){throw new \InvalidArgumentException('Work item instants must be canonical UTC.');}}
	}

	/** @param array<string,mixed> $definition */
	public static function make(string $tenantId,array $definition,string|int|\DateTimeInterface $now):self {
		$now=PanelOperationsGuard::instant($now);$id=PanelOperationsGuard::identifier((string)($definition['id']??''),'work item id');$subject=is_array($definition['subject']??null)?$definition['subject']:[];
		return new self($id,PanelOperationsGuard::identifier($tenantId,'work tenant id'),PanelOperationsGuard::name((string)($definition['type']??'case'),'work item type'),PanelOperationsGuard::label((string)($definition['title']??$id),'work item title',512),PanelOperationsGuard::name((string)($definition['state']??'open'),'work item state'),max(0,min(100,(int)($definition['priority']??50))),isset($definition['queue'])?PanelOperationsGuard::name((string)$definition['queue'],'work queue'):null,isset($definition['assignee'])?PanelOperationsGuard::identifier((string)$definition['assignee'],'work assignee'):null,isset($subject['type'])?PanelOperationsGuard::name((string)$subject['type'],'work subject type'):null,isset($subject['id'])?PanelOperationsGuard::identifier((string)$subject['id'],'work subject id'):null,isset($definition['due_at'])?PanelOperationsGuard::instant(is_int($definition['due_at'])?$definition['due_at']:(string)$definition['due_at']):null,PanelOperationsGuard::names(is_array($definition['tags']??null)?$definition['tags']:[],'work tag'),PanelOperationsGuard::safeMetadata(is_array($definition['data']??null)?$definition['data']:[],1024),1,$now,$now);
	}

	/** @param array<string,mixed> $payload */
	public static function restore(array $payload):self {
		$expected=['id','tenant_id','type','title','state','priority','queue','assignee','subject_type','subject_id','due_at','tags','data','version','created_at','updated_at'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected||!is_string($payload['id'])||!is_string($payload['tenant_id'])||!is_string($payload['type'])||!is_string($payload['title'])||!is_string($payload['state'])||!is_int($payload['priority'])||(!is_string($payload['queue'])&&$payload['queue']!==null)||(!is_string($payload['assignee'])&&$payload['assignee']!==null)||(!is_string($payload['subject_type'])&&$payload['subject_type']!==null)||(!is_string($payload['subject_id'])&&$payload['subject_id']!==null)||(!is_string($payload['due_at'])&&$payload['due_at']!==null)||!is_array($payload['tags'])||!is_array($payload['data'])||!is_int($payload['version'])||!is_string($payload['created_at'])||!is_string($payload['updated_at'])){throw new \UnexpectedValueException('Stored work item shape is invalid.');}return new self($payload['id'],$payload['tenant_id'],$payload['type'],$payload['title'],$payload['state'],$payload['priority'],$payload['queue'],$payload['assignee'],$payload['subject_type'],$payload['subject_id'],$payload['due_at'],$payload['tags'],$payload['data'],$payload['version'],$payload['created_at'],$payload['updated_at']);
	}

	/** @param array<string,mixed> $patch */
	public function evolve(array $patch,string|int|\DateTimeInterface $now):self {
		$allowed=['title','state','priority','queue','assignee','due_at','tags','data'];$unknown=array_values(array_diff(array_keys($patch),$allowed));if($unknown!==[]){throw new \InvalidArgumentException('Unknown work item patch key: '.(string)$unknown[0]);}$now=PanelOperationsGuard::instant($now);
		return new self($this->id,$this->tenantId,$this->type,array_key_exists('title',$patch)?PanelOperationsGuard::label((string)$patch['title'],'work item title',512):$this->title,array_key_exists('state',$patch)?PanelOperationsGuard::name((string)$patch['state'],'work item state'):$this->state,array_key_exists('priority',$patch)?max(0,min(100,(int)$patch['priority'])):$this->priority,array_key_exists('queue',$patch)?($patch['queue']===null?null:PanelOperationsGuard::name((string)$patch['queue'],'work queue')):$this->queue,array_key_exists('assignee',$patch)?($patch['assignee']===null?null:PanelOperationsGuard::identifier((string)$patch['assignee'],'work assignee')):$this->assignee,$this->subjectType,$this->subjectId,array_key_exists('due_at',$patch)?($patch['due_at']===null?null:PanelOperationsGuard::instant(is_int($patch['due_at'])?$patch['due_at']:(string)$patch['due_at'])):$this->dueAt,array_key_exists('tags',$patch)?PanelOperationsGuard::names(is_array($patch['tags'])?$patch['tags']:[],'work tag'):$this->tags,array_key_exists('data',$patch)?PanelOperationsGuard::safeMetadata(is_array($patch['data'])?$patch['data']:[],1024):$this->data,$this->version+1,$this->createdAt,$now);
	}

	public function id():string{return$this->id;}public function tenantId():string{return$this->tenantId;}public function type():string{return$this->type;}public function title():string{return$this->title;}public function state():string{return$this->state;}public function priority():int{return$this->priority;}public function queue():?string{return$this->queue;}public function assignee():?string{return$this->assignee;}public function subjectType():?string{return$this->subjectType;}public function subjectId():?string{return$this->subjectId;}public function dueAt():?string{return$this->dueAt;}public function version():int{return$this->version;}public function createdAt():string{return$this->createdAt;}public function updatedAt():string{return$this->updatedAt;}
	/** @return list<string> */ public function tags():array{return$this->tags;}/** @return array<string,mixed> */ public function data():array{return$this->data;}
	public function overdue(string|int|\DateTimeInterface $at):bool{return$this->dueAt!==null&&strcmp($this->dueAt,PanelOperationsGuard::instant($at))<0;}
	public function jsonSerialize():array{return['id'=>$this->id,'tenant_id'=>$this->tenantId,'type'=>$this->type,'title'=>$this->title,'state'=>$this->state,'priority'=>$this->priority,'queue'=>$this->queue,'assignee'=>$this->assignee,'subject_type'=>$this->subjectType,'subject_id'=>$this->subjectId,'due_at'=>$this->dueAt,'tags'=>$this->tags,'data'=>$this->data,'version'=>$this->version,'created_at'=>$this->createdAt,'updated_at'=>$this->updatedAt];}
}
