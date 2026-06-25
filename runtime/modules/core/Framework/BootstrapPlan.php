<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable description of how one Dataphyre application can be booted from the current runtime.
 *
 * BootstrapPlan captures the normalized project root, application identity, available bootstrap files, selected boot mode,
 * and ROOTPATH priming requirement before runtime boot is attempted. It gives diagnostics and Flightdeck a structured
 * view of boot readiness while keeping the mutating boot() call explicit and failure-checked.
 */
final class BootstrapPlan implements \JsonSerializable {

	/** @var array{project_root: ?string, application_id: string, boot_mode: ?string, can_boot: bool, available_boot_modes: list<string>, missing_boot_modes: list<string>, rootpath_priming_required: bool, autoload_prefix_count: int}|null */
	private ?array $summaryPayload=null;

	/**
	 * Stores the normalized project root and application descriptor used by the boot decision.
	 *
	 * @param ?string $projectRoot Absolute or caller-supplied root directory, or null when booting cannot be attempted.
	 * @param Application $application Application descriptor with bootstrap files and boot policy.
	 */
	public function __construct(
		private readonly ?string $projectRoot,
		private readonly Application $application
	){}

	/**
	 * Creates a plan from an application descriptor and optional project root override.
	 *
	 * When no project root is supplied, the current Runtime project root is used. The root is normalized once so every
	 * readiness check, diagnostic export, and boot attempt reports the same path.
	 *
	 * @param Application $application Application descriptor to plan for.
	 * @param ?string $projectRoot Optional project root override.
	 * @return self Bootstrap plan for the supplied application.
	 */
	public static function fromApplication(Application $application, ?string $projectRoot=null): self {
		return new self(
			static::normalizeProjectRoot($projectRoot ?? Runtime::projectRoot()),
			$application
		);
	}

	/**
	 * Returns the normalized root directory that will be passed to the runtime boot call.
	 *
	 * @return ?string Normalized project root, or null when no usable root was discovered.
	 */
	public function projectRoot(): ?string {
		return $this->projectRoot;
	}

	/**
	 * Returns the application descriptor backing this plan.
	 *
	 * @return Application Application metadata and bootstrap path provider.
	 */
	public function application(): Application {
		return $this->application;
	}

	/**
	 * Returns the application identifier used by runtime boot and diagnostics.
	 *
	 * @return string Application id from the descriptor.
	 */
	public function applicationId(): string {
		return $this->application->id;
	}

	/**
	 * Returns the descriptor-selected boot mode.
	 *
	 * @return ?string Boot mode such as compiled_routes, framework, or legacy; null when no boot path is available.
	 */
	public function bootMode(): ?string {
		return $this->application->bootMode();
	}

	/**
	 * Reports whether the application descriptor exposes at least one executable bootstrap path.
	 *
	 * @return bool True when runtime boot has a selected application boot path.
	 */
	public function canBoot(): bool {
		return $this->application->canBoot();
	}

	/**
	 * Reports whether boot will use the compiled routes bootstrap path.
	 *
	 * @return bool True when the selected boot mode is compiled_routes.
	 */
	public function usesCompiledRoutes(): bool {
		return $this->bootMode()==='compiled_routes';
	}

	/**
	 * Reports whether boot will use the framework bootstrap path.
	 *
	 * @return bool True when the selected boot mode is framework.
	 */
	public function usesFrameworkBootstrap(): bool {
		return $this->bootMode()==='framework';
	}

	/**
	 * Reports whether boot will use the legacy bootstrap path.
	 *
	 * @return bool True when the selected boot mode is legacy.
	 */
	public function usesLegacyBootstrap(): bool {
		return $this->bootMode()==='legacy';
	}

	/**
	 * Reports whether the descriptor allows legacy bootstrap fallback.
	 *
	 * @return bool True when legacy fallback is enabled for this application.
	 */
	public function fallbackToLegacyBootstrap(): bool {
		return $this->application->fallbackToLegacyBootstrap();
	}

	/**
	 * Reports whether the application still provides a rootpath compatibility file.
	 *
	 * @return bool True when the descriptor points to a rootpath file.
	 */
	public function hasRootpathFile(): bool {
		return $this->application->hasRootpathFile();
	}

	/**
	 * Reports whether ROOTPATH must be primed before booting legacy-compatible application code.
	 *
	 * @return bool True when a rootpath file exists and ROOTPATH has not already been defined.
	 */
	public function rootpathPrimingRequired(): bool {
		return $this->hasRootpathFile() && defined('ROOTPATH')===false;
	}

	/**
	 * Returns the application autoload prefixes that should be available during boot.
	 *
	 * @return array<string, string|list<string>> Autoload prefix map from the application descriptor.
	 */
	public function autoloadPrefixes(): array {
		return $this->application->autoloadPrefixes();
	}

	/**
	 * Returns readable bootstrap paths grouped by boot mode.
	 *
	 * @return array<string, string> Available boot mode names mapped to their descriptor file paths.
	 */
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

	/**
	 * Returns the boot mode names available to this application.
	 *
	 * @return list<string> Available boot modes in descriptor priority order.
	 */
	public function availableBootModes(): array {
		return array_keys($this->bootPaths());
	}

	/**
	 * Returns the standard boot modes absent from the application descriptor.
	 *
	 * @return list<string> Missing mode names among compiled_routes, framework, and legacy.
	 */
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

