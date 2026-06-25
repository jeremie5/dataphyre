<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * In-memory repository for discovered Panel package manifests.
 *
 * The repository accepts package manifests from runtime registration,
 * filesystem discovery, or generated artifact bundles, tracks their source and
 * discovery errors, and exposes compatibility and lock-manifest read models for
 * installers, package views, and diagnostics. It records metadata but does not write
 * package files itself.
 */
final class PanelPackageRepository implements \JsonSerializable {

	/** @var array<string, PanelPackageManifest> Package manifests keyed by package id. */
	private array $packages=[];

	/** @var array<string, string> Discovery source keyed by package id. */
	private array $sources=[];

	/** @var array<int, array{source:string, message:string}> Discovery errors. */
	private array $errors=[];

	/** @var array<string, mixed> Runtime compatibility facts used when rendering manifests. */
	private array $runtime=[];

	/** @var array<string, mixed> Repository metadata merged into manifest output. */
	private array $meta=[];

	/**
	 * Creates a repository with optional initial packages and runtime facts.
	 *
	 * Empty runtime input falls back to `PanelCompatibilityMatrix::defaultRuntime()`.
	 *
	 * @param array<int, PanelPlugin|PanelPackageManifest|array|string> $packages Initial package definitions.
	 * @param array<string, mixed> $runtime Runtime facts used for compatibility checks.
	 */
	public function __construct(array $packages=[], array $runtime=[]) {
		$this->runtime=$runtime!==[] ? $runtime : PanelCompatibilityMatrix::defaultRuntime();
		foreach($packages as $package){
			$this->register($package);
		}
	}

	/**
	 * Creates a package repository.
	 *
	 * @param array<int, PanelPlugin|PanelPackageManifest|array|string> $packages Initial package definitions.
	 * @param array<string, mixed> $runtime Runtime facts used for compatibility checks.
	 * @return self New repository instance.
	 */
	public static function make(array $packages=[], array $runtime=[]): self {
		return new self($packages, $runtime);
	}

	/**
	 * Registers or replaces a package manifest.
	 *
	 * Source information is optional and retained only when non-empty, allowing
	 * diagnostics and lock manifests to show where a package was discovered.
	 *
	 * @param PanelPlugin|PanelPackageManifest|array|string $package Package object, manifest, payload, or package id.
	 * @param array<string, mixed> $config Supplemental manifest configuration.
	 * @param ?string $source Discovery source label.
	 * @return PanelPackageManifest Normalized manifest stored by package id.
	 */
	public function register(PanelPlugin|PanelPackageManifest|array|string $package, array $config=[], ?string $source=null): PanelPackageManifest {
		$manifest=PanelPackageManifest::from($package, $config);
		$this->packages[$manifest->id()]=$manifest;
		if($source!==null && trim($source)!==''){
			$this->sources[$manifest->id()]=$source;
		}
		return $manifest;
	}

	/**
	 * Recursively discovers `dataphyre-panel-package.json` files.
	 *
	 * Unreadable roots are recorded as errors. `vendor` and `node_modules`
	 * directories are skipped during recursion to avoid scanning dependency
	 * trees.
	 *
	 * @param string|array<int, string> $paths Root path or paths to scan.
	 * @param int $depth Maximum recursive depth; negative values are clamped to zero.
	 * @return self Same repository after discovery.
	 */
	public function discover(string|array $paths, int $depth=3): self {
		foreach((array)$paths as $path){
			$this->discoverPath((string)$path, max(0, $depth));
		}
		return $this;
	}

	/**
	 * Discovers package manifests from generated artifact descriptors.
	 *
	 * Only artifacts named `dataphyre-panel-package.json` are considered.
	 * Invalid JSON is retained as a discovery error with the bundle source.
	 *
	 * @param array<int, array<string, mixed>> $artifacts Artifact descriptors containing `path` and `contents`.
	 * @param string $source Source label for diagnostics.
	 * @return self Same repository after artifact discovery.
	 */
	public function discoverArtifacts(array $artifacts, string $source='artifact_bundle'): self {
		foreach($artifacts as $artifact){
			if(!is_array($artifact)){
				continue;
			}
			$path=(string)($artifact['path'] ?? '');
			if(basename($path)!=='dataphyre-panel-package.json'){
				continue;
			}
			$data=json_decode((string)($artifact['contents'] ?? ''), true);
			if(is_array($data)){
				$this->register($data, [], $source.':'.$path);
			}
			else{
				$this->errors[]=[
					'source'=>$source.':'.$path,
					'message'=>'Invalid package manifest JSON.',
				];
			}
		}
		return $this;
	}

	/**
	 * Returns registered package manifests in insertion/replacement order.
	 *
	 * @return array<int, PanelPackageManifest> Package manifests.
	 */
	public function packages(): array {
		return array_values($this->packages);
	}

