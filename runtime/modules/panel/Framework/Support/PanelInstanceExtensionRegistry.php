<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Instance-owned state for Panel extension points.
 *
 * A registry belongs to exactly one PanelInstance. Component descriptors,
 * builder macros/configurators, and theme contributions are layered by their
 * contributor so a plugin can be unloaded without changing another surface.
 * PanelComponentRegistry and PanelExtensible remain source-compatible facades;
 * inside a Panel context they resolve this object instead of process globals.
 */
final class PanelInstanceExtensionRegistry {

	/** @var array<string,array<string,mixed>> */ public array $schemaKinds=[];
	/** @var array<string,array<string,mixed>> */ public array $fieldTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $columnTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $actionTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $filterTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $relationTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $widgetTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $pageTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $resourceTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $summaryTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $viewTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $navigationTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $exportTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $importTypes=[];
	/** @var array<string,array<string,mixed>> */ public array $bulkOperationTypes=[];

	private static ?self $legacy=null;
	/** @var array<int,\WeakReference> Live surface registries for the unscoped migration adapter. */
	private static array $instances=[];
	private PanelThemeLibrary $themeLibrary;
	/** @var array<string,array<string,list<array{owner:string,value:array<string,mixed>,revision:int,meta:array<string,mixed>}>>> */
	private array $componentLayers=[];
	/** @var array<string,array<string,list<array{owner:string,value:callable,revision:int,meta:array<string,mixed>}>>> */
	private array $macroLayers=[];
	/** @var array<string,list<array{owner:string,value:callable,important:bool,revision:int,meta:array<string,mixed>}>> */
	private array $configuratorLayers=[];
	/** @var array{presets:array<string,list<array{owner:string,value:PanelThemePreset,revision:int,meta:array<string,mixed>}>>,themes:array<string,list<array{owner:string,value:PanelTheme,revision:int,meta:array<string,mixed>}>>} */
	private array $themeLayers=['presets'=>[], 'themes'=>[]];
	/** @var list<array{id:string,permissions:list<string>,meta:array<string,mixed>}> */
	private array $contributors=[];
	private int $revision=0;
	private string $conflictPolicy;
	private bool $legacyAdapter;

	public function __construct(string $conflictPolicy='reject', bool $legacyAdapter=false) {
		$this->conflictPolicy=self::normalizeConflictPolicy($conflictPolicy);
		$this->legacyAdapter=$legacyAdapter;
		$this->themeLibrary=PanelThemeLibrary::make();
		$this->seedBuiltinThemes();
		if(!$legacyAdapter){
			self::$instances[spl_object_id($this)]=\WeakReference::create($this);
		}
	}

	/** Returns the explicit process-local migration adapter used outside a Panel context. */
	public static function legacy(): self {
		return self::$legacy ??= new self('replace', true);
	}

	/** Clears the process-local migration adapter without touching any PanelInstance. */
	public static function flushLegacy(): void {
		self::$legacy=null;
	}

	public function isLegacyAdapter(): bool { return $this->legacyAdapter; }
	public function revision(): int { return $this->revision; }
	public function conflictPolicy(): string { return $this->conflictPolicy; }
	public function themeLibrary(): PanelThemeLibrary { return $this->themeLibrary; }

	public function conflictPolicyUsing(string $policy): self {
		$this->conflictPolicy=self::normalizeConflictPolicy($policy);
		return $this;
	}

	/**
	 * Runs work as one authenticated extension contributor.
	 *
	 * @template TResult
	 * @param list<string> $permissions
	 * @param array<string,mixed> $meta
	 * @param callable():TResult $callback
	 * @return TResult
	 */
	public function runAs(string $id, array $permissions, array $meta, callable $callback): mixed {
		$id=Resource::normalizeName($id);
		if($id==='' || in_array($id, ['core','application','legacy'], true)){
			throw new \InvalidArgumentException('Panel extension contributors require a stable id.');
		}
		$this->contributors[]=['id'=>$id, 'permissions'=>self::normalizePermissions($permissions), 'meta'=>$meta];
		try{
			$result=$callback();
		}
		catch(\Throwable $exception){
			array_pop($this->contributors);
			throw $exception;
		}
		array_pop($this->contributors);
		return $result;
	}

