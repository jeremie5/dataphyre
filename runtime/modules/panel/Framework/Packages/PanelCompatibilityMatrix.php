<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Compatibility report builder for panel packages and runtime capabilities.
 *
 * The matrix compares package manifests against a runtime profile containing
 * PHP, panel, module, theme, and feature information. It produces aggregate
 * counts and per-package compatibility payloads for operator dashboards,
 * package catalogs, and diagnostics.
 */
final class PanelCompatibilityMatrix implements \JsonSerializable {

	/**
	 * Package manifests keyed by normalized package id.
	 *
	 * @var array<string, PanelPackageManifest>
	 */
	private array $packages=[];
	/** @var array<string, mixed> Runtime versions and capabilities used for compatibility checks. */
	private array $runtime=[];
	/** @var array<string, mixed> Extra metadata emitted with matrix manifests. */
	private array $meta=[];

	/**
	 * Creates a compatibility matrix and registers package definitions.
	 *
	 * Empty runtime input uses the built-in panel runtime profile. Package arrays
	 * keyed by string id inherit that key as their id when the payload does not
	 * declare one.
	 *
	 * @param array<int|string, mixed> $packages Package manifests, plugins, ids, or definition arrays.
	 * @param array<string, mixed> $runtime Runtime profile used for compatibility evaluation.
	 */
	public function __construct(array $packages=[], array $runtime=[]) {
		$this->runtime=$runtime!==[] ? $runtime : self::defaultRuntime();
		foreach($packages as $key=>$package){
			if(is_array($package) && !isset($package['id']) && is_string($key)){
				$package['id']=$key;
			}
			$this->register($package);
		}
	}

	/**
	 * Creates a compatibility matrix from package and runtime definitions.
	 *
	 *
	 * @param array<int|string, mixed> $packages Package manifests, plugins, ids, or definition arrays.
	 * @param array<string, mixed> $runtime Runtime profile used for compatibility evaluation.
	 * @return self Matrix with supplied packages registered.
	 */
	public static function make(array $packages=[], array $runtime=[]): self {
		return new self($packages, $runtime);
	}

	/**
	 * Provides Dataphyre's default panel runtime compatibility profile.
	 *
	 * The profile includes current PHP version, core panel/reactor versions,
	 * module versions, and known theme identifiers used by package compatibility
	 * checks.
	 *
	 * @return array<string, mixed> Default runtime profile.
	 */
	public static function defaultRuntime(): array {
		return [
			'php'=>PHP_VERSION,
			'panel'=>'2.0',
			'reactor'=>'2.0',
			'modules'=>[
				'panel'=>'2.0',
				'reactor'=>'2.0',
				'templating'=>'2.0',
				'sql'=>'2.0',
			],
			'themes'=>['flat_minima', 'default', 'glass', 'brutalist'],
		];
	}

