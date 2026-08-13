<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Mcp\Panel;

/**
 * Converts Panel source inventory into bounded capability intelligence.
 *
 * Source truth (features, services, files, contracts, and tests) remains
 * dynamic. This class contributes the human semantics that source declarations
 * cannot express: when to use a domain, host obligations, dependencies, and
 * safe implementation/verification sequences.
 */
final class PanelCapabilityCatalog {
	public const MODEL_VERSION=1;
	public const KINDS=['platform_domain','framework_area'];
	public const VIEWS=['overview','contracts','integration','verification','security','all'];
	public const MODES=['auto','application','adapter','platform','operations','studio','realtime','migration'];
	public const PROVIDERS=['auto','callback','pdo','redis','dataphyre_storage','filesystem','memory','custom'];
	public const TOPOLOGIES=['auto','local','shared_sql','distributed','host_managed'];

	private ?array $records=null;
	private ?array $snapshot=null;

	public function __construct(private PanelCapabilitySource $source) {}

	/** @return array<string,mixed> */
	public function catalog(array $query=[]): array {
		$kinds=$this->stringList($query['kinds']??[]);$unknown=array_values(array_diff($kinds,self::KINDS));
		if($unknown!==[]){throw new \InvalidArgumentException('Unknown Panel capability kinds: '.implode(', ',$unknown).'.');}
		$categories=$this->stringList($query['categories']??[]);$needle=strtolower(trim((string)($query['query']??'')));
		$offset=max(0,(int)($query['offset']??0));$limit=max(1,min((int)($query['limit']??50)?:50,200));
		$records=array_values(array_filter($this->records(),static function(array $record)use($kinds,$categories,$needle):bool{
			if($kinds!==[]&&!in_array($record['kind'],$kinds,true)){return false;}
			if($categories!==[]&&!in_array($record['category'],$categories,true)){return false;}
			if($needle===''){return true;}
			return str_contains(strtolower(implode(' ',[
				$record['id'],$record['name'],$record['label'],$record['category'],$record['summary'],implode(' ',$record['aliases']??[]),implode(' ',$record['related_domains']??[]),
			])),$needle);
		}));
		if($needle!==''){usort($records,fn(array $left,array $right): int=>$this->searchScore($right,$needle)<=>$this->searchScore($left,$needle)?:strcmp($left['id'],$right['id']));}
		$kindSummary=[];$categorySummary=[];
		foreach($records as $record){$kindSummary[$record['kind']]=($kindSummary[$record['kind']]??0)+1;$categorySummary[$record['category']]=($categorySummary[$record['category']]??0)+1;}
		ksort($kindSummary,SORT_STRING);ksort($categorySummary,SORT_STRING);$page=array_slice($records,$offset,$limit);
		return [
			'catalog_type'=>'dataphyre_panel_capability_catalog','capability_model_version'=>self::MODEL_VERSION,
			'write_policy'=>'read_only','execution'=>'not_executed','source_strategy'=>$this->snapshot()['source_strategy'],
			'filters'=>['kinds'=>$kinds,'categories'=>$categories,'query'=>$needle,'offset'=>$offset,'limit'=>$limit],
			'counts'=>['matched'=>count($records),'returned'=>count($page),'total'=>count($this->records())],
			'kind_summary'=>$kindSummary,'category_summary'=>$categorySummary,'records'=>array_map($this->compactRecord(...),$page),
			'pagination'=>['offset'=>$offset,'next_offset'=>$offset+count($page)<count($records)?$offset+count($page):null,'has_more'=>$offset+count($page)<count($records)],
			'inventory_fingerprint'=>$this->snapshot()['inventory_fingerprint'],'diagnostics'=>$this->semanticDiagnostics(),
		];
	}

	/** @return array<string,mixed> */
	public function describe(string $identity,string $view='overview',int $maxItems=40): array {
		$view=strtolower(trim($view));if(!in_array($view,self::VIEWS,true)){throw new \InvalidArgumentException('Panel capability view must be one of: '.implode(', ',self::VIEWS).'.');}
		$record=$this->resolve($identity);$maxItems=max(1,min($maxItems,200));
		if($record['kind']==='framework_area'){return $this->describeArea($record,$view,$maxItems);}
		$domain=$this->domainSource($record['name']);$evidence=$this->domainEvidence($domain,$record['profile']);
		$overview=[
			'id'=>$record['id'],'kind'=>$record['kind'],'name'=>$record['name'],'label'=>$record['label'],'category'=>$record['category'],
			'summary'=>$record['summary'],'use_when'=>$record['profile']['use_when'],'risk'=>$record['profile']['risk'],
			'feature_count'=>$domain['feature_count'],'required_feature_count'=>$domain['required_feature_count'],'service_count'=>$domain['service_count'],
			'framework_areas'=>$domain['framework_areas'],'depends_on'=>$record['profile']['depends_on'],'related_domains'=>$record['profile']['related_domains'],
			'documentation'=>array_slice($evidence['documents'],0,$maxItems),'evidence_counts'=>$evidence['counts'],
		];
		$contracts=[
			'platform_contract'=>[
				'prefix'=>$domain['prefix'],'required_features'=>$domain['required_features'],'features'=>array_slice($domain['features'],0,$maxItems),
				'services'=>array_slice($domain['services'],0,$maxItems),'feature_inventory_truncated'=>count($domain['features'])>$maxItems,'service_inventory_truncated'=>count($domain['services'])>$maxItems,
			],
			'contract_records'=>array_slice($evidence['contracts'],0,$maxItems),'contract_records_truncated'=>count($evidence['contracts'])>$maxItems,
			'serialized_contracts'=>array_slice($evidence['serialized'],0,$maxItems),'serialized_contracts_truncated'=>count($evidence['serialized'])>$maxItems,
			'php_type_contracts'=>array_slice($evidence['types'],0,$maxItems),'php_type_contracts_truncated'=>count($evidence['types'])>$maxItems,
		];
		$integration=$this->domainIntegration($domain,$record['profile'],$evidence,'auto','auto',$maxItems);
		$verification=$this->domainVerification($domain,$record['profile'],$evidence,'focused',$maxItems);
		$security=$this->domainSecurity($record['profile']);
		$detail=match($view){
			'overview'=>['overview'=>$overview],
			'contracts'=>['overview'=>$overview,'contracts'=>$contracts],
			'integration'=>['overview'=>$overview,'integration'=>$integration],
			'verification'=>['overview'=>$overview,'verification'=>$verification],
			'security'=>['overview'=>$overview,'security'=>$security],
			'all'=>['overview'=>$overview,'contracts'=>$contracts,'integration'=>$integration,'verification'=>$verification,'security'=>$security],
		};
		return [
			'descriptor_type'=>'dataphyre_panel_capability_descriptor','capability_model_version'=>self::MODEL_VERSION,
			'status'=>'found','view'=>$view,'write_policy'=>'read_only','execution'=>'not_executed','capability'=>$detail,
			'next_actions'=>$this->descriptorNextActions($record['name'],$view),'safety'=>$this->safetyContract('capability_describe'),
		];
	}

