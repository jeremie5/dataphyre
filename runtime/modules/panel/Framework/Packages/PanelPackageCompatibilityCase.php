<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable evaluation of one package against one deterministic runtime profile. */
final class PanelPackageCompatibilityCase implements \JsonSerializable {
	public const FORMAT='dataphyre.panel.package.compatibility-case.v1';
	private const MAX_EVIDENCE_BYTES=1048576;
	private const MAX_ITEMS=256;
	private const MAX_NESTING_DEPTH=64;
	private const MAX_CANONICAL_ITEMS=131072;

	private readonly string $key;
	private readonly string $packageId;
	private readonly string $version;
	private readonly string $runtimeId;
	private readonly array $dependencies;
	private readonly array $result;

	/** @param array<string,mixed> $definition Canonical manifest, runtime, evidence, and policy snapshot. */
	public function __construct(array $definition) {
		$allowed=['manifest','runtime','dependencies','required_features','available_packages','lock','distribution','verification','trust','install_plan','policy','meta'];
		if(array_diff(array_keys($definition), $allowed)!==[]){throw new \InvalidArgumentException('Compatibility case contains unknown fields.');}
		$manifest=$this->normalizeManifest($definition['manifest'] ?? null);
		$this->packageId=(string)$manifest['id'];
		$this->version=(string)$manifest['version'];
		$runtime=self::normalizeRuntime(is_array($definition['runtime'] ?? null) ? $definition['runtime'] : []);
		$this->runtimeId=(string)$runtime['id'];
		$this->dependencies=$this->normalizeDependencies($definition['dependencies'] ?? []);
		$features=$this->normalizeNames($definition['required_features'] ?? [], 'Required feature');
		$available=$this->normalizeAvailable($definition['available_packages'] ?? []);
		$policy=$this->normalizePolicy($definition['policy'] ?? []);
		$lock=$this->normalizeLock($definition['lock'] ?? null);
		$distribution=$this->normalizeDistribution($definition['distribution'] ?? null);
		$verification=$this->normalizeVerification($definition['verification'] ?? null);
		$trust=$this->normalizeTrust($definition['trust'] ?? null, $manifest);
		$install=$this->normalizeInstall($definition['install_plan'] ?? null);
		$meta=$this->safeArray($definition['meta'] ?? [], 'Compatibility case metadata', 65536);

		$manifestObject=PanelPackageManifest::from($manifest);
		$manifestCheck=$manifestObject->compatibility($runtime);
		$featureChecks=[];
		foreach($features as $feature){
			$present=in_array($feature, $runtime['features'], true);
			$featureChecks[]=['name'=>'feature:'.$feature,'expected'=>'available','actual'=>$present ? 'available' : 'missing','ok'=>$present];
		}
		$dependencyChecks=[];
		foreach($this->dependencies as $dependency=>$constraint){
			$actual=(string)($available[$dependency] ?? '');
			$dependencyChecks[]=['name'=>'dependency:'.$dependency,'expected'=>$constraint,'actual'=>$actual!=='' ? $actual : 'missing','ok'=>$actual!=='' && PanelPackageManifest::matchesConstraint($actual, $constraint)];
		}
		$lockCheck=$this->lockCheck($lock, $policy);
		$distributionCheck=$this->distributionCheck($distribution, $policy);
		$publisherCheck=$this->publisherCheck($manifest, $distribution, $policy);
		$signatureCheck=$this->signatureCheck($manifest, $verification, $policy);
		$trustCheck=$this->trustCheck($manifest, $trust, $policy);
		$installCheck=$this->installCheck($install, $policy);
		$failures=[];
		if(($manifestCheck['ok'] ?? false)!==true){$failures[]='manifest:runtime';}
		foreach($featureChecks as $check){if(($check['ok'] ?? false)!==true){$failures[]=(string)$check['name'];}}
		foreach($dependencyChecks as $check){if(($check['ok'] ?? false)!==true){$failures[]=(string)$check['name'];}}
		foreach([$lockCheck,$distributionCheck,$publisherCheck,$signatureCheck,$trustCheck,$installCheck] as $check){
			foreach((array)($check['failures'] ?? []) as $failure){$failures[]=(string)$failure;}
		}
		$failures=array_values(array_unique($failures));sort($failures, SORT_STRING);
		$this->key=$this->packageId.'@'.$this->version.'#'.$this->runtimeId;
		$checks=[
			'manifest'=>['status'=>($manifestCheck['ok'] ?? false)===true ? 'compatible' : 'blocked','ok'=>($manifestCheck['ok'] ?? false)===true,'checks'=>(array)($manifestCheck['checks'] ?? [])],
			'features'=>['status'=>$featureChecks===[] ? 'not_required' : (array_reduce($featureChecks, static fn(bool $ok,array $check): bool=>$ok && ($check['ok'] ?? false)===true, true) ? 'compatible' : 'blocked'),'checks'=>$featureChecks],
			'dependencies'=>['status'=>$dependencyChecks===[] ? 'not_required' : (array_reduce($dependencyChecks, static fn(bool $ok,array $check): bool=>$ok && ($check['ok'] ?? false)===true, true) ? 'compatible' : 'blocked'),'checks'=>$dependencyChecks],
			'lock'=>$lockCheck,'distribution'=>$distributionCheck,'publisher'=>$publisherCheck,
			'signature'=>$signatureCheck,'trust'=>$trustCheck,'install_plan'=>$installCheck,
		];
		$identity=$this->safeManifest($manifest);
		$fingerprint=hash('sha256', self::canonicalJson([
			'format'=>self::FORMAT,'key'=>$this->key,'package'=>$identity,'runtime'=>$runtime,
			'dependencies'=>$this->dependencies,'required_features'=>$features,'checks'=>$checks,'failures'=>$failures,
		]));
		$this->result=[
			'type'=>'panel_package_compatibility_case','format'=>self::FORMAT,'case_key'=>$this->key,
			'package'=>$identity,'runtime'=>$runtime,'dependencies'=>$this->dependencies,'required_features'=>$features,
			'checks'=>$this->sanitize($checks),'blocked'=>$failures!==[],'failures'=>$failures,
			'fingerprint'=>$fingerprint,'meta'=>$this->sanitize($meta),
		];
	}

