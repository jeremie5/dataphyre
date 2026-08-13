<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Canonical source of truth for one governed operational application. */
final class PanelDomainManifest implements \JsonSerializable {
	public const VERSION=1;
	public const SECTIONS=['entities','relationships','workflows','commands','policies','metrics','surfaces','queues','agents'];
	private readonly array $manifest;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $manifest */
	private function __construct(array $manifest){$this->manifest=$manifest;$this->fingerprint=PanelOperationsGuard::digest($manifest);}

	/** @param array<string,mixed> $manifest */
	public static function from(array $manifest):self {
		$diagnostics=self::diagnose($manifest);$errors=array_values(array_filter($diagnostics,static fn(PanelDomainDiagnostic $item):bool=>$item->error()));
		if($errors!==[]){throw new \InvalidArgumentException('Domain manifest is invalid: '.$errors[0]->path().' '.$errors[0]->message());}
		return new self(self::normalize($manifest));
	}

	/** @return list<PanelDomainDiagnostic> */
	public static function diagnose(mixed $manifest):array {
		if(!is_array($manifest)||($manifest!==[]&&array_is_list($manifest))){return[new PanelDomainDiagnostic('root','invalid_root','Domain manifests must be object-like maps.')];}
		try{$normalized=self::normalize($manifest);}catch(\LengthException $error){return[new PanelDomainDiagnostic('root','limit_exceeded',$error->getMessage())];}catch(\Throwable $error){return[new PanelDomainDiagnostic('root','invalid_shape',$error->getMessage())];}
		$diagnostics=[];$entities=$normalized['entities'];
		if($entities===[]){$diagnostics[]=new PanelDomainDiagnostic('entities','entities_required','A domain must declare at least one entity.');}
		foreach($entities as$name=>$entity){
			if(($entity['fields']??[])===[]){$diagnostics[]=new PanelDomainDiagnostic('entities.'.$name.'.fields','fields_required','Entities must declare at least one field.');}
			$primary=(string)($entity['primary_key']??'id');if(!isset($entity['fields'][$primary])){$diagnostics[]=new PanelDomainDiagnostic('entities.'.$name.'.primary_key','primary_key_missing','The primary key must reference a declared field.');}
			foreach(($entity['fields']??[])as$field=>$definition){$type=(string)($definition['type']??'');if(!in_array($type,self::fieldTypes(),true)){$diagnostics[]=new PanelDomainDiagnostic('entities.'.$name.'.fields.'.$field.'.type','field_type_invalid','Field type is not supported.');}}
		}
		foreach($normalized['relationships']as$index=>$relationship){foreach(['from','to']as$side){$entity=(string)($relationship[$side]??'');if(!isset($entities[$entity])){$diagnostics[]=new PanelDomainDiagnostic('relationships['.$index.'].'.$side,'relationship_entity_missing','Relationship endpoint must reference a declared entity.');}}}
		foreach($normalized['commands']as$name=>$command){$entity=(string)($command['entity']??'');if(!isset($entities[$entity])){$diagnostics[]=new PanelDomainDiagnostic('commands.'.$name.'.entity','command_entity_missing','Command must reference a declared entity.');}$policy=$command['policy']??null;if(is_string($policy)&&$policy!==''&&!isset($normalized['policies'][$policy])){$diagnostics[]=new PanelDomainDiagnostic('commands.'.$name.'.policy','command_policy_missing','Command policy must reference a declared policy.');}}
		foreach($normalized['workflows']as$name=>$workflow){$entity=(string)($workflow['entity']??'');if(!isset($entities[$entity])){$diagnostics[]=new PanelDomainDiagnostic('workflows.'.$name.'.entity','workflow_entity_missing','Workflow must reference a declared entity.');}$states=$workflow['states']??[];$initial=(string)($workflow['initial']??'');if(!in_array($initial,$states,true)){$diagnostics[]=new PanelDomainDiagnostic('workflows.'.$name.'.initial','workflow_initial_missing','Workflow initial state must be declared.');}foreach($workflow['transitions']??[]as$transitionIndex=>$transition){foreach(['from','to']as$side){if(!in_array((string)($transition[$side]??''),$states,true)){$diagnostics[]=new PanelDomainDiagnostic('workflows.'.$name.'.transitions['.$transitionIndex.'].'.$side,'workflow_state_missing','Transition endpoint must reference a declared workflow state.');}}$command=(string)($transition['command']??'');if($command!==''&&!isset($normalized['commands'][$command])){$diagnostics[]=new PanelDomainDiagnostic('workflows.'.$name.'.transitions['.$transitionIndex.'].command','workflow_command_missing','Transition command must reference a declared command.');}}}
		foreach($normalized['metrics']as$name=>$metric){$entity=(string)($metric['entity']??'');if(!isset($entities[$entity])){$diagnostics[]=new PanelDomainDiagnostic('metrics.'.$name.'.entity','metric_entity_missing','Metric must reference a declared entity.');}foreach(array_merge((array)($metric['dimensions']??[]),array_filter([(string)($metric['field']??'')]))as$field){if(!isset($entities[$entity]['fields'][$field])){$diagnostics[]=new PanelDomainDiagnostic('metrics.'.$name,'metric_field_missing','Metric fields and dimensions must reference declared entity fields.');}}}
		foreach($normalized['queues']as$name=>$queue){$entity=(string)($queue['entity']??'');if($entity!==''&&!isset($entities[$entity])){$diagnostics[]=new PanelDomainDiagnostic('queues.'.$name.'.entity','queue_entity_missing','Queue entity must reference a declared entity.');}}
		foreach($normalized['surfaces']as$name=>$surface){$entity=(string)($surface['entity']??'');$queue=(string)($surface['queue']??'');if($entity!==''&&!isset($entities[$entity])){$diagnostics[]=new PanelDomainDiagnostic('surfaces.'.$name.'.entity','surface_entity_missing','Surface entity must reference a declared entity.');}if($queue!==''&&!isset($normalized['queues'][$queue])){$diagnostics[]=new PanelDomainDiagnostic('surfaces.'.$name.'.queue','surface_queue_missing','Surface queue must reference a declared queue.');}}
		foreach($normalized['agents']as$name=>$agent){foreach($agent['commands']??[]as$command){if(!isset($normalized['commands'][$command])){$diagnostics[]=new PanelDomainDiagnostic('agents.'.$name.'.commands','agent_command_missing','Agent commands must reference declared commands.');}}}
		usort($diagnostics,static fn(PanelDomainDiagnostic $a,PanelDomainDiagnostic $b):int=>[$a->path(),$a->code()]<=>[$b->path(),$b->code()]);return$diagnostics;
	}

