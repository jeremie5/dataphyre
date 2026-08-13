<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Per-panel definition registry and the sole authorized path from signed windows to data adapters. */
final class PanelDataSurfaceRegistry implements \JsonSerializable, PanelCheckpointableService {
	private const POLICIES=['reject','keep_first','replace'];
	private const MAX_DEFINITIONS=512;
	private const MAX_LAYERS_PER_DEFINITION=64;
	private const MAX_REGISTRY_BYTES=8388608;
	/** @var array<string,list<array{definition:PanelDataSurfaceDefinition,manifest:array<string,mixed>,owner:string,meta:array<string,mixed>,revision:int}>> */
	private array $layers=[];
	/** @var \WeakMap<PanelDataSurfaceDefinition,true> */ private \WeakMap $trustedDefinitions;
	private int $revision=0;
	private \Closure $authorize;

	public function __construct(
		private readonly PanelDataSourceRegistry $sources,
		private readonly PanelDataSurfaceIntentSigner $signer,
		callable $authorize,
		private string $conflictPolicy='reject'
	){
		$this->authorize=\Closure::fromCallable($authorize);
		$this->conflictPolicy=self::policy($conflictPolicy);
		$this->trustedDefinitions=new \WeakMap();
	}

	public function conflictPolicy(): string { return $this->conflictPolicy; }
	public function revision(): int { return $this->revision; }
	public function conflictPolicyUsing(string $policy): self {
		$policy=self::policy($policy);
		if($policy!==$this->conflictPolicy){ $this->conflictPolicy=$policy; $this->revision++; }
		return $this;
	}

	public function register(PanelDataSurfaceDefinition $definition, bool $replace=false): self {
		$id=$definition->id();
		if(isset($this->layers[$id]) && !$replace){ throw new \LogicException("Panel DataSurface definition '{$id}' is already registered."); }
		if(!isset($this->layers[$id]) && count($this->layers)>=self::MAX_DEFINITIONS){ throw new \LengthException('Panel DataSurface registries support at most 512 definitions.'); }
		$revision=$this->revision+1;$candidate=$this->layers;$candidate[$id]=[$this->record($definition,'application',[],$revision)];self::assertBudget($candidate);
		$this->layers=$candidate;$this->revision=$revision;
		ksort($this->layers,SORT_STRING);
		return $this;
	}

	/** @param array<string,mixed> $meta */
	public function contribute(PanelDataSurfaceDefinition $definition,string $owner,array $meta=[],?string $policy=null): bool {
		$id=$definition->id();$owner=self::owner($owner);$policy=self::policy($policy??$this->conflictPolicy);
		$layers=$this->layers[$id]??[];$top=$layers[array_key_last($layers)]??null;
		if(is_array($top)&&$top['owner']!==$owner){if($policy==='reject'){throw new \LogicException("Panel DataSurface definition '{$id}' conflicts between '{$top['owner']}' and '{$owner}'.");}if($policy==='keep_first'){return false;}}
		if($layers===[]&&count($this->layers)>=self::MAX_DEFINITIONS){throw new \LengthException('Panel DataSurface registries support at most 512 definitions.');}
		$ownerPresent=false;foreach($layers as$record){if($record['owner']===$owner){$ownerPresent=true;break;}}
		if(!$ownerPresent&&count($layers)>=self::MAX_LAYERS_PER_DEFINITION){throw new \LengthException('Panel DataSurface definitions support at most 64 contribution layers.');}
		$layers=array_values(array_filter($layers,static fn(array $record):bool=>$record['owner']!==$owner));$revision=$this->revision+1;
		$layers[]=$this->record($definition,$owner,$meta,$revision);$candidate=$this->layers;$candidate[$id]=$layers;self::assertBudget($candidate);$this->layers=$candidate;$this->revision=$revision;ksort($this->layers,SORT_STRING);return true;
	}

