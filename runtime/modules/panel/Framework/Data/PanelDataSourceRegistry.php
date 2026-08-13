<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Instance-owned, contribution-aware registry for trusted data-source adapters.
 *
 * Capabilities and provenance are validated and snapshotted at registration so
 * public manifests never execute adapter code or retain secret-bearing values.
 * Contributor layers allow plugin removal to reveal the previous registration,
 * while checkpoint/restore gives host boot transactions an exact rollback unit.
 */
final class PanelDataSourceRegistry implements \JsonSerializable, PanelCheckpointableService {
	private const POLICIES=['reject','keep_first','replace'];
	private const MAX_SOURCES=512;
	private const MAX_LAYERS_PER_SOURCE=64;
	private const MAX_TOTAL_LAYERS=4096;
	private const MAX_REGISTRY_BYTES=16777216;
	/** @var array<string,list<array{source:PanelDataSource,owner:string,capabilities:array<string,mixed>,meta:array<string,mixed>,revision:int}>> */
	private array $layers=[];
	/** @var \WeakMap<PanelDataSource,true> */
	private \WeakMap $trustedSources;
	private int $revision=0;
	private int $registryBytes=0;

	public function __construct(private string $conflictPolicy='reject') {
		$this->conflictPolicy=self::policy($conflictPolicy);
		$this->trustedSources=new \WeakMap();
	}

	public function conflictPolicy(): string { return $this->conflictPolicy; }
	public function revision(): int { return $this->revision; }
	public function conflictPolicyUsing(string $policy): self {
		$policy=self::policy($policy);
		if($policy!==$this->conflictPolicy){ $this->conflictPolicy=$policy; $this->revision++; }
		return $this;
	}

	/**
	 * Registers an application-owned source. `replace=true` deliberately removes
	 * every previous layer to preserve the original registry's replacement API.
	 */
	public function register(string $name, PanelDataSource $source, bool $replace=false): self {
		$name=$this->name($name);
		if(isset($this->layers[$name]) && !$replace){ throw new \LogicException("Panel data source '{$name}' is already registered."); }
		if(!isset($this->layers[$name]) && count($this->layers)>=self::MAX_SOURCES){ throw new \LengthException('Panel data-source registries support at most 512 names.'); }
		$revision=$this->revision+1;$record=$this->record($source,'application',[],$revision);$bytes=$this->registryBytes-self::layerBytes($this->layers[$name]??[])+self::recordBytes($record);self::assertBytes($bytes);
		$candidate=$this->layers;$candidate[$name]=[$record];$this->layers=$candidate;$this->revision=$revision;$this->registryBytes=$bytes;ksort($this->layers,SORT_STRING);
		return $this;
	}

	/**
	 * Adds a reversible owner layer for plugin or package contributions.
	 *
	 * @param array<string,mixed> $meta Secret-free provenance metadata.
	 */
	public function contribute(string $name, PanelDataSource $source, string $owner, array $meta=[], ?string $policy=null): bool {
		$name=$this->name($name); $owner=self::owner($owner); $policy=self::policy($policy ?? $this->conflictPolicy);
		$layers=$this->layers[$name] ?? []; $top=$layers[array_key_last($layers)] ?? null;
		if(is_array($top) && $top['owner']!==$owner){
			if($policy==='reject'){ throw new \LogicException("Panel data source '{$name}' conflicts between '{$top['owner']}' and '{$owner}'."); }
			if($policy==='keep_first'){ return false; }
		}
		if($layers===[] && count($this->layers)>=self::MAX_SOURCES){ throw new \LengthException('Panel data-source registries support at most 512 names.'); }
		$ownerPresent=false; foreach($layers as $record){ if($record['owner']===$owner){ $ownerPresent=true; break; } }
		if(!$ownerPresent && count($layers)>=self::MAX_LAYERS_PER_SOURCE){ throw new \LengthException('Panel data-source names support at most 64 contribution layers.'); }
		if(!$ownerPresent && $this->layerCount()>=self::MAX_TOTAL_LAYERS){ throw new \LengthException('Panel data-source registries support at most 4096 contribution layers.'); }
		$layers=array_values(array_filter($layers, static fn(array $record): bool=>$record['owner']!==$owner));
		$revision=$this->revision+1;$layers[]=$this->record($source,$owner,$meta,$revision);$bytes=$this->registryBytes-self::layerBytes($this->layers[$name]??[])+self::layerBytes($layers);self::assertBytes($bytes);$candidate=$this->layers;$candidate[$name]=$layers;
		$this->layers=$candidate;$this->revision=$revision;$this->registryBytes=$bytes;ksort($this->layers,SORT_STRING);
		return true;
	}

	public function forget(string $name): self {
		$name=$this->name($name);
		if(isset($this->layers[$name])){ $this->registryBytes-=self::layerBytes($this->layers[$name]);unset($this->layers[$name]);$this->revision++; }
		return $this;
	}