	public function id():string{return$this->manifest['id'];}
	public function version():string{return$this->manifest['version'];}
	public function label():string{return$this->manifest['label'];}
	public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,mixed> */ public function values():array{return$this->manifest;}
	/** @return array<string,mixed>|list<array<string,mixed>> */ public function section(string $name):array{if(!in_array($name,self::SECTIONS,true)){throw new \OutOfBoundsException('Unknown domain manifest section.');}return$this->manifest[$name];}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_domain_manifest','version'=>self::VERSION]+$this->manifest+['fingerprint'=>$this->fingerprint]);}

	/** @param array<string,mixed> $source @return array<string,mixed> */
	private static function normalize(array $source):array {
		PanelOperationsGuard::object($source,'domain manifest',32);$allowed=array_merge(['id','version','label','description','metadata'],self::SECTIONS);$unknown=array_values(array_diff(array_keys($source),$allowed));if($unknown!==[]){throw new \InvalidArgumentException('Unknown domain manifest key: '.(string)$unknown[0]);}
		$id=PanelOperationsGuard::name((string)($source['id']??''),'domain id');$version=trim((string)($source['version']??''));if($version===''||strlen($version)>64||preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/D',$version)!==1){throw new \InvalidArgumentException('Domain version is invalid.');}
		$label=PanelOperationsGuard::label((string)($source['label']??$id),'domain label');$description=trim((string)($source['description']??''));if(strlen($description)>4096){throw new \LengthException('Domain description exceeds its byte limit.');}
		$entities=self::namedMap($source['entities']??[],'entities',512,static function(string $name,array $entity):array{
			$fields=self::namedMap($entity['fields']??[],'entity fields',1024,static function(string $field,array $definition):array{
				$type=strtolower(trim((string)($definition['type']??'string')));$enum=isset($definition['enum'])?PanelOperationsGuard::names((array)$definition['enum'],'enum value',96,1024):[];
				$result=['type'=>$type,'label'=>PanelOperationsGuard::label((string)($definition['label']??self::humanize($field)),'field label'),'required'=>($definition['required']??false)===true,'nullable'=>($definition['nullable']??true)!==false,'immutable'=>($definition['immutable']??false)===true,'searchable'=>($definition['searchable']??false)===true,'sortable'=>($definition['sortable']??false)===true,'classification'=>PanelOperationsGuard::name((string)($definition['classification']??'internal'),'field classification')];
				if($enum!==[]){$result['enum']=$enum;}if(array_key_exists('default',$definition)){$result['default']=PanelOperationsGuard::canonical($definition['default']);}return$result+['metadata'=>PanelOperationsGuard::safeMetadata(is_array($definition['metadata']??null)?$definition['metadata']:[])];
			});
			$states=PanelOperationsGuard::names(is_array($entity['states']??null)?$entity['states']:[],'entity state');
			return['label'=>PanelOperationsGuard::label((string)($entity['label']??self::humanize($name)),'entity label'),'primary_key'=>PanelOperationsGuard::name((string)($entity['primary_key']??'id'),'primary key'),'fields'=>$fields,'states'=>$states,'metadata'=>PanelOperationsGuard::safeMetadata(is_array($entity['metadata']??null)?$entity['metadata']:[])];
		});
		$relationships=self::listOfObjects($source['relationships']??[],'relationships',2048,static function(array $item,int $index):array{return['id'=>PanelOperationsGuard::name((string)($item['id']??'relationship_'.$index),'relationship id'),'from'=>PanelOperationsGuard::name((string)($item['from']??''),'relationship source'),'to'=>PanelOperationsGuard::name((string)($item['to']??''),'relationship target'),'type'=>PanelOperationsGuard::name((string)($item['type']??'references'),'relationship type'),'cardinality'=>PanelOperationsGuard::name((string)($item['cardinality']??'many_to_one'),'relationship cardinality'),'required'=>($item['required']??false)===true,'metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])] ;});
		$commands=self::namedMap($source['commands']??[],'commands',2048,static function(string $name,array $item):array{$risk=strtolower((string)($item['risk']??'medium'));if(!in_array($risk,['low','medium','high','critical'],true)){throw new \InvalidArgumentException('Command risk is invalid.');}return['label'=>PanelOperationsGuard::label((string)($item['label']??self::humanize($name)),'command label'),'entity'=>PanelOperationsGuard::name((string)($item['entity']??''),'command entity'),'operation'=>PanelOperationsGuard::name((string)($item['operation']??$name),'command operation'),'risk'=>$risk,'reversible'=>($item['reversible']??false)===true,'approval'=>max(0,min(16,(int)($item['approval']??0))),'policy'=>isset($item['policy'])?PanelOperationsGuard::name((string)$item['policy'],'command policy'):null,'input'=>PanelOperationsGuard::canonical(is_array($item['input']??null)?$item['input']:[]),'effects'=>PanelOperationsGuard::canonical(is_array($item['effects']??null)?$item['effects']:[]),'metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])];});
		$policies=self::namedMap($source['policies']??[],'policies',2048,static function(string $name,array $item):array{return['abilities'=>PanelOperationsGuard::abilityPatterns(is_array($item['abilities']??null)?$item['abilities']:[$name],'policy ability'),'effect'=>in_array(($effect=strtolower((string)($item['effect']??'deny'))),['allow','deny'],true)?$effect:'deny','priority'=>max(-100000,min(100000,(int)($item['priority']??0))),'when'=>PanelOperationsGuard::canonical(is_array($item['when']??null)?$item['when']:[]),'obligations'=>PanelOperationsGuard::canonical(is_array($item['obligations']??null)?$item['obligations']:[]),'metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])];});
		$workflows=self::namedMap($source['workflows']??[],'workflows',1024,static function(string $name,array $item):array{$transitions=self::listOfObjects($item['transitions']??[],'workflow transitions',4096,static function(array $transition,int $index):array{return['name'=>PanelOperationsGuard::name((string)($transition['name']??'transition_'.$index),'transition name'),'from'=>PanelOperationsGuard::name((string)($transition['from']??''),'transition source'),'to'=>PanelOperationsGuard::name((string)($transition['to']??''),'transition target'),'command'=>isset($transition['command'])?PanelOperationsGuard::name((string)$transition['command'],'transition command'):'','reversible'=>($transition['reversible']??false)===true,'sla_seconds'=>max(0,(int)($transition['sla_seconds']??0))];});return['label'=>PanelOperationsGuard::label((string)($item['label']??self::humanize($name)),'workflow label'),'entity'=>PanelOperationsGuard::name((string)($item['entity']??''),'workflow entity'),'initial'=>PanelOperationsGuard::name((string)($item['initial']??''),'initial state'),'states'=>PanelOperationsGuard::names(is_array($item['states']??null)?$item['states']:[],'workflow state'),'transitions'=>$transitions,'metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])];});
		$metrics=self::namedMap($source['metrics']??[],'metrics',2048,static function(string $name,array $item):array{$aggregation=strtolower((string)($item['aggregation']??'count'));if(!in_array($aggregation,['count','sum','average','minimum','maximum','distinct_count','ratio'],true)){throw new \InvalidArgumentException('Metric aggregation is invalid.');}return['label'=>PanelOperationsGuard::label((string)($item['label']??self::humanize($name)),'metric label'),'entity'=>PanelOperationsGuard::name((string)($item['entity']??''),'metric entity'),'aggregation'=>$aggregation,'field'=>isset($item['field'])?PanelOperationsGuard::name((string)$item['field'],'metric field'):'','dimensions'=>PanelOperationsGuard::names(is_array($item['dimensions']??null)?$item['dimensions']:[],'metric dimension'),'filter'=>PanelOperationsGuard::canonical(is_array($item['filter']??null)?$item['filter']:[]),'metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])];});
		$queues=self::namedMap($source['queues']??[],'queues',1024,static function(string $name,array $item):array{return['label'=>PanelOperationsGuard::label((string)($item['label']??self::humanize($name)),'queue label'),'entity'=>isset($item['entity'])?PanelOperationsGuard::name((string)$item['entity'],'queue entity'):'','states'=>PanelOperationsGuard::names(is_array($item['states']??null)?$item['states']:[],'queue state'),'priority'=>max(-100000,min(100000,(int)($item['priority']??0))),'sla_seconds'=>max(0,(int)($item['sla_seconds']??0)),'filter'=>PanelOperationsGuard::canonical(is_array($item['filter']??null)?$item['filter']:[]),'metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])];});
		$surfaces=self::namedMap($source['surfaces']??[],'surfaces',2048,static function(string $name,array $item):array{$kind=strtolower((string)($item['kind']??'workspace'));if(!in_array($kind,['resource','workspace','queue','board','dashboard','form','show','timeline'],true)){throw new \InvalidArgumentException('Surface kind is invalid.');}return['label'=>PanelOperationsGuard::label((string)($item['label']??self::humanize($name)),'surface label'),'kind'=>$kind,'entity'=>isset($item['entity'])?PanelOperationsGuard::name((string)$item['entity'],'surface entity'):'','queue'=>isset($item['queue'])?PanelOperationsGuard::name((string)$item['queue'],'surface queue'):'','layout'=>PanelOperationsGuard::canonical(is_array($item['layout']??null)?$item['layout']:[]),'metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])];});
		$agents=self::namedMap($source['agents']??[],'agents',512,static function(string $name,array $item):array{return['label'=>PanelOperationsGuard::label((string)($item['label']??self::humanize($name)),'agent label'),'commands'=>PanelOperationsGuard::names(is_array($item['commands']??null)?$item['commands']:[],'agent command'),'instructions'=>substr(trim((string)($item['instructions']??'')),0,16384),'risk_ceiling'=>in_array(($risk=strtolower((string)($item['risk_ceiling']??'medium'))),['low','medium','high','critical'],true)?$risk:'medium','metadata'=>PanelOperationsGuard::safeMetadata(is_array($item['metadata']??null)?$item['metadata']:[])];});
		$result=['id'=>$id,'version'=>$version,'label'=>$label,'description'=>$description,'entities'=>$entities,'relationships'=>$relationships,'workflows'=>$workflows,'commands'=>$commands,'policies'=>$policies,'metrics'=>$metrics,'surfaces'=>$surfaces,'queues'=>$queues,'agents'=>$agents,'metadata'=>PanelOperationsGuard::safeMetadata(is_array($source['metadata']??null)?$source['metadata']:[])];return PanelOperationsGuard::canonical($result);
	}

	/** @param mixed $source @param callable(string,array<string,mixed>):array<string,mixed> $normalize @return array<string,array<string,mixed>> */
	private static function namedMap(mixed $source,string $label,int $limit,callable $normalize):array {
		if(!is_array($source)){throw new \InvalidArgumentException(ucfirst($label).' must be an object-like map.');}PanelOperationsGuard::object($source,$label,$limit);$result=[];
		foreach($source as$key=>$value){$name=PanelOperationsGuard::name((string)$key,rtrim($label,'s').' name');if(isset($result[$name])){throw new \InvalidArgumentException(ucfirst($label).' contain a normalized name collision.');}if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new \InvalidArgumentException(ucfirst($label).' entries must be object-like maps.');}$result[$name]=$normalize($name,$value);}ksort($result,SORT_STRING);return$result;
	}

	/** @param mixed $source @param callable(array<string,mixed>,int):array<string,mixed> $normalize @return list<array<string,mixed>> */
	private static function listOfObjects(mixed $source,string $label,int $limit,callable $normalize):array {
		if(!is_array($source)||($source!==[]&&!array_is_list($source))){throw new \InvalidArgumentException(ucfirst($label).' must be a list.');}if(count($source)>$limit){throw new \LengthException(ucfirst($label).' exceeds its item limit.');}$result=[];foreach($source as$index=>$value){if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new \InvalidArgumentException(ucfirst($label).' entries must be object-like maps.');}$result[]=$normalize($value,$index);}return$result;
	}

	/** @return list<string> */
	private static function fieldTypes():array {
		$types=['string','text','integer','number','boolean','date','datetime','time','uuid','ulid','email','url','money','enum','json','reference','file'];
		return$types;
	}
	private static function humanize(string $value):string{return ucwords(str_replace(['_','-','.'],' ',$value));}
}