	/** @return array<string,mixed> */
	public function graph(array $query=[]): array {
		$roots=$this->stringList($query['roots']??[]);if($roots===[]){throw new \InvalidArgumentException('roots must name at least one Panel platform domain for a bounded surface graph.');}
		$depth=max(0,min((int)($query['depth']??2),4));$direction=strtolower(trim((string)($query['direction']??'dependencies')));
		if(!in_array($direction,['dependencies','dependents','both'],true)){throw new \InvalidArgumentException('Panel graph direction must be dependencies, dependents, or both.');}
		$profiles=$this->availableProfiles();foreach($roots as $root){if(!isset($profiles[$root])){throw new \OutOfBoundsException('Panel platform domain was not found: '.$root);}}
		$seen=[];$frontier=[];foreach($roots as $root){$seen[$root]=0;$frontier[]=$root;}
		$edges=[];
		while($frontier!==[]){$current=array_shift($frontier);$level=$seen[$current];if($level>=$depth){continue;}
			$neighbors=[];
			if($direction!=='dependents'){foreach($profiles[$current]['depends_on'] as $target){$neighbors[]=['from'=>$current,'to'=>$target,'relation'=>'depends_on'];}}
			if($direction!=='dependencies'){foreach($profiles as $candidate=>$profile){if(in_array($current,$profile['depends_on'],true)){$neighbors[]=['from'=>$candidate,'to'=>$current,'relation'=>'depends_on'];}}}
			foreach($neighbors as $edge){$key=$edge['from'].'>'.$edge['to'];$edges[$key]=$edge;$neighbor=$edge['from']===$current?$edge['to']:$edge['from'];if(!isset($seen[$neighbor])){$seen[$neighbor]=$level+1;$frontier[]=$neighbor;}}
		}
		ksort($seen,SORT_STRING);ksort($edges,SORT_STRING);$nodes=[];
		foreach($seen as $name=>$level){$record=$this->resolve('panel:domain:'.$name);$nodes[]=['id'=>$record['id'],'name'=>$name,'label'=>$record['label'],'category'=>$record['category'],'depth'=>$level];}
		return [
			'graph_type'=>'dataphyre_panel_surface_graph','capability_model_version'=>self::MODEL_VERSION,'roots'=>$roots,'direction'=>$direction,'max_depth'=>$depth,
			'nodes'=>$nodes,'edges'=>array_values($edges),'counts'=>['nodes'=>count($nodes),'edges'=>count($edges)],
			'edge_contract'=>'depends_on means the caller must resolve or deliberately replace the target domain boundary; it does not imply automatic runtime registration.',
			'write_policy'=>'read_only','execution'=>'not_executed','safety'=>$this->safetyContract('surface_graph'),
		];
	}

	/** @return array<string,mixed> */
	public function recipe(array $args): array {
		$task=trim((string)($args['task']??''));if($task===''){throw new \InvalidArgumentException('task is required for a Panel recipe plan.');}
		$mode=strtolower(trim((string)($args['mode']??'auto')));if(!in_array($mode,self::MODES,true)){throw new \InvalidArgumentException('Panel recipe mode must be one of: '.implode(', ',self::MODES).'.');}
		$domains=$this->resolveDomains($args['domains']??[],$task);$areas=$this->inferAreas($task);
		if($mode==='auto'){$mode=$this->inferMode($task,$domains);}
		$steps=[
			['phase'=>'discover','action'=>'Describe every selected Panel capability before choosing classes or providers.','tools'=>['dataphyre_panel_capability_describe','dataphyre_panel_surface_graph']],
			['phase'=>'contract','action'=>'Inspect exact PHP interfaces, serialized envelopes, TestKit contracts, and host obligations.','tools'=>['dataphyre_contract_catalog','dataphyre_contract_describe']],
			['phase'=>'design','action'=>'Choose app-owned composition, provider adapters, persistence topology, authority, retries, and rollback explicitly.','tools'=>['dataphyre_panel_integration_plan']],
			['phase'=>'implement','action'=>'Write only the owning application, adapter, plugin, or explicitly requested Dataphyre MCP/framework files.','tools'=>['dataphyre_scaffold_plan_generate','dataphyre_app_builder_plan_generate']],
			['phase'=>'verify','action'=>'Run domain-selected focused evidence before any broader exact or publication claim.','tools'=>['dataphyre_panel_verification_plan']],
		];
		$focus=[];$obligations=[];$documents=[];
		$profiles=$this->availableProfiles();foreach($domains as $domain){$profile=$profiles[$domain];foreach($profile['implementation_focus'] as $item){$focus[]=['domain'=>$domain,'focus'=>$item];}array_push($obligations,...$profile['host_obligations']);array_push($documents,...$this->domainEvidence($this->domainSource($domain),$profile)['documents']);}
		return [
			'plan_type'=>'dataphyre_panel_recipe_plan','capability_model_version'=>self::MODEL_VERSION,'task'=>$task,'mode'=>$mode,
			'selection'=>['domains'=>$domains,'framework_areas'=>$areas,'selection_strategy'=>isset($args['domains'])&&$this->stringList($args['domains'])!==[]?'explicit_then_validated':'semantic_task_inference','bounded_domain_limit'=>6],
			'steps'=>$steps,'implementation_focus'=>array_slice($focus,0,30),'documentation'=>$this->uniqueArrays($documents,20),
			'host_obligations'=>$this->uniqueStrings($obligations),'negative_guarantees'=>$this->negativeGuarantees(),
			'next_calls'=>[
				['tool'=>'dataphyre_panel_capability_describe','arguments'=>['id'=>'panel:domain:<selected>','view'=>'all']],
				['tool'=>'dataphyre_panel_integration_plan','arguments'=>['domains'=>$domains,'provider'=>'auto','topology'=>'auto']],
				['tool'=>'dataphyre_panel_verification_plan','arguments'=>['domains'=>$domains,'claim'=>'focused']],
			],
			'write_policy'=>'dry_run_plan_only','execution'=>'not_executed','safety'=>$this->safetyContract('recipe_plan'),
		];
	}

	/** @return array<string,mixed> */
	public function integration(array $args): array {
		$domains=$this->resolveDomains($args['domains']??[],'');if($domains===[]){throw new \InvalidArgumentException('domains must name at least one Panel platform domain for an integration plan.');}
		$provider=strtolower(trim((string)($args['provider']??'auto')));if(!in_array($provider,self::PROVIDERS,true)){throw new \InvalidArgumentException('Panel integration provider must be one of: '.implode(', ',self::PROVIDERS).'.');}
		$topology=strtolower(trim((string)($args['topology']??'auto')));if(!in_array($topology,self::TOPOLOGIES,true)){throw new \InvalidArgumentException('Panel integration topology must be one of: '.implode(', ',self::TOPOLOGIES).'.');}
		$maxItems=max(1,min((int)($args['max_items']??40)?:40,200));$plans=[];
		$profiles=$this->availableProfiles();foreach($domains as $name){$domain=$this->domainSource($name);$profile=$profiles[$name];$evidence=$this->domainEvidence($domain,$profile);$plans[]=$this->domainIntegration($domain,$profile,$evidence,$provider,$topology,$maxItems);}
		return [
			'plan_type'=>'dataphyre_panel_integration_plan','capability_model_version'=>self::MODEL_VERSION,'domains'=>$domains,'provider'=>$provider,'topology'=>$topology,
			'domain_plans'=>$plans,'shared_sequence'=>[
				'Choose one typed interface and a first-party or app-owned implementation.','Create schemas/namespaces explicitly; loading an adapter must not mutate infrastructure.',
				'Register host-owned clients, callbacks, credentials, clocks, keys, and service factories without serializing them.',
				'Preview activation, validate capability/conformance manifests, then activate transactionally with rollback evidence.',
				'Exercise restart, contention, stale state, cancellation, corruption, and secret-boundary cases appropriate to the selected topology.',
			],
			'negative_guarantees'=>$this->negativeGuarantees(),'write_policy'=>'dry_run_plan_only','execution'=>'not_executed','safety'=>$this->safetyContract('integration_plan'),
		];
	}

