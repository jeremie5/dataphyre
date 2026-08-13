<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, transport-neutral package/runtime compatibility matrix plan. */
final class PanelPackageCompatibilityPlan implements \JsonSerializable {
	public const FORMAT='dataphyre.panel.package.compatibility-plan.v1';
	private const CEILINGS=['max_packages'=>256,'max_runtimes'=>128,'max_cases'=>4096,'max_baseline_cases'=>4096];
	private const MAX_NESTING_DEPTH=64;
	private const MAX_CANONICAL_ITEMS=262144;

	/** @var array<int,PanelPackageCompatibilityCase> */
	private readonly array $cases;
	private readonly array $packageOrder;
	private readonly array $runtimeIds;
	private readonly array $policy;
	private readonly array $limits;
	private readonly array $meta;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $definition Package definitions, runtimes or axes, policy, limits, and metadata. */
	public function __construct(array $definition) {
		$allowed=['format','packages','runtimes','runtime_axes','policy','limits','meta'];
		if(array_diff(array_keys($definition),$allowed)!==[]){throw new \InvalidArgumentException('Compatibility plan contains unknown fields.');}
		if(isset($definition['format']) && $definition['format']!==self::FORMAT){throw new \InvalidArgumentException('Compatibility plan format is unsupported.');}
		$this->limits=$this->normalizeLimits($definition['limits'] ?? []);
		$this->policy=$this->normalizePolicy($definition['policy'] ?? []);
		$this->meta=$this->normalizeMeta($definition['meta'] ?? []);
		$packages=$this->normalizePackages($definition['packages'] ?? null);
		if(count($packages)>$this->limits['max_packages']){throw new \LengthException('Compatibility plan exceeds its package limit.');}
		$runtimes=$this->normalizeRuntimes($definition['runtimes'] ?? [],$definition['runtime_axes'] ?? []);
		if(count($runtimes)>$this->limits['max_runtimes']){throw new \LengthException('Compatibility plan exceeds its runtime limit.');}
		if(count($packages)*count($runtimes)>$this->limits['max_cases']){throw new \LengthException('Compatibility plan exceeds its expanded case limit.');}
		$available=[];$graphs=[];
		foreach($packages as $id=>$package){$available[$id]=(string)$package['manifest']['version'];$graphs[$id]=$this->dependencyNames($package['dependencies'] ?? []);}
		ksort($available,SORT_STRING);
		$this->packageOrder=$this->topologicalOrder($graphs);
		$this->runtimeIds=array_keys($runtimes);
		$casePolicy=array_intersect_key($this->policy,array_flip(['require_lock','require_distribution','require_authenticated_distribution','require_publisher','require_signature','require_trust','require_install_ready']));
		$cases=[];
		foreach($runtimes as $runtime){
			foreach($this->packageOrder as $packageId){
				$package=$packages[$packageId];
				$localAvailable=$available;
				if(isset($package['available_packages'])){
					if(!is_array($package['available_packages']) || ($package['available_packages']!==[] && array_is_list($package['available_packages']))){throw new \InvalidArgumentException('Package available_packages must be a map.');}
					$localAvailable=array_replace($localAvailable,$package['available_packages']);
				}
				$cases[]=PanelPackageCompatibilityCase::make([
					'manifest'=>$package['manifest'],'runtime'=>$runtime,'dependencies'=>$package['dependencies'] ?? [],
					'required_features'=>$package['required_features'] ?? [],'available_packages'=>$localAvailable,
					'lock'=>$package['lock'] ?? null,'distribution'=>$package['distribution'] ?? null,
					'verification'=>$package['verification'] ?? null,'trust'=>$package['trust'] ?? null,
					'install_plan'=>$package['install_plan'] ?? null,'policy'=>$casePolicy,'meta'=>$package['meta'] ?? [],
				]);
			}
		}
		$this->cases=$cases;
		$this->fingerprint=hash('sha256',self::canonicalJson([
			'format'=>self::FORMAT,'package_order'=>$this->packageOrder,'runtime_ids'=>$this->runtimeIds,
			'policy'=>$this->policy,'limits'=>$this->limits,'cases'=>array_map(static fn(PanelPackageCompatibilityCase $case): string=>$case->fingerprint(),$this->cases),
		]));
	}

