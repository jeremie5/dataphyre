<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Runtime {

	public static function tracingEnabled(): bool {
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

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

	public static function applicationId(): ?string {
		$application=static::application();
		return $application?->id;
	}

	public static function hasApplication(): bool {
		return static::application() instanceof Application;
	}

	public static function application(): ?Application {
		return Application::current(static::projectRoot());
	}

	public static function applicationDefinition(): ?array {
		$application=static::application();
		return $application?->toArray();
	}

	public static function applicationRoots(): array {
		$project_root=static::projectRoot();
		return $project_root!==null ? Application::roots($project_root) : [];
	}

	public static function availableApplications(): array {
		$project_root=static::projectRoot();
		return $project_root!==null ? Application::available($project_root) : [];
	}

	public static function applications(): ApplicationCatalog {
		return Application::catalog(static::projectRoot());
	}

	public static function modules(): ModuleCatalog {
		return Module::catalog();
	}

	public static function enabledModules(): ModuleCatalog {
		return Module::enabledCatalog();
	}

	public static function disabledModules(): ModuleCatalog {
		return Module::disabledCatalog();
	}

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

	public static function bootstrap(): ?BootstrapPlan {
		return Bootstrap::current(static::projectRoot());
	}

	public static function bootstraps(): BootstrapCatalog {
		return Bootstrap::catalog(static::projectRoot());
	}

	public static function clientIp(): string {
		return static::clientAddress()->ip();
	}

	public static function clientAddress(): ClientAddress {
		return ClientAddress::current();
	}

	public static function trace(mixed $source, int $sql_limit=50): RuntimeTrace {
		if(static::tracingEnabled()!==true){
			return new RuntimeTrace(null, null, null, [], []);
		}
		if($source instanceof RuntimeTrace){
			return $source;
		}

		$render_trace_id=null;
		$template_name=null;
		$manifest=null;
		$binding_trace=[];

		if(is_string($source)){
			$render_trace_id=static::normalizeTraceId($source);
		}elseif(is_object($source)){
			$render_trace_id=static::extractRenderTraceId($source);
			$template_name=static::extractTemplateName($source);
			$binding_trace=static::extractBindingTrace($source);
			$manifest=static::extractManifest($source);
		}

		$sql_traces=[];
		if(
			$render_trace_id!==null
			&& class_exists('Dataphyre\\Database\\DB', false)
			&& method_exists('Dataphyre\\Database\\DB', 'recentTracesByContext')
		){
			$sql_traces=\Dataphyre\Database\DB::recentTracesByContext(
				['render_trace_id'=>$render_trace_id],
				max(1, $sql_limit)
			);
		}

		return new RuntimeTrace(
			$render_trace_id,
			$template_name,
			$manifest,
			$binding_trace,
			is_array($sql_traces) ? $sql_traces : []
		);
	}

	public static function traceById(string $render_trace_id, int $sql_limit=50): RuntimeTrace {
		return static::trace($render_trace_id, $sql_limit);
	}

	private static function normalizeTraceId(?string $render_trace_id): ?string {
		if(!is_string($render_trace_id)){
			return null;
		}
		$render_trace_id=trim($render_trace_id);
		return $render_trace_id!=='' ? $render_trace_id : null;
	}

	private static function extractRenderTraceId(object $source): ?string {
		if(method_exists($source, 'renderTraceId')){
			return static::normalizeTraceId($source->renderTraceId());
		}
		return null;
	}

	private static function extractTemplateName(object $source): ?string {
		if(method_exists($source, 'templateName')){
			$template_name=$source->templateName();
			if(is_string($template_name) && trim($template_name)!==''){
				return trim($template_name);
			}
		}
		return null;
	}

	private static function extractBindingTrace(object $source): array {
		if(method_exists($source, 'bindingTrace')){
			$binding_trace=$source->bindingTrace();
			return is_array($binding_trace) ? $binding_trace : [];
		}
		return [];
	}

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
