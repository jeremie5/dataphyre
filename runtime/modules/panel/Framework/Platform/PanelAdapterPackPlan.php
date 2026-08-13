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
 * Immutable, previewable installation plan for one adapter pack.
 *
 * Raw values remain process-local. Public output contains key names and a
 * process-keyed digest, preventing low-entropy credentials from becoming
 * offline-comparable hashes while still detecting any change before apply.
 */
final class PanelAdapterPackPlan implements \JsonSerializable {
	private const RESERVED_BINDING_KEYS=['enabled','replace','conformance','conformance_options'];
	private const TOP_LEVEL_KEYS=[
		'adapters','conformance','conformance_options','require_conformance',
		'allow_destructive_conformance','allow_skipped_conformance','replace',
		'extension_permissions','permissions',
	];

	/** @var array<string,array<string,mixed>> */
	private array $bindingConfig=[];
	/** @var array<string,bool> */
	private array $replacement=[];
	/** @var array<string,bool> */
	private array $conformance=[];
	/** @var array<string,array<string,mixed>> */
	private array $conformanceOptions=[];
	/** @var list<string> */
	private array $order=[];
	/** @var list<string> */
	private array $errors=[];
	private bool $requireConformance=true;
	private bool $allowDestructiveConformance=false;
	private bool $allowSkippedConformance=false;
	private string $configDigest;
	private string $stateFingerprint;
	private string $fingerprint;
	private static ?string $digestKey=null;

	/** @param array<string,mixed> $config */
	private function __construct(
		private readonly PanelAdapterPack $pack,
		private readonly PanelInstance $panel,
		private readonly array $config,
		private readonly bool $staged
	) {
		$this->configDigest=self::privateDigest($config);
		$this->stateFingerprint=self::panelStateFingerprint($panel, $staged ? $pack->id() : null);
		$this->compile();
		$this->fingerprint=hash('sha256', json_encode([
			'pack'=>$pack->runtimeFingerprint(),
			'config'=>$this->configDigest,
			'state'=>$this->stateFingerprint,
			'order'=>$this->order,
			'replace'=>$this->replacement,
			'conformance'=>$this->conformance,
			'require_conformance'=>$this->requireConformance,
			'allow_destructive_conformance'=>$this->allowDestructiveConformance,
			'allow_skipped_conformance'=>$this->allowSkippedConformance,
			'errors'=>$this->errors,
		], JSON_THROW_ON_ERROR));
	}

	/** @param array<string,mixed> $config */
	public static function make(PanelAdapterPack $pack, PanelInstance $panel, array $config=[]): self {
		return new self($pack, $panel, $config, false);
	}

	/** Internal registration-phase plan after the parent plugin is staged. @param array<string,mixed> $config */
	public static function forRegistration(PanelAdapterPack $pack, PanelInstance $panel, array $config=[]): self {
		return new self($pack, $panel, $config, true);
	}

	public function pack(): PanelAdapterPack {return $this->pack;}
	public function panel(): PanelInstance {return $this->panel;}
	public function ready(): bool {return $this->errors===[];}
	/** @return list<string> */
	public function errors(): array {return $this->errors;}
	/** @return list<string> */
	public function order(): array {return $this->order;}
	public function fingerprint(): string {return $this->fingerprint;}
	public function configDigest(): string {return $this->configDigest;}
	public function stateFingerprint(): string {return $this->stateFingerprint;}
	public function requireConformance(): bool {return $this->requireConformance;}
	public function allowDestructiveConformance(): bool {return $this->allowDestructiveConformance;}
	public function allowSkippedConformance(): bool {return $this->allowSkippedConformance;}

	public function enabled(string $binding): bool {
		return array_key_exists(Resource::normalizeName($binding), $this->bindingConfig);
	}

	/** @return array<string,mixed> */
	public function bindingConfig(string $binding): array {
		return $this->bindingConfig[Resource::normalizeName($binding)] ?? [];
	}

	public function replace(string $binding): bool {
		return $this->replacement[Resource::normalizeName($binding)] ?? false;
	}

	public function runsConformance(string $binding): bool {
		return $this->conformance[Resource::normalizeName($binding)] ?? false;
	}

	/** @return array<string,mixed> */
	public function conformanceOptions(string $binding): array {
		return $this->conformanceOptions[Resource::normalizeName($binding)] ?? [];
	}

