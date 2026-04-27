<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class RuntimeState implements \JsonSerializable {

	public function __construct(
		private readonly bool $tracing_enabled,
		private readonly ?string $project_root,
		private readonly ?Application $application,
		private readonly array $application_roots,
		private readonly ApplicationCatalog $applications,
		private readonly ModuleCatalog $modules
	){}

	public function tracingEnabled(): bool {
		return $this->tracing_enabled;
	}

	public function projectRoot(): ?string {
		return $this->project_root;
	}

	public function hasApplication(): bool {
		return $this->application instanceof Application;
	}

	public function application(): ?Application {
		return $this->application;
	}

	public function applicationId(): ?string {
		return $this->application?->id;
	}

	public function applicationRoots(): array {
		return $this->application_roots;
	}

	public function applications(): ApplicationCatalog {
		return $this->applications;
	}

	public function modules(): ModuleCatalog {
		return $this->modules;
	}

	public function enabledModules(): ModuleCatalog {
		return $this->modules->enabled();
	}

	public function disabledModules(): ModuleCatalog {
		return $this->modules->disabled();
	}

	public function summary(): array {
		return [
			'tracing_enabled'=>$this->tracing_enabled,
			'project_root'=>$this->project_root,
			'application_id'=>$this->applicationId(),
			'application_root_count'=>count($this->application_roots),
			'application_count'=>$this->applications->count(),
			'module_count'=>$this->modules->count(),
			'enabled_module_count'=>$this->enabledModules()->count(),
			'disabled_module_count'=>$this->disabledModules()->count(),
		];
	}

	public function toArray(): array {
		return [
			'tracing_enabled'=>$this->tracing_enabled,
			'project_root'=>$this->project_root,
			'application'=>$this->application?->toArray(),
			'application_roots'=>$this->application_roots,
			'applications'=>$this->applications->toArray(),
			'modules'=>$this->modules->toArray(),
			'summary'=>$this->summary(),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
