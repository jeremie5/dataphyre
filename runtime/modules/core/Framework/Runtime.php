<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Static facade for current Dataphyre runtime state, catalogs, bootstrap plans, client address, and render traces.
 *
 * Runtime keeps framework callers away from lower-case kernel classes while preserving the same
 * discovery order for project roots, applications, modules, bootstraps, and optional SQL/template trace data.
 */
final class Runtime {

	/**
	 * Reports whether diagnostic tracing is enabled for the current runtime.
	 *
	 * @return bool False only when IS_PRODUCTION is defined as true.
	 */
	public static function tracingEnabled(): bool {
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

	/**
	 * Resolves the current project root.
	 *
	 * Runtime-provided project root takes precedence; ROOTPATH['root'] is used as a legacy fallback.
	 *
	 * @return ?string Normalized project root path, or null when no root is known.
	 */
	public static function projectRoot(): ?string {
		$root=\dataphyre\runtime::current_project_root();
		if($root!==null){
			return $root;
		}
		if(defined('ROOTPATH') && !empty(ROOTPATH['root'])){
			return rtrim((string)ROOTPATH['root'], '/\\');
		}
		return null;
	}

	/**
	 * Returns the current application id.
	 *
	 * @return ?string Current application id, or null outside an application context.
	 */
	public static function applicationId(): ?string {
		$application=static::application();
		return $application?->id;
	}

	/**
	 * Reports whether a current application can be resolved.
	 *
	 * @return bool True when application() returns an Application instance.
	 */
	public static function hasApplication(): bool {
		return static::application() instanceof Application;
	}

	/**
	 * Resolves the current application from the current project root.
	 *
	 * @return ?Application Current application descriptor, or null when none is active.
	 */
	public static function application(): ?Application {
		return Application::current(static::projectRoot());
	}

	/**
	 * Serializes the current application definition.
	 *
	 * @return ?array Application payload, or null when no application is active.
	 */
	public static function applicationDefinition(): ?array {
		$application=static::application();
		return $application?->toArray();
	}

	/**
	 * Returns application root directories under the current project root.
	 *
	 * @return array Application root paths, or an empty array when no project root is known.
	 */
	public static function applicationRoots(): array {
		$projectRoot=static::projectRoot();
		return $projectRoot!==null ? Application::roots($projectRoot) : [];
	}

	/**
	 * Returns application definitions available under the current project root.
	 *
	 * @return array Available application payloads, or an empty array when no project root is known.
	 */
	public static function availableApplications(): array {
		$projectRoot=static::projectRoot();
		return $projectRoot!==null ? Application::available($projectRoot) : [];
	}

	/**
	 * Builds an application catalog for the current project root.
	 *
	 * @return ApplicationCatalog Catalog of available applications.
	 */
	public static function applications(): ApplicationCatalog {
		return Application::catalog(static::projectRoot());
	}

	/**
	 * Builds a catalog of all known module definitions.
	 *
	 * @return ModuleCatalog Catalog containing enabled and disabled modules.
	 */
	public static function modules(): ModuleCatalog {
		return Module::catalog();
	}

	/**
	 * Builds a catalog of enabled module definitions.
	 *
	 * @return ModuleCatalog Catalog filtered to enabled modules.
	 */
	public static function enabledModules(): ModuleCatalog {
		return Module::enabledCatalog();
	}

	/**
	 * Builds a catalog of disabled module definitions.
	 *
	 * @return ModuleCatalog Catalog filtered to disabled modules.
	 */
	public static function disabledModules(): ModuleCatalog {
		return Module::disabledCatalog();
	}

	/**
	 * Captures a RuntimeState snapshot for diagnostics and runtime introspection.
	 *
	 * @return RuntimeState Snapshot of tracing, project, application, roots, application catalog, and module catalog.
	 */
	public static function state(): RuntimeState {
		return new RuntimeState(
			static::tracingEnabled(),
			static::projectRoot(),
			static::application(),
			static::applicationRoots(),
			static::applications(),
			static::modules()
		);
	}

	/**
	 * Resolves the current bootstrap plan for the project root.
	 *
	 * @return ?BootstrapPlan Current bootstrap plan, or null when none is active.
	 */
	public static function bootstrap(): ?BootstrapPlan {
		return Bootstrap::current(static::projectRoot());
	}

	/**
	 * Builds a catalog of bootstrap plans for the current project root.
	 *
	 * @return BootstrapCatalog Bootstrap catalog for diagnostics and tooling.
	 */
	public static function bootstraps(): BootstrapCatalog {
		return Bootstrap::catalog(static::projectRoot());
	}

	/**
	 * Returns the detected client IP address.
	 *
	 * @return string Client IP from ClientAddress detection.
	 */
	public static function clientIp(): string {
		return static::clientAddress()->ip();
	}

	/**
	 * Resolves the current client address descriptor.
	 *
	 * @return ClientAddress Client address object derived from the current request/server context.
	 */
	public static function clientAddress(): ClientAddress {
		return ClientAddress::current();
	}

	/**
	 * Builds a runtime trace from a trace id, rendered template object, or existing RuntimeTrace.
	 *
	 * When tracing is disabled, an empty trace is returned. Rendered objects may contribute a
	 * render trace id, template name, binding trace, and manifest; matching SQL traces are loaded
	 * only when the database tracing API is available.
	 *
	 * @param mixed $source RuntimeTrace, render trace id string, or rendered object exposing trace accessors.
	 * @param int $sqlLimit Maximum SQL traces to attach when a render trace id is available.
	 * @return RuntimeTrace Normalized runtime trace payload.
	 */
	public static function trace(mixed $source, int $sqlLimit=50): RuntimeTrace {
		if(static::tracingEnabled()!==true){
			return new RuntimeTrace(null, null, null, [], []);
		}
		if($source instanceof RuntimeTrace){
			return $source;
		}

		$renderTraceId=null;
		$templateName=null;
		$manifest=null;
		$bindingTrace=[];

		if(is_string($source)){
			$renderTraceId=static::normalizeTraceId($source);
		}elseif(is_object($source)){
			$renderTraceId=static::extractRenderTraceId($source);
			$templateName=static::extractTemplateName($source);
			$bindingTrace=static::extractBindingTrace($source);
			$manifest=static::extractManifest($source);
		}

		$sqlTraces=[];
		if(
			$renderTraceId!==null
			&& class_exists('Dataphyre\\Database\\DB', false)
			&& method_exists('Dataphyre\\Database\\DB', 'recentTracesByContext')
		){
			$sqlTraces=\Dataphyre\Database\DB::recentTracesByContext(
				['render_trace_id'=>$renderTraceId],
				max(1, $sqlLimit)
			);
		}

		return new RuntimeTrace(
			$renderTraceId,
			$templateName,
			$manifest,
			$bindingTrace,
			is_array($sqlTraces) ? $sqlTraces : []
		);
	}

	/**
	 * Builds a runtime trace from a render trace id.
	 *
	 * @param string $renderTraceId Render trace id.
	 * @param int $sqlLimit Maximum SQL traces to attach.
	 * @return RuntimeTrace Normalized runtime trace payload.
	 */
	public static function traceById(string $renderTraceId, int $sqlLimit=50): RuntimeTrace {
		return static::trace($renderTraceId, $sqlLimit);
	}

	/**
	 * Normalizes optional render trace ids.
	 *
	 * @param ?string $renderTraceId Candidate render trace id.
	 * @return ?string Trimmed trace id, or null when blank.
	 */
	private static function normalizeTraceId(?string $renderTraceId): ?string {
		if(!is_string($renderTraceId)){
			return null;
		}
		$renderTraceId=trim($renderTraceId);
		return $renderTraceId!=='' ? $renderTraceId : null;
	}

	/**
	 * Extracts a render trace id from a rendered object when supported.
	 *
	 * @param object $source Rendered object or trace-aware value.
	 * @return ?string Normalized render trace id.
	 */
	private static function extractRenderTraceId(object $source): ?string {
		if(method_exists($source, 'renderTraceId')){
			return static::normalizeTraceId($source->renderTraceId());
		}
		return null;
	}

	/**
	 * Extracts a non-empty template name from a rendered object when supported.
	 *
	 * @param object $source Rendered object or trace-aware value.
	 * @return ?string Template name.
	 */
	private static function extractTemplateName(object $source): ?string {
		if(method_exists($source, 'templateName')){
			$templateName=$source->templateName();
			if(is_string($templateName) && trim($templateName)!==''){
				return trim($templateName);
			}
		}
		return null;
	}

	/**
	 * Extracts binding trace rows from a rendered object when supported.
	 *
	 * @param object $source Rendered object or trace-aware value.
	 * @return array<int|string,mixed> binding trace rows exposed by the source object, or an empty array.
	 */
	private static function extractBindingTrace(object $source): array {
		if(method_exists($source, 'bindingTrace')){
			$bindingTrace=$source->bindingTrace();
			return is_array($bindingTrace) ? $bindingTrace : [];
		}
		return [];
	}

	/**
	 * Extracts an optional manifest payload from rendered or trace-aware objects.
	 *
	 * @param object $source Rendered object or manifest-aware value.
	 * @return ?array Manifest payload.
	 */
	private static function extractManifest(object $source): ?array {
		if(method_exists($source, 'manifest') && method_exists($source, 'hasManifest')){
			if($source->hasManifest()!==true){
				return null;
			}
			$manifest=$source->manifest();
			if(is_object($manifest) && method_exists($manifest, 'toArray')){
				$payload=$manifest->toArray();
				return is_array($payload) ? $payload : null;
			}
		}
		if(method_exists($source, 'toArray') && method_exists($source, 'bindingTrace')){
			$payload=$source->toArray();
			return is_array($payload) ? $payload : null;
		}
		return null;
	}
}
