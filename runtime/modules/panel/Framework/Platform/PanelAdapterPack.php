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
 * Transactional framework-integration package built on Panel's plugin lifecycle.
 *
 * A pack is immutable after composition. Installation resolves bindings in a
 * deterministic dependency order, optionally gates them through production
 * conformance suites, and contributes them only through rollback-aware Panel
 * registries.
 */
final class PanelAdapterPack implements PanelPlugin, \JsonSerializable {
	/** @var array<string,PanelAdapterPackBinding> */
	private array $bindings=[];
	/** @var list<string> */
	private array $requiredPlugins;
	/** @var \WeakMap<PanelInstance,PanelAdapterPackActivation> */
	private \WeakMap $activations;

	/**
	 * @param array{label?:string,description?:string,publisher?:string,required_plugins?:list<string>} $options
	 */
	public function __construct(
		private readonly string $packId,
		private readonly string $packVersion='1.0.0',
		private readonly array $options=[]
	) {
		if(Resource::normalizeName($packId)!==trim($packId) || trim($packId)==='' || strlen($packId)>128){
			throw new \InvalidArgumentException('Adapter pack ids must be canonical names.');
		}
		if(preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D',trim($packVersion))!==1){
			throw new \InvalidArgumentException('Adapter pack versions must be semantic versions.');
		}
		foreach(array_keys($options) as $key){
			if(!in_array($key,['label','description','publisher','required_plugins'],true)){
				throw new \InvalidArgumentException("Unknown adapter pack definition option '{$key}'.");
			}
		}
		$this->requiredPlugins=self::names($options['required_plugins'] ?? []);
		if(in_array($packId,$this->requiredPlugins,true)){
			throw new \InvalidArgumentException('Adapter packs cannot require themselves.');
		}
		$this->activations=new \WeakMap();
	}

	/** @param array<string,mixed> $options */
	public static function make(string $id, string $version='1.0.0', array $options=[]): self {
		return new self($id,$version,$options);
	}

	public function __clone() {
		$this->activations=new \WeakMap();
	}

	public function id(): string {return $this->packId;}
	public function version(): string {return $this->packVersion;}
	public function label(): string {return self::text((string)($this->options['label'] ?? '')) ?: ucwords(str_replace(['_','-','.'],' ',$this->packId));}
	public function description(): string {return self::text((string)($this->options['description'] ?? ''));}
	public function publisher(): string {return self::text((string)($this->options['publisher'] ?? ''));}
	/** @return list<string> */
	public function dependencies(): array {return $this->requiredPlugins;}
	/** @return list<string> */
	public function requiredPlugins(): array {return $this->requiredPlugins;}

	public function binding(PanelAdapterPackBinding $binding): self {
		if(isset($this->bindings[$binding->id()])){
			throw new \LogicException("Adapter pack binding '{$binding->id()}' is already defined.");
		}
		if(count($this->bindings)>=128){
			throw new \LengthException('Adapter packs support at most 128 bindings.');
		}
		foreach($this->bindings as $existing){
			if($existing->target()===$binding->target()){
				throw new \LogicException("Adapter pack target '{$binding->target()}' is already claimed by '{$existing->id()}'.");
			}
		}
		$clone=clone $this;
		$clone->bindings[$binding->id()]=$binding;
		return $clone;
	}

	/** @param list<PanelAdapterPackBinding> $bindings */
	public function bindingsFrom(array $bindings): self {
		$pack=$this;
		foreach($bindings as $binding){
			if(!$binding instanceof PanelAdapterPackBinding){
				throw new \InvalidArgumentException('Adapter pack binding collections must contain PanelAdapterPackBinding objects.');
			}
			$pack=$pack->binding($binding);
		}
		return $pack;
	}

	/** @return array<string,PanelAdapterPackBinding> */
	public function bindings(): array {return $this->bindings;}

	/** @param array<string,mixed> $config */
	public function plan(PanelInstance $panel, array $config=[]): PanelAdapterPackPlan {
		return PanelAdapterPackPlan::make($this,$panel,$config);
	}

	/** @param array<string,mixed> $config */
	public function install(PanelInstance $panel, array $config=[]): PanelAdapterPackActivation {
		return $this->plan($panel,$config)->apply();
	}