	public function assertReady(): self {
		if(!$this->ready()){
			throw new \LogicException('Adapter pack plan is not ready: '.implode(' ', $this->errors));
		}
		return $this;
	}

	/**
	 * Revalidates the exact definition, private configuration, and Panel state
	 * immediately before entering the plugin lifecycle transaction.
	 */
	public function apply(): PanelAdapterPackActivation {
		$this->assertReady();
		$fresh=self::make($this->pack, $this->panel, $this->config);
		if(!$fresh->ready() || !hash_equals($this->fingerprint, $fresh->fingerprint())){
			throw new \LogicException('Adapter pack plan is stale; create a fresh preview before applying it.');
		}
		$this->panel->plugin($this->pack, $this->config);
		return $this->pack->activation($this->panel);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		$bindings=[];
		foreach($this->pack->bindings() as $id=>$binding){
			$bindings[$id]=[
				'enabled'=>$this->enabled($id),
				'target'=>$binding->target(),
				'contract'=>$binding->contract(),
				'config_keys_present'=>array_keys($this->bindingConfig[$id] ?? []),
				'replace'=>$this->replacement[$id] ?? $binding->replaceDefault(),
				'conformance'=>$this->conformance[$id] ?? false,
				'factory_executed'=>false,
			];
		}
		return [
			'type'=>'panel_adapter_pack_plan',
			'schema_version'=>1,
			'pack'=>$this->pack->id(),
			'version'=>$this->pack->version(),
			'panel'=>$this->panel->name(),
			'ready'=>$this->ready(),
			'errors'=>$this->errors,
			'order'=>$this->order,
			'binding_count'=>count($this->bindingConfig),
			'bindings'=>$bindings,
			'require_conformance'=>$this->requireConformance,
			'allow_destructive_conformance'=>$this->allowDestructiveConformance,
			'allow_skipped_conformance'=>$this->allowSkippedConformance,
			'config_digest'=>$this->configDigest,
			'state_fingerprint'=>$this->stateFingerprint,
			'fingerprint'=>$this->fingerprint,
			'configuration_serialized'=>false,
			'factory_objects_serialized'=>false,
		];
	}