	/** @return array<string,mixed> */
	public function verification(array $args): array {
		$task=trim((string)($args['task']??''));$paths=$this->stringList($args['changed_paths']??[]);$domains=$this->resolveDomains($args['domains']??[],trim($task.' '.implode(' ',$paths)));
		if($domains===[]){throw new \InvalidArgumentException('domains, task, or changed_paths must identify at least one Panel platform domain for verification.');}
		$claim=strtolower(trim((string)($args['claim']??'focused')));if(!in_array($claim,['focused','exact','browser','release'],true)){throw new \InvalidArgumentException('Panel verification claim must be focused, exact, browser, or release.');}
		$maxItems=max(1,min((int)($args['max_items']??40)?:40,200));$plans=[];$tests=[];$contracts=[];$sources=[];$browser=false;
		$profiles=$this->availableProfiles();foreach($domains as $name){$domain=$this->domainSource($name);$profile=$profiles[$name];$evidence=$this->domainEvidence($domain,$profile);$plan=$this->domainVerification($domain,$profile,$evidence,$claim,$maxItems);$plans[]=$plan;array_push($tests,...$plan['test_files']);array_push($contracts,...$plan['test_contracts']);array_push($sources,...$plan['coverage_sources']);$browser=$browser||$profile['browser'];}
		$tests=$this->uniqueStrings($tests);$contracts=$this->uniqueStrings($contracts);$sources=$this->uniqueStrings($sources);
		$commands=$this->verificationCommands($domains,$tests,$sources,$claim,$browser);
		return [
			'plan_type'=>'dataphyre_panel_verification_plan','capability_model_version'=>self::MODEL_VERSION,'claim'=>$claim,'domains'=>$domains,'changed_paths'=>$paths,
			'domain_plans'=>$plans,'selected'=>['test_files'=>array_slice($tests,0,$maxItems),'test_contracts'=>array_slice($contracts,0,$maxItems),'coverage_sources'=>array_slice($sources,0,$maxItems),'browser_required'=>$browser||in_array($claim,['browser','release'],true)],
			'commands'=>$commands,'claim_boundary'=>$this->verificationClaimBoundary($claim),'write_policy'=>'plan_only','execution'=>'not_executed','safety'=>$this->safetyContract('verification_plan'),
		];
	}

	/** @return array<string,mixed> */
	public function resourceSnapshot(): array {
		$catalog=$this->catalog(['kinds'=>['platform_domain'],'limit'=>200]);
		return [
			'resource_type'=>'dataphyre_panel_capability_index','capability_model_version'=>self::MODEL_VERSION,'resource_mode'=>'bounded_domain_federation',
			'write_policy'=>'read_only','execution'=>'not_executed','counts'=>$this->snapshot()['counts'],'categories'=>$catalog['category_summary'],'domains'=>$catalog['records'],
			'inventory_fingerprint'=>$this->snapshot()['inventory_fingerprint'],'diagnostics'=>$catalog['diagnostics'],'safety'=>$this->safetyContract('panel_resource'),
			'enumeration_contract'=>[
				'strategy'=>'domain_and_view_federation','complete_when'=>'Catalog platform domains, then describe only affected domains with the contracts, integration, verification, security, or all view.',
				'reason'=>'Panel has hundreds of classes and payloads; domain/view partials retain the complete map without flooding every client session.',
			],
			'tools'=>['dataphyre_panel_capability_catalog','dataphyre_panel_capability_describe','dataphyre_panel_surface_graph','dataphyre_panel_recipe_plan','dataphyre_panel_integration_plan','dataphyre_panel_verification_plan'],
		];
	}

	/** @return list<array<string,mixed>> */
	private function records(): array {
		if($this->records!==null){return $this->records;}$profiles=$this->profiles();$records=[];
		foreach($this->snapshot()['domains'] as $domain){$profile=$this->profile((string)$domain['name'],$profiles);$evidence=$this->domainEvidence($domain,$profile);$records[]=[
			'id'=>$domain['id'],'kind'=>'platform_domain','name'=>$domain['name'],'label'=>$profile['label'],'category'=>$profile['category'],'summary'=>$profile['summary'],'aliases'=>$profile['aliases'],'profile'=>$profile,
			'feature_count'=>$domain['feature_count'],'required_feature_count'=>$domain['required_feature_count'],'service_count'=>$domain['service_count'],'framework_areas'=>$domain['framework_areas'],
			'evidence_counts'=>$evidence['counts'],'related_domains'=>$profile['related_domains'],
		];}
		foreach($this->snapshot()['areas'] as $area){$related=$area['related_domains'];$records[]=[
			'id'=>$area['id'],'kind'=>'framework_area','name'=>$area['name'],'label'=>$this->humanize($area['name']),'category'=>'framework','summary'=>'Panel framework source area containing '.$area['file_count'].' bounded file(s).',
			'aliases'=>[$this->slug($area['name'])],'related_domains'=>$related,'area'=>$area,
		];}
		usort($records,static fn(array $left,array $right): int=>strcmp($left['kind'],$right['kind'])?:strcmp($left['name'],$right['name']));return $this->records=$records;
	}

	/** @return array<string,mixed> */
	private function compactRecord(array $record): array {
		$compact=$record;unset($compact['profile'],$compact['area']);
		if($record['kind']==='framework_area'){$compact+=[
			'path'=>$record['area']['path'],'file_count'=>$record['area']['file_count'],'php_file_count'=>$record['area']['php_file_count'],'extensions'=>$record['area']['extensions'],
		];}
		$compact['describe_with']='dataphyre_panel_capability_describe';return $compact;
	}

	/** @return array<string,mixed> */
	private function resolve(string $identity): array {
		$identity=trim($identity);if($identity===''){throw new \InvalidArgumentException('Panel capability id or name is required.');}
		$matches=array_values(array_filter($this->records(),static fn(array $record): bool=>$record['id']===$identity||$record['name']===$identity||strtolower($record['label'])===strtolower($identity)));
		if($matches===[]){$folded=$this->slug($identity);$matches=array_values(array_filter($this->records(),fn(array $record): bool=>$this->slug($record['name'])===$folded||in_array($folded,$record['aliases']??[],true)));}
		if($matches===[]){throw new \OutOfBoundsException('Panel capability was not found: '.$identity);}
		if(count($matches)>1){throw new \UnexpectedValueException('Panel capability is ambiguous: '.$identity.'. Use a stable panel:domain:* or panel:area:* id.');}
		return $matches[0];
	}