	public function register(PanelInstance $panel): void {
		if($this->bindings===[]){
			throw new \LogicException("Adapter pack '{$this->packId}' has no bindings.");
		}
		$config=$panel->pluginConfig($this->packId);
		$plan=PanelAdapterPackPlan::forRegistration($this,$panel,$config)->assertReady();
		$bindingConfig=[];
		foreach($plan->order() as $id){$bindingConfig[$id]=$plan->bindingConfig($id);}
		$context=new PanelAdapterPackContext($panel,$this,$bindingConfig);
		$runner=new PanelAdapterConformanceRunner();
		$conformance=[];$targets=[];

		foreach($plan->order() as $id){
			$binding=$this->bindings[$id];
			$adapter=$binding->create($context,$plan->bindingConfig($id));
			$context->resolved($id,$adapter);

			if($plan->runsConformance($id)){
				$suite=$binding->conformance() ?? throw new \LogicException("Adapter '{$id}' has no conformance suite.");
				$options=array_replace($plan->conformanceOptions($id),[
					'allow_destructive'=>$plan->allowDestructiveConformance(),
					'meta'=>[
						'pack'=>$this->packId,
						'binding'=>$id,
						'target'=>$binding->target(),
					],
				]);
				$report=$runner->run($suite,$adapter,$options);
				$conformance[$id]=$report;
				if($plan->requireConformance() && !$report->passed($plan->allowSkippedConformance())){
					$summary=$report->summary();
					throw new \RuntimeException(
						"Adapter '{$id}' failed conformance ({$summary['failed']} failed, {$summary['skipped']} skipped)."
					);
				}
			}

			$this->installBinding($panel,$binding,$adapter,$plan->replace($id));
			$targets[$id]=$binding->target();
		}

		$this->activations[$panel]=new PanelAdapterPackActivation(
			$this->packId,
			$this->packVersion,
			$panel->name(),
			$plan->fingerprint(),
			$context->adapters(),
			$conformance,
			$targets,
			time()
		);
	}

	public function boot(PanelInstance $panel): void {
		if(!isset($this->activations[$panel])){
			throw new \LogicException("Adapter pack '{$this->packId}' was not activated during registration.");
		}
	}

	public function unregister(?PanelInstance $panel=null): void {
		if($panel!==null){unset($this->activations[$panel]);}
	}

	public function active(PanelInstance $panel): bool {return isset($this->activations[$panel]);}

	public function activation(PanelInstance $panel): PanelAdapterPackActivation {
		return $this->activations[$panel] ?? throw new \OutOfBoundsException("Adapter pack '{$this->packId}' is not active on panel '{$panel->name()}'.");
	}

	public function runtimeFingerprint(): string {
		$bindings=[];
		foreach($this->bindings as $id=>$binding){$bindings[$id]=$binding->runtimeFingerprint();}
		return hash('sha256',json_encode([
			'id'=>$this->packId,
			'version'=>$this->packVersion,
			'label'=>$this->label(),
			'description'=>$this->description(),
			'publisher'=>$this->publisher(),
			'required_plugins'=>$this->requiredPlugins,
			'bindings'=>$bindings,
		],JSON_THROW_ON_ERROR));
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_adapter_pack',
			'schema_version'=>1,
			'id'=>$this->packId,
			'version'=>$this->packVersion,
			'label'=>$this->label(),
			'description'=>$this->description(),
			'publisher'=>$this->publisher(),
			'required_plugins'=>$this->requiredPlugins,
			'binding_count'=>count($this->bindings),
			'bindings'=>array_map(static fn(PanelAdapterPackBinding $binding):array=>$binding->jsonSerialize(),$this->bindings),
			'runtime_fingerprint'=>$this->runtimeFingerprint(),
			'factories_serialized'=>false,
			'activation_objects_serialized'=>false,
		];
	}

	private function installBinding(PanelInstance $panel, PanelAdapterPackBinding $binding, object $adapter, bool $replace): void {
		$name=$binding->targetName();
		switch($binding->targetType()){
			case 'platform':
				$panel->platform()->register($name,$adapter,$replace);
				return;
			case 'search':
				if(!$adapter instanceof PanelSearchProvider || $adapter->name()!==$name){
					throw new \UnexpectedValueException("Adapter '{$binding->id()}' must return search provider '{$name}'.");
				}
				$panel->registerSearchProvider($adapter);
				return;
			case 'plugin':
				if(!$adapter instanceof PanelPlugin || Resource::normalizeName($adapter->id())!==$name){
					throw new \UnexpectedValueException("Adapter '{$binding->id()}' must return plugin '{$name}'.");
				}
				$panel->plugin($adapter);
				return;
			case 'data':
				/** @var PanelDataSource $adapter The binding contract is validated before installation. */
				$panel->registerDataSource($name,$adapter,$replace,[
					'adapter_pack'=>$this->packId,
					'binding'=>$binding->id(),
				]);
				return;
		}
	}

	/** @param mixed $values @return list<string> */
	private static function names(mixed $values): array {
		if(!is_array($values) || !array_is_list($values)){
			throw new \InvalidArgumentException('Adapter pack required_plugins must be a list.');
		}
		$names=[];
		foreach($values as $value){
			if(!is_string($value)){throw new \InvalidArgumentException('Adapter pack required plugin ids must be strings.');}
			$name=Resource::normalizeName($value);
			if($name==='' || $name!==trim($value)){throw new \InvalidArgumentException('Adapter pack required plugin ids must be canonical names.');}
			$names[$name]=true;
		}
		$names=array_keys($names);sort($names,SORT_STRING);
		return $names;
	}

	private static function text(string $value): string {
		$value=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',$value) ?? '');
		return function_exists('mb_substr')?mb_substr($value,0,500,'UTF-8'):substr($value,0,500);
	}
}