	/**
	 * Reads or mutates the runtime profile.
	 *
	 * Null returns the full runtime profile. Array input recursively merges
	 * runtime overrides. String input writes a single top-level runtime value.
	 *
	 * @param array<string, mixed>|string|null $key Runtime override map, single key, or null to read.
	 * @param mixed $value Value used with a string key.
	 * @return mixed Full runtime profile when reading, otherwise this matrix after mutation.
	 */
	public function runtime(array|string|null $key=null, mixed $value=null): mixed {
		if($key===null){
			return $this->runtime;
		}
		if(is_array($key)){
			$this->runtime=array_replace_recursive($this->runtime, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->runtime[$key]=$value;
		}
		return $this;
	}

	/**
	 * Registers a package manifest with the matrix.
	 *
	 * Plugins, manifests, arrays, and id strings are normalized by
	 * {@see PanelPackageManifest::from()} before storage. Later registrations
	 * replace packages with the same normalized id.
	 *
	 * @param PanelPlugin|PanelPackageManifest|array<string, mixed>|string $package Package definition.
	 * @param array<string, mixed> $config Supplemental manifest config.
	 * @return PanelPackageManifest Registered package manifest.
	 */
	public function register(PanelPlugin|PanelPackageManifest|array|string $package, array $config=[]): PanelPackageManifest {
		$package=PanelPackageManifest::from($package, $config);
		$this->packages[$package->id()]=$package;
		return $package;
	}

	/**
	 * Returns an existing package manifest or creates one by id.
	 *
	 * Blank ids fall back to `panel_package`, giving interactive builders a
	 * stable manifest object instead of failing immediately.
	 *
	 * @param string $id Package id before normalization.
	 * @param string $label Optional display label for newly created packages.
	 * @return PanelPackageManifest Package manifest stored in the matrix.
	 */
	public function package(string $id, string $label=''): PanelPackageManifest {
		$id=Resource::normalizeName($id);
		if($id===''){
			$id='panel_package';
		}
		if(!isset($this->packages[$id])){
			$this->packages[$id]=PanelPackageManifest::make($id, $label);
		}
		return $this->packages[$id];
	}

	/**
	 * Lists all registered package manifests.
	 *
	 * @return array<int, PanelPackageManifest> Registered packages in insertion/replacement order.
	 */
	public function packages(): array {
		return array_values($this->packages);
	}

	/**
	 * Lists packages that pass compatibility checks for the current runtime.
	 *
	 * @return array<int, PanelPackageManifest> Packages whose compatibility payload reports ok.
	 */
	public function compatible(): array {
		return array_values(array_filter($this->packages, fn(PanelPackageManifest $package): bool => ($package->compatibility($this->runtime)['ok'] ?? false)===true));
	}

	/**
	 * Lists packages blocked by compatibility checks for the current runtime.
	 *
	 * @return array<int, PanelPackageManifest> Packages whose compatibility payload is not ok.
	 */
	public function blocked(): array {
		return array_values(array_filter($this->packages, fn(PanelPackageManifest $package): bool => ($package->compatibility($this->runtime)['ok'] ?? false)!==true));
	}

	/**
	 * Adds metadata to the compatibility matrix.
	 *
	 * Array input shallow-merges metadata. String input writes a single metadata
	 * key when non-blank.
	 *
	 * @param array<string, mixed>|string $key Metadata map or single metadata key.
	 * @param mixed $value Metadata value used with a string key.
	 * @return self This matrix with metadata updated.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Builds the aggregate compatibility manifest.
	 *
	 * Each package is serialized with the current runtime profile. Aggregate
	 * counts summarize compatible and blocked packages, while `provides` counts
	 * how many packages advertise each capability.
	 *
	 * @param array<string, mixed> $meta Additional metadata merged into the manifest.
	 * @return array<string, mixed> Matrix manifest payload for catalogs and diagnostics.
	 */
	public function manifest(array $meta=[]): array {
		$packages=array_map(fn(PanelPackageManifest $package): array => $package->toArray($this->runtime), $this->packages());
		$compatible=count(array_filter($packages, static fn(array $package): bool => ($package['compatibility']['ok'] ?? false)===true));
		$blocked=count($packages)-$compatible;
		$provides=[];
		foreach($packages as $package){
			foreach((array)($package['provides'] ?? []) as $provide){
				$provides[$provide]=($provides[$provide] ?? 0)+1;
			}
		}
		ksort($provides);
		return [
			'type'=>'panel_compatibility_matrix',
			'package_count'=>count($packages),
			'compatible_count'=>$compatible,
			'blocked_count'=>$blocked,
			'provides'=>$provides,
			'runtime'=>$this->runtime,
			'packages'=>$packages,
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Serializes the matrix with its current runtime and metadata.
	 *
	 * @return array<string, mixed> Matrix manifest payload.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Serializes the compatibility report matrix with its current runtime inventory.
	 *
	 * @return array<string, mixed> Matrix manifest payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