	/** @return array<string,mixed> */
	private function describeArea(array $record,string $view,int $maxItems): array {
		$area=$record['area'];$contracts=array_values(array_filter($this->snapshot()['contracts'],static fn(array $contract): bool=>str_contains(strtolower(implode(' ',$contract['producers']??[])),strtolower($area['name']))));
		$tests=array_values(array_filter($this->snapshot()['tests'],static fn(array $test): bool=>str_contains(strtolower($test['path']),'/'.strtolower($area['name']).'/')));
		return [
			'descriptor_type'=>'dataphyre_panel_capability_descriptor','capability_model_version'=>self::MODEL_VERSION,'status'=>'found','view'=>$view,'write_policy'=>'read_only','execution'=>'not_executed',
			'capability'=>['overview'=>['id'=>$record['id'],'kind'=>$record['kind'],'name'=>$record['name'],'label'=>$record['label'],'summary'=>$record['summary'],'path'=>$area['path'],'file_count'=>$area['file_count'],'php_file_count'=>$area['php_file_count'],'extensions'=>$area['extensions'],'related_domains'=>$area['related_domains'],'sample_files'=>array_slice($area['sample_files'],0,$maxItems)],'contracts'=>array_slice($contracts,0,$maxItems),'tests'=>array_slice($tests,0,$maxItems)],
			'next_actions'=>['Describe one related platform domain for semantic integration and verification guidance.','Use dataphyre_contract_catalog modules=[panel] with a class or payload term for exact source contracts.'],'safety'=>$this->safetyContract('area_describe'),
		];
	}

	/** @return array<string,mixed> */
	private function domainEvidence(array $domain,array $profile): array {
		$featureClasses=array_fill_keys(array_filter(array_column($domain['features'],'class')),true);$terms=$this->domainTerms($domain,$profile);$contracts=[];$serialized=[];$types=[];
		foreach($this->snapshot()['contracts'] as $contract){$score=$this->contractScore($contract,$featureClasses,$terms);if($score<1){continue;}$contract['panel_relevance_score']=$score;$contracts[]=$contract;if($contract['kind']==='serialized_contract'){$serialized[]=$contract;}if($contract['kind']==='php_type_contract'){$types[]=$contract;}}
		$sort=static fn(array $left,array $right): int=>($right['panel_relevance_score']<=>$left['panel_relevance_score'])?:strcmp($left['id'],$right['id']);usort($contracts,$sort);usort($serialized,$sort);usort($types,$sort);
		$tests=[];foreach($this->snapshot()['tests'] as $test){$score=$this->testScore($test,$terms);if($score<1){continue;}$test['panel_relevance_score']=$score;$tests[]=$test;}
		usort($tests,static fn(array $left,array $right): int=>($right['panel_relevance_score']<=>$left['panel_relevance_score'])?:strcmp($left['path'],$right['path']));
		$documents=$this->documents($profile,$terms);
		return ['contracts'=>$contracts,'serialized'=>$serialized,'types'=>$types,'tests'=>$tests,'documents'=>$documents,'counts'=>['contracts'=>count($contracts),'serialized_contracts'=>count($serialized),'php_type_contracts'=>count($types),'tests'=>count($tests),'documents'=>count($documents)]];
	}

	private function contractScore(array $contract,array $featureClasses,array $terms): int {
		$name=(string)($contract['name']??'');if(isset($featureClasses[$name])){return 100;}$id=strtolower((string)($contract['id']??''));$normalized=$this->slug($name);$score=0;
		foreach($terms as $term){if(strlen($term)<4){continue;}if($normalized===$term){$score=max($score,80);}elseif(str_starts_with($normalized,'panel_'.$term.'_')||str_contains($id,'panel.'.$term)||str_contains($id,'panel-'.$term)){$score=max($score,60);}elseif(str_contains($normalized,$term)){$score=max($score,20);}}
		foreach($contract['producers']??[] as $producer){foreach($featureClasses as $class=>$_){if(str_ends_with((string)$producer,$this->shortClass($class))){$score=max($score,90);}}}
		return $score;
	}

	private function searchScore(array $record,string $needle): int {
		$name=strtolower((string)($record['name']??''));$label=strtolower((string)($record['label']??''));$id=strtolower((string)($record['id']??''));$aliases=array_map('strtolower',$record['aliases']??[]);
		if($name===$needle||$id===$needle){return 100;}if($label===$needle){return 90;}if(in_array($needle,$aliases,true)){return 80;}
		if(str_starts_with($name,$needle)||str_starts_with($label,$needle)){return 60;}
		if(str_contains(strtolower((string)($record['summary']??'')),$needle)){return 20;}
		return 10;
	}

	private function testScore(array $test,array $terms): int {
		$haystack=$this->slug($test['path'].' '.implode(' ',$test['contract_ids']).' '.implode(' ',$test['contract_names']));$score=0;
		foreach($terms as $term){if(strlen($term)<4){continue;}if(str_contains($haystack,'panel_'.$term)){$score=max($score,60);}elseif(str_contains($haystack,$term)){$score=max($score,20);}}
		return $score;
	}

	/** @return list<array<string,mixed>> */
	private function documents(array $profile,array $terms): array {
		$wanted=array_fill_keys($profile['documents'],true);$documents=[];
		foreach($this->snapshot()['documents'] as $document){$score=isset($wanted[$document['name']])?100:0;$haystack=$this->slug($document['name'].' '.$document['title'].' '.implode(' ',array_column($document['headings'],'title')));foreach($terms as $term){if(strlen($term)>=5&&str_contains($haystack,$term)){$score=max($score,20);}}if($document['name']==='Dataphyre_Panel.md'){$score=max($score,10);}if($score>0){$document['panel_relevance_score']=$score;$documents[]=$document;}}
		usort($documents,static fn(array $left,array $right): int=>($right['panel_relevance_score']<=>$left['panel_relevance_score'])?:strcmp($left['path'],$right['path']));return $documents;
	}

	/** @return array<string,mixed> */
	private function domainIntegration(array $domain,array $profile,array $evidence,string $provider,string $topology,int $maxItems): array {
		$candidates=[];foreach($domain['features'] as $feature){$haystack=strtolower($feature['name'].' '.$feature['class']);if(!$this->integrationFeature($haystack)){continue;}if($provider!=='auto'&&!$this->providerMatch($haystack,$provider)){continue;}$candidates[]=$feature;}
		$interfaces=[];foreach($evidence['types'] as $contract){if(in_array('interface',$contract['roles']??[],true)){$interfaces[]=$contract;}}
		return [
			'domain'=>$domain['name'],'provider'=>$provider,'topology'=>$topology,'service_bindings'=>array_slice($domain['services'],0,$maxItems),
			'typed_interfaces'=>array_slice($interfaces,0,$maxItems),'first_party_candidates'=>array_slice($candidates,0,$maxItems),
			'activation'=>['preview_required'=>true,'explicit_initialization'=>true,'transactional_replacement_required'=>true,'rollback_required'=>true,'autoload_side_effects_allowed'=>false],
			'persistence'=>['host_selects_topology'=>true,'automatic_schema_mutation'=>false,'shared_sql_requires_explicit_migration'=>in_array($topology,['shared_sql','distributed'],true),'distributed_authority_must_be_single_and_declared'=>$topology==='distributed'],
			'host_obligations'=>$profile['host_obligations'],'conformance_test_files'=>array_slice(array_column($evidence['tests'],'path'),0,$maxItems),
			'secret_boundary'=>['host_owned'=>['credentials','connections','callbacks','keys','tokens','TLS policy'],'serialized_by_panel'=>false,'returned_by_mcp'=>false],
		];
	}