	public static function make(array $definition): self { return new self($definition); }
	/** @return array<int,PanelPackageCompatibilityCase> */
	public function cases(): array { return $this->cases; }
	/** @return array<int,string> */
	public function packageOrder(): array { return $this->packageOrder; }
	/** @return array<int,string> */
	public function runtimeIds(): array { return $this->runtimeIds; }
	/** @return array<string,bool> */
	public function policy(): array { return $this->policy; }
	/** @return array<string,int> */
	public function limits(): array { return $this->limits; }
	public function fingerprint(): string { return $this->fingerprint; }
	public function report(array $baseline=[]): PanelPackageCompatibilityReport {
		return PanelPackageCompatibilityReport::fromPlan($this,$baseline);
	}

	/** @return array<string,mixed> Safe, deterministic preview with no package artifacts or local paths. */
	public function toArray(): array {
		return [
			'type'=>'panel_package_compatibility_plan','format'=>self::FORMAT,'fingerprint'=>$this->fingerprint,
			'package_count'=>count($this->packageOrder),'runtime_count'=>count($this->runtimeIds),'case_count'=>count($this->cases),
			'package_order'=>$this->packageOrder,'runtime_ids'=>$this->runtimeIds,'case_fingerprints'=>array_map(static fn(PanelPackageCompatibilityCase $case): array=>['case_key'=>$case->key(),'fingerprint'=>$case->fingerprint()],$this->cases),
			'policy'=>$this->policy,'limits'=>$this->limits,'meta'=>$this->meta,
		];
	}
	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return array<string,int> */
	private function normalizeLimits(mixed $limits): array {
		if(!is_array($limits) || ($limits!==[] && array_is_list($limits)) || array_diff(array_keys($limits),array_keys(self::CEILINGS))!==[]){throw new \InvalidArgumentException('Compatibility plan limits are malformed.');}
		$normalized=self::CEILINGS;
		foreach($limits as $name=>$value){if(!is_int($value) || $value<1 || $value>self::CEILINGS[$name]){throw new \InvalidArgumentException('Compatibility plan limit exceeds its hard ceiling.');}$normalized[$name]=$value;}
		return $normalized;
	}

	/** @return array<string,bool> */
	private function normalizePolicy(mixed $policy): array {
		$defaults=[
			'require_lock'=>false,'require_distribution'=>false,'require_authenticated_distribution'=>false,
			'require_publisher'=>false,'require_signature'=>false,'require_trust'=>false,'require_install_ready'=>false,
			'fail_on_blocked'=>true,'fail_on_regression'=>true,'fail_on_removed'=>false,
		];
		if(!is_array($policy) || ($policy!==[] && array_is_list($policy)) || array_diff(array_keys($policy),array_keys($defaults))!==[]){throw new \InvalidArgumentException('Compatibility plan policy is malformed.');}
		foreach($policy as $name=>$value){if(!is_bool($value)){throw new \InvalidArgumentException('Compatibility plan policy flags must be boolean.');}$defaults[$name]=$value;}
		return $defaults;
	}

	/** @return array<string,mixed> */
	private function normalizeMeta(mixed $meta): array {
		if(!is_array($meta) || ($meta!==[] && array_is_list($meta))){throw new \InvalidArgumentException('Compatibility plan metadata must be an object.');}
		try{$json=self::canonicalJson($meta);}catch(\Throwable $exception){throw new \InvalidArgumentException('Compatibility plan metadata is not serializable.',0,$exception);}
		if(strlen($json)>65536){throw new \LengthException('Compatibility plan metadata exceeds its byte budget.');}
		return $this->sanitize($meta);
	}