	public function forget(string $id): self { $id=PanelDataSurfaceGuard::identifier($id,'definition',100);if(isset($this->layers[$id])){unset($this->layers[$id]);$this->revision++;}return $this; }
	public function unregisterContributor(string $owner): self {
		$owner=self::owner($owner);$changed=false;
		foreach(array_keys($this->layers)as$id){$before=count($this->layers[$id]);$this->layers[$id]=array_values(array_filter($this->layers[$id],static fn(array $record):bool=>$record['owner']!==$owner));if(count($this->layers[$id])!==$before){$changed=true;}if($this->layers[$id]===[]){unset($this->layers[$id]);}}
		if($changed){$this->revision++;}return $this;
	}
	public function has(string $id): bool { return $this->active(PanelDataSurfaceGuard::identifier($id,'definition',100))!==null; }
	public function get(string $id): PanelDataSurfaceDefinition {
		$id=PanelDataSurfaceGuard::identifier($id, 'definition', 100);
		$record=$this->active($id);
		return $record['definition'] ?? throw new \OutOfBoundsException("Panel DataSurface definition '{$id}' is not registered.");
	}
	/** @return list<string> */ public function names(): array { $names=array_keys($this->layers);sort($names,SORT_STRING);return$names; }

	/** @return list<array{id:string,owner:string,active:bool,revision:int,meta:array<string,mixed>}> */
	public function provenance(): array {
		$out=[];foreach($this->layers as$id=>$layers){$last=array_key_last($layers);foreach($layers as$index=>$record){$out[]=['id'=>$id,'owner'=>$record['owner'],'active'=>$index===$last,'revision'=>$record['revision'],'meta'=>$record['meta']];}}
		usort($out,static fn(array $a,array $b):int=>[$a['id'],$a['revision']]<=>[$b['id'],$b['revision']]);return$out;
	}