	public function contributorId(): string {
		$current=$this->contributors[array_key_last($this->contributors)] ?? null;
		return is_array($current) ? (string)$current['id'] : ($this->legacyAdapter ? 'legacy' : 'application');
	}

	/** @return list<string> */
	public function contributorPermissions(): array {
		$current=$this->contributors[array_key_last($this->contributors)] ?? null;
		return is_array($current) ? $current['permissions'] : ['*'];
	}

	/** @return array<string,mixed> */
	public function contributorMeta(): array {
		$current=$this->contributors[array_key_last($this->contributors)] ?? null;
		return is_array($current) ? $current['meta'] : [];
	}

	public function assertPermission(string $permission): void {
		$permission=self::normalizePermission($permission);
		if($permission==='' || $this->permissionMatches($permission, $this->contributorPermissions())){
			return;
		}
		throw new \LogicException('Panel extension contributor "'.$this->contributorId().'" is not permitted to register "'.$permission.'".');
	}

	/**
	 * Replaces all component maps with a trusted core baseline.
	 *
	 * @param array<string,array<string,array<string,mixed>>> $maps
	 */
	public function resetComponents(array $maps): void {
		$this->componentLayers=[];
		foreach(self::componentProperties() as $category=>$property){
			$this->{$property}=is_array($maps[$category] ?? null) ? $maps[$category] : [];
			foreach($this->{$property} as $name=>$definition){
				$this->componentLayers[$category][(string)$name]=[[
					'owner'=>'core',
					'value'=>$definition,
					'revision'=>0,
					'meta'=>['phase'=>'bootstrap'],
				]];
			}
		}
		$this->revision++;
	}

	/** Registers a descriptor after permission and deterministic conflict checks. */
	public function contributeComponent(string $category, string $name, array $definition): bool {
		$category=Resource::normalizeName($category);
		$name=Resource::normalizeName($name);
		$property=self::componentProperties()[$category] ?? null;
		if($property===null || $name===''){
			return false;
		}
		$this->assertPermission('component.'.$category.'.register');
		$owner=$this->contributorId();
		$layers=$this->componentLayers[$category][$name] ?? [];
		$top=$layers[array_key_last($layers)] ?? null;
		if(is_array($top) && $top['owner']!==$owner){
			if($this->conflictPolicy==='reject'){
				throw new \LogicException('Panel extension conflict for '.$category.' "'.$name.'" between "'.$top['owner'].'" and "'.$owner.'".');
			}
			if($this->conflictPolicy==='keep_first'){
				return false;
			}
		}
		$this->revision++;
		$record=['owner'=>$owner, 'value'=>$definition, 'revision'=>$this->revision, 'meta'=>$this->contributorMeta()];
		if(is_array($top) && $top['owner']===$owner){
			$layers[array_key_last($layers)]=$record;
		}
		else{
			$layers[]=$record;
		}
		$this->componentLayers[$category][$name]=$layers;
		$this->{$property}[$name]=$definition;
		return true;
	}

	/**
	 * @template TBuilder of object
	 * @param class-string<TBuilder> $class
	 * @param callable(TBuilder,mixed...):mixed $macro
	 */
	public function registerMacro(string $class, string $name, callable $macro): void {
		$name=Resource::normalizeName($name);
		if($name===''){
			return;
		}
		$this->assertPermission('extensible.macro.register');
		$owner=$this->contributorId();
		$layers=$this->macroLayers[$class][$name] ?? [];
		$top=$layers[array_key_last($layers)] ?? null;
		if(is_array($top) && $top['owner']!==$owner){
			if($this->conflictPolicy==='reject'){
				throw new \LogicException('Panel macro conflict for '.$class.'::'.$name.' between "'.$top['owner'].'" and "'.$owner.'".');
			}
			if($this->conflictPolicy==='keep_first'){
				return;
			}
		}
		$this->revision++;
		$record=['owner'=>$owner, 'value'=>$macro, 'revision'=>$this->revision, 'meta'=>$this->contributorMeta()];
		if(is_array($top) && $top['owner']===$owner){
			$layers[array_key_last($layers)]=$record;
		}
		else{
			$layers[]=$record;
		}
		$this->macroLayers[$class][$name]=$layers;
	}

