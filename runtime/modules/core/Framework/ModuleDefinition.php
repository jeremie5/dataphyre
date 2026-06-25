<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable value object describing one Dataphyre runtime module.
 *
 * A definition captures the module name, version, enabled state, source directories, kernel entry,
 * framework entry/directory, and framework namespace exactly as normalized from the registry.
 */
final class ModuleDefinition implements \JsonSerializable {

	/** @var ?array<string, mixed> Cached registry-compatible serialization payload. */
	private ?array $arrayPayload=null;

	/**
	 * Creates a module definition instance from already-normalized values.
	 *
	 * @param string $module Stable module name.
	 * @param string $version Module version reported by the registry.
	 * @param bool $enabled Whether the module is enabled for the current runtime.
	 * @param ?string $directory Module root directory.
	 * @param ?string $commonDirectory Shared/common source directory.
	 * @param ?string $appDirectory Application-specific source directory.
	 * @param ?string $kernelEntry Kernel entry file.
	 * @param ?string $frameworkEntry Framework entry file.
	 * @param ?string $frameworkDirectory Framework source directory.
	 * @param ?string $frameworkNamespace PascalCase framework namespace exposed by the module.
	 */
	public function __construct(
		private readonly string $module,
		private readonly string $version='1.0',
		private readonly bool $enabled=true,
		private readonly ?string $directory=null,
		private readonly ?string $commonDirectory=null,
		private readonly ?string $appDirectory=null,
		private readonly ?string $kernelEntry=null,
		private readonly ?string $frameworkEntry=null,
		private readonly ?string $frameworkDirectory=null,
		private readonly ?string $frameworkNamespace=null
	){}

	/**
	 * Hydrates a module definition from a registry array.
	 *
	 * Empty path and string fields are normalized to null. Missing version defaults to `1.0`,
	 * and missing enabled state defaults to true to match legacy registry behavior.
	 *
	 * @param array{module?:string,version?:string,enabled?:bool,directory?:string|null,common_directory?:string|null,app_directory?:string|null,kernel_entry?:string|null,framework_entry?:string|null,framework_directory?:string|null,framework_namespace?:string|null} $definition Raw module definition from module_registry.
	 * @return self Normalized immutable definition.
	 */
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

	/**
	 * Returns the module name.
	 *
	 * @return string Stable module name.
	 */
	public function module(): string {
		return $this->module;
	}

	/**
	 * Returns the module name.
	 *
	 * This alias exists for collection and catalog code that uses generic `name()` accessors.
	 *
	 * @return string Stable module name.
	 */
	public function name(): string {
		return $this->module;
	}

	/**
	 * Returns the module version string.
	 *
	 * @return string Version reported by the registry.
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Reports whether the module is enabled.
	 *
	 * @return bool True when enabled for the current runtime.
	 */
	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Returns the module root directory.
	 *
	 * @return ?string Root directory, or null when the registry did not provide one.
	 */
	public function directory(): ?string {
		return $this->directory;
	}

	/**
	 * Returns the shared/common source directory.
	 *
	 * @return ?string Common source directory, or null when absent.
	 */
	public function commonDirectory(): ?string {
		return $this->commonDirectory;
	}

	/**
	 * Returns the application-specific source directory.
	 *
	 * @return ?string App source directory, or null when absent.
	 */
	public function appDirectory(): ?string {
		return $this->appDirectory;
	}

	/**
	 * Reports whether common/shared source is available.
	 *
	 * @return bool True when commonDirectory() is not null.
	 */
	public function hasCommonSource(): bool {
		return $this->commonDirectory!==null;
	}

	/**
	 * Reports whether application-specific source is available.
	 *
	 * @return bool True when appDirectory() is not null.
	 */
	public function hasAppSource(): bool {
		return $this->appDirectory!==null;
	}

	/**
	 * Reports whether the module has common source without app source.
	 *
	 * @return bool True for common-only modules.
	 */
	public function isCommonOnly(): bool {
		return $this->hasCommonSource() && !$this->hasAppSource();
	}

	/**
	 * Reports whether the module has app source without common source.
	 *
	 * @return bool True for app-only modules.
	 */
	public function isAppOnly(): bool {
		return !$this->hasCommonSource() && $this->hasAppSource();
	}

	/**
	 * Reports whether the module combines common and app source.
	 *
	 * @return bool True for hybrid modules.
	 */
	public function isHybrid(): bool {
		return $this->hasCommonSource() && $this->hasAppSource();
	}

	/**
	 * Returns the kernel entry file.
	 *
	 * @return ?string Kernel entry path, or null when no kernel entry is declared.
	 */
	public function kernelEntry(): ?string {
		return $this->kernelEntry;
	}

	/**
	 * Returns the framework entry file.
	 *
	 * @return ?string Framework entry path, or null when no framework entry is declared.
	 */
	public function frameworkEntry(): ?string {
		return $this->frameworkEntry;
	}

	/**
	 * Returns the framework source directory.
	 *
	 * @return ?string Framework directory, or null when absent.
	 */
	public function frameworkDirectory(): ?string {
		return $this->frameworkDirectory;
	}

	/**
	 * Returns the framework namespace exposed by the module.
	 *
	 * @return ?string Namespace string, or null when the module has no framework API namespace.
	 */
	public function frameworkNamespace(): ?string {
		return $this->frameworkNamespace;
	}

	/**
	 * Reports whether the definition declares a kernel entry.
	 *
	 * @return bool True when kernelEntry() is not null.
	 */
	public function hasKernel(): bool {
		return $this->kernelEntry!==null;
	}

	/**
	 * Reports whether the definition declares framework source or an entry file.
	 *
	 * @return bool True when a framework directory or framework entry is available.
	 */
	public function hasFramework(): bool {
		return $this->frameworkDirectory!==null || $this->frameworkEntry!==null;
	}

	/**
	 * Serializes the definition to the registry-compatible array shape.
	 *
	 * @return array{module:string,version:string,enabled:bool,directory:?string,common_directory:?string,app_directory:?string,kernel_entry:?string,framework_entry:?string,framework_directory:?string,framework_namespace:?string} Module definition payload.
	 */
	public function toArray(): array {
		return $this->arrayPayload ??= [
			'module'=>$this->module,
			'version'=>$this->version,
			'enabled'=>$this->enabled,
			'directory'=>$this->directory,
			'common_directory'=>$this->commonDirectory,
			'app_directory'=>$this->appDirectory,
			'kernel_entry'=>$this->kernelEntry,
			'framework_entry'=>$this->frameworkEntry,
			'framework_directory'=>$this->frameworkDirectory,
			'framework_namespace'=>$this->frameworkNamespace,
		];
	}

	/**
	 * Serializes the definition for json_encode().
	 *
	 * @return array<string,mixed> module registry payload with paths, entry files, namespace, version, and enabled flag.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes optional module path values from the registry.
	 *
	 * @param mixed $value Raw registry value.
	 * @return ?string Trimmed non-empty path, or null when absent.
	 */
	private static function normalizePath(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Normalizes optional module string metadata from the registry.
	 *
	 * @param mixed $value Raw registry value.
	 * @return ?string Trimmed non-empty string, or null when absent.
	 */
	private static function normalizeString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}
}