	/** @return array<string,array<string,mixed>> */
	private function normalizePackages(mixed $packages): array {
		if(!is_array($packages) || !array_is_list($packages) || $packages===[]){throw new \InvalidArgumentException('Compatibility plan packages must be a non-empty list.');}
		if(count($packages)>self::CEILINGS['max_packages']){throw new \LengthException('Compatibility plan package input exceeds its hard ceiling.');}
		$normalized=[];
		foreach($packages as $package){
			if(!is_array($package) || array_is_list($package)){throw new \InvalidArgumentException('Compatibility plan package definition must be an object.');}
			$allowed=['manifest','dependencies','required_features','available_packages','lock','distribution','verification','trust','install_plan','meta'];
			if(array_diff(array_keys($package),$allowed)!==[]){throw new \InvalidArgumentException('Compatibility plan package contains unknown fields.');}
			$manifest=$package['manifest'] ?? null;
			if($manifest instanceof PanelPackageManifest){$manifest=$manifest->toArray();}
			if(!is_array($manifest) || array_is_list($manifest)){throw new \InvalidArgumentException('Compatibility plan package manifest must be an object.');}
			$id=$manifest['id'] ?? null;$version=$manifest['version'] ?? null;
			if(!is_string($id) || strlen($id)>128 || Resource::normalizeName($id)!==$id || !is_string($version) || strlen($version)>128 || !PanelPackageManifest::validVersion($version) || isset($normalized[$id])){throw new \InvalidArgumentException('Compatibility plan package identity is malformed or duplicated.');}
			$package['manifest']=$manifest;$normalized[$id]=$package;
		}
		ksort($normalized,SORT_STRING);return $normalized;
	}

	/** @return array<string,array<string,mixed>> */
	private function normalizeRuntimes(mixed $runtimes, mixed $axes): array {
		if($runtimes!==[] && $axes!==[]){throw new \InvalidArgumentException('Use either explicit runtimes or runtime_axes, not both.');}
		if($runtimes===[] && $axes===[]){
			$default=PanelCompatibilityMatrix::defaultRuntime();
			$runtimes=[['id'=>'default','php'=>$this->fullVersion((string)$default['php']),'panel'=>$this->fullVersion((string)$default['panel']),'reactor'=>$this->fullVersion((string)$default['reactor']),'modules'=>array_map(fn(mixed $version): string=>$this->fullVersion((string)$version),(array)$default['modules']),'themes'=>(array)$default['themes'],'features'=>[]]];
		}
		if($axes!==[]){return $this->expandAxes($axes);}
		if(!is_array($runtimes) || $runtimes===[] || count($runtimes)>self::CEILINGS['max_runtimes']){throw new \InvalidArgumentException('Compatibility runtimes must be a bounded non-empty collection.');}
		$normalized=[];
		foreach($runtimes as $key=>$runtime){
			if(!is_array($runtime)){throw new \InvalidArgumentException('Compatibility runtime must be an object.');}
			if(!isset($runtime['id']) && is_string($key)){$runtime['id']=$key;}
			$runtime=PanelPackageCompatibilityCase::normalizeRuntime($runtime);
			if(isset($normalized[$runtime['id']])){throw new \InvalidArgumentException('Compatibility runtime id is duplicated.');}
			$normalized[$runtime['id']]=$runtime;
		}
		ksort($normalized,SORT_STRING);return $normalized;
	}