	/** @return self Immutable compatibility case. */
	public static function make(array $definition): self { return new self($definition); }
	public function key(): string { return $this->key; }
	public function packageId(): string { return $this->packageId; }
	public function version(): string { return $this->version; }
	public function runtimeId(): string { return $this->runtimeId; }
	/** @return array<string,string> */
	public function dependencies(): array { return $this->dependencies; }
	public function blocked(): bool { return (bool)$this->result['blocked']; }
	/** @return array<int,string> */
	public function failures(): array { return $this->result['failures']; }
	public function fingerprint(): string { return (string)$this->result['fingerprint']; }
	/** @return array<string,mixed> */
	public function toArray(): array { return $this->result; }
	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return array{id:string,php:string,panel:string,reactor:string,modules:array<string,string>,themes:array<int,string>,features:array<int,string>,axes:array<string,string>} */
	public static function normalizeRuntime(array $runtime): array {
		$allowed=['id','php','panel','reactor','modules','themes','features','axes'];
		if(array_diff(array_keys($runtime), $allowed)!==[]){throw new \InvalidArgumentException('Runtime profile contains unknown fields.');}
		$rawId=$runtime['id'] ?? null;$id=is_string($rawId)?$rawId:'';
		$id=Resource::normalizeName($id);
		if($id==='' || $id!==($runtime['id'] ?? null) || strlen($id)>128){throw new \InvalidArgumentException('Runtime profile id must be canonical and bounded.');}
		$versions=[];
		foreach(['php','panel','reactor'] as $name){
			$value=$runtime[$name] ?? null;
			if(!is_string($value) || strlen($value)>128 || !PanelPackageManifest::validVersion($value)){throw new \InvalidArgumentException('Runtime '.$name.' version must be strict semantic versioning.');}
			$versions[$name]=$value;
		}
		$modules=$runtime['modules'] ?? [];
		if(!is_array($modules) || ($modules!==[] && array_is_list($modules)) || count($modules)>self::MAX_ITEMS){throw new \InvalidArgumentException('Runtime modules must be a bounded name-to-version map.');}
		$normalizedModules=[];
		foreach($modules as $name=>$version){
			$rawName=is_string($name) ? $name : '';$idName=Resource::normalizeName($rawName);
			if($idName==='' || $idName!==$rawName || strlen($idName)>128 || isset($normalizedModules[$idName]) || !is_string($version) || strlen($version)>128 || !PanelPackageManifest::validVersion($version)){throw new \InvalidArgumentException('Runtime module entry is malformed.');}
			$normalizedModules[$idName]=$version;
		}
		ksort($normalizedModules, SORT_STRING);
		$themes=self::normalizeStaticNames($runtime['themes'] ?? [], 'Runtime theme');
		$features=self::normalizeStaticNames($runtime['features'] ?? [], 'Runtime feature');
		$axes=$runtime['axes'] ?? [];
		if(!is_array($axes) || ($axes!==[] && array_is_list($axes)) || count($axes)>16){throw new \InvalidArgumentException('Runtime axes must be a bounded map.');}
		$normalizedAxes=[];
		foreach($axes as $name=>$value){
			$rawName=is_string($name)?$name:'';$name=Resource::normalizeName($rawName);$value=is_string($value) ? trim($value) : '';
			if($name==='' || $name!==$rawName || $value==='' || strlen($value)>128){throw new \InvalidArgumentException('Runtime axis label is malformed.');}
			$normalizedAxes[$name]=$value;
		}
		ksort($normalizedAxes, SORT_STRING);
		return ['id'=>$id]+$versions+['modules'=>$normalizedModules,'themes'=>$themes,'features'=>$features,'axes'=>$normalizedAxes];
	}