	/**
	 * Builds a compact boot readiness summary for diagnostics and tooling.
	 *
	 * @return array{project_root: ?string, application_id: string, boot_mode: ?string, can_boot: bool, available_boot_modes: list<string>, missing_boot_modes: list<string>, rootpath_priming_required: bool, autoload_prefix_count: int}
	 */
	public function summary(): array {
		if($this->summaryPayload!==null){
			return $this->summaryPayload;
		}
		$application=$this->application;
		$hasCompiledRoutes=$application->hasCompiledRoutes();
		$hasFrameworkBootstrap=$application->hasFrameworkBootstrap();
		$hasLegacyBootstrap=$application->hasLegacyBootstrap();
		$fallbackToLegacy=$application->fallbackToLegacyBootstrap();
		$availableBootModes=[];
		$missingBootModes=[];
		if($hasCompiledRoutes){
			$availableBootModes[]='compiled_routes';
		}
		else{
			$missingBootModes[]='compiled_routes';
		}
		if($hasFrameworkBootstrap){
			$availableBootModes[]='framework';
		}
		else{
			$missingBootModes[]='framework';
		}
		if($hasLegacyBootstrap){
			$availableBootModes[]='legacy';
		}
		else{
			$missingBootModes[]='legacy';
		}
		$bootMode=null;
		if($hasCompiledRoutes){
			$bootMode='compiled_routes';
		}
		elseif($hasFrameworkBootstrap){
			$bootMode='framework';
		}
		elseif($fallbackToLegacy && $hasLegacyBootstrap){
			$bootMode='legacy';
		}
		return $this->summaryPayload=[
			'project_root'=>$this->projectRoot,
			'application_id'=>$application->id,
			'boot_mode'=>$bootMode,
			'can_boot'=>$bootMode!==null,
			'available_boot_modes'=>$availableBootModes,
			'missing_boot_modes'=>$missingBootModes,
			'rootpath_priming_required'=>$application->hasRootpathFile() && defined('ROOTPATH')===false,
			'autoload_prefix_count'=>count($application->autoload),
		];
	}

	/**
	 * Boots the planned application through the global Dataphyre runtime.
	 *
	 * This is the only mutating operation on the plan. It validates that a project root and executable application boot path
	 * exist before delegating to runtime::boot(), which may define globals, load modules, register routes, and mutate shared
	 * runtime state.
	 *
	 * @throws \RuntimeException When the project root is missing or the application has no executable bootstrap path.
	 */
	public function boot(): void {
		if($this->projectRoot===null || trim($this->projectRoot)===''){
			throw new \RuntimeException("Project root is required to boot application {$this->applicationId()}.");
		}
		if(!$this->canBoot()){
			throw new \RuntimeException("Application {$this->applicationId()} has no executable bootstrap path.");
		}
		\dataphyre\runtime::boot($this->projectRoot, $this->applicationId());
	}

	/**
	 * Exports project root, application metadata, boot paths, and readiness summary.
	 *
	 * @return array{project_root: ?string, application: array<string, mixed>, boot_paths: array<string, string>, summary: array<string, mixed>}
	 */
	public function toArray(): array {
		$application=$this->application;
		$hasCompiledRoutes=$application->hasCompiledRoutes();
		$hasFrameworkBootstrap=$application->hasFrameworkBootstrap();
		$hasLegacyBootstrap=$application->hasLegacyBootstrap();
		$fallbackToLegacy=$application->fallbackToLegacyBootstrap();
		$bootPaths=[];
		$availableBootModes=[];
		$missingBootModes=[];
		if($hasCompiledRoutes){
			$bootPaths['compiled_routes']=$application->compiled_routes_file;
			$availableBootModes[]='compiled_routes';
		}
		else{
			$missingBootModes[]='compiled_routes';
		}
		if($hasFrameworkBootstrap){
			$bootPaths['framework']=$application->framework_bootstrap_file;
			$availableBootModes[]='framework';
		}
		else{
			$missingBootModes[]='framework';
		}
		if($hasLegacyBootstrap){
			$bootPaths['legacy']=$application->legacy_bootstrap_file;
			$availableBootModes[]='legacy';
		}
		else{
			$missingBootModes[]='legacy';
		}
		$bootMode=null;
		if($hasCompiledRoutes){
			$bootMode='compiled_routes';
		}
		elseif($hasFrameworkBootstrap){
			$bootMode='framework';
		}
		elseif($fallbackToLegacy && $hasLegacyBootstrap){
			$bootMode='legacy';
		}
		return [
			'project_root'=>$this->projectRoot,
			'application'=>$application->toArray(),
			'boot_paths'=>$bootPaths,
			'summary'=>[
				'project_root'=>$this->projectRoot,
				'application_id'=>$application->id,
				'boot_mode'=>$bootMode,
				'can_boot'=>$bootMode!==null,
				'available_boot_modes'=>$availableBootModes,
				'missing_boot_modes'=>$missingBootModes,
				'rootpath_priming_required'=>$application->hasRootpathFile() && defined('ROOTPATH')===false,
				'autoload_prefix_count'=>count($application->autoload),
			],
		];
	}

	/**
	 * Serializes the bootstrap plan for JSON diagnostics.
	 *
	 * @return array{project_root: ?string, application: array<string, mixed>, boot_paths: array<string, string>, summary: array<string, mixed>}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes a candidate project root into a stable path string.
	 *
	 * Existing paths are resolved through realpath(); non-existing but non-empty roots are preserved after trimming so
	 * diagnostics can still show the caller's intended root.
	 *
	 * @param ?string $projectRoot Candidate project root.
	 * @return ?string Normalized root path, or null when input is empty.
	 */
	private static function normalizeProjectRoot(?string $projectRoot): ?string {
		if(!is_string($projectRoot)){
			return null;
		}
		$projectRoot=trim($projectRoot);
		if($projectRoot===''){
			return null;
		}
		$resolved=realpath($projectRoot);
		return rtrim($resolved!==false ? $resolved : $projectRoot, '/\\');
	}
}