	public function checkpointType(): string { return 'panel_data_surface_registry'; }
	/** @return array<string,mixed> */
	public function checkpoint(): array { return ['layers'=>$this->layers,'revision'=>$this->revision,'conflict_policy'=>$this->conflictPolicy]; }
	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint): self {
		if(array_keys($checkpoint)!==['layers','revision','conflict_policy']||!is_array($checkpoint['layers'])||count($checkpoint['layers'])>self::MAX_DEFINITIONS||!is_int($checkpoint['revision'])||$checkpoint['revision']<0||!is_string($checkpoint['conflict_policy'])){throw new \InvalidArgumentException('Invalid Panel DataSurface registry checkpoint.');}
		try{$policy=self::policy($checkpoint['conflict_policy']);}catch(\Throwable $error){throw new \InvalidArgumentException('Invalid Panel DataSurface registry checkpoint.',0,$error);}
		foreach($checkpoint['layers']as$id=>$layers){
			if(!is_string($id)||PanelDataSurfaceGuard::identifier($id,'definition',100)!==$id||!is_array($layers)||!array_is_list($layers)||$layers===[]||count($layers)>self::MAX_LAYERS_PER_DEFINITION){throw new \InvalidArgumentException('Invalid Panel DataSurface registry checkpoint.');}
			$owners=[];foreach($layers as$record){
				if(!is_array($record)||array_keys($record)!==['definition','manifest','owner','meta','revision']||!($record['definition']??null)instanceof PanelDataSurfaceDefinition||!isset($this->trustedDefinitions[$record['definition']])||!is_array($record['manifest']??null)||!is_string($record['owner']??null)||isset($owners[$record['owner']])||!is_array($record['meta']??null)||!is_int($record['revision']??null)||$record['revision']<1||$record['revision']>$checkpoint['revision']){throw new \InvalidArgumentException('Invalid Panel DataSurface registry checkpoint.');}
				try{$owner=self::owner($record['owner']);$manifest=self::definitionManifest($record['definition']);$meta=self::meta($record['meta']);}catch(\Throwable $error){throw new \InvalidArgumentException('Invalid Panel DataSurface registry checkpoint.',0,$error);}
				if($owner!==$record['owner']||$manifest!==$record['manifest']||$meta!==$record['meta']||$record['definition']->id()!==$id){throw new \InvalidArgumentException('Invalid Panel DataSurface registry checkpoint.');}$owners[$owner]=true;
			}
		}
		try{self::assertBudget($checkpoint['layers']);}catch(\Throwable $error){throw new \InvalidArgumentException('Invalid Panel DataSurface registry checkpoint.',0,$error);}
		$this->layers=$checkpoint['layers'];$this->revision=$checkpoint['revision'];$this->conflictPolicy=$policy;ksort($this->layers,SORT_STRING);return$this;
	}

	public function issue(
		string $definition,
		PanelDataSurfaceContext $context,
		PanelDataQuery|array|null $query=null,
		?PanelDataSurfaceRange $range=null,
		int $ttl=300
	): PanelDataSurfaceWindowIntent {
		try{ $definitionObject=$this->get($definition); }
		catch(\Throwable){ throw new PanelDataSurfaceException('surface_not_found', 404, 'Panel DataSurface definition was not found.'); }
		$range ??= $definitionObject->defaultRange();
		$state=$definitionObject->safeState($query);
		$this->authorize([
			'operation'=>'issue','panel'=>$context->panel(),'definition'=>$definitionObject->id(),
			'definition_fingerprint'=>$definitionObject->fingerprint(),
			'resource'=>$definitionObject->resource(),'source'=>$definitionObject->source(),
			'surface'=>$definitionObject->surface()->value,'projection_fingerprint'=>$definitionObject->projection()->fingerprint(),
			'range'=>$range->jsonSerialize(),
		], $context);
		$source=$this->source($definitionObject->source());
		$resolved=$this->resolve($definitionObject, $context, $state, $range, $source);
		return $this->signer->issue($definitionObject, $resolved, $state, $range, $context, $ttl);
	}

	public function execute(PanelDataSurfaceWindowRequest $request, PanelDataSurfaceContext $context, int $continuationTtl=300): PanelDataSurfaceWindowResult {
		$verified=$this->signer->verify($request->intent(), $context);
		$this->authorize($verified->authorizationEnvelope(), $context);
		try{ $definition=$this->get($verified->definition()); }
		catch(\Throwable){ throw new PanelDataSurfaceException('surface_not_found', 404, 'Panel DataSurface definition was not found.'); }
			if(
				($verified->definitionFingerprint()!==null&&!hash_equals($definition->fingerprint(),$verified->definitionFingerprint()))||
				!hash_equals($definition->resource(), $verified->resource()) ||
			!hash_equals($definition->source(), $verified->source()) ||
			$definition->surface()!==$verified->surface() ||
			!hash_equals($definition->projection()->fingerprint(), $verified->projectionFingerprint())
		){ throw new PanelDataSurfaceException('intent_stale', 409, 'Panel DataSurface definition has changed.'); }
		$interaction=$request->interaction();$canvas=$definition->canvas();
		$source=$this->source($verified->source());
		$state=$verified->safeState();$range=$verified->range();$query=$this->resolve($definition,$context,$state,$range,$source);
		if(!hash_equals($verified->queryFingerprint(), $query->fingerprint())){ throw new PanelDataSurfaceException('intent_stale', 409, 'Panel DataSurface query contract has changed.'); }
		if($interaction!==null){
			if(!$canvas instanceof PanelDataCanvasSpec||!$canvas->crossFilterEnabled()){throw new PanelDataSurfaceException('interaction_unsupported',409,'Panel DataSurface cross-filtering is not configured.');}
			$values=$interaction->values();$this->authorize(['operation'=>'interact','panel'=>$context->panel(),'definition'=>$definition->id(),'definition_fingerprint'=>$definition->fingerprint(),'resource'=>$definition->resource(),'source'=>$definition->source(),'surface'=>$definition->surface()->value,'interaction'=>$interaction->type(),'value_count'=>count($values),'values_digest'=>hash('sha256',PanelDataSurfaceGuard::canonicalJson($values)),'cross_filter_group'=>$canvas->crossFilterGroup()],$context);
			$state['canvas_filter']=['values'=>$values];$range=PanelDataSurfaceRange::make(0,$range->length(),$range->overscanBefore(),$range->overscanAfter());$query=$this->resolve($definition,$context,$state,$range,$source);
		}
		try{ $result=$source->query($query); }
		catch(PanelDataSurfaceException $exception){ throw $exception; }
		catch(PanelUnsupportedQueryException){ throw new PanelDataSurfaceException('adapter_incompatible', 409, 'Panel data adapter does not support this surface query.'); }
		catch(\Throwable){ throw new PanelDataSurfaceException('query_failed', 502, 'Panel DataSurface query failed.'); }
		return $this->window($definition,$query,$result,$context,$continuationTtl,$state,$range);
	}

	/** Secret-free operational manifest. */
	public function manifest(): array {
		$definitions=[];$sourceDefinitions=$this->sources->manifest()['sources']??[];
		foreach($this->names() as $name){
			$record=$this->active($name);if($record===null){continue;}$definition=$record['definition'];
			$sourceEntry=$sourceDefinitions[$definition->source()]??null;$sourceRegistered=is_array($sourceEntry);$capabilities=$sourceRegistered?$this->publicCapabilities(is_array($sourceEntry['capabilities']??null)?$sourceEntry['capabilities']:[]):[];
			$entry=$record['manifest'];
			$entry['source_registered']=$sourceRegistered;
			$entry['source_capabilities']=$capabilities;
			$entry['adapter_compatibility']='evaluated_at_execution';
			$entry['owner']=$record['owner'];$entry['revision']=$record['revision'];$entry['layers']=count($this->layers[$name]);$entry['meta']=$record['meta'];
			$definitions[$name]=$entry;
		}
			return ['type'=>'panel_data_surface_registry','version'=>1,'count'=>count($definitions),'revision'=>$this->revision,'conflict_policy'=>$this->conflictPolicy,'authorization'=>'required','definitions'=>$definitions,'intent_signer'=>$this->signer->jsonSerialize(),'fingerprint'=>$this->fingerprint(),'capabilities'=>['instance_scoped'=>true,'contributor_layers'=>true,'transactional_checkpoint'=>true,'registration_manifest_snapshot'=>true,'advanced_data_canvases'=>true,'signed_server_cross_filter'=>true,'definition_fingerprint_binding'=>true,'live_adapter_code_run_by_manifest'=>false],'secrets_exposed'=>false];
	}
	public function jsonSerialize(): array { return $this->manifest(); }
	public function fingerprint(): string {
		$active=[];foreach($this->names()as$id){$record=$this->active($id);if($record!==null){$active[$id]=['owner'=>$record['owner'],'manifest'=>$record['manifest'],'meta'=>$record['meta']];}}
		return hash('sha256',PanelDataSurfaceGuard::canonicalJson($active));
	}

	/** @param array<string,mixed> $envelope */
	private function authorize(array $envelope, PanelDataSurfaceContext $context): void {
		try{ $decision=($this->authorize)($envelope, $context); }
		catch(\Throwable){ throw new PanelDataSurfaceException('transport_authorization_unavailable', 503, 'Panel DataSurface authorization is unavailable.'); }
		if($decision!==true){ throw new PanelDataSurfaceException('transport_denied', 403, 'Panel DataSurface request was denied.'); }
	}

	private function source(string $name): PanelDataSource {
		try{ return $this->sources->get($name); }
		catch(\Throwable){ throw new PanelDataSurfaceException('source_unavailable', 503, 'Panel DataSurface source is unavailable.'); }
	}

	/** @return ?array{definition:PanelDataSurfaceDefinition,manifest:array<string,mixed>,owner:string,meta:array<string,mixed>,revision:int} */
	private function active(string $id): ?array {$layers=$this->layers[$id]??[];$record=$layers[array_key_last($layers)]??null;return is_array($record)?$record:null;}
	/** @param array<string,mixed> $meta @return array{definition:PanelDataSurfaceDefinition,manifest:array<string,mixed>,owner:string,meta:array<string,mixed>,revision:int} */
	private function record(PanelDataSurfaceDefinition $definition,string $owner,array $meta,int $revision): array {
		$manifest=self::definitionManifest($definition);$meta=self::meta($meta);$this->trustedDefinitions[$definition]=true;
		return['definition'=>$definition,'manifest'=>$manifest,'owner'=>$owner,'meta'=>$meta,'revision'=>$revision];
	}
	/** @return array<string,mixed> */
	private static function definitionManifest(PanelDataSurfaceDefinition $definition): array {$manifest=$definition->jsonSerialize();PanelDataSurfaceGuard::assertJson($manifest,65536);return$manifest;}
	/** @param array<string,mixed> $meta @return array<string,mixed> */
	private static function meta(array $meta): array {
		if($meta!==[]&&array_is_list($meta)){throw new \InvalidArgumentException('Panel DataSurface provenance metadata must be object-like.');}
		PanelDataSurfaceGuard::assertJson($meta,8192);$safe=PanelSensitiveDataSanitizer::sanitize($meta,['max_depth'=>12,'max_items'=>128,'max_string_bytes'=>1024]);
		if(!is_array($safe)||($safe!==[]&&array_is_list($safe))){throw new \InvalidArgumentException('Panel DataSurface provenance metadata is invalid.');}PanelDataSurfaceGuard::assertJson($safe,8192);return$safe;
	}
	private static function owner(string $owner): string {$owner=strtolower(trim($owner));$owner=preg_replace('/[^a-z0-9_.-]+/','_',$owner)??'';$owner=trim($owner,'_.-');if($owner===''||strlen($owner)>100){throw new \InvalidArgumentException('Panel DataSurface contributor names must contain between 1 and 100 normalized bytes.');}return$owner;}
	private static function policy(string $policy): string {$policy=strtolower(trim($policy));if(!in_array($policy,self::POLICIES,true)){throw new \InvalidArgumentException('Panel DataSurface conflict policy must be reject, keep_first, or replace.');}return$policy;}
	/** @param array<string,list<array{definition:PanelDataSurfaceDefinition,manifest:array<string,mixed>,owner:string,meta:array<string,mixed>,revision:int}>> $layers */
	private static function assertBudget(array $layers): void {$bytes=0;foreach($layers as$records){foreach($records as$record){$bytes+=strlen(json_encode(['manifest'=>$record['manifest'],'owner'=>$record['owner'],'meta'=>$record['meta']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));if($bytes>self::MAX_REGISTRY_BYTES){throw new \LengthException('Panel DataSurface registry metadata exceeds 8 MiB.');}}}}

	/** @param array<string,mixed> $state */
	private function resolve(PanelDataSurfaceDefinition $definition, PanelDataSurfaceContext $context, array $state, PanelDataSurfaceRange $range, PanelDataSource $source): PanelDataQuery {
		try{ $query=$definition->resolveQuery($context, $state); }
		catch(\Throwable){ throw new PanelDataSurfaceException('query_invalid', 422, 'Panel DataSurface query could not be materialized.'); }
		$capabilities=$source->capabilities();
		if($range->cursor()!==null && ($capabilities['cursor'] ?? false)!==true){ throw new PanelDataSurfaceException('cursor_unsupported', 409, 'Panel data adapter does not support cursor windows.'); }
		if($range->cursor()===null && ($capabilities['offset'] ?? false)!==true){ throw new PanelDataSurfaceException('offset_unsupported', 409, 'Panel data adapter does not support offset windows.'); }
		if(($capabilities['select'] ?? false)===true){ $query=$query->select(array_values(array_unique(array_merge($query->selectedFields(), $definition->projection()->fields())))); }
		$query=$range->cursor()!==null
			? $query->cursor($range->cursor())->limit($range->fetchLimit())
			: $query->cursor(null)->offset($range->effectiveOffset())->limit($range->fetchLimit());
		try{ PanelQueryCapabilities::fromArray($capabilities)->assertSupports($query); }
		catch(PanelUnsupportedQueryException){ throw new PanelDataSurfaceException('adapter_incompatible', 409, 'Panel data adapter does not support this surface query.'); }
		return $query;
	}

	private function window(
		PanelDataSurfaceDefinition $definition,
		PanelDataQuery $query,
		PanelDataResult $result,
		PanelDataSurfaceContext $context,
		int $ttl,
		array $safeState,
		PanelDataSurfaceRange $range,
	): PanelDataSurfaceWindowResult {
		$items=$result->items(); $page=$result->page();
		if(count($items)>$range->fetchLimit() || $page->returned()!==count($items) || $page->limit()>$range->fetchLimit()){ throw new PanelDataSurfaceException('result_invalid', 502, 'Panel DataSurface source returned an invalid bounded page.'); }
		$base=$range->cursor()===null ? $range->effectiveOffset() : $range->start();
		$records=[]; $keys=[];
		foreach($items as $index=>$item){
			try{ $projected=$definition->projection()->project($item); }
			catch(\Throwable){ throw new PanelDataSurfaceException('result_invalid', 502, 'Panel DataSurface source returned an invalid record.'); }
			if(isset($keys[$projected['key']])){ throw new PanelDataSurfaceException('result_duplicate_key', 502, 'Panel DataSurface source returned duplicate stable keys.'); }
			$keys[$projected['key']]=true; $position=$base+$index;
			$records[]=['key'=>$projected['key'],'position'=>$position,'visible'=>$position>=$range->start() && $position<$range->start()+$range->length(),'data'=>$projected['data']];
		}
		$total=$page->total();
		if($total!==null && $base+count($items)>$total){ throw new PanelDataSurfaceException('result_invalid', 502, 'Panel DataSurface source returned inconsistent total metadata.'); }
		$hasBefore=$range->cursor()!==null ? $page->previousCursor()!==null : $range->start()>0;
		$hasAfter=$page->nextCursor()!==null ? true : ($total!==null ? $base+count($items)<$total : (count($items)<$range->fetchLimit() ? false : null));
		$previous=null; $next=null;
		if($hasBefore){
			$previousRange=$range->cursor()!==null && $page->previousCursor()!==null
				? PanelDataSurfaceRange::make(max(0,$range->start()-$range->length()),$range->length(),0,$range->overscanAfter(),$page->previousCursor())
				: PanelDataSurfaceRange::make(max(0,$range->start()-$range->length()),$range->length(),$range->overscanBefore(),$range->overscanAfter());
			$previous=$this->signer->issue($definition,$query,$safeState,$previousRange,$context,$ttl);
		}
		if($hasAfter!==false){
			if($range->cursor()!==null){
				if($page->nextCursor()!==null){ $nextRange=PanelDataSurfaceRange::make($base+count($items),$range->length(),0,$range->overscanAfter(),$page->nextCursor()); }
				else{ $nextRange=null; }
			}
			else{ $nextRange=PanelDataSurfaceRange::make(min(PanelDataSurfaceRange::MAX_START,$range->start()+$range->length()),$range->length(),$range->overscanBefore(),$range->overscanAfter()); }
				if(isset($nextRange) && $nextRange!==null){ $next=$this->signer->issue($definition,$query,$safeState,$nextRange,$context,$ttl); }
		}
		$canvas=$definition->surface()->advanced()?(new PanelDataCanvasProjector())->project($definition,$records):null;
		return new PanelDataSurfaceWindowResult($definition->id(),$definition->resource(),$definition->surface(),$definition->projection(),$records,$range,$total,$hasBefore,$hasAfter,$previous,$next,$canvas);
	}

	/** @param array<string,mixed> $raw @return array<string,mixed> */
	private function publicCapabilities(array $raw): array {
		$out=[];
		foreach(['cursor','offset','select','search','filters','sorts','tenant','authorization'] as $key){ $out[$key]=($raw[$key] ?? false)===true; }
		$out['adapter']=PanelDataSurfaceGuard::boundedString((string)($raw['adapter'] ?? 'unknown'), 'adapter', 100);
		return $out;
	}
}