	/** @return array<string,array<string,mixed>> */
	private function expandAxes(mixed $axes): array {
		if(!is_array($axes) || array_is_list($axes) || array_diff(array_keys($axes),['php','panel','reactor','modules','themes','features'])!==[]){throw new \InvalidArgumentException('Runtime axes are malformed.');}
		$defaults=PanelCompatibilityMatrix::defaultRuntime();
		$versions=[];
		foreach(['php','panel','reactor'] as $name){$versions[$name]=$this->versionAxis($axes[$name] ?? [$this->fullVersion((string)$defaults[$name])],$name);}
		$modules=$this->mapProfiles($axes['modules'] ?? ['default'=>array_map(fn(mixed $version): string=>$this->fullVersion((string)$version),(array)$defaults['modules'])],'modules');
		$themes=$this->listProfiles($axes['themes'] ?? ['default'=>(array)$defaults['themes']],'themes');
		$features=$this->listProfiles($axes['features'] ?? ['default'=>[]],'features');
		$product=count($versions['php'])*count($versions['panel'])*count($versions['reactor'])*count($modules)*count($themes)*count($features);
		if($product<1 || $product>self::CEILINGS['max_runtimes']){throw new \LengthException('Runtime axis product exceeds its hard ceiling.');}
		$profiles=[];
		foreach($versions['php'] as $php){foreach($versions['panel'] as $panel){foreach($versions['reactor'] as $reactor){foreach($modules as $moduleName=>$moduleProfile){foreach($themes as $themeName=>$themeProfile){foreach($features as $featureName=>$featureProfile){
			$profile=['php'=>$php,'panel'=>$panel,'reactor'=>$reactor,'modules'=>$moduleProfile,'themes'=>$themeProfile,'features'=>$featureProfile];
			$id='runtime_'.substr(hash('sha256',self::canonicalJson($profile)),0,20);
			$profile['id']=$id;$profile['axes']=['php'=>$php,'panel'=>$panel,'reactor'=>$reactor,'modules'=>$moduleName,'themes'=>$themeName,'features'=>$featureName];
			$profiles[$id]=PanelPackageCompatibilityCase::normalizeRuntime($profile);
		}}}}}}
		ksort($profiles,SORT_STRING);return $profiles;
	}

	/** @return array<int,string> */
	private function versionAxis(mixed $values,string $name): array {
		if(!is_array($values) || !array_is_list($values) || $values===[] || count($values)>16){throw new \InvalidArgumentException('Runtime '.$name.' axis must be a bounded list.');}
		$normalized=[];foreach($values as $version){if(!is_string($version) || !PanelPackageManifest::validVersion($version) || in_array($version,$normalized,true)){throw new \InvalidArgumentException('Runtime '.$name.' axis version is malformed.');}$normalized[]=$version;}usort($normalized,[PanelPackageManifest::class,'compareVersions']);return $normalized;
	}

	/** @return array<string,array<string,string>> */
	private function mapProfiles(mixed $profiles,string $name): array {
		if(!is_array($profiles) || array_is_list($profiles) || $profiles===[] || count($profiles)>16){throw new \InvalidArgumentException('Runtime '.$name.' profiles must be a bounded map.');}
		$normalized=[];
		foreach($profiles as $profileName=>$profile){$rawName=is_string($profileName)?$profileName:'';$profileName=Resource::normalizeName($rawName);if($profileName==='' || $profileName!==$rawName || !is_array($profile) || ($profile!==[] && array_is_list($profile))){throw new \InvalidArgumentException('Runtime '.$name.' profile is malformed.');}$values=[];foreach($profile as $id=>$version){$rawId=is_string($id)?$id:'';$id=Resource::normalizeName($rawId);if($id==='' || $id!==$rawId || !is_string($version) || !PanelPackageManifest::validVersion($version)){throw new \InvalidArgumentException('Runtime module profile entry is malformed.');}$values[$id]=$version;}ksort($values,SORT_STRING);if(in_array($values,$normalized,true)){throw new \InvalidArgumentException('Runtime '.$name.' profiles contain duplicate values.');}$normalized[$profileName]=$values;}
		ksort($normalized,SORT_STRING);return $normalized;
	}

	/** @return array<string,array<int,string>> */
	private function listProfiles(mixed $profiles,string $name): array {
		if(!is_array($profiles) || array_is_list($profiles) || $profiles===[] || count($profiles)>16){throw new \InvalidArgumentException('Runtime '.$name.' profiles must be a bounded map.');}
		$normalized=[];
		foreach($profiles as $profileName=>$profile){$rawName=is_string($profileName)?$profileName:'';$profileName=Resource::normalizeName($rawName);if($profileName==='' || $profileName!==$rawName || !is_array($profile) || !array_is_list($profile) || count($profile)>256){throw new \InvalidArgumentException('Runtime '.$name.' profile is malformed.');}$items=[];foreach($profile as $item){$id=is_string($item)?Resource::normalizeName($item):'';if($id==='' || $id!==$item || in_array($id,$items,true)){throw new \InvalidArgumentException('Runtime '.$name.' profile entry is malformed.');}$items[]=$id;}sort($items,SORT_STRING);if(in_array($items,$normalized,true)){throw new \InvalidArgumentException('Runtime '.$name.' profiles contain duplicate values.');}$normalized[$profileName]=$items;}
		ksort($normalized,SORT_STRING);return $normalized;
	}