	/** @param class-string<object>|string $class */
	public function hasMacro(string $class, string $name): bool {
		return $this->macro($class, $name)!==null;
	}

	/**
	 * @template TBuilder of object
	 * @param class-string<TBuilder> $class
	 * @return (callable(TBuilder,mixed...):mixed)|null
	 */
	public function macro(string $class, string $name): mixed {
		$layers=$this->macroLayers[$class][Resource::normalizeName($name)] ?? [];
		$top=$layers[array_key_last($layers)] ?? null;
		return is_array($top) ? $top['value'] : null;
	}

	/**
	 * Resolves a macro outside a Panel context only when exactly one live surface
	 * owns it. This compatibility bridge never guesses between multiple panels.
	 *
	 * @return array{0:self,1:callable}|null
	 */
	public static function uniqueUnscopedMacro(string $class, string $name): ?array {
		$matches=[];
		foreach(self::liveInstances() as $registry){
			$macro=$registry->macro($class, $name);
			if(is_callable($macro)){ $matches[]=[ $registry, $macro ]; }
		}
		if(count($matches)>1){
			throw new \LogicException('Panel macro '.$class.'::'.Resource::normalizeName($name).' is ambiguous outside a PanelInstance context.');
		}
		return $matches[0] ?? null;
	}

	/** @param class-string<object>|string $class */
	public function flushMacros(string $class): void {
		unset($this->macroLayers[$class]);
		$this->revision++;
	}

	/**
	 * @template TBuilder of object
	 * @param class-string<TBuilder> $class
	 * @param callable(TBuilder):mixed $callback
	 */
	public function registerConfigurator(string $class, callable $callback, bool $important=false): void {
		$this->assertPermission('extensible.configurator.register');
		$this->revision++;
		$this->configuratorLayers[$class][]=[
			'owner'=>$this->contributorId(),
			'value'=>$callback,
			'important'=>$important,
			'revision'=>$this->revision,
			'meta'=>$this->contributorMeta(),
		];
	}

	/**
	 * @template TBuilder of object
	 * @param class-string<TBuilder> $class
	 * @return list<callable(TBuilder):mixed>
	 */
	public function configurators(string $class): array {
		$layers=$this->configuratorLayers[$class] ?? [];
		usort($layers, static fn(array $left, array $right): int => [(int)$left['important'], (int)$left['revision']] <=> [(int)$right['important'], (int)$right['revision']]);
		return array_values(array_map(static fn(array $record): callable=>$record['value'], $layers));
	}

	/**
	 * @template TBuilder of object
	 * @param class-string<TBuilder> $class
	 * @return array{0:self,1:list<callable(TBuilder):mixed>}|null
	 */
	public static function uniqueUnscopedConfigurators(string $class): ?array {
		$matches=[];
		foreach(self::liveInstances() as $registry){
			$configurators=$registry->configurators($class);
			if($configurators!==[]){ $matches[]=[$registry, $configurators]; }
		}
		if(count($matches)>1){
			throw new \LogicException('Panel configurators for '.$class.' are ambiguous outside a PanelInstance context.');
		}
		return $matches[0] ?? null;
	}

	/** @param class-string<object>|string $class */
	public function flushConfigurators(string $class): void {
		unset($this->configuratorLayers[$class]);
		$this->revision++;
	}

	public function registerThemePreset(PanelThemePreset|array $preset): PanelThemePreset {
		$this->assertPermission('theme.preset.register');
		$preset=$preset instanceof PanelThemePreset ? $preset : PanelThemePreset::fromArray($preset);
		$name=Resource::normalizeName((string)($preset->toArray()['name'] ?? 'preset')) ?: 'preset';
		$this->contributeTheme('presets', $name, $preset);
		return $preset;
	}

	public function registerTheme(PanelTheme|array $theme): PanelTheme {
		$this->assertPermission('theme.theme.register');
		$theme=$theme instanceof PanelTheme ? $theme : PanelTheme::fromArray($theme);
		$name=Resource::normalizeName($theme->name()) ?: 'theme';
		$this->contributeTheme('themes', $name, $theme);
		return $theme;
	}