	/** @return array<string,mixed> */
	private function domainVerification(array $domain,array $profile,array $evidence,string $claim,int $maxItems): array {
		$testFiles=array_slice(array_column($evidence['tests'],'path'),0,$maxItems);$testContracts=[];foreach($evidence['tests'] as $test){array_push($testContracts,...$test['contract_ids']);}
		$sources=[];foreach($domain['features'] as $feature){array_push($sources,...$feature['source_paths']);}
		return [
			'domain'=>$domain['name'],'claim'=>$claim,'test_files'=>$this->uniqueStrings($testFiles),'test_contracts'=>array_slice($this->uniqueStrings($testContracts),0,$maxItems),
			'coverage_sources'=>array_slice($this->uniqueStrings($sources),0,$maxItems),'documentation'=>array_slice(array_column($evidence['documents'],'path'),0,$maxItems),
			'browser_required'=>$profile['browser']||in_array($claim,['browser','release'],true),'dual_runtime_required'=>in_array($claim,['exact','release'],true),'closed_world_required'=>in_array($claim,['exact','release'],true),
			'proof_order'=>['focused semantic contract','PHP 8.2 and PHP 8.4 parity when portable persistence or provider boundaries are touched','phpdbg exact changed-source closure','browser/asset evidence for rendered behavior','release/export attestation only for publication claims'],
		];
	}

	/** @return array<string,mixed> */
	private function domainSecurity(array $profile): array {return ['risk'=>$profile['risk'],'host_obligations'=>$profile['host_obligations'],'negative_guarantees'=>$this->negativeGuarantees(),'authority_contract'=>'MCP describes source contracts and plans only. Hosts remain authoritative for identity, policy, credentials, keys, infrastructure, persistence, external effects, and deployment.'];}

	/** @return list<array<string,mixed>> */
	private function verificationCommands(array $domains,array $tests,array $sources,string $claim,bool $browser): array {
		$commands=[];$domainQuery=implode('|',$domains);$commands[]=['lane'=>'focused','command'=>'php bin/dataphyre-test run --owner=panel --path=<selected-test-path>','selection'=>array_slice($tests,0,12),'purpose'=>'Execute only semantically matched Panel test files.'];
		if(in_array($claim,['exact','release'],true)){$commands[]=['lane'=>'exact','command'=>'phpdbg -qrr bin/dataphyre-test run --owner=panel --path=<selected-test-path> --coverage=<report.json> --coverage-source=<changed-source> --coverage-require=phpdbg --coverage-min-percent=100 --coverage-closed-world --no-test-cache','selection'=>array_slice($sources,0,12),'purpose'=>'Close every executable changed Panel source line under a stable source epoch.'];}
		if($browser||in_array($claim,['browser','release'],true)){$commands[]=['lane'=>'browser','command'=>'node runtime/modules/panel/testing/panel_interaction_regression.js --changed-paths <paths>','selection'=>$domains,'purpose'=>'Exercise rendered, responsive, accessibility, interaction, and asset contracts selected by the changed surface.'];}
		if($claim==='release'){$commands[]=['lane'=>'release','command'=>'php bin/dataphyre-test run --owner=panel --tag=release','selection'=>[$domainQuery],'purpose'=>'Run Panel release contracts after focused and exact evidence, without treating them as application behavior proof.'];}
		return $commands;
	}

	private function verificationClaimBoundary(string $claim): string {return match($claim){'focused'=>'Focused domain behavior only; no whole-Panel or release claim.','exact'=>'Exact changed-source line coverage plus focused behavior; no browser or publication claim unless separately selected.','browser'=>'Rendered/browser behavior only; no server exact or release claim.','release'=>'Panel publication evidence after focused, dual-runtime, exact, browser, asset, and source-epoch checks.'};}

	/** @return list<string> */
	private function resolveDomains(mixed $requested,string $task): array {
		$profiles=$this->availableProfiles();$domains=$this->stringList($requested);foreach($domains as $domain){if(!isset($profiles[$domain])){throw new \OutOfBoundsException('Panel platform domain was not found: '.$domain);}}
		if($domains!==[]){return array_slice($domains,0,6);}$needle=$this->slug($task);if($needle===''){return [];}$scores=[];
		foreach($profiles as $name=>$profile){$score=0;foreach(array_merge([$name,$profile['label']],$profile['aliases']) as $term){$term=$this->slug($term);if($term!==''&&str_contains($needle,$term)){$score+=strlen($term)+($term===$name?20:0);}}if($score>0){$scores[$name]=$score;}}
		arsort($scores,SORT_NUMERIC);return array_slice(array_keys($scores),0,6);
	}

	/** @return list<string> */
	private function inferAreas(string $task): array {
		$needle=$this->slug($task);$map=['Resources'=>['resource','crud'],'Forms'=>['form','field'],'Tables'=>['table','column','filter'],'Relations'=>['relation'],'Widgets'=>['widget','dashboard'],'Rendering'=>['render','html','css','responsive'],'Navigation'=>['navigation','menu'],'Schemas'=>['schema'],'Editors'=>['editor'],'Testing'=>['test','verification']];$areas=[];
		foreach($map as $area=>$terms){foreach($terms as $term){if(str_contains($needle,$term)){$areas[]=$area;break;}}}return $areas;
	}

	private function inferMode(string $task,array $domains): string {
		$needle=$this->slug($task);if(in_array('studio',$domains,true)){return 'studio';}if(in_array('realtime',$domains,true)){return 'realtime';}if(in_array('migrations',$domains,true)){return 'migration';}
		if(array_intersect($domains,['operations_os','operations','distributed_operations','workflows','automation','agent_workflows'])!==[]){return 'operations';}
		if(str_contains($needle,'adapter')||str_contains($needle,'provider')||str_contains($needle,'transport')){return 'adapter';}
		return array_intersect($domains,['platform','packages','extensions','development'])!==[]?'platform':'application';
	}

	private function integrationFeature(string $haystack): bool {foreach(['adapter','store','transport','broker','disk','manager','runtime','registry','endpoint','executor','loader','exporter','bridge','factory'] as $term){if(str_contains($haystack,$term)){return true;}}return false;}
	private function providerMatch(string $haystack,string $provider): bool {return match($provider){'callback'=>str_contains($haystack,'callback'),'pdo'=>str_contains($haystack,'pdo')||str_contains($haystack,'sql'),'redis'=>str_contains($haystack,'redis')||str_contains($haystack,'predis'),'dataphyre_storage'=>str_contains($haystack,'dataphyre')&&str_contains($haystack,'storage'),'filesystem'=>str_contains($haystack,'filesystem')||str_contains($haystack,'local'),'memory'=>str_contains($haystack,'memory')||str_contains($haystack,'atomic'),'custom'=>str_contains($haystack,'interface')||str_contains($haystack,'adapter')||str_contains($haystack,'transport')||str_contains($haystack,'store'),default=>true};}