	/**
	 * Builds a compatibility matrix for the current repository state.
	 *
	 * @return PanelCompatibilityMatrix Matrix comparing packages against runtime facts.
	 */
	public function matrix(): PanelCompatibilityMatrix {
		return PanelCompatibilityMatrix::make($this->packages(), $this->runtime);
	}

	/**
	 * Creates a lock object from the current repository.
	 *
	 * @return PanelPackageLock Lock representation derived from registered package manifests.
	 */
	public function lock(): PanelPackageLock {
		return PanelPackageLock::fromRepository($this);
	}

	/**
	 * Adds repository metadata for future manifest output.
	 *
	 * @param array<string, mixed>|string $key Metadata map or key.
	 * @param mixed $value Value used when `$key` is a string.
	 * @return self Same repository for fluent setup.
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
	 * Serializes repository state for diagnostics.
	 *
	 * @param array<string, mixed> $meta Per-call metadata merged over repository metadata.
	 * @return array<string, mixed> Repository manifest with package, source, error, compatibility, and metadata sections.
	 */
	public function manifest(array $meta=[]): array {
		$matrix=$this->matrix()->manifest();
		return [
			'type'=>'panel_package_repository',
			'package_count'=>count($this->packages),
			'source_count'=>count($this->sources),
			'error_count'=>count($this->errors),
			'sources'=>$this->sources,
			'errors'=>$this->errors,
			'compatibility'=>$matrix,
			'packages'=>array_map(fn(PanelPackageManifest $package): array => $package->toArray($this->runtime), $this->packages()),
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Builds a deterministic package lock manifest.
	 *
	 * Packages are keyed and sorted by id, include source and compatibility
	 * details, and contribute to a checksum derived from the package payload.
	 *
	 * @param array<string, mixed> $meta Per-call metadata merged over repository metadata.
	 * @return array<string, mixed> Lock manifest suitable for persistence by callers.
	 */
	public function lockManifest(array $meta=[]): array {
		$packages=[];
		foreach($this->packages() as $package){
			$data=$package->toArray($this->runtime);
			$packages[$package->id()]=[
				'id'=>$data['id'],
				'version'=>$data['version'],
				'type'=>$data['type'],
				'status'=>$data['status'],
				'requirements'=>$data['requirements'],
				'provides'=>$data['provides'],
				'signature'=>$data['signature'] ?? [],
				'compatibility'=>$data['compatibility'],
				'source'=>$this->sources[$package->id()] ?? null,
			];
		}
		ksort($packages);
		$payload=[
			'type'=>'panel_package_lock',
			'generated_at'=>date('c'),
			'package_count'=>count($packages),
			'runtime'=>$this->runtime,
			'packages'=>$packages,
			'errors'=>$this->errors,
			'meta'=>array_replace($this->meta, $meta),
		];
		$payload['checksum']=hash('sha256', json_encode($packages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
		return $payload;
	}

	/**
	 * Serializes the current repository manifest with stored metadata.
	 *
	 * @return array<string, mixed> Repository manifest without extra call-site metadata.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Exposes the repository manifest to json_encode().
	 *
	 * @return array<string, mixed> Serializable repository manifest.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Scans a directory for package manifests.
	 *
	 * @param string $path Directory path to inspect.
	 * @param int $depth Remaining recursive depth.
	 * @return void Discovery mutates repository packages/errors.
	 */
	private function discoverPath(string $path, int $depth): void {
		$path=rtrim($path, "\\/");
		if($path==='' || !is_dir($path)){
			$this->errors[]=[
				'source'=>$path,
				'message'=>'Package path is not a readable directory.',
			];
			return;
		}
		$manifestPath=$path.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json';
		if(is_file($manifestPath)){
			$this->readManifest($manifestPath);
		}
		if($depth<=0){
			return;
		}
		$items=@scandir($path);
		if(!is_array($items)){
			return;
		}
		foreach($items as $item){
			if($item==='.' || $item==='..' || $item==='vendor' || $item==='node_modules'){
				continue;
			}
			$child=$path.DIRECTORY_SEPARATOR.$item;
			if(is_dir($child)){
				$this->discoverPath($child, $depth-1);
			}
		}
	}

	/**
	 * Reads and registers a package manifest file.
	 *
	 * @param string $path Manifest file path.
	 * @return void Invalid or unreadable manifests are recorded as errors.
	 */
	private function readManifest(string $path): void {
		$json=@file_get_contents($path);
		if(!is_string($json)){
			$this->errors[]=[
				'source'=>$path,
				'message'=>'Package manifest could not be read.',
			];
			return;
		}
		$data=json_decode($json, true);
		if(!is_array($data)){
			$this->errors[]=[
				'source'=>$path,
				'message'=>'Package manifest JSON is invalid.',
			];
			return;
		}
		$this->register($data, [], $path);
	}
}