	private function compile(): void {
		$this->validateTopLevel();
		$this->requireConformance=$this->boolean('require_conformance', true);
		$this->allowDestructiveConformance=$this->boolean('allow_destructive_conformance', false);
		$this->allowSkippedConformance=$this->boolean('allow_skipped_conformance', false);
		$definitions=$this->pack->bindings();
		$configured=$this->adapterSelections($definitions);
		$globalReplace=$this->replacementSelections($definitions);
		[$globalConformance,$globalConformanceOptions]=$this->conformanceSelections($definitions);
		$sharedConformance=$this->sharedConformanceOptions($definitions);

		foreach($definitions as $id=>$binding){
			$entry=$configured[$id] ?? null;
			$explicit=array_key_exists($id, $configured);
			$enabled=!$binding->optional();
			$entryConfig=[];
			if($explicit){
				if(is_bool($entry)){
					$enabled=$entry;
				}
				elseif(is_array($entry)){
					if(array_key_exists('enabled', $entry) && !is_bool($entry['enabled'])){
						$this->error("Adapter '{$id}' enabled must be boolean.");
					}
					$enabled=array_key_exists('enabled', $entry) ? $entry['enabled']===true : true;
					$entryConfig=$entry;
				}
				else {
					$this->error("Adapter '{$id}' selection must be boolean or an option map.");
					$enabled=false;
				}
			}
			if(!$binding->optional() && !$enabled){
				$this->error("Required adapter '{$id}' cannot be disabled.");
				$enabled=true;
			}
			if(!$enabled){continue;}

			$allowed=array_fill_keys(array_merge($binding->configKeys(), self::RESERVED_BINDING_KEYS), true);
			foreach(array_keys($entryConfig) as $key){
				if(!is_string($key) || !isset($allowed[$key])){
					$this->error("Adapter '{$id}' has unknown configuration key '".(is_scalar($key)?(string)$key:get_debug_type($key))."'.");
				}
			}
			$factoryConfig=[];
			foreach($binding->configKeys() as $key){
				if(array_key_exists($key, $entryConfig)){$factoryConfig[$key]=$entryConfig[$key];}
			}
			foreach($binding->requiredConfigKeys() as $key){
				if(!array_key_exists($key, $factoryConfig)){
					$this->error("Adapter '{$id}' requires configuration key '{$key}'.");
				}
			}
			$this->bindingConfig[$id]=$factoryConfig;

			$replace=$binding->replaceDefault();
			if(array_key_exists($id, $globalReplace)){$replace=$globalReplace[$id];}
			if(array_key_exists('replace', $entryConfig)){
				if(!is_bool($entryConfig['replace'])){$this->error("Adapter '{$id}' replace must be boolean.");}
				else{$replace=$entryConfig['replace'];}
			}
			$this->replacement[$id]=$replace;

			$run=$globalConformance[$id] ?? false;
			if(array_key_exists('conformance', $entryConfig)){
				if(!is_bool($entryConfig['conformance'])){$this->error("Adapter '{$id}' conformance must be boolean.");}
				else{$run=$entryConfig['conformance'];}
			}
			if($run && $binding->conformance()===null){
				$this->error("Adapter '{$id}' does not declare a conformance suite.");
				$run=false;
			}
			$this->conformance[$id]=$run;
			$options=array_replace(
				$sharedConformance['*'] ?? [],
				$sharedConformance[$id] ?? [],
				$globalConformanceOptions[$id] ?? []
			);
			if(array_key_exists('conformance_options', $entryConfig)){
				if(!is_array($entryConfig['conformance_options'])){
					$this->error("Adapter '{$id}' conformance_options must be a map.");
				}
				else{$options=array_replace($options, $entryConfig['conformance_options']);}
			}
			$this->conformanceOptions[$id]=$options;

			foreach($binding->requiredClasses() as $class){
				if(!class_exists($class) && !interface_exists($class) && !trait_exists($class)){
					$this->error("Adapter '{$id}' requires unavailable class '{$class}'.");
				}
			}
		}

		if($this->bindingConfig===[]){
			$this->error('Adapter pack plans must enable at least one binding.');
		}
		$this->order=$this->dependencyOrder($definitions);
		$this->preflightTargets($definitions);
	}

	private function validateTopLevel(): void {
		$allowed=array_fill_keys(self::TOP_LEVEL_KEYS, true);
		foreach(array_keys($this->config) as $key){
			if(!is_string($key) || !isset($allowed[$key])){
				$this->error("Unknown adapter pack option '".(is_scalar($key)?(string)$key:get_debug_type($key))."'.");
			}
		}
	}

	private function boolean(string $key, bool $default): bool {
		if(!array_key_exists($key, $this->config)){return $default;}
		if(!is_bool($this->config[$key])){
			$this->error("Adapter pack option '{$key}' must be boolean.");
			return $default;
		}
		return $this->config[$key];
	}

	/**
	 * @param array<string,PanelAdapterPackBinding> $definitions
	 * @return array<string,bool|array<string,mixed>>
	 */
	private function adapterSelections(array $definitions): array {
		$value=$this->config['adapters'] ?? [];
		if(!is_array($value)){
			$this->error('Adapter pack adapters must be a name list or option map.');
			return [];
		}
		if(array_is_list($value)){
			$mapped=[];
			foreach($value as $id){
				if(!is_string($id)){$this->error('Adapter pack adapter lists may contain only names.');continue;}
				$id=Resource::normalizeName($id);
				if($id!==''){$mapped[$id]=true;}
			}
			$value=$mapped;
		}
		$result=[];
		foreach($value as $id=>$selection){
			$id=is_string($id)?Resource::normalizeName($id):'';
			if($id==='' || !isset($definitions[$id])){
				$this->error("Unknown adapter binding '".($id!==''?$id:(is_scalar($id)?(string)$id:'invalid'))."'.");
				continue;
			}
			$result[$id]=$selection;
		}
		return $result;
	}