	/** @return array<string,mixed> */
	private function normalizeManifest(mixed $source): array {
		if($source instanceof PanelPackageManifest){$source=$source->toArray();}
		if(!is_array($source) || array_is_list($source)){throw new \InvalidArgumentException('Compatibility case manifest must be an object or package manifest.');}
		$allowed=['id','label','version','description','class','type','status','requirements','provides','links','support','signature','compatibility','meta'];
		if(array_diff(array_keys($source), $allowed)!==[]){throw new \InvalidArgumentException('Compatibility case manifest contains unknown fields.');}
		$id=$source['id'] ?? null;$version=$source['version'] ?? null;
		if(!is_string($id) || Resource::normalizeName($id)!==$id || strlen($id)>128 || !is_string($version) || strlen($version)>128 || !PanelPackageManifest::validVersion($version)){throw new \InvalidArgumentException('Compatibility case package identity is malformed.');}
		foreach(['label','description','class'] as $name){if(isset($source[$name]) && !is_string($source[$name])){throw new \InvalidArgumentException('Compatibility case package scalar metadata is malformed.');}}
		foreach(['type','status'] as $name){if(isset($source[$name]) && (!is_string($source[$name]) || strlen($source[$name])>128 || Resource::normalizeName($source[$name])!==$source[$name])){throw new \InvalidArgumentException('Compatibility case package lifecycle metadata is malformed.');}}
		$requirements=$source['requirements'] ?? [];if(!is_array($requirements) || ($requirements!==[] && array_is_list($requirements)) || array_diff(array_keys($requirements),['php','panel','reactor','modules','themes'])!==[]){throw new \InvalidArgumentException('Compatibility case package requirements are malformed.');}
		foreach(['php','panel','reactor'] as $name){if(isset($requirements[$name]) && (!is_string($requirements[$name]) || !$this->validConstraint($requirements[$name]))){throw new \InvalidArgumentException('Compatibility case package runtime constraint is malformed.');}}
		$modules=$requirements['modules'] ?? [];if(!is_array($modules) || ($modules!==[] && array_is_list($modules)) || count($modules)>self::MAX_ITEMS){throw new \InvalidArgumentException('Compatibility case package module requirements are malformed.');}foreach($modules as $name=>$constraint){if(!is_string($name) || Resource::normalizeName($name)!==$name || strlen($name)>128 || !is_string($constraint) || !$this->validConstraint($constraint)){throw new \InvalidArgumentException('Compatibility case package module requirement is malformed.');}}
		self::normalizeStaticNames($requirements['themes'] ?? [],'Package theme');
		self::normalizeStaticNames($source['provides'] ?? [],'Package provide');
		$links=$source['links'] ?? [];if(!is_array($links) || !array_is_list($links) || count($links)>self::MAX_ITEMS){throw new \InvalidArgumentException('Compatibility case package links are malformed.');}foreach($links as $link){if(!is_array($link) || array_diff(array_keys($link),['label','target'])!==[] || !is_string($link['label'] ?? null) || !is_string($link['target'] ?? null) || strlen($link['label'])>4096 || strlen($link['target'])>4096){throw new \InvalidArgumentException('Compatibility case package link is malformed.');}}
		foreach(['support','signature','meta'] as $name){$value=$source[$name] ?? [];if(!is_array($value) || ($value!==[] && array_is_list($value))){throw new \InvalidArgumentException('Compatibility case package '.$name.' must be an object.');}}
		if(isset($source['compatibility']) && $source['compatibility']!==null && !is_array($source['compatibility'])){throw new \InvalidArgumentException('Compatibility case embedded compatibility is malformed.');}
		$this->safeArray($source, 'Package manifest', self::MAX_EVIDENCE_BYTES);
		$manifest=PanelPackageManifest::from($source)->toArray();
		$signature=$manifest['signature'] ?? [];$support=$manifest['support'] ?? [];$meta=$manifest['meta'] ?? [];
		if(!is_array($signature) || !is_array($support) || !is_array($meta)){throw new \InvalidArgumentException('Compatibility case package provenance is malformed.');}
		foreach(['algorithm','key_id','publisher','digest','signature'] as $name){if(isset($signature[$name]) && !is_string($signature[$name])){throw new \InvalidArgumentException('Compatibility case package signature identity is malformed.');}}
		if(((string)($signature['algorithm'] ?? '')!=='' && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',(string)$signature['algorithm'])!==1) || ((string)($signature['key_id'] ?? '')!=='' && preg_match('/^[A-Za-z0-9._:-]{1,256}$/D',(string)$signature['key_id'])!==1) || ((string)($signature['digest'] ?? '')!=='' && preg_match('/^[a-f0-9]{64}$/D',(string)$signature['digest'])!==1)){throw new \InvalidArgumentException('Compatibility case package signature identity is malformed.');}
		foreach([$signature['publisher'] ?? null,$support['owner'] ?? null,$meta['publisher'] ?? null] as $publisher){if($publisher!==null && (!is_string($publisher) || ($publisher!=='' && (strlen($publisher)>128 || Resource::normalizeName($publisher)!==$publisher)))){throw new \InvalidArgumentException('Compatibility case package publisher is malformed.');}}
		$requirements=(array)$manifest['requirements'];
		$modules=(array)($requirements['modules'] ?? []);ksort($modules, SORT_STRING);$requirements['modules']=$modules;
		$themes=array_values(array_unique(array_map('strval', (array)($requirements['themes'] ?? []))));sort($themes, SORT_STRING);$requirements['themes']=$themes;
		$manifest['requirements']=$requirements;
		$provides=array_values(array_unique(array_map('strval', (array)($manifest['provides'] ?? []))));sort($provides, SORT_STRING);$manifest['provides']=$provides;
		return $manifest;
	}

	/** @return array<string,string> */
	private function normalizeDependencies(mixed $dependencies): array {
		if(!is_array($dependencies) || ($dependencies!==[] && array_is_list($dependencies)) || count($dependencies)>self::MAX_ITEMS){throw new \InvalidArgumentException('Package dependencies must be a bounded name-to-constraint map.');}
		$normalized=[];
		foreach($dependencies as $id=>$constraint){
			$rawId=is_string($id) ? $id : '';$id=Resource::normalizeName($rawId);
			if($id==='' || $id!==$rawId || strlen($id)>128 || $id===$this->packageId || !is_string($constraint) || strlen($constraint)>128 || !$this->validConstraint($constraint)){throw new \InvalidArgumentException('Package dependency entry is malformed.');}
			$normalized[$id]=trim($constraint);
		}
		ksort($normalized, SORT_STRING);return $normalized;
	}

	/** @return array<string,string> */
	private function normalizeAvailable(mixed $packages): array {
		if(!is_array($packages) || ($packages!==[] && array_is_list($packages)) || count($packages)>self::MAX_ITEMS){throw new \InvalidArgumentException('Available packages must be a bounded name-to-version map.');}
		$normalized=[];
		foreach($packages as $id=>$version){
			$rawId=is_string($id) ? $id : '';$id=Resource::normalizeName($rawId);
			if($id==='' || $id!==$rawId || strlen($id)>128 || !is_string($version) || strlen($version)>128 || !PanelPackageManifest::validVersion($version)){throw new \InvalidArgumentException('Available package entry is malformed.');}
			$normalized[$id]=$version;
		}
		ksort($normalized, SORT_STRING);return $normalized;
	}

	/** @return array<string,bool> */
	private function normalizePolicy(mixed $policy): array {
		if(!is_array($policy) || ($policy!==[] && array_is_list($policy))){throw new \InvalidArgumentException('Compatibility case policy must be an object.');}
		$defaults=['require_lock'=>false,'require_distribution'=>false,'require_authenticated_distribution'=>false,'require_publisher'=>false,'require_signature'=>false,'require_trust'=>false,'require_install_ready'=>false];
		if(array_diff(array_keys($policy), array_keys($defaults))!==[]){throw new \InvalidArgumentException('Compatibility case policy contains unknown fields.');}
		foreach($policy as $name=>$value){if(!is_bool($value)){throw new \InvalidArgumentException('Compatibility case policy flags must be boolean.');}$defaults[$name]=$value;}
		return $defaults;
	}

	/** @return array<string,mixed> */
	private function normalizeLock(mixed $lock): array {
		$origin='snapshot';
		if($lock===null){return ['present'=>false,'origin'=>'none','checksum_valid'=>null,'packages'=>[]];}
		if($lock instanceof PanelPackageLock){$origin='runtime_object';$lock=$lock->toArray();}
		$lock=$this->safeArray($lock, 'Package lock', self::MAX_EVIDENCE_BYTES);
		if(isset($lock['type']) && $lock['type']!=='panel_package_lock'){throw new \InvalidArgumentException('Package lock type is unsupported.');}
		$rawPackages=$lock['packages'] ?? null;
		$packages=$this->normalizePackageRows($rawPackages, 'Package lock');
		$checksum=$lock['checksum'] ?? '';
		if(!is_string($checksum) || ($checksum!=='' && preg_match('/^[a-f0-9]{64}$/D', $checksum)!==1)){throw new \InvalidArgumentException('Package lock checksum is malformed.');}
		$encoded=json_encode($rawPackages, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		$valid=$checksum==='' ? null : is_string($encoded) && hash_equals($checksum, hash('sha256', $encoded));
		return ['present'=>true,'origin'=>$origin,'checksum_valid'=>$valid,'checksum'=>$checksum,'packages'=>$packages];
	}

	/** @return array<string,mixed> */
	private function normalizeDistribution(mixed $distribution): array {
		$origin='snapshot';
		if($distribution===null){return ['present'=>false,'origin'=>'none','authenticated'=>false,'reported_ok'=>null,'publisher'=>'','packages'=>[]];}
		if($distribution instanceof PanelPackageRegistryIndex){$origin='runtime_authenticated';$distribution=$distribution->toArray();}
		elseif($distribution instanceof PanelPackageResolutionPlan){$origin='runtime_resolution';$distribution=$distribution->toArray();}
		$distribution=$this->safeArray($distribution, 'Distribution snapshot', self::MAX_EVIDENCE_BYTES);
		$type=is_string($distribution['type'] ?? null)?$distribution['type']:'';
		if(!in_array($type, ['panel_package_registry_index','panel_package_resolution_plan'], true)){throw new \InvalidArgumentException('Distribution snapshot type is unsupported.');}
		if(!is_bool($distribution['ok'] ?? null)){throw new \InvalidArgumentException('Distribution snapshot must report an explicit status.');}
		$packages=$this->normalizePackageRows($distribution['packages'] ?? null, 'Distribution snapshot', true);
		if(isset($distribution['publisher']) && !is_string($distribution['publisher'])){throw new \InvalidArgumentException('Distribution publisher is malformed.');}$publisher=(string)($distribution['publisher'] ?? '');
		if($publisher!=='' && (Resource::normalizeName($publisher)!==$publisher || strlen($publisher)>128)){throw new \InvalidArgumentException('Distribution publisher is malformed.');}
		$reported=(bool)$distribution['ok'];
		return ['present'=>true,'origin'=>$origin,'authenticated'=>$origin==='runtime_authenticated' && $reported,'reported_ok'=>$reported,'publisher'=>$publisher,'packages'=>$packages];
	}

	/** @return array<string,mixed> */
	private function normalizeVerification(mixed $verification): array {
		$origin='snapshot';
		if($verification===null){return ['present'=>false,'origin'=>'none','ok'=>null];}
		if($verification instanceof PanelPackageVerificationResult){$origin='runtime_result';$verification=$verification->toArray();}
		$verification=$this->safeArray($verification, 'Verification result', 262144);
		if(!is_bool($verification['ok'] ?? null)){throw new \InvalidArgumentException('Verification result must report an explicit status.');}
		foreach(['package','algorithm','key_id','digest'] as $name){if(isset($verification[$name]) && !is_string($verification[$name])){throw new \InvalidArgumentException('Verification result identity is malformed.');}}
		$package=(string)($verification['package'] ?? '');$algorithm=(string)($verification['algorithm'] ?? '');$keyId=(string)($verification['key_id'] ?? '');$digest=(string)($verification['digest'] ?? '');
		if(($package!=='' && (strlen($package)>128 || Resource::normalizeName($package)!==$package)) || ($algorithm!=='' && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$algorithm)!==1) || ($keyId!=='' && preg_match('/^[A-Za-z0-9._:-]{1,256}$/D',$keyId)!==1) || ($digest!=='' && preg_match('/^[a-f0-9]{64}$/D',$digest)!==1) || (($verification['ok'] ?? false)===true && ($package==='' || $algorithm==='' || $keyId==='' || $digest===''))){throw new \InvalidArgumentException('Verification result identity is incomplete or malformed.');}
		return ['present'=>true,'origin'=>$origin,'ok'=>(bool)$verification['ok'],'package'=>$package,'algorithm'=>$algorithm,'key_id'=>$keyId,'digest'=>$digest];
	}

	/** @return array<string,mixed> */
	private function normalizeTrust(mixed $trust, array $manifest): array {
		$origin='snapshot';
		if($trust===null){return ['present'=>false,'origin'=>'none','trusted'=>null];}
		if($trust instanceof PanelPackageTrustPolicy){$origin='runtime_evaluated';$trust=$trust->evaluate(PanelPackageManifest::from($manifest));}
		$trust=$this->safeArray($trust, 'Trust result', 262144);
		if(!is_bool($trust['trusted'] ?? null)){throw new \InvalidArgumentException('Trust result must report an explicit status.');}
		foreach(['package','publisher'] as $name){if(isset($trust[$name]) && $trust[$name]!==null && !is_string($trust[$name])){throw new \InvalidArgumentException('Trust result identity is malformed.');}}
		$package=(string)($trust['package'] ?? '');$publisher=(string)($trust['publisher'] ?? '');
		if(($package!=='' && (strlen($package)>128 || Resource::normalizeName($package)!==$package)) || ($publisher!=='' && (strlen($publisher)>128 || Resource::normalizeName($publisher)!==$publisher)) || (($trust['trusted'] ?? false)===true && $package==='')){throw new \InvalidArgumentException('Trust result identity is incomplete or malformed.');}
		return ['present'=>true,'origin'=>$origin,'trusted'=>(bool)$trust['trusted'],'package'=>$package,'publisher'=>$publisher];
	}

	/** @return array<string,mixed> */
	private function normalizeInstall(mixed $install): array {
		$origin='snapshot';
		if($install===null){return ['present'=>false,'origin'=>'none','ready'=>null];}
		if($install instanceof PanelPackageInstallPlan){$origin='runtime_preview';$install=$install->manifest();}
		$install=$this->safeArray($install, 'Install plan preview', self::MAX_EVIDENCE_BYTES);
		if(($install['type'] ?? null)!=='panel_package_install_plan' || !is_bool($install['ready'] ?? null)){throw new \InvalidArgumentException('Install plan preview is malformed.');}
		if(isset($install['blocked']) && !is_bool($install['blocked'])){throw new \InvalidArgumentException('Install plan blocked state is malformed.');}if(isset($install['package']) && !is_array($install['package'])){throw new \InvalidArgumentException('Install plan package identity is malformed.');}$package=is_array($install['package'] ?? null) ? $install['package'] : [];
		if((isset($package['id']) && !is_string($package['id'])) || (isset($package['version']) && !is_string($package['version']))){throw new \InvalidArgumentException('Install plan package identity is malformed.');}$packageId=(string)($package['id'] ?? '');$version=(string)($package['version'] ?? '');if(($packageId!=='' && (strlen($packageId)>128 || Resource::normalizeName($packageId)!==$packageId)) || ($version!=='' && (strlen($version)>128 || !PanelPackageManifest::validVersion($version)))){throw new \InvalidArgumentException('Install plan package identity is malformed.');}
		$summary=$install['summary'] ?? [];if(!is_array($summary) || ($summary!==[] && array_is_list($summary))){throw new \InvalidArgumentException('Install plan summary is malformed.');}
		return ['present'=>true,'origin'=>$origin,'ready'=>(bool)$install['ready'],'blocked'=>(bool)($install['blocked'] ?? !$install['ready']),'package'=>$packageId,'version'=>$version,'summary'=>$this->sanitize($summary)];
	}

	/** @return array<string,array<string,mixed>> */
	private function normalizePackageRows(mixed $rows, string $label, bool $multipleVersions=false): array {
		if(!is_array($rows) || count($rows)>self::MAX_ITEMS){throw new \InvalidArgumentException($label.' packages must be a bounded collection.');}
		$normalized=[];
		foreach($rows as $key=>$row){
			if(!is_array($row)){throw new \InvalidArgumentException($label.' package row is malformed.');}
			$rawId=$row['id'] ?? (is_string($key)?$key:null);$id=is_string($rawId)?$rawId:'';$version=$row['version'] ?? null;
			if($id==='' || strlen($id)>128 || Resource::normalizeName($id)!==$id || !is_string($version) || strlen($version)>128 || !PanelPackageManifest::validVersion($version) || (!$multipleVersions && isset($normalized[$id])) || ($multipleVersions && isset($normalized[$id][$version]))){throw new \InvalidArgumentException($label.' package identity is malformed.');}
			$dependencies=$this->normalizeDependenciesFor($row['dependencies'] ?? [], $id, $label);
			if((isset($row['publisher']) && !is_string($row['publisher'])) || (isset($row['key_id']) && !is_string($row['key_id']))){throw new \InvalidArgumentException($label.' publisher or key id is malformed.');}$publisher=(string)($row['publisher'] ?? '');$keyId=(string)($row['key_id'] ?? '');
			if(($publisher!=='' && (strlen($publisher)>128 || Resource::normalizeName($publisher)!==$publisher)) || strlen($keyId)>256 || ($keyId!=='' && preg_match('/^[A-Za-z0-9._:-]+$/D',$keyId)!==1)){throw new \InvalidArgumentException($label.' publisher or key id is malformed.');}
			if(isset($row['yanked']) && !is_bool($row['yanked'])){throw new \InvalidArgumentException($label.' package yanked flag is malformed.');}
			$row=['id'=>$id,'version'=>$version,'publisher'=>$publisher,'key_id'=>$keyId,'dependencies'=>$dependencies,'yanked'=>(bool)($row['yanked'] ?? false)];
			if($multipleVersions){$normalized[$id][$version]=$row;}else{$normalized[$id]=$row;}
		}
		ksort($normalized, SORT_STRING);if($multipleVersions){foreach($normalized as &$versions){uksort($versions,[PanelPackageManifest::class,'compareVersions']);}unset($versions);}return $normalized;
	}

	/** @return array<string,string> */
	private function normalizeDependenciesFor(mixed $dependencies, string $owner, string $label): array {
		if(!is_array($dependencies) || ($dependencies!==[] && array_is_list($dependencies)) || count($dependencies)>self::MAX_ITEMS){throw new \InvalidArgumentException($label.' dependency map is malformed.');}
		$normalized=[];
		foreach($dependencies as $id=>$constraint){$rawId=is_string($id)?$id:'';$id=Resource::normalizeName($rawId);if($id==='' || $id!==$rawId || strlen($id)>128 || $id===$owner || !is_string($constraint) || strlen($constraint)>128 || !$this->validConstraint($constraint)){throw new \InvalidArgumentException($label.' dependency entry is malformed.');}$normalized[$id]=trim($constraint);}
		ksort($normalized, SORT_STRING);return $normalized;
	}

	/** @return array<string,mixed> */
	private function lockCheck(array $lock, array $policy): array {
		$failures=[];
		if(!$lock['present']){if($policy['require_lock']){$failures[]='lock:missing';}return ['status'=>$policy['require_lock'] ? 'blocked' : 'not_evaluated','present'=>false,'origin'=>'none','ok'=>$failures===[],'failures'=>$failures];}
		if($lock['checksum_valid']===false){$failures[]='lock:integrity';}
		$row=$lock['packages'][$this->packageId] ?? null;
		if(!is_array($row) || ($row['version'] ?? null)!==$this->version){$failures[]='lock:package';}
		foreach($this->dependencies as $id=>$constraint){$version=(string)($lock['packages'][$id]['version'] ?? '');if($version==='' || !PanelPackageManifest::matchesConstraint($version, $constraint)){$failures[]='lock:dependency:'.$id;}}
		return ['status'=>$failures===[] ? 'compatible' : 'blocked','present'=>true,'origin'=>$lock['origin'],'checksum_valid'=>$lock['checksum_valid'],'ok'=>$failures===[],'failures'=>$failures];
	}

	/** @return array<string,mixed> */
	private function distributionCheck(array $distribution, array $policy): array {
		$failures=[];
		if(!$distribution['present']){if($policy['require_distribution']){$failures[]='distribution:missing';}return ['status'=>$failures===[] ? 'not_evaluated' : 'blocked','present'=>false,'origin'=>'none','authenticated'=>false,'ok'=>$failures===[],'failures'=>$failures];}
		if($distribution['reported_ok']!==true){$failures[]='distribution:reported_blocked';}
		if($policy['require_authenticated_distribution'] && !$distribution['authenticated']){$failures[]='distribution:unauthenticated';}
		$row=$distribution['packages'][$this->packageId][$this->version] ?? null;
		if(!is_array($row) || ($row['version'] ?? null)!==$this->version){$failures[]='distribution:package';}
		elseif(($row['yanked'] ?? false)===true){$failures[]='distribution:yanked';}
		foreach($this->dependencies as $id=>$constraint){$versions=(array)($distribution['packages'][$id] ?? []);$matches=array_filter($versions,static fn(array $row): bool=>($row['yanked'] ?? false)!==true && PanelPackageManifest::matchesConstraint((string)($row['version'] ?? ''),$constraint));if($matches===[]){$failures[]='distribution:dependency:'.$id;}}
		return ['status'=>$failures===[] ? 'compatible' : 'blocked','present'=>true,'origin'=>$distribution['origin'],'authenticated'=>$distribution['authenticated'],'ok'=>$failures===[],'failures'=>$failures];
	}

	/** @return array<string,mixed> */
	private function publisherCheck(array $manifest, array $distribution, array $policy): array {
		$signature=(array)($manifest['signature'] ?? []);$support=(array)($manifest['support'] ?? []);$meta=(array)($manifest['meta'] ?? []);
		$values=[];
		foreach([$signature['publisher'] ?? null,$support['owner'] ?? null,$meta['publisher'] ?? null,$distribution['publisher'] ?? null,$distribution['packages'][$this->packageId][$this->version]['publisher'] ?? null] as $value){if(is_string($value) && trim($value)!==''){$values[]=Resource::normalizeName($value);}}
		$values=array_values(array_unique(array_filter($values, static fn(string $value): bool=>$value!=='')));sort($values, SORT_STRING);
		$failures=[];if($values===[] && $policy['require_publisher']){$failures[]='publisher:missing';}elseif(count($values)>1){$failures[]='publisher:mismatch';}
		return ['status'=>$values===[] ? ($failures===[] ? 'not_declared' : 'blocked') : ($failures===[] ? 'consistent' : 'blocked'),'declared'=>$values,'ok'=>$failures===[],'failures'=>$failures];
	}

	/** @return array<string,mixed> */
	private function signatureCheck(array $manifest, array $verification, array $policy): array {
		$signature=(array)($manifest['signature'] ?? []);$declared=(string)($signature['signature'] ?? '')!=='' && (string)($signature['digest'] ?? '')!=='' && (string)($signature['key_id'] ?? '')!=='' && (string)($signature['algorithm'] ?? '')!=='';
		$failures=[];
		if($policy['require_signature'] && !$declared){$failures[]='signature:missing';}
		if($verification['present']){
			if(!$verification['ok']){$failures[]='signature:unverified';}
			if($verification['ok'] && !$declared){$failures[]='signature:manifest';}
			if($verification['package']!=='' && $verification['package']!==$this->packageId){$failures[]='signature:package';}
			if((string)($signature['algorithm'] ?? '')!=='' && $verification['algorithm']!=='' && !hash_equals((string)$signature['algorithm'], $verification['algorithm'])){$failures[]='signature:algorithm';}
			if((string)($signature['key_id'] ?? '')!=='' && $verification['key_id']!=='' && !hash_equals((string)$signature['key_id'], $verification['key_id'])){$failures[]='signature:key';}
			if((string)($signature['digest'] ?? '')!=='' && $verification['digest']!=='' && !hash_equals((string)$signature['digest'], $verification['digest'])){$failures[]='signature:digest';}
		}
		elseif($policy['require_signature']){$failures[]='signature:not_evaluated';}
		$failures=array_values(array_unique($failures));
		return ['status'=>$verification['present'] ? ($failures===[] ? 'reported_verified' : 'blocked') : ($declared ? 'declared_not_evaluated' : ($failures===[] ? 'not_declared' : 'blocked')),'declared'=>$declared,'evaluated'=>$verification['present'],'origin'=>$verification['origin'],'ok'=>$failures===[],'algorithm'=>$verification['present']?(string)$verification['algorithm']:(string)($signature['algorithm']??''),'key_id'=>$verification['present']?(string)$verification['key_id']:(string)($signature['key_id']??''),'digest'=>$verification['present']?(string)$verification['digest']:(string)($signature['digest']??''),'failures'=>$failures];
	}

	/** @return array<string,mixed> */
	private function trustCheck(array $manifest, array $trust, array $policy): array {
		$failures=[];
		if($trust['present']){
			if(!$trust['trusted']){$failures[]='trust:blocked';}
			if($trust['package']!=='' && $trust['package']!==$this->packageId){$failures[]='trust:package';}
			$publisher=Resource::normalizeName((string)($manifest['signature']['publisher'] ?? $manifest['meta']['publisher'] ?? $manifest['support']['owner'] ?? ''));
			if($publisher!=='' && Resource::normalizeName($trust['publisher'])!==$publisher){$failures[]='trust:publisher';}
		}
		elseif($policy['require_trust']){$failures[]='trust:missing';}
		return ['status'=>$trust['present'] ? ($failures===[] ? 'trusted' : 'blocked') : ($failures===[] ? 'not_evaluated' : 'blocked'),'evaluated'=>$trust['present'],'origin'=>$trust['origin'],'ok'=>$failures===[],'failures'=>$failures];
	}

	/** @return array<string,mixed> */
	private function installCheck(array $install, array $policy): array {
		$failures=[];
		if($install['present']){if(!$install['ready']){$failures[]='install_plan:blocked';}if($install['package']!==$this->packageId){$failures[]='install_plan:package';}if($install['version']!==$this->version){$failures[]='install_plan:version';}}
		elseif($policy['require_install_ready']){$failures[]='install_plan:missing';}
		return ['status'=>$install['present'] ? ($failures===[] ? 'ready' : 'blocked') : ($failures===[] ? 'not_evaluated' : 'blocked'),'evaluated'=>$install['present'],'origin'=>$install['origin'],'ready'=>$install['ready'],'summary'=>$install['summary'] ?? [],'ok'=>$failures===[],'failures'=>$failures];
	}

	/** @return array<string,mixed> */
	private function safeManifest(array $manifest): array {
		$signature=(array)($manifest['signature'] ?? []);
		return ['id'=>$this->packageId,'version'=>$this->version,'type'=>(string)($manifest['type'] ?? ''),'status'=>(string)($manifest['status'] ?? ''),'publisher'=>Resource::normalizeName((string)($signature['publisher'] ?? $manifest['meta']['publisher'] ?? $manifest['support']['owner'] ?? '')),'signature'=>['algorithm'=>(string)($signature['algorithm'] ?? ''),'key_id'=>(string)($signature['key_id'] ?? ''),'digest'=>(string)($signature['digest'] ?? '')],'requirements'=>$this->sanitize((array)($manifest['requirements'] ?? [])),'provides'=>array_values((array)($manifest['provides'] ?? []))];
	}

	/** @return array<int,string> */
	private function normalizeNames(mixed $names, string $label): array { return self::normalizeStaticNames($names, $label); }
	/** @return array<int,string> */
	private static function normalizeStaticNames(mixed $names, string $label): array {
		if(!is_array($names) || !array_is_list($names) || count($names)>self::MAX_ITEMS){throw new \InvalidArgumentException($label.' list must be bounded.');}
		$normalized=[];foreach($names as $name){$id=is_string($name) ? Resource::normalizeName($name) : '';if($id==='' || $id!==$name || strlen($id)>128 || in_array($id,$normalized,true)){throw new \InvalidArgumentException($label.' entry is malformed.');}$normalized[]=$id;}sort($normalized,SORT_STRING);return $normalized;
	}

	private function validConstraint(string $constraint): bool {
		$constraint=trim($constraint);if($constraint==='' || strlen($constraint)>128){return false;}if($constraint==='*'){return true;}
		foreach(preg_split('/\s*,\s*/',$constraint) ?: [] as $part){$part=trim($part);if($part==='*'){continue;}if(preg_match('/^(?:\^|>=|<=|>|<|==|=)?\s*(\S+)$/D',$part,$match)!==1){return false;}$version=$match[1];if(!PanelPackageManifest::validVersion($version) && preg_match('/^(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/D',$version)!==1){return false;}}
		return true;
	}

	/** @return array<string,mixed> */
	private function safeArray(mixed $value, string $label, int $maxBytes): array {
		if(!is_array($value)){throw new \InvalidArgumentException($label.' must be an object or array.');}
		try{$json=self::canonicalJson($value);}catch(\Throwable $exception){throw new \InvalidArgumentException($label.' is not canonically serializable.',0,$exception);}
		if(strlen($json)>$maxBytes){throw new \InvalidArgumentException($label.' exceeds its byte budget.');}
		return $value;
	}

	private static function canonicalJson(mixed $value): string { $items=0;return json_encode(self::canonicalize($value,0,$items),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR); }
	private static function canonicalize(mixed $value,int $depth,int &$items): mixed {
		if($depth>self::MAX_NESTING_DEPTH || ++$items>self::MAX_CANONICAL_ITEMS){throw new \InvalidArgumentException('Compatibility value exceeds canonicalization limits.');}
		if(is_array($value)){if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as $key=>$item){$value[$key]=self::canonicalize($item,$depth+1,$items);}return $value;}
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){return $value;}if(is_float($value) && is_finite($value)){return $value;}throw new \InvalidArgumentException('Unsupported compatibility value.');
	}

