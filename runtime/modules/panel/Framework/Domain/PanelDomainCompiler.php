<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Deterministically compiles one domain into Panel's governed runtime contracts. */
final class PanelDomainCompiler implements \JsonSerializable {
	public const VERSION=1;

	/** @return list<PanelDomainDiagnostic> */ public function diagnose(mixed $manifest):array{return PanelDomainManifest::diagnose($manifest);}

	public function compile(PanelDomainManifest|array $manifest):PanelDomainCompilation {
		$manifest=$manifest instanceof PanelDomainManifest?$manifest:PanelDomainManifest::from($manifest);$source=$manifest->values();$entities=$source['entities'];$commands=$source['commands'];
		$resources=[];foreach($entities as$name=>$entity){$fields=[];$columns=[];foreach($entity['fields']as$field=>$definition){$fields[$field]=['name'=>$field,'label'=>$definition['label'],'type'=>$this->fieldType((string)$definition['type']),'required'=>$definition['required'],'nullable'=>$definition['nullable'],'immutable'=>$definition['immutable'],'classification'=>$definition['classification']];$columns[$field]=['name'=>$field,'label'=>$definition['label'],'type'=>$this->columnType((string)$definition['type']),'searchable'=>$definition['searchable'],'sortable'=>$definition['sortable']];}$actions=[];foreach($commands as$commandName=>$command){if($command['entity']===$name){$actions[$commandName]=['label'=>$command['label'],'operation'=>$command['operation'],'risk'=>$command['risk'],'reversible'=>$command['reversible'],'approval'=>$command['approval'],'policy'=>$command['policy']];}}$relations=[];foreach($source['relationships']as$relationship){if($relationship['from']===$name||$relationship['to']===$name){$relations[]=$relationship;}}$resources[$name]=['type'=>'panel_domain_resource_manifest','name'=>$name,'label'=>$entity['label'],'primary_key'=>$entity['primary_key'],'states'=>$entity['states'],'fields'=>$fields,'columns'=>$columns,'actions'=>$actions,'relations'=>$relations,'routes'=>['index'=>'/'.$manifest->id().'/'.$name,'create'=>'/'.$manifest->id().'/'.$name.'/create','show'=>'/'.$manifest->id().'/'.$name.'/{record}','edit'=>'/'.$manifest->id().'/'.$name.'/{record}/edit']];}
		$workTypes=[];foreach($entities as$name=>$entity){$workTypes[$name]=['states'=>$entity['states'],'subject_type'=>$name,'commands'=>array_values(array_keys(array_filter($commands,static fn(array $command):bool=>$command['entity']===$name)))];}
		$lineage=[];foreach($entities as$name=>$entity){$lineage['entity:'.$name]=['kind'=>'entity','name'=>$name];foreach($entity['fields']as$field=>$definition){$lineage['field:'.$name.'.'.$field]=['kind'=>'field','entity'=>$name,'name'=>$field,'classification'=>$definition['classification']];}}foreach($source['metrics']as$name=>$metric){$lineage['metric:'.$name]=['kind'=>'metric','entity'=>$metric['entity'],'field'=>$metric['field'],'dimensions'=>$metric['dimensions']];}ksort($lineage,SORT_STRING);
		$artifacts=['source'=>$source,'resources'=>$resources,'studio'=>['surfaces'=>$source['surfaces'],'portable'=>true],'work_graph'=>['node_types'=>$workTypes,'edge_types'=>$source['relationships'],'queues'=>$source['queues']],'workflows'=>$source['workflows'],'commands'=>$commands,'policies'=>$source['policies'],'semantic_metrics'=>$source['metrics'],'lineage'=>$lineage,'agents'=>$source['agents'],'runtime'=>['default_deny'=>true,'optimistic_concurrency'=>true,'idempotency_required'=>true,'audit_chain_required'=>true,'signed_materialization_supported'=>true,'portable_manifests'=>true,'executable_code'=>false]];
		return new PanelDomainCompilation($manifest->id(),$manifest->version(),$manifest->fingerprint(),$this->fingerprint(),PanelOperationsGuard::canonical($artifacts));
	}

