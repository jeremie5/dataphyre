<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable snapshot of Dataphyre bootstrap/runtime discovery state.
 *
 * RuntimeState captures the core facts discovered during bootstrap: tracing mode, project root, active application,
 * application roots, application catalog, and module catalog. The object is read-only and intended for diagnostics,
 * health checks, and framework code that needs a stable view of runtime topology.
 *
 * Catalog objects retain their own filtering and serialization behavior. RuntimeState only aggregates them and provides a
 * compact summary so callers can inspect high-level status without walking every application or module record.
 */
final class RuntimeState implements \JsonSerializable {

	/** @var array{tracing_enabled:bool, project_root:?string, application_id:?string, application_root_count:int, application_count:int, module_count:int, enabled_module_count:int, disabled_module_count:int}|null */
	private ?array $summary=null;

	/**
	 * Creates a runtime state snapshot.
	 *
	 * @param bool $tracingEnabled Whether runtime tracing was enabled at snapshot time.
	 * @param ?string $projectRoot Project root path, when discovered.
	 * @param ?Application $application Active application, when one is selected.
	 * @param array<string, string> $applicationRoots Application root paths keyed by application id or name.
	 * @param ApplicationCatalog $applications Discovered application catalog.
	 * @param ModuleCatalog $modules Discovered module catalog.
	 */
	public function __construct(
		private readonly bool $tracingEnabled,
		private readonly ?string $projectRoot,
		private readonly ?Application $application,
		private readonly array $applicationRoots,
		private readonly ApplicationCatalog $applications,
		private readonly ModuleCatalog $modules
	){}

	/**
	 * Reports whether tracing was enabled for this runtime snapshot.
	 *
	 * @return bool Tracing flag captured at bootstrap/discovery time.
	 */
	public function tracingEnabled(): bool {
		return $this->tracingEnabled;
	}

	/**
	 * Returns the discovered project root path.
	 *
	 * @return ?string Project root, or null when unavailable.
	 */
	public function projectRoot(): ?string {
		return $this->projectRoot;
	}

	/**
	 * Reports whether an active application is attached.
	 *
	 * @return bool Whether application() returns an Application instance.
	 */
	public function hasApplication(): bool {
		return $this->application instanceof Application;
	}

	/**
	 * Returns the active application record.
	 *
	 * @return ?Application Active application, or null outside an application context.
	 */
	public function application(): ?Application {
		return $this->application;
	}

	/**
	 * Returns the active application id.
	 *
	 * @return ?string Application id, or null when no active application is attached.
	 */
	public function applicationId(): ?string {
		return $this->application?->id;
	}

	/**
	 * Returns discovered application root paths.
	 *
	 * @return array<string, string> Application roots keyed by application id or name.
	 */
	public function applicationRoots(): array {
		return $this->applicationRoots;
	}

	/**
	 * Returns the full application catalog.
	 *
	 * @return ApplicationCatalog Discovered applications.
	 */
	public function applications(): ApplicationCatalog {
		return $this->applications;
	}

	/**
	 * Returns the full module catalog.
	 *
	 * @return ModuleCatalog Discovered modules.
	 */
	public function modules(): ModuleCatalog {
		return $this->modules;
	}

	/**
	 * Returns a catalog containing only enabled modules.
	 *
	 * @return ModuleCatalog Enabled-module catalog view.
	 */
	public function enabledModules(): ModuleCatalog {
		return $this->modules->enabled();
	}

	/**
	 * Returns a catalog containing only disabled modules.
	 *
	 * @return ModuleCatalog Disabled-module catalog view.
	 */
	public function disabledModules(): ModuleCatalog {
		return $this->modules->disabled();
	}

	/**
	 * Returns a compact diagnostic summary of the runtime topology.
	 *
	 * @return array{tracing_enabled:bool, project_root:?string, application_id:?string, application_root_count:int, application_count:int, module_count:int, enabled_module_count:int, disabled_module_count:int} Runtime summary.
	 */
	public function summary(): array {
		if($this->summary!==null){
			return $this->summary;
		}
		$moduleCounts=$this->modules->enabledDisabledCounts();
		return $this->summary=[
			'tracing_enabled'=>$this->tracingEnabled,
			'project_root'=>$this->projectRoot,
			'application_id'=>$this->applicationId(),
			'application_root_count'=>count($this->applicationRoots),
			'application_count'=>$this->applications->count(),
			'module_count'=>$this->modules->count(),
			'enabled_module_count'=>$moduleCounts['enabled'],
			'disabled_module_count'=>$moduleCounts['disabled'],
		];
	}

	/**
	 * Serializes the full runtime snapshot.
	 *
	 * @return array{tracing_enabled:bool, project_root:?string, application:?array, application_roots:array<string, string>, applications:array<string, mixed>, modules:array<string, mixed>, summary:array<string, mixed>} Runtime state payload.
	 */
	public function toArray(): array {
		return [
			'tracing_enabled'=>$this->tracingEnabled,
			'project_root'=>$this->projectRoot,
			'application'=>$this->application?->toArray(),
			'application_roots'=>$this->applicationRoots,
			'applications'=>$this->applications->toArray(),
			'modules'=>$this->modules->toArray(),
			'summary'=>$this->summary(),
		];
	}

	/**
	 * Serializes the runtime snapshot for json_encode().
	 *
	 * @return array<string, mixed> Runtime state payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