	/** @return list<string> */
	private function domainTerms(array $domain,array $profile): array {
		$terms=[$this->slug($domain['name']),$this->slug($domain['prefix'])];foreach($profile['aliases'] as $alias){$terms[]=$this->slug($alias);}foreach($domain['framework_areas'] as $area){$terms[]=$this->slug($area);}return array_values(array_unique(array_filter($terms)));
	}

	/** @return array<string,mixed> */
	private function domainSource(string $name): array {foreach($this->snapshot()['domains'] as $domain){if($domain['name']===$name){return $domain;}}throw new \OutOfBoundsException('Panel platform domain source was not found: '.$name);}

	/** @return array<string,mixed> */
	private function profile(string $name,array $profiles): array {
		if(isset($profiles[$name])){return $profiles[$name];}
		return $this->makeProfile($this->humanize($name),'unclassified','Source-discovered Panel platform domain awaiting explicit MCP semantics.','Use only after inspecting its source contracts.',[$name],[],[],['Inspect every required feature and service before integration.'],['Define host authority and persistence explicitly.'],'high',false,['platform']);
	}

	/** @return array<string,array<string,mixed>> */
	private function profiles(): array {
		return [
			'operations_os'=>$this->makeProfile('Operations OS','orchestration','Cohesive domain-as-code, command fabric, intelligence, policy, compliance, federation, release, marketplace, and operator runtime.','Building or operating a governed multi-domain control plane.',['operations os','domain compiler','command fabric','operator','compliance','federation','release execution'],['operations','distributed_operations','migrations','observability','data','realtime','workflows','automation','agent_workflows','iam','studio','media','collaboration','packages','security','platform'],['Dataphyre_Panel_Operations_OS.md','Dataphyre_Panel_Distributed_Command_Fabric.md','Dataphyre_Panel_Compliance_Automation.md','Dataphyre_Panel_Release_Deployment.md','Dataphyre_Panel_Marketplace_Trust.md'],['Compile and inspect domain manifests before activation.','Bind one cohesive service graph and validate identity equality across shared dependencies.','Use signed intents, receipts, retained feeds, and rollback-safe activation for external effects.'],['Own tenant authority, policy data, workers, credentials, signing keys, infrastructure, and deployment topology.'],'critical',true,['operations','platform']),
			'operations'=>$this->makeProfile('Operations','orchestration','Local queued operation records, handlers, runners, controls, and compatibility bridges.','Running durable or deferred Panel operations inside one host.',['operation','queue','runner','data job'],['observability','platform'],['Dataphyre_Panel_Distributed_Operations.md'],['Choose record/store/queue/runner ownership.','Register bounded handlers and explicit cancellation/control.','Prove retries and result receipts without duplicating side effects.'],['Own worker lifecycle, scheduling, external-effect idempotency, and retention.'],'high',false,['operations']),
			'distributed_operations'=>$this->makeProfile('Distributed Operations','orchestration','Leased and fenced operation execution over shared persistence.','Multiple workers or processes must safely coordinate deferred operations.',['distributed operation','lease','fence','leased store'],['operations','observability','platform'],['Dataphyre_Panel_Distributed_Operations.md'],['Install shared schema explicitly.','Use lease/fence tokens at every state transition.','Exercise contention, expiry, cancellation, restart, and stale-worker rejection.'],['Own database HA, worker supervision, clocks, credentials, and effect idempotency.'],'critical',false,['operations','adapter']),
			'migrations'=>$this->makeProfile('Migrations','orchestration','Versioned, planned, transactional, leased, and restart-safe Panel migrations.','Changing durable Panel-owned state or coordinating schema/data evolution.',['migration','schema rollout','checkpoint'],['distributed_operations','observability','platform'],['Dataphyre_Panel_Distributed_Migrations.md'],['Register immutable migration definitions.','Preview ordered plans and scope ownership.','Persist checkpoints atomically with handler effects where promised.'],['Own backup, rollout windows, database authority, and irreversible-step approval.'],'critical',false,['migration','adapter']),
			'observability'=>$this->makeProfile('Observability','foundation','Vendor-neutral telemetry runtime, hub, exporters, context, propagation, sampling, signals, spans, and bridges.','Adding correlated diagnostics or provider-neutral telemetry to Panel services.',['observability','telemetry','trace','span','exporter'],['platform'],['Dataphyre_Panel.md'],['Choose one exporter boundary.','Propagate bounded correlation context without secrets.','Keep sampling and failure policy explicit.'],['Own provider clients, credentials, network policy, retention, and alerting.'],'high',false,['adapter','platform']),
			'data'=>$this->makeProfile('Data','data','Typed query, mutation, registry, SQL/PDO, HTTP, repository, array, and change-feed data sources.','Connecting Panel surfaces to application or remote data.',['data source','query','mutation','sql','pdo','http data'],['security','observability','platform'],['Dataphyre_Panel_SQL.md','Dataphyre_Panel_HTTP_Data_Source.md'],['Choose read-only or mutable authority explicitly.','Pin capabilities, scopes, cursors, limits, and mutation receipts.','Keep query compilation separate from execution and provider ownership.'],['Own authorization, transactions, connections, HTTP clients, credentials, retries, and schema.'],'critical',false,['adapter','application']),
			'data_surfaces'=>$this->makeProfile('Data Surfaces','data','Signed, windowed, progressively enhanced DataSurface and DataCanvas projections.','Rendering large or interactive data windows without unbounded resource tables.',['data surface','data canvas','window','projection'],['data','security','realtime','platform'],['Dataphyre_Panel_Data_Surface.md'],['Define a bounded projection and stable identity.','Sign scope-bound window intents.','Mount endpoint and renderer with progressive fallback.'],['Own source authorization, identity, signing keys, route mounting, and browser policy.'],'critical',true,['application','platform']),
			'realtime'=>$this->makeProfile('Realtime','data','Broker, publisher, signed subscription intent, SSE session, PDO, and Redis Streams contracts.','Streaming bounded Panel events or bridging data subscriptions.',['realtime','stream','sse','broker','redis','subscription'],['data','security','observability','platform'],['Dataphyre_Panel_Realtime.md'],['Choose one stream head authority and delivery model.','Bind broker, signer, endpoint, replay policy, and client runtime.','Prove retention gaps, cancellation, integrity, replay rejection, and reconnect behavior.'],['Own Redis/SQL clients, credentials, TLS, persistence, failover, key rotation, and infrastructure monitoring.'],'critical',true,['realtime','adapter']),
			'workflows'=>$this->makeProfile('Workflows','orchestration','Workflow definitions, stores, approvals, engines, and audit events.','Encoding deterministic stateful business workflows.',['workflow','approval','workflow engine'],['operations','automation','observability','platform'],['Dataphyre_Panel_Agent_Safe_Workflows.md'],['Define states and transitions explicitly.','Persist transition/audit state before external continuation.','Model approvals and cancellation as contracts.'],['Own business authorization, durable scheduling, external effects, and retention.'],'high',false,['operations','application']),
			'automation'=>$this->makeProfile('Automation','orchestration','Registered automation actions, plans, stores, executors, and receipts.','Executing bounded reusable actions from Panel workflows.',['automation','action registry','automation plan'],['workflows','operations','security','observability'],['Dataphyre_Panel_Agent_Safe_Workflows.md'],['Register only typed bounded actions.','Separate planning from execution.','Persist receipts and enforce replay/idempotency policy.'],['Own action authorization, credentials, external-effect idempotency, and kill switches.'],'critical',false,['operations','application']),
			'agent_workflows'=>$this->makeProfile('Agent Workflows','orchestration','Policy-gated agent tools, signed intents, durable workflows, deferred jobs, and audit receipts.','Allowing agents to plan or execute governed Panel operations.',['agent workflow','agent tool','agent policy','signed intent'],['automation','workflows','operations','security','observability'],['Dataphyre_Panel_Agent_Safe_Workflows.md','Dataphyre_Panel_Distributed_Agent_Workflows.md'],['Declare tools and effect classes explicitly.','Resolve policy and confirmation before signing intent.','Persist one-attempt execution and audit receipts across deferred workers.'],['Own identity, authorization, secure material, model/tool policy, workers, credentials, and external effects.'],'critical',false,['operations','platform']),
			'authentication'=>$this->makeProfile('Authentication','trust','Authentication manager, stores, TOTP, recovery, step-up, trusted-device, session, and challenge contracts.','Adding Panel-native authentication and step-up flows.',['authentication','totp','step up','trusted device','session'],['security','iam','observability','platform'],['Dataphyre_Panel.md'],['Select a durable authentication store.','Bind challenge adapter and manager.','Exercise recovery, replay, expiry, rotation, and trusted-device revocation.'],['Own identity proofing, session transport, rate limiting, delivery channels, secrets, and audit policy.'],'critical',true,['platform','adapter']),
			'iam'=>$this->makeProfile('IAM','trust','Tenant-scoped principals, memberships, service accounts, invitations, policies, and IAM stores.','Managing Panel tenant identity and authorization control-plane state.',['iam','identity','membership','invitation','service account'],['authentication','security','observability','platform'],['Dataphyre_Panel.md'],['Choose tenant scope and principal identity contract.','Persist membership/invitation/policy transitions atomically.','Bind IAM decisions to Panel security context.'],['Own authoritative directories, provisioning, identity lifecycle, audit, and policy data.'],'critical',true,['platform','adapter']),
			'studio'=>$this->makeProfile('Studio','experience','Portable blueprints, trusted materialization, visual editor, collaboration connectors, previews, stores, and impact plans.','Building or extending Panel Studio and its live visual editing lifecycle.',['studio','visual editor','blueprint','materializer','editor','collaboration connector'],['collaboration','media','realtime','security','preferences','development','platform'],['Dataphyre_Panel_Studio_Editor.md'],['Keep portable compilation non-executable.','Cross trusted registry/materialization boundaries explicitly.','Bind editor, preview, collaboration, identity, media, and persistence lifecycles without losing unsaved state.'],['Own routes, identity, authorization, CSRF/origin policy, signing keys, persistence, asset delivery, and publication.'],'critical',true,['studio','platform']),
			'notifications'=>$this->makeProfile('Notifications','experience','Notification adapters, activity stores, and snapshot-backed state.','Delivering or recording Panel notifications and operator activity.',['notification','activity','inbox'],['observability','preferences','security','platform'],['Dataphyre_Panel.md'],['Choose delivery and activity persistence separately.','Preserve passive navigation versus mutating activity semantics.','Expose delivery failures and receipts honestly.'],['Own provider transport, credentials, recipient policy, retries, and retention.'],'high',true,['application','adapter']),
			'media'=>$this->makeProfile('Media','data','Media manager, disks, snapshot catalogues, resumable upload, processing, signed delivery, cleanup, and Dataphyre Storage integration.','Persisting, cataloguing, transforming, or delivering Panel media.',['media','upload','snapshot store','storage disk','asset'],['data','security','observability','platform'],['Dataphyre_Panel.md','Dataphyre_Panel_Studio_Editor.md'],['Choose disk and snapshot catalogue independently.','Install shared snapshot schema explicitly when distributed.','Verify size/digest after writes and compensate failed catalogue mutations.'],['Own storage manager, credentials, bucket policy, malware scanning, transformers, delivery keys, and retention.'],'critical',true,['adapter','application']),
			'localization'=>$this->makeProfile('Localization','experience','Locale catalogue loading, formatting, metadata, and RTL runtime.','Localizing Panel copy, formatting, direction, and locale behavior.',['localization','locale','translation','rtl','formatter'],['preferences','platform'],['Dataphyre_Panel.md'],['Bind a host-owned catalogue loader.','Keep locale fallback and formatting deterministic.','Verify RTL, long text, pseudo-locale, and input behavior.'],['Own translations, locale policy, plural data, deployment, and content review.'],'medium',true,['application']),
			'preferences'=>$this->makeProfile('Preferences','experience','Preference stores, workspace factories, profiles, conflicts, and state engines.','Persisting tenant/user workspace presentation state.',['preference','workspace preference','saved view'],['security','localization','platform'],['Dataphyre_Panel.md'],['Define preference scope and defaults.','Choose conflict/merge semantics.','Persist only non-secret bounded presentation state.'],['Own identity/scope mapping, retention, privacy policy, and durable storage.'],'high',true,['application','adapter']),
			'collaboration'=>$this->makeProfile('Collaboration','data','Memory, filesystem, and PDO collaboration stores, manager, receipts, policy, and state engine.','Coordinating revisions, presence, focus, or draft convergence across Panel clients.',['collaboration','presence','revision','draft','receipt'],['realtime','security','preferences','platform'],['Dataphyre_Panel_Studio_Editor.md'],['Choose durable revision authority.','Use signed scoped intents and host-held presence leases.','Reconcile deltas after one-attempt mutations while preserving peer drafts.'],['Own identity, authorization, origin/CSRF policy, clocks, durable storage, cleanup, and transport.'],'critical',true,['studio','realtime','adapter']),
			'packages'=>$this->makeProfile('Packages','ecosystem','Package manifests, templates, signed registries, trust, transparency, resolution, acquisition, install, and rollback.','Publishing, discovering, acquiring, or activating Panel packages.',['package','marketplace','registry','trust','transparency','install'],['security','extensions','development','platform'],['Dataphyre_Panel_Marketplace_Trust.md','Dataphyre_Panel_Filament_Migration.md','Dataphyre_Panel.md'],['Verify manifest, publisher, signature, transparency, revocation, compatibility, and artifact evidence independently.','Resolve and acquire before installation.','Preview lock-held activation and retain complete rollback evidence.'],['Own keys, trust roots, moderation, remote transport, credentials, cache/CDN, execution isolation, and package review.'],'critical',true,['platform','adapter']),
			'relations'=>$this->makeProfile('Relations','experience','Relation workspaces, adapters, commands, and nested resource integration.','Building related-record managers and scoped nested operations.',['relation','relation manager','nested resource'],['data','security','platform'],['Dataphyre_Panel.md'],['Choose relation adapter and parent scope.','Preserve record identity and authorization across commands.','Exercise attach/detach/reorder/create/edit/delete semantics.'],['Own relationship authorization, persistence transactions, and business invariants.'],'high',true,['application']),
			'security'=>$this->makeProfile('Security','trust','Panel security context, policy, audit, impersonation, and permission simulation.','Establishing authorization and auditable privileged boundaries.',['security','authorization','policy','audit','impersonation','permission'],['observability','platform'],['Dataphyre_Panel.md','Dataphyre_Panel_Agent_Safe_Workflows.md'],['Construct explicit tenant/principal context.','Evaluate policy before rendering and mutation.','Record privileged effects and isolate simulation from enforcement.'],['Own identity, policy source, CSRF/origin/session security, audit keys, retention, and incident response.'],'critical',true,['platform','application']),
			'development'=>$this->makeProfile('Development','ecosystem','Developer toolkit, manifest inspection/diff, schema blueprints, quality matrices, Studio tooling, and SDK generation.','Generating, inspecting, or validating Panel development artifacts.',['development','toolkit','sdk','static analysis','quality matrix','blueprint'],['platform','studio'],['Dataphyre_Panel_Application_SDK.md','Dataphyre_Panel_Static_Analysis.md','Dataphyre_Panel_Testing.md'],['Inspect before generating.','Keep generated contracts deterministic and host-bound.','Compile generated PHP and strict TypeScript, then run exact focused evidence.'],['Own destination paths, transport implementations, credentials, publication, and generated-code review.'],'high',false,['platform','application']),
			'extensions'=>$this->makeProfile('Extensions','ecosystem','Extension descriptors, registries, runtimes, assets, hooks, and lifecycle boundaries.','Adding optional Panel behavior without coupling core.',['extension','plugin','hook','asset lifecycle'],['packages','security','platform'],['Dataphyre_Panel_Reactor_Widgets.md','Dataphyre_Panel.md'],['Declare dependencies, versions, hooks, events, and assets.','Register transactionally with rollback.','Revoke browser/runtime lifecycle state on unload.'],['Own package distribution, authorization, routes, assets, and third-party runtime semantics.'],'high',true,['platform','application']),
			'platform'=>$this->makeProfile('Platform','foundation','Typed Panel service container, capability manifest, controller, template, and cohesive composition root.','Composing multiple Panel domains into one truthful host runtime.',['platform','service container','capability manifest','composition root'],['security','observability'],['Dataphyre_Panel.md','Dataphyre_Panel_Operations_OS.md'],['Register typed services without hidden global state.','Inspect availability, configuration, readiness, and cohesion separately.','Keep optional factories unresolved until the host supplies them.'],['Own complete service composition, routing, identity, infrastructure, credentials, lifecycle, and deployment.'],'critical',true,['platform']),
		];
	}