	public function unregisterContributor(string $owner): self {
		$owner=self::owner($owner); $changed=false;
		foreach(array_keys($this->layers) as $name){
			$before=count($this->layers[$name]);
			$this->layers[$name]=array_values(array_filter($this->layers[$name], static fn(array $record): bool=>$record['owner']!==$owner));
			if(count($this->layers[$name])!==$before){ $changed=true; }
			if($this->layers[$name]===[]){ unset($this->layers[$name]); }
		}
		if($changed){$this->revision++;$this->registryBytes=self::budgetBytes($this->layers);}
		return $this;
	}

	public function has(string $name): bool { return $this->active($this->name($name))!==null; }

	public function get(string $name): PanelDataSource {
		$name=$this->name($name); $record=$this->active($name);
		return $record['source'] ?? throw new \OutOfBoundsException("Panel data source '{$name}' is not registered.");
	}

	public function mutable(string $name):PanelMutableDataSource {
		$source=$this->get($name);
		if(!$source instanceof PanelMutableDataSource){throw new PanelDataMutationUnsupported(['mutable_data_source'],"Panel data source '{$name}' is read-only.");}
		$capabilities=PanelDataMutationCapabilities::fromArray($source->capabilities());if(!$capabilities->enabled()){throw new PanelDataMutationUnsupported(['mutations'],"Panel data source '{$name}' is read-only.");}return$source;
	}

	public function mutate(string $name,PanelDataMutation $mutation):PanelDataMutationReceipt{return$this->mutable($name)->mutate($mutation);}
	public function mutateBatch(string $name,PanelDataMutationBatch $batch):PanelDataMutationBatchResult{return$this->mutable($name)->mutateBatch($batch);}

	/** @return list<string> */
	public function names(): array { $names=array_keys($this->layers); sort($names, SORT_STRING); return $names; }

	/** @return list<array{name:string,owner:string,active:bool,revision:int,meta:array<string,mixed>}> */
	public function provenance(): array {
		$out=[];
		foreach($this->layers as $name=>$layers){
			$last=array_key_last($layers);
			foreach($layers as $index=>$record){ $out[]=['name'=>$name,'owner'=>$record['owner'],'active'=>$index===$last,'revision'=>$record['revision'],'meta'=>self::publicSnapshot($record['meta'])]; }
		}
		usort($out, static fn(array $a,array $b): int=>[$a['name'],$a['revision']]<=>[$b['name'],$b['revision']]);
		return $out;
	}