	/**
	 * Loads theme payloads through a temporary parser, then records every
	 * resulting preset/theme as an owned contribution. Loading through the
	 * active library directly would make plugin unload unable to remove it.
	 */
	public function loadThemes(string|array $paths): PanelThemeLibrary {
		$this->assertPermission('theme.load');
		$loaded=PanelThemeLibrary::load($paths);
		foreach($loaded->all() as $preset){ $this->registerThemePreset($preset); }
		foreach($loaded->allThemes() as $theme){ $this->registerTheme($theme); }
		return $this->themeLibrary;
	}

	/** Removes every contribution owned by an id and reveals the previous layer. */
	public function unregisterContributor(string $id): self {
		$id=Resource::normalizeName($id);
		if($id==='' || in_array($id, ['core', 'application', 'legacy'], true)){
			throw new \InvalidArgumentException('Only plugin-owned Panel extension contributions can be unregistered.');
		}
		foreach($this->componentLayers as $category=>$entries){
			foreach(array_keys($entries) as $name){
				$this->componentLayers[$category][$name]=array_values(array_filter($this->componentLayers[$category][$name], static fn(array $record): bool=>$record['owner']!==$id));
				$this->rebuildComponent($category, $name);
			}
		}
		foreach($this->macroLayers as $class=>$entries){
			foreach(array_keys($entries) as $name){
				$this->macroLayers[$class][$name]=array_values(array_filter($this->macroLayers[$class][$name], static fn(array $record): bool=>$record['owner']!==$id));
				if($this->macroLayers[$class][$name]===[]){ unset($this->macroLayers[$class][$name]); }
			}
			if($this->macroLayers[$class]===[]){ unset($this->macroLayers[$class]); }
		}
		foreach($this->configuratorLayers as $class=>$records){
			$this->configuratorLayers[$class]=array_values(array_filter($records, static fn(array $record): bool=>$record['owner']!==$id));
			if($this->configuratorLayers[$class]===[]){ unset($this->configuratorLayers[$class]); }
		}
		foreach(['presets','themes'] as $kind){
			foreach(array_keys($this->themeLayers[$kind]) as $name){
				$this->themeLayers[$kind][$name]=array_values(array_filter($this->themeLayers[$kind][$name], static fn(array $record): bool=>$record['owner']!==$id));
				if($this->themeLayers[$kind][$name]===[]){ unset($this->themeLayers[$kind][$name]); }
			}
		}
		$this->rebuildThemeLibrary();
		$this->revision++;
		return $this;
	}