	/** Adds honest fallback semantics for a newly source-discovered domain. */
	private function availableProfiles(): array {
		$profiles=$this->profiles();
		foreach($this->snapshot()['domains'] as $domain){$name=(string)($domain['name']??'');if($name!==''&&!isset($profiles[$name])){$profiles[$name]=$this->profile($name,$profiles);}}
		return $profiles;
	}

	/** @return array<string,mixed> */
	private function makeProfile(string $label,string $category,string $summary,string $useWhen,array $aliases,array $dependsOn,array $documents,array $focus,array $hostObligations,string $risk,bool $browser,array $modes): array {
		$dependsOn=$this->uniqueStrings($dependsOn);return ['label'=>$label,'category'=>$category,'summary'=>$summary,'use_when'=>$useWhen,'aliases'=>array_values(array_unique(array_map($this->slug(...),array_merge([$label],$aliases)))),'depends_on'=>$dependsOn,'related_domains'=>$dependsOn,'documents'=>$documents,'implementation_focus'=>$focus,'host_obligations'=>$hostObligations,'risk'=>$risk,'browser'=>$browser,'modes'=>$modes];
	}

	/** @return array<string,mixed> */
	private function semanticDiagnostics(): array {$profiles=$this->profiles();$source=array_column($this->snapshot()['domains'],'name');$profileNames=array_keys($profiles);sort($source,SORT_STRING);sort($profileNames,SORT_STRING);return ['source_domain_count'=>count($source),'profile_count'=>count($profileNames),'missing_profiles'=>array_values(array_diff($source,$profileNames)),'stale_profiles'=>array_values(array_diff($profileNames,$source)),'source_diagnostics'=>$this->snapshot()['diagnostics']];}