	public function diff(PanelDomainCompilation $from,PanelDomainCompilation $to):PanelDomainDiff {
		if($from->domainId()!==$to->domainId()){throw new \InvalidArgumentException('Domain diffs require matching domain ids.');}$before=$from->artifact('source');$after=$to->artifact('source');if(!is_array($before)||!is_array($after)){throw new \UnexpectedValueException('Domain compilation source artifact is invalid.');}$sections=[];$steps=[];$breaking=false;
		foreach(PanelDomainManifest::SECTIONS as$section){$left=is_array($before[$section]??null)?$before[$section]:[];$right=is_array($after[$section]??null)?$after[$section]:[];$leftMap=array_is_list($left)?$this->listIndex($left):$left;$rightMap=array_is_list($right)?$this->listIndex($right):$right;$added=array_values(array_diff(array_keys($rightMap),array_keys($leftMap)));$removed=array_values(array_diff(array_keys($leftMap),array_keys($rightMap)));$changed=[];foreach(array_intersect(array_keys($leftMap),array_keys($rightMap))as$key){if(PanelOperationsGuard::digest($leftMap[$key])!==PanelOperationsGuard::digest($rightMap[$key])){$changed[]=(string)$key;}}sort($added,SORT_STRING);sort($removed,SORT_STRING);sort($changed,SORT_STRING);$sections[$section]=['added'=>$added,'removed'=>$removed,'changed'=>$changed];foreach($added as$key){$steps[]=['section'=>$section,'item'=>$key,'operation'=>'create','destructive'=>false];}foreach($removed as$key){$steps[]=['section'=>$section,'item'=>$key,'operation'=>'remove','destructive'=>true];$breaking=true;}foreach($changed as$key){$destructive=$this->destructiveChange($section,$leftMap[$key],$rightMap[$key]);$steps[]=['section'=>$section,'item'=>$key,'operation'=>'alter','destructive'=>$destructive];$breaking=$breaking||$destructive;}}
		return new PanelDomainDiff($from->digest(),$to->digest(),$sections,$steps,$breaking);
	}

	public function fingerprint():string{return PanelOperationsGuard::digest($this->manifestBase());}
	public function jsonSerialize():array{return PanelManifestContract::stamp($this->manifestBase()+['fingerprint'=>$this->fingerprint()]);}

	/** @return array<string,mixed> */
	private function manifestBase():array {
		$manifest=['type'=>'panel_domain_compiler_manifest','version'=>self::VERSION,'input'=>'panel_domain_manifest','outputs'=>['resources','studio','work_graph','workflows','commands','policies','semantic_metrics','lineage','agents'],'deterministic'=>true,'runtime_code_generation'=>false];
		return$manifest;
	}
	private function fieldType(string $type):string{return match($type){'text'=>'textarea','integer'=>'integer','number','money'=>'number','boolean'=>'checkbox','enum'=>'select','json'=>'structured','file'=>'file',default=>$type};}
	private function columnType(string $type):string{return match($type){'money'=>'money','date'=>'date','datetime'=>'datetime','boolean'=>'boolean','enum'=>'badge','integer','number'=>'number',default=>'text'};}
	/** @param list<array<string,mixed>> $items @return array<string,array<string,mixed>> */ private function listIndex(array $items):array{$result=[];foreach($items as$index=>$item){$key=is_string($item['id']??null)?$item['id']:(string)$index;$result[$key]=$item;}return$result;}
	private function destructiveChange(string $section,mixed $before,mixed $after):bool {
		if(!is_array($before)||!is_array($after)){return true;}if($section==='entities'){$beforeFields=$before['fields']??[];$afterFields=$after['fields']??[];foreach($beforeFields as$name=>$field){if(!isset($afterFields[$name])){return true;}$next=$afterFields[$name];if(($field['type']??null)!==($next['type']??null)||(($field['nullable']??true)===true&&($next['nullable']??true)===false)||(($field['required']??false)===false&&($next['required']??false)===true)){return true;}}return false;}if(in_array($section,['commands','workflows','policies'],true)){return true;}return false;
	}
}