	/** @return array<string,mixed> Internal rollback checkpoint. */
	public function checkpoint(): array {
		$maps=[];
		foreach(self::componentProperties() as $category=>$property){ $maps[$category]=$this->{$property}; }
		return [
			'maps'=>$maps,
			'component_layers'=>$this->componentLayers,
			'macro_layers'=>$this->macroLayers,
			'configurator_layers'=>$this->configuratorLayers,
			'theme_layers'=>$this->themeLayers,
			'revision'=>$this->revision,
			'conflict_policy'=>$this->conflictPolicy,
		];
	}

	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint): self {
		$required=['maps','component_layers','macro_layers','configurator_layers','theme_layers','revision','conflict_policy'];
		foreach($required as $key){
			if(!array_key_exists($key, $checkpoint)){
				throw new \InvalidArgumentException('Invalid Panel extension registry checkpoint.');
			}
		}
		if(!is_array($checkpoint['maps']) || !is_array($checkpoint['component_layers']) || !is_array($checkpoint['macro_layers']) || !is_array($checkpoint['configurator_layers']) || !is_array($checkpoint['theme_layers'])){
			throw new \InvalidArgumentException('Invalid Panel extension registry checkpoint.');
		}
		foreach(self::componentProperties() as $category=>$property){
			$this->{$property}=is_array($checkpoint['maps'][$category] ?? null) ? $checkpoint['maps'][$category] : [];
		}
		$this->componentLayers=$checkpoint['component_layers'];
		$this->macroLayers=$checkpoint['macro_layers'];
		$this->configuratorLayers=$checkpoint['configurator_layers'];
		$this->themeLayers=$checkpoint['theme_layers'];
		$this->revision=(int)$checkpoint['revision'];
		$this->conflictPolicy=self::normalizeConflictPolicy((string)$checkpoint['conflict_policy']);
		$this->rebuildThemeLibrary();
		return $this;
	}

	/** @return array<string,mixed> */
	public function diagnostics(): array {
		$owners=[];
		$components=[];
		foreach($this->componentLayers as $category=>$entries){
			$components[$category]=count($entries);
			foreach($entries as $records){ foreach($records as $record){ $owners[$record['owner']]=true; } }
		}
		foreach($this->macroLayers as $entries){ foreach($entries as $records){ foreach($records as $record){ $owners[$record['owner']]=true; } } }
		foreach($this->configuratorLayers as $records){ foreach($records as $record){ $owners[$record['owner']]=true; } }
		return [
			'type'=>'panel_extension_registry',
			'revision'=>$this->revision,
			'legacy_adapter'=>$this->legacyAdapter,
			'conflict_policy'=>$this->conflictPolicy,
			'contributors'=>array_values(array_keys($owners)),
			'components'=>$components,
			'macros'=>array_sum(array_map('count', $this->macroLayers)),
			'configurators'=>array_sum(array_map('count', $this->configuratorLayers)),
			'themes'=>count($this->themeLayers['themes']),
			'presets'=>count($this->themeLayers['presets']),
		];
	}

	/** @return list<array<string,mixed>> */
	public function provenance(?string $owner=null): array {
		$owner=$owner===null ? null : Resource::normalizeName($owner);
		$result=[];
		foreach($this->componentLayers as $category=>$entries){
			foreach($entries as $name=>$records){
				foreach($records as $record){
					if($owner===null || $record['owner']===$owner){ $result[]=['owner'=>$record['owner'],'kind'=>'component','category'=>$category,'name'=>$name,'revision'=>$record['revision'],'meta'=>$record['meta'] ?? []]; }
				}
			}
		}
		foreach($this->macroLayers as $class=>$entries){
			foreach($entries as $name=>$records){ foreach($records as $record){ if($owner===null || $record['owner']===$owner){ $result[]=['owner'=>$record['owner'],'kind'=>'macro','class'=>$class,'name'=>$name,'revision'=>$record['revision'],'meta'=>$record['meta'] ?? []]; } } }
		}
		foreach($this->configuratorLayers as $class=>$records){
			foreach($records as $record){ if($owner===null || $record['owner']===$owner){ $result[]=['owner'=>$record['owner'],'kind'=>'configurator','class'=>$class,'important'=>$record['important'],'revision'=>$record['revision'],'meta'=>$record['meta'] ?? []]; } }
		}
		foreach($this->themeLayers as $kind=>$entries){
			foreach($entries as $name=>$records){ foreach($records as $record){ if($owner===null || $record['owner']===$owner){ $result[]=['owner'=>$record['owner'],'kind'=>rtrim($kind,'s'),'name'=>$name,'revision'=>$record['revision'],'meta'=>$record['meta'] ?? []]; } } }
		}
		usort($result, static fn(array $left,array $right): int=>(int)$left['revision']<=>(int)$right['revision']);
		return $result;
	}

	private function contributeTheme(string $kind, string $name, PanelTheme|PanelThemePreset $value): void {
		$owner=$this->contributorId();
		$layers=$this->themeLayers[$kind][$name] ?? [];
		$top=$layers[array_key_last($layers)] ?? null;
		if(is_array($top) && $top['owner']!==$owner){
			if($this->conflictPolicy==='reject'){
				throw new \LogicException('Panel '.$kind.' conflict for "'.$name.'" between "'.$top['owner'].'" and "'.$owner.'".');
			}
			if($this->conflictPolicy==='keep_first'){
				return;
			}
		}
		$this->revision++;
		$record=['owner'=>$owner,'value'=>$value,'revision'=>$this->revision,'meta'=>$this->contributorMeta()];
		if(is_array($top) && $top['owner']===$owner){ $layers[array_key_last($layers)]=$record; } else { $layers[]=$record; }
		$this->themeLayers[$kind][$name]=$layers;
		if($kind==='presets'){ $this->themeLibrary->register($value); } else { $this->themeLibrary->registerTheme($value->toArray()); }
	}

	private function rebuildComponent(string $category, string $name): void {
		$property=self::componentProperties()[$category] ?? null;
		if($property===null){ return; }
		$layers=$this->componentLayers[$category][$name] ?? [];
		$top=$layers[array_key_last($layers)] ?? null;
		if(is_array($top)){ $this->{$property}[$name]=$top['value']; }
		else{ unset($this->{$property}[$name], $this->componentLayers[$category][$name]); }
	}

	private function seedBuiltinThemes(): void {
		foreach([PanelThemePreset::flatMinima(), PanelThemePreset::brutalist(), PanelThemePreset::glass()] as $preset){
			$name=Resource::normalizeName((string)($preset->toArray()['name'] ?? 'preset')) ?: 'preset';
			$this->themeLayers['presets'][$name]=[['owner'=>'core','value'=>$preset,'revision'=>0,'meta'=>['phase'=>'bootstrap']]];
			$this->themeLibrary->register($preset);
		}
	}

	private function rebuildThemeLibrary(): void {
		$this->themeLibrary=PanelThemeLibrary::make();
		foreach($this->themeLayers['presets'] as $records){ $top=$records[array_key_last($records)] ?? null; if(is_array($top)){ $this->themeLibrary->register($top['value']); } }
		foreach($this->themeLayers['themes'] as $records){ $top=$records[array_key_last($records)] ?? null; if(is_array($top)){ $this->themeLibrary->registerTheme($top['value']->toArray()); } }
	}

	/** @return array<string,string> */
	private static function componentProperties(): array {
		return [
			'schema_kinds'=>'schemaKinds','field_types'=>'fieldTypes','column_types'=>'columnTypes','action_types'=>'actionTypes',
			'filter_types'=>'filterTypes','relation_types'=>'relationTypes','widget_types'=>'widgetTypes','page_types'=>'pageTypes',
			'resource_types'=>'resourceTypes','summary_types'=>'summaryTypes','view_types'=>'viewTypes','navigation_types'=>'navigationTypes',
			'export_types'=>'exportTypes','import_types'=>'importTypes','bulk_operation_types'=>'bulkOperationTypes',
		];
	}

	/** @param list<string> $permissions */
	private function permissionMatches(string $required, array $permissions): bool {
		foreach($permissions as $permission){
			if($permission==='*' || $permission===$required){ return true; }
			if(str_ends_with($permission, '.*') && str_starts_with($required, substr($permission, 0, -1))){ return true; }
		}
		return false;
	}

	/** @param array<int|string,mixed> $permissions @return list<string> */
	private static function normalizePermissions(array $permissions): array {
		$normalized=[];
		foreach($permissions as $key=>$value){
			$permission=is_string($key) && is_bool($value) ? ($value ? $key : '') : (is_scalar($value) ? (string)$value : '');
			$permission=self::normalizePermission($permission);
			if($permission!==''){ $normalized[$permission]=true; }
		}
		return array_values(array_keys($normalized));
	}

	private static function normalizePermission(string $permission): string {
		$permission=strtolower(trim(str_replace([':', '/', '\\'], '.', $permission)));
		$permission=preg_replace('/[^a-z0-9_.*-]+/', '_', $permission) ?? '';
		return trim(preg_replace('/\.{2,}/', '.', $permission) ?? '', '.');
	}

	private static function normalizeConflictPolicy(string $policy): string {
		$policy=Resource::normalizeName($policy);
		return in_array($policy, ['reject','replace','keep_first'], true) ? $policy : 'reject';
	}

	/** @return list<self> */
	private static function liveInstances(): array {
		$instances=[];
		foreach(self::$instances as $id=>$reference){
			$registry=$reference->get();
			if($registry instanceof self){ $instances[]=$registry; }
			else{ unset(self::$instances[$id]); }
		}
		return $instances;
	}
}