	/** @return list<string> */
	private function descriptorNextActions(string $domain,string $view): array {return [
		$view==='overview'?'Open only the contracts, integration, verification, security, or all view required by the next decision.':'Use this view as planning evidence; describe exact contract ids before implementing.',
		'Call dataphyre_panel_surface_graph with roots=['.$domain.'] before composing adjacent domains.',
		'Call dataphyre_panel_verification_plan after selecting changed source paths and the intended claim.',
	];}

	/** @return list<string> */
	private function negativeGuarantees(): array {return ['No Panel source is loaded, reflected, bootstrapped, or executed.','No routes, SQL, storage, Redis, HTTP, browser, package, migration, worker, or external-effect operation is performed.','No capability availability implies host configuration or runtime readiness.','No exact, browser, compatibility, or release claim is inferred from static metadata.'];}

	/** @return array<string,mixed> */
	private function safetyContract(string $surface): array {return ['surface'=>$surface,'classification'=>'read_only_panel_capability_intelligence','execution'=>'not_executed','source_methods'=>['literal PHP array token decoding','Dataphyre contract token index','bounded source/document/test inventory'],'reflection_used'=>false,'eval_used'=>false,'runtime_bootstrap_used'=>false,'not_performed'=>['Panel class loading','route dispatch','SQL or storage access','network requests','browser execution','package activation','source writes'],'host_authority_preserved'=>true];}

	/** @return array<string,mixed> */ private function snapshot(): array {return $this->snapshot??=$this->source->snapshot();}
	/** @return list<string> */ private function stringList(mixed $value): array {$values=is_array($value)?$value:[$value];return $this->uniqueStrings(array_values(array_filter(array_map(static fn(mixed $item): string=>trim((string)$item),$values),static fn(string $item): bool=>$item!=='')));}
	/** @return list<string> */ private function uniqueStrings(array $values): array {$values=array_values(array_unique(array_filter(array_map('strval',$values),static fn(string $item): bool=>$item!=='')));sort($values,SORT_STRING);return $values;}
	/** @return list<array<string,mixed>> */ private function uniqueArrays(array $values,int $limit): array {$unique=[];foreach($values as $value){if(!is_array($value)){continue;}$key=(string)($value['path']??hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)));$unique[$key]=$value;}return array_slice(array_values($unique),0,$limit);}
	private function slug(string $value): string {return trim(preg_replace('/[^a-z0-9]+/','_',strtolower($value))??'','_');}
	private function humanize(string $value): string {return ucwords(str_replace(['_','-'],' ',$value));}
	private function shortClass(string $class): string {return str_contains($class,'\\')?substr($class,(int)strrpos($class,'\\')+1):$class;}
}