	/**
	 * @param array<string,PanelAdapterPackBinding> $definitions
	 * @return array<string,bool>
	 */
	private function replacementSelections(array $definitions): array {
		$value=$this->config['replace'] ?? [];
		if(is_bool($value)){
			return array_fill_keys(array_keys($definitions), $value);
		}
		if(!is_array($value) || array_is_list($value)){
			if($value!==[]){$this->error('Adapter pack replace must be boolean or a binding-to-boolean map.');}
			return [];
		}
		$result=[];
		foreach($value as $id=>$replace){
			$id=is_string($id)?Resource::normalizeName($id):'';
			if($id==='' || !isset($definitions[$id])){$this->error("Unknown replacement binding '{$id}'.");continue;}
			if(!is_bool($replace)){$this->error("Replacement policy for '{$id}' must be boolean.");continue;}
			$result[$id]=$replace;
		}
		return $result;
	}

	/**
	 * @param array<string,PanelAdapterPackBinding> $definitions
	 * @return array{0:array<string,bool>,1:array<string,array<string,mixed>>}
	 */
	private function conformanceSelections(array $definitions): array {
		$value=$this->config['conformance'] ?? false;
		if(is_bool($value)){
			return [$value?array_fill_keys(array_keys($definitions), true):[],[]];
		}
		if(!is_array($value)){
			$this->error('Adapter pack conformance must be boolean, a binding list, or an option map.');
			return [[],[]];
		}
		if(array_is_list($value)){
			$selected=[];
			foreach($value as $id){
				$id=is_string($id)?Resource::normalizeName($id):'';
				if($id==='' || !isset($definitions[$id])){$this->error("Unknown conformance binding '{$id}'.");continue;}
				$selected[$id]=true;
			}
			return [$selected,[]];
		}
		$selected=[];$options=[];
		foreach($value as $id=>$selection){
			$id=is_string($id)?Resource::normalizeName($id):'';
			if($id==='' || !isset($definitions[$id])){$this->error("Unknown conformance binding '{$id}'.");continue;}
			if(is_bool($selection)){$selected[$id]=$selection;continue;}
			if(is_array($selection)){$selected[$id]=true;$options[$id]=$selection;continue;}
			$this->error("Conformance selection for '{$id}' must be boolean or an option map.");
		}
		return [$selected,$options];
	}

	/**
	 * @param array<string,PanelAdapterPackBinding> $definitions
	 * @return array<string,array<string,mixed>>
	 */
	private function sharedConformanceOptions(array $definitions): array {
		$value=$this->config['conformance_options'] ?? [];
		if(!is_array($value) || ($value!==[] && array_is_list($value))){
			$this->error('Adapter pack conformance_options must map bindings to option maps.');
			return [];
		}
		$result=[];
		foreach($value as $rawId=>$options){
			$id=$rawId==='*'?'*':(is_string($rawId)?Resource::normalizeName($rawId):'');
			if($id!=='*' && ($id==='' || !isset($definitions[$id]))){
				$this->error("Unknown conformance-options binding '{$id}'.");
				continue;
			}
			if(!is_array($options)){$this->error("Conformance options for '{$id}' must be a map.");continue;}
			$result[$id]=$options;
		}
		return $result;
	}

	/**
	 * @param array<string,PanelAdapterPackBinding> $definitions
	 * @return list<string>
	 */
	private function dependencyOrder(array $definitions): array {
		$order=[];$states=[];$stack=[];
		$visit=function(string $id) use (&$visit,&$order,&$states,&$stack,$definitions): void {
			if(($states[$id] ?? 0)===2){return;}
			if(($states[$id] ?? 0)===1){
				$cycle=array_slice($stack, array_search($id, $stack, true) ?: 0);$cycle[]=$id;
				$this->error('Adapter dependency cycle: '.implode(' -> ', $cycle).'.');
				return;
			}
			$states[$id]=1;$stack[]=$id;
			$binding=$definitions[$id];
			foreach($binding->dependencies() as $dependency){
				if(!isset($definitions[$dependency])){
					$this->error("Adapter '{$id}' depends on undefined binding '{$dependency}'.");
					continue;
				}
				if(!isset($this->bindingConfig[$dependency])){
					$this->error("Adapter '{$id}' requires disabled binding '{$dependency}'.");
					continue;
				}
				$visit($dependency);
			}
			array_pop($stack);$states[$id]=2;
			if(!in_array($id,$order,true)){$order[]=$id;}
		};
		foreach(array_keys($this->bindingConfig) as $id){$visit($id);}
		return $order;
	}

