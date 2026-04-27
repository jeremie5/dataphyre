<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class BootstrapPlan implements \JsonSerializable {

	public function __construct(
		private readonly ?string $project_root,
		private readonly Application $application
	){}

	public static function fromApplication(Application $application, ?string $project_root=null): self {
		return new self(
			static::normalizeProjectRoot($project_root ?? Runtime::projectRoot()),
			$application
		);
	}

	public function projectRoot(): ?string {
		return $this->project_root;
	}

	public function application(): Application {
		return $this->application;
	}

	public function applicationId(): string {
		return $this->application->id;
	}

	public function bootMode(): ?string {
		return $this->application->bootMode();
	}

	public function canBoot(): bool {
		return $this->application->canBoot();
	}

	public function usesCompiledRoutes(): bool {
		return $this->bootMode()==='compiled_routes';
	}

	public function usesFrameworkBootstrap(): bool {
		return $this->bootMode()==='framework';
	}

	public function usesLegacyBootstrap(): bool {
		return $this->bootMode()==='legacy';
	}

	public function fallbackToLegacyBootstrap(): bool {
		return $this->application->fallbackToLegacyBootstrap();
	}

	public function hasRootpathFile(): bool {
		return $this->application->hasRootpathFile();
	}

	public function rootpathPrimingRequired(): bool {
		return $this->hasRootpathFile() && defined('ROOTPATH')===false;
	}

	public function autoloadPrefixes(): array {
		return $this->application->autoloadPrefixes();
	}

	public function bootPaths(): array {
		$paths=[];
		if($this->application->hasCompiledRoutes()){
			$paths['compiled_routes']=$this->application->compiled_routes_file;
		}
		if($this->application->hasFrameworkBootstrap()){
			$paths['framework']=$this->application->framework_bootstrap_file;
		}
		if($this->application->hasLegacyBootstrap()){
			$paths['legacy']=$this->application->legacy_bootstrap_file;
		}
		return $paths;
	}

	public function availableBootModes(): array {
		return array_keys($this->bootPaths());
	}

	public function missingBootModes(): array {
		$missing=[];
		if(!$this->application->hasCompiledRoutes()){
			$missing[]='compiled_routes';
		}
		if(!$this->application->hasFrameworkBootstrap()){
			$missing[]='framework';
		}
		if(!$this->application->hasLegacyBootstrap()){
			$missing[]='legacy';
		}
		return $missing;
	}

	public function summary(): array {
		return [
			'project_root'=>$this->project_root,
			'application_id'=>$this->applicationId(),
			'boot_mode'=>$this->bootMode(),
			'can_boot'=>$this->canBoot(),
			'available_boot_modes'=>$this->availableBootModes(),
			'missing_boot_modes'=>$this->missingBootModes(),
			'rootpath_priming_required'=>$this->rootpathPrimingRequired(),
			'autoload_prefix_count'=>count($this->autoloadPrefixes()),
		];
	}

	public function boot(): void {
		if($this->project_root===null || trim($this->project_root)===''){
			throw new \RuntimeException("Project root is required to boot application {$this->applicationId()}.");
		}
		if(!$this->canBoot()){
			throw new \RuntimeException("Application {$this->applicationId()} has no executable bootstrap path.");
		}
		\dataphyre\runtime::boot($this->project_root, $this->applicationId());
	}

	public function toArray(): array {
		return [
			'project_root'=>$this->project_root,
			'application'=>$this->application->toArray(),
			'boot_paths'=>$this->bootPaths(),
			'summary'=>$this->summary(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private static function normalizeProjectRoot(?string $project_root): ?string {
		if(!is_string($project_root)){
			return null;
		}
		$project_root=trim($project_root);
		if($project_root===''){
			return null;
		}
		$resolved=realpath($project_root);
		return rtrim($resolved!==false ? $resolved : $project_root, '/\\');
	}
}