	private function sanitize(mixed $value, string $key=''): mixed {
		if($key!=='' && ($this->sensitiveKey($key) || $this->locationKey($key))){return '[REDACTED]';}if(!is_array($value)){if(is_object($value)){return '[OBJECT]';}return is_string($value)&&$this->absolutePath($value)?'[REDACTED]':$value;}$safe=[];foreach($value as $itemKey=>$item){$safe[$itemKey]=$this->sanitize($item,is_string($itemKey)?$itemKey:'');}return $safe;
	}
	private function sensitiveKey(string $key): bool {
		$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/','_',$key) ?? $key;return preg_match('/(?:^|[_\-.])(?:secret|password|passwd|token|private[_\-.]?key|secret[_\-.]?key|seed|credential|authorization|cookie|bearer|api[_\-.]?key|access[_\-.]?key)(?:$|[_\-.])/i',$key)===1;
	}
	private function locationKey(string $key): bool {$key=preg_replace('/(?<=[a-z0-9])(?=[A-Z])/','_',$key)??$key;return preg_match('/(?:^|[_\-.])(?:path|root|directory|filename|filepath|locator)(?:$|[_\-.])/i',$key)===1;}
	private function absolutePath(string $value): bool {$value=trim($value);return str_starts_with($value,'/')||str_starts_with($value,'\\\\')||preg_match('~^[A-Za-z]:[\\\\/]~D',$value)===1;}
}
