<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class ModuleDefinition implements \JsonSerializable {

	public function __construct(
		private readonly string $module,
		private readonly string $version='1.0',
		private readonly bool $enabled=true,
		private readonly ?string $directory=null,
		private readonly ?string $common_directory=null,
		private readonly ?string $app_directory=null,
		private readonly ?string $kernel_entry=null,
		private readonly ?string $framework_entry=null,
		private readonly ?string $framework_directory=null,
		private readonly ?string $framework_namespace=null
	){}

	public static function fromArray(array $definition): self {
		return new self(
			(string)($definition['module'] ?? ''),
			(string)($definition['version'] ?? '1.0'),
			($definition['enabled'] ?? true)===true,
			static::normalizePath($definition['directory'] ?? null),
			static::normalizePath($definition['common_directory'] ?? null),
			static::normalizePath($definition['app_directory'] ?? null),
			static::normalizePath($definition['kernel_entry'] ?? null),
			static::normalizePath($definition['framework_entry'] ?? null),
			static::normalizePath($definition['framework_directory'] ?? null),
			static::normalizeString($definition['framework_namespace'] ?? null)
		);
	}

	public function module(): string {
		return $this->module;
	}

	public function name(): string {
		return $this->module;
	}

	public function version(): string {
		return $this->version;
	}

	public function enabled(): bool {
		return $this->enabled;
	}

	public function directory(): ?string {
		return $this->directory;
	}

	public function commonDirectory(): ?string {
		return $this->common_directory;
	}

	public function appDirectory(): ?string {
		return $this->app_directory;
	}

	public function hasCommonSource(): bool {
		return $this->common_directory!==null;
	}

	public function hasAppSource(): bool {
		return $this->app_directory!==null;
	}

	public function isCommonOnly(): bool {
		return $this->hasCommonSource() && !$this->hasAppSource();
	}

	public function isAppOnly(): bool {
		return !$this->hasCommonSource() && $this->hasAppSource();
	}

	public function isHybrid(): bool {
		return $this->hasCommonSource() && $this->hasAppSource();
	}

	public function kernelEntry(): ?string {
		return $this->kernel_entry;
	}

	public function frameworkEntry(): ?string {
		return $this->framework_entry;
	}

	public function frameworkDirectory(): ?string {
		return $this->framework_directory;
	}

	public function frameworkNamespace(): ?string {
		return $this->framework_namespace;
	}

	public function hasKernel(): bool {
		return $this->kernel_entry!==null;
	}

	public function hasFramework(): bool {
		return $this->framework_directory!==null || $this->framework_entry!==null;
	}

	public function toArray(): array {
		return [
			'module'=>$this->module,
			'version'=>$this->version,
			'enabled'=>$this->enabled,
			'directory'=>$this->directory,
			'common_directory'=>$this->common_directory,
			'app_directory'=>$this->app_directory,
			'kernel_entry'=>$this->kernel_entry,
			'framework_entry'=>$this->framework_entry,
			'framework_directory'=>$this->framework_directory,
			'framework_namespace'=>$this->framework_namespace,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private static function normalizePath(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	private static function normalizeString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}
}