	/** @return array{layers:array<string,list<array{source:PanelDataSource,owner:string,capabilities:array<string,mixed>,meta:array<string,mixed>,revision:int}>>,revision:int,conflict_policy:string} */
	public function checkpoint(): array { return ['layers'=>$this->layers,'revision'=>$this->revision,'conflict_policy'=>$this->conflictPolicy]; }
	public function checkpointType(): string { return 'panel_data_source_registry'; }

	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint): self {
		if(array_keys($checkpoint)!==['layers','revision','conflict_policy'] || !is_array($checkpoint['layers']) || !is_int($checkpoint['revision']) || $checkpoint['revision']<0 || !is_string($checkpoint['conflict_policy'])){
			throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.');
		}
		self::policy($checkpoint['conflict_policy']);
		if(count($checkpoint['layers'])>self::MAX_SOURCES){ throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.'); }
		$totalLayers=0;
		foreach($checkpoint['layers'] as $name=>$layers){
			if(!is_string($name) || $this->name($name)!==$name || !is_array($layers) || $layers===[] || !array_is_list($layers) || count($layers)>self::MAX_LAYERS_PER_SOURCE){ throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.'); }
			$totalLayers+=count($layers); if($totalLayers>self::MAX_TOTAL_LAYERS){ throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.'); }
			$owners=[];
			foreach($layers as $record){
				if(!is_array($record) || array_keys($record)!==['source','owner','capabilities','meta','revision'] || !($record['source'] ?? null) instanceof PanelDataSource || !isset($this->trustedSources[$record['source']]) || !is_string($record['owner'] ?? null) || isset($owners[$record['owner']]) || !is_array($record['capabilities'] ?? null) || !is_array($record['meta'] ?? null) || !is_int($record['revision'] ?? null) || $record['revision']<1 || $record['revision']>$checkpoint['revision']){
					throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.');
				}
				try{ $owner=self::owner($record['owner']); $capabilities=self::normalizeCapabilities($record['capabilities']); $meta=self::meta($record['meta']); }
				catch(\Throwable $error){ throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.', 0, $error); }
				if($owner!==$record['owner'] || $capabilities!==$record['capabilities'] || $meta!==$record['meta']){ throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.'); }
				$owners[$record['owner']]=true;
			}
		}
		try{$bytes=self::budgetBytes($checkpoint['layers']);}catch(\Throwable $error){throw new \InvalidArgumentException('Invalid Panel data-source registry checkpoint.',0,$error);}
		$this->layers=$checkpoint['layers'];$this->revision=$checkpoint['revision'];$this->conflictPolicy=$checkpoint['conflict_policy'];$this->registryBytes=$bytes;
		ksort($this->layers, SORT_STRING); return $this;
	}

	public function fingerprint(): string {
		$active=[];
		foreach($this->names() as $name){ $record=$this->active($name); if($record!==null){ $active[$name]=['owner'=>$record['owner'],'class'=>$record['source']::class,'capabilities'=>self::publicSnapshot($record['capabilities']),'meta'=>self::publicSnapshot($record['meta'])]; } }
		return hash('sha256', PanelQueryValue::stableJson($active));
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		$sources=[];
		foreach($this->names() as $name){
			$record=$this->active($name); if($record===null){ continue; }
			$sources[$name]=[
				'owner'=>$record['owner'], 'class'=>$record['source']::class,
				'capabilities'=>self::publicSnapshot($record['capabilities']), 'meta'=>self::publicSnapshot($record['meta']),
				'revision'=>$record['revision'], 'layers'=>count($this->layers[$name]),
			];
		}
		return [
			'type'=>'panel_data_source_registry', 'contract_version'=>1,
			'revision'=>$this->revision, 'conflict_policy'=>$this->conflictPolicy,
			'count'=>count($sources), 'sources'=>$sources, 'fingerprint'=>$this->fingerprint(),
			'capabilities'=>[
				'instance_scoped'=>true, 'contributor_layers'=>true,
				'transactional_checkpoint'=>true, 'registration_manifest_snapshot'=>true,
				'live_adapter_code_run_by_manifest'=>false, 'secret_values_serialized'=>false,
				'typed_mutation_dispatch'=>true, 'mutation_capabilities_snapshotted'=>true,
			],
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }

	/** @return ?array{source:PanelDataSource,owner:string,capabilities:array<string,mixed>,meta:array<string,mixed>,revision:int} */
	private function active(string $name): ?array {
		$layers=$this->layers[$name] ?? []; $record=$layers[array_key_last($layers)] ?? null;
		return is_array($record) ? $record : null;
	}

	/** @param array<string,mixed> $meta @return array{source:PanelDataSource,owner:string,capabilities:array<string,mixed>,meta:array<string,mixed>,revision:int} */
	private function record(PanelDataSource $source, string $owner, array $meta, int $revision): array {
		$capabilities=self::capabilityMap($source);$meta=self::meta($meta);
		$this->trustedSources[$source]=true;
		return ['source'=>$source,'owner'=>$owner,'capabilities'=>$capabilities,'meta'=>$meta,'revision'=>$revision];
	}

	/** @return array<string,mixed> */
	private static function capabilityMap(PanelDataSource $source): array {
		try{ $capabilities=$source->capabilities(); }
		catch(\Throwable $error){ throw new \UnexpectedValueException('Panel data-source capabilities are unavailable.', 0, $error); }
		return self::normalizeCapabilities($capabilities);
	}

	/** @param array<string,mixed> $capabilities @return array<string,mixed> */
	private static function normalizeCapabilities(array $capabilities): array {
		if(($capabilities!==[] && array_is_list($capabilities)) || count($capabilities)>128){ throw new \UnexpectedValueException('Panel data-source capabilities must be a bounded object-like map.'); }
		$clean=[];
		foreach($capabilities as $name=>$value){
			if(!is_string($name) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name)!==1){ throw new \UnexpectedValueException('Panel data-source capability names are invalid.'); }
			if(is_array($value)){
				if(!array_is_list($value) || count($value)>128){ throw new \UnexpectedValueException('Panel data-source capability lists are invalid.'); }
				$list=[]; foreach($value as $item){ if(!is_string($item) || strlen($item)>256 || preg_match('//u',$item)!==1){ throw new \UnexpectedValueException('Panel data-source capability list entries are invalid.'); } $list[]=$item; }
				$value=$list;
			}
			elseif(!is_bool($value) && !is_int($value) && !is_string($value)){ throw new \UnexpectedValueException('Panel data-source capability values must be booleans, integers, strings, or string lists.'); }
			if(is_int($value) && ($value < -1000000000 || $value > 1000000000)){ throw new \UnexpectedValueException('Panel data-source capability integers are outside the supported range.'); }
			if(is_string($value) && (strlen($value)>512 || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x1F\x7F]/',$value)===1)){ throw new \UnexpectedValueException('Panel data-source capability strings are invalid.'); }
			if(!is_bool($value) && preg_match('/(?:^|[_.-])(?:password|passwd|token|secret|credential|cookie|csrf|private_key|encryption_key|signing_key|pepper)(?:[_.-]|$)/i',$name)===1){ throw new \UnexpectedValueException('Panel data-source capability maps cannot contain secret-bearing values.'); }
			$clean[$name]=$value;
		}
		ksort($clean, SORT_STRING);
		if(strlen(PanelQueryValue::stableJson($clean))>32768){ throw new \UnexpectedValueException('Panel data-source capabilities exceed the public manifest budget.'); }
		return $clean;
	}

	private function layerCount(): int { $count=0; foreach($this->layers as $layers){ $count+=count($layers); } return $count; }
	/** @param array<string,list<array{source:PanelDataSource,owner:string,capabilities:array<string,mixed>,meta:array<string,mixed>,revision:int}>> $layers */
	private static function recordBytes(array $record): int {return strlen(PanelQueryValue::stableJson(['class'=>$record['source']::class,'owner'=>$record['owner'],'capabilities'=>$record['capabilities'],'meta'=>$record['meta']]));}
	private static function layerBytes(array $records): int {$bytes=0;foreach($records as$record){$bytes+=self::recordBytes($record);}return$bytes;}
	private static function budgetBytes(array $layers): int {$bytes=0;foreach($layers as$records){$bytes+=self::layerBytes($records);self::assertBytes($bytes);}return$bytes;}
	private static function assertBytes(int $bytes): void {if($bytes>self::MAX_REGISTRY_BYTES){throw new \LengthException('Panel data-source registry metadata exceeds 16 MiB.');}}

	/**
	 * Removes deploy-time network coordinates from every public registry view.
	 * Boolean/int capability flags remain useful, while string/array endpoint,
	 * origin, URL, header, and transport details are never serialized.
	 */
	private static function publicSnapshot(mixed $value,?string $key=null): mixed {
		if($key!==null){
			$split=preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$key)??$key;
			$normalized=strtolower(trim(preg_replace('/[^a-z0-9]+/i','_',$split)??'','_'));
			if(preg_match('/(?:^|_)(?:endpoint|origin|base_url|url|uri|headers?|transport)(?:_|$)/D',$normalized)===1&&!is_bool($value)&&$value!==null){
				return PanelSensitiveDataSanitizer::REDACTED;
			}
			if(PanelSensitiveDataSanitizer::isSensitiveKey($key)&&!is_bool($value)&&$value!==null){return PanelSensitiveDataSanitizer::REDACTED;}
		}
		if(!is_array($value)){return PanelSensitiveDataSanitizer::sanitize($value,['max_depth'=>16,'max_items'=>512,'max_string_bytes'=>2048]);}
		$out=[];$seen=0;foreach($value as$itemKey=>$item){if($seen++>=512){$out['__truncated_items__']=count($value)-512;break;}$out[$itemKey]=self::publicSnapshot($item,is_string($itemKey)?$itemKey:null);}return$out;
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	private static function meta(array $meta): array {
		if($meta!==[] && array_is_list($meta)){ throw new \InvalidArgumentException('Panel data-source provenance metadata must be an object-like map.'); }
		try{ $meta=PanelQueryValue::normalize($meta, 'data-source provenance'); }
		catch(\Throwable $error){ throw new \InvalidArgumentException('Panel data-source provenance metadata must contain bounded JSON values.', 0, $error); }
		if(strlen(PanelQueryValue::stableJson($meta))>8192){ throw new \LengthException('Panel data-source provenance metadata exceeds 8192 bytes.'); }
		$safe=PanelSensitiveDataSanitizer::sanitize($meta, ['max_depth'=>16,'max_items'=>256,'max_string_bytes'=>2048]);
		return is_array($safe) && !array_is_list($safe) ? $safe : [];
	}

	private function name(string $name): string {
		$name=strtolower(trim($name)); $name=preg_replace('/[^a-z0-9]+/', '_', $name) ?? ''; $name=trim($name, '_');
		if($name===''){ throw new \InvalidArgumentException('Panel data source name cannot be blank.'); }
		return substr($name, 0, 100);
	}

	private static function owner(string $owner): string {
		$owner=strtolower(trim($owner)); $owner=preg_replace('/[^a-z0-9_.-]+/', '_', $owner) ?? ''; $owner=trim($owner, '_.-');
		if($owner==='' || strlen($owner)>100){ throw new \InvalidArgumentException('Panel data-source contributor names must contain between 1 and 100 normalized bytes.'); }
		return $owner;
	}

	private static function policy(string $policy): string {
		$policy=strtolower(trim($policy));
		if(!in_array($policy, self::POLICIES, true)){ throw new \InvalidArgumentException('Panel data-source conflict policy must be reject, keep_first, or replace.'); }
		return $policy;
	}
}