	/** @return array<int,string> */
	private function dependencyNames(mixed $dependencies): array {
		if(!is_array($dependencies) || ($dependencies!==[] && array_is_list($dependencies)) || count($dependencies)>256){throw new \InvalidArgumentException('Compatibility package dependencies must be a bounded map.');}
		$names=[];foreach($dependencies as $id=>$constraint){$raw=(string)$id;$id=Resource::normalizeName($raw);if($id==='' || $id!==$raw || !is_string($constraint)) { throw new \InvalidArgumentException('Compatibility package dependency is malformed.'); }$names[]=$id;}sort($names,SORT_STRING);return $names;
	}

	/** @return array<int,string> */
	private function topologicalOrder(array $graph): array {
		$visiting=[];$visited=[];$order=[];
		$visit=function(string $id) use (&$visit,&$visiting,&$visited,&$order,$graph): void {
			if(isset($visiting[$id])){throw new \InvalidArgumentException('Compatibility package dependency graph contains a cycle.');}
			if(isset($visited[$id])){return;}$visiting[$id]=true;$dependencies=array_values(array_filter((array)($graph[$id] ?? []),static fn(string $dependency): bool=>array_key_exists($dependency,$graph)));sort($dependencies,SORT_STRING);foreach($dependencies as $dependency){$visit($dependency);}unset($visiting[$id]);$visited[$id]=true;$order[]=$id;
		};
		$ids=array_keys($graph);sort($ids,SORT_STRING);foreach($ids as $id){$visit($id);}return $order;
	}

	private function fullVersion(string $version): string {
		$version=trim($version);if(PanelPackageManifest::validVersion($version)){return $version;}if(preg_match('/^(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?$/D',$version,$match)!==1){throw new \InvalidArgumentException('Default runtime version is not semantic.');}return $match[1].'.'.($match[2] ?? '0').'.0';
	}

	private static function canonicalJson(mixed $value): string { $items=0;return json_encode(self::canonicalize($value,0,$items),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR); }
	private static function canonicalize(mixed $value,int $depth,int &$items): mixed {if($depth>self::MAX_NESTING_DEPTH || ++$items>self::MAX_CANONICAL_ITEMS){throw new \InvalidArgumentException('Compatibility plan value exceeds canonicalization limits.');}if(is_array($value)){if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as $key=>$item){$value[$key]=self::canonicalize($item,$depth+1,$items);}return $value;}if($value===null||is_bool($value)||is_int($value)||is_string($value)){return $value;}if(is_float($value)&&is_finite($value)){return $value;}throw new \InvalidArgumentException('Unsupported compatibility plan value.');}
	private function sanitize(mixed $value,string $key=''): mixed {if($key!==''&&($this->sensitiveKey($key)||$this->locationKey($key))){return '[REDACTED]';}if(!is_array($value)){if(is_object($value)){return '[OBJECT]';}return is_string($value)&&$this->absolutePath($value)?'[REDACTED]':$value;}$safe=[];foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item,is_string($itemKey)?$itemKey:'');}return $safe;}
	private function sensitiveKey(string $key): bool {$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/','_',$key)??$key;return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i',$key)===1;}
	private function locationKey(string $key): bool {$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/','_',$key)??$key;return preg_match('/(?:^|[_\-.])(?:path|root|directory|filename|filepath|locator)(?:$|[_\-.])/i',$key)===1;}
	private function absolutePath(string $value): bool {$value=trim($value);return str_starts_with($value,'/')||str_starts_with($value,'\\\\')||preg_match('~^[A-Za-z]:[\\\\/]~D',$value)===1;}
}