	/** @param array<string,PanelAdapterPackBinding> $definitions */
	private function preflightTargets(array $definitions): void {
		if($this->staged){
			if(!$this->panel->hasPlugin($this->pack->id())){
				$this->error("Adapter pack '{$this->pack->id()}' is not staged in the Panel plugin transaction.");
			}
		}
		elseif($this->panel->hasPlugin($this->pack->id())){
			$this->error("Adapter pack '{$this->pack->id()}' is already installed.");
		}
		foreach($this->order as $id){
			$binding=$definitions[$id];$name=$binding->targetName();$replace=$this->replacement[$id] ?? false;
			try{
				switch($binding->targetType()){
					case 'platform':
						if($this->panel->platform()->has($name) && !$replace){$this->error("Panel platform service '{$name}' already exists.");}
						break;
					case 'search':
						if($this->panel->hasSearchProvider($name) && !$replace){$this->error("Panel search provider '{$name}' already exists.");}
						break;
					case 'plugin':
						if($this->panel->hasPlugin($name)){$this->error("Panel plugin '{$name}' already exists and cannot be replaced by an adapter pack.");}
						break;
					case 'data':
						if(!$this->panel->hasDataSources()){$this->error("Adapter '{$id}' requires a ready Panel data-source registry.");break;}
						if($this->panel->dataSources()->has($name) && !$replace){$this->error("Panel data source '{$name}' already exists.");}
						break;
				}
			}
			catch(\Throwable $exception){
				$this->error("Adapter '{$id}' target preflight failed with ".$exception::class.'.');
			}
		}
	}

	private function error(string $message): void {
		$message=trim($message);
		if($message!=='' && !in_array($message,$this->errors,true) && count($this->errors)<100){$this->errors[]=$message;}
	}

	private static function panelStateFingerprint(PanelInstance $panel, ?string $ignorePlugin=null): string {
		$platform=null;
		try{
			$platform=$panel->platform();
			$platform=[
				'object'=>spl_object_id($platform),
				'revision'=>$platform->revision(),
				'services'=>$platform->serviceDescriptors(),
			];
		}
		catch(\Throwable $exception){$platform=['error'=>$exception::class];}
		$data=null;
		try{$data=$panel->hasDataSources()?$panel->dataSources()->fingerprint():null;}catch(\Throwable $exception){$data=$exception::class;}
		$plugins=$panel->pluginIds();
		if($ignorePlugin!==null){$plugins=array_values(array_filter($plugins,static fn(string $id):bool=>$id!==$ignorePlugin));}
		sort($plugins,SORT_STRING);
		$search=array_keys($panel->searchProviders());sort($search,SORT_STRING);
		return hash('sha256', json_encode([
			'panel'=>$panel->name(),
			'panel_object'=>spl_object_id($panel),
			'plugins'=>$plugins,
			'search'=>$search,
			'platform'=>$platform,
			'data'=>$data,
		], JSON_THROW_ON_ERROR));
	}

	private static function privateDigest(mixed $value): string {
		self::$digestKey ??= random_bytes(32);
		return hash_hmac('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION), self::$digestKey);
	}

	private static function canonical(mixed $value): mixed {
		if($value===null){return ['null'];}
		if(is_bool($value)){return ['bool',$value];}
		if(is_int($value)){return ['int',$value];}
		if(is_float($value)){
			return ['float',is_finite($value)?$value:(is_nan($value)?'nan':($value>0?'inf':'-inf'))];
		}
		if(is_string($value)){return ['string',$value];}
		if(is_resource($value)){return ['resource',get_resource_type($value),get_resource_id($value)];}
		if(is_array($value)){
			$items=[];
			foreach($value as $key=>$item){$items[]=[is_int($key)?['int',$key]:['string',$key],self::canonical($item)];}
			if(!array_is_list($value)){
				usort($items,static fn(array $left,array $right):int=>json_encode($left[0],JSON_THROW_ON_ERROR)<=>json_encode($right[0],JSON_THROW_ON_ERROR));
			}
			return ['array',$items];
		}
		if($value instanceof \BackedEnum){return ['backed_enum',$value::class,$value->name,$value->value];}
		if($value instanceof \UnitEnum){return ['enum',$value::class,$value->name];}
		if(is_object($value)){return ['object',$value::class,spl_object_id($value)];}
		return ['type',get_debug_type($value)];
	}
}
