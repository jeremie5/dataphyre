<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Dataphyre
 * SPDX-License-Identifier: MIT
 */

if(!function_exists('tracelog')){
	function tracelog(...$args): void {}
}

if(!function_exists('sql_select')){
	function sql_select(mixed $columns, string $table, ?string $params=null, ?array $values=null, bool $all=false, mixed $caching=false): array|false {
		$rows=[];
		foreach(($values ?? []) as $value){
			$id=trim((string)$value);
			if($id===''){
				continue;
			}
			$rows[]=[
				'id'=>$id,
				'title'=>'Catalog item '.$id,
			];
		}
		return $rows;
	}
}

if(!defined('RUN_MODE')){
	define('RUN_MODE', 'benchmark');
}

require_once __DIR__.'/../../../runtime/modules/core/kernel/helper_functions.php';
if(!defined('CFG')){
	define('CFG', new class implements ArrayAccess {
		private array $config=[];

		public function &raw(): array {
			return $this->config;
		}

		public function offsetExists(mixed $offset): bool {
			return isset($this->config[$offset]);
		}

		public function offsetGet(mixed $offset): mixed {
			return $this->config[$offset] ?? null;
		}

		public function offsetSet(mixed $offset, mixed $value): void {
			if($offset===null){
				$this->config[]=$value;
				return;
			}
			$this->config[$offset]=$value;
		}

		public function offsetUnset(mixed $offset): void {
			unset($this->config[$offset]);
		}
	});
}
require_once __DIR__.'/../../../runtime/modules/core/kernel/core_functions.php';
require_once __DIR__.'/../../../runtime/modules/routing/kernel/compiled_route_dispatcher.php';
require_once __DIR__.'/../../../runtime/modules/routing/Framework/Route.php';
require_once __DIR__.'/../../../runtime/modules/routing/Framework/RouteCompiler.php';
require_once __DIR__.'/../../../runtime/modules/routing/Framework/RouteManifest.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/RuntimeTrace.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/UrlValue.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/DateValue.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/Env.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/EnvRepository.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/EnvSnapshot.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/Config.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/ConfigRepository.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/ConfigSnapshot.php';
if(!defined('ROOTPATH')){
	define('ROOTPATH', [
		'root'=>dirname(__DIR__, 5).'/',
		'dataphyre'=>dirname(__DIR__, 3).'/',
		'common_dataphyre'=>dirname(__DIR__, 3).'/',
		'common_dataphyre_runtime'=>dirname(__DIR__, 3).'/runtime/',
	]);
}
if(!function_exists('sql_define_table')){
	function sql_define_table(...$args): void {}
}
if(!defined('DP_VESTRA_CFG')){
	define('DP_VESTRA_CFG', [
		'base_url'=>'https://vestra.example.com/',
		'object_url'=>'https://vestra.example.com/',
		'default_tenant'=>'dataphyre-bench-tenant',
		'use_tenant_grant'=>true,
		'allow_unsigned'=>true,
		'tenants'=>[
			'dataphyre-bench-tenant'=>[
				'tenant'=>'dataphyre-bench-tenant',
				'rate'=>'s.p',
				'object_url'=>'https://vestra.example.com/',
				'allow_unsigned'=>true,
			],
		],
	]);
}
require_once __DIR__.'/../../../runtime/modules/vestra/kernel/vestra.main.php';
require_once __DIR__.'/../../../runtime/modules/vestra/Framework/VestraManager.php';
require_once __DIR__.'/../../../runtime/modules/vestra/Framework/Client.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/DialbackEvent.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/DialbackCatalog.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/ClientAddress.php';
require_once __DIR__.'/../../../runtime/modules/core/kernel/application_definition.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/Application.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/ApplicationCatalog.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/BootstrapPlan.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/BootstrapCatalog.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/ModuleDefinition.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/ModuleCatalog.php';
require_once __DIR__.'/../../../runtime/modules/core/Framework/RuntimeState.php';
require_once __DIR__.'/../../../runtime/modules/localization/Framework/LocaleDefinition.php';
require_once __DIR__.'/../../../runtime/modules/localization/Framework/LocaleDefinitionCatalog.php';
require_once __DIR__.'/../../../runtime/modules/http/Framework/ActionArguments.php';
require_once __DIR__.'/../../../runtime/modules/http/Framework/UploadedFile.php';
require_once __DIR__.'/../../../runtime/modules/http/Framework/Request.php';
require_once __DIR__.'/../../../runtime/modules/http/Framework/Response.php';
require_once __DIR__.'/../../../runtime/modules/api/Framework/ApiContext.php';
require_once __DIR__.'/../../../runtime/modules/api/Framework/SecurityScheme.php';
require_once __DIR__.'/../../../runtime/modules/api/Framework/Endpoint.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/ResponseResult.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/Controller.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/RedirectResult.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/MvcDispatcher.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/Session.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/ViewResult.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/Validator.php';
require_once __DIR__.'/../../../runtime/modules/mvc/Framework/Mvc.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/PermissionRule.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/PermissionSet.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/PermissionNamer.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/PermissionSnapshot.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/PermissionManifest.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/PermissionOptimizer.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/PermissionSimulator.php';
require_once __DIR__.'/../../../runtime/modules/permission/Framework/SubjectResolver.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/DB.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/SqlError.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/ExecutionTrace.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/QuerySpec.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/TableSchema.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/TableDefinition.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/PageResult.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/MutationResult.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/MutationBatchResult.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/TransactionResult.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/Record.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/CurrencyBridge.php';
require_once __DIR__.'/../../../runtime/modules/sql/Framework/Concerns/TransformsRows.php';
require_once __DIR__.'/../../../runtime/modules/sanitation/Framework/PresetRegistry.php';
require_once __DIR__.'/../../../runtime/modules/sanitation/Framework/Sanitation.php';
require_once __DIR__.'/../../../runtime/modules/sanitation/Framework/SanitationManager.php';
require_once __DIR__.'/../../../runtime/modules/sanitation/Framework/Sanitizer.php';
require_once __DIR__.'/../../../runtime/modules/sanitation/Framework/SanitizationResult.php';
require_once __DIR__.'/../../../runtime/modules/sanitation/Framework/InputBag.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/DataBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/BindingMetadataProvider.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/BindingCacheIdentityProvider.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/BindingPersistentCacheProvider.php';
require_once __DIR__.'/../../../runtime/modules/api/Framework/ApiCallableBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/BindingContext.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/BindingResolution.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/CallableBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/CachedBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/ConditionalBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/RememberedBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/SearchQueryBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/SqlQueryBinding.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/AssetPolicy.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/AssetManifest.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/TemplateContract.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/TemplatingState.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/RenderedTemplate.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/TemplateManifest.php';
require_once __DIR__.'/../../../runtime/modules/templating/Framework/TemplatePlan.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/kernel/fulltext_engine.main.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/SearchHit.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/SearchResults.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/HydratedSearchHit.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/HydratedSearchResults.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/IndexDefinition.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/IndexSyncReport.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/Contracts/DocumentResolver.php';
require_once __DIR__.'/../../../runtime/modules/fulltext_engine/Framework/Resolvers/TableDocumentResolver.php';
require_once __DIR__.'/../../../runtime/modules/reactor/Framework/Support/ReactorName.php';
require_once __DIR__.'/../../../runtime/modules/reactor/Framework/Validation/ReactorValidator.php';
require_once __DIR__.'/../../../runtime/modules/reactor/Framework/State/ReactorSnapshot.php';
require_once __DIR__.'/../../../runtime/modules/mailer/Framework/Message.php';
require_once __DIR__.'/../../../runtime/modules/mailer/Framework/SendResult.php';
require_once __DIR__.'/../../../runtime/modules/access/Framework/OAuthClient/Manager.php';
require_once __DIR__.'/../../../runtime/modules/access/Framework/OAuthClient/Provider.php';
require_once __DIR__.'/../../../runtime/modules/access/Framework/Exceptions/AuthenticationException.php';
require_once __DIR__.'/../../../runtime/modules/access/Framework/Jwt/JwtPayload.php';
require_once __DIR__.'/../../../runtime/modules/access/Framework/Jwt/JwtCodec.php';
require_once __DIR__.'/../../../runtime/modules/currency/kernel/currency.main.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/Exceptions/UnknownExchangeRateException.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/Exceptions/StaleExchangeRatesException.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/Exceptions/CurrencyMismatchException.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/CurrencyState.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/CurrencyManager.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/ExchangeQuote.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/ExchangeRates.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/ExchangeSnapshot.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/Currency.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/Money.php';
require_once __DIR__.'/../../../runtime/modules/currency/Framework/StoredMoney.php';

use Dataphyre\Routing\RouteCompiler;
use Dataphyre\Routing\Route;
use Dataphyre\Routing\RouteManifest;
use Dataphyre\RuntimeTrace;
use Dataphyre\UrlValue;
use Dataphyre\DateValue;
use Dataphyre\Env;
use Dataphyre\Config;
use Dataphyre\ConfigSnapshot;
use Dataphyre\DialbackEvent;
use Dataphyre\DialbackCatalog;
use Dataphyre\ClientAddress;
use Dataphyre\Application;
use Dataphyre\ApplicationCatalog;
use Dataphyre\BootstrapCatalog;
use Dataphyre\BootstrapPlan;
use Dataphyre\ModuleCatalog;
use Dataphyre\RuntimeState;
use Dataphyre\Localization\LocaleDefinition;
use Dataphyre\Localization\LocaleDefinitionCatalog;
use Dataphyre\Http\ActionArguments;
use Dataphyre\Http\UploadedFile;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Api\ApiContext;
use Dataphyre\Api\ApiCallableBinding;
use Dataphyre\Api\SecurityScheme;
use Dataphyre\Api\Endpoint;
use Dataphyre\Mvc\Controller;
use Dataphyre\Mvc\MvcDispatcher;
use Dataphyre\Mvc\RedirectResult;
use Dataphyre\Mvc\Session;
use Dataphyre\Mvc\Validator;
use Dataphyre\Mvc\ViewResult;
use Dataphyre\Permission\PermissionRule;
use Dataphyre\Permission\PermissionSet;
use Dataphyre\Permission\PermissionNamer;
use Dataphyre\Permission\PermissionSnapshot;
use Dataphyre\Permission\PermissionManifest;
use Dataphyre\Permission\PermissionOptimizer;
use Dataphyre\Permission\PermissionSimulator;
use Dataphyre\Permission\SubjectResolver;
use Dataphyre\Database\DB;
use Dataphyre\Database\SqlError;
use Dataphyre\Database\ExecutionTrace;
use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\TableSchema;
use Dataphyre\Database\TableDefinition;
use Dataphyre\Database\PageResult;
use Dataphyre\Database\MutationResult;
use Dataphyre\Database\MutationBatchResult;
use Dataphyre\Database\TransactionResult;
use Dataphyre\Database\Record;
use Dataphyre\Database\CurrencyBridge;
use Dataphyre\Database\Concerns\TransformsRows;
use Dataphyre\Sanitation\SanitationManager;
use Dataphyre\Sanitation\SanitizationResult;
use Dataphyre\Sanitation\InputBag;
use Dataphyre\Templating\AssetPolicy;
use Dataphyre\Templating\AssetManifest;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Templating\CallableBinding;
use Dataphyre\Templating\CachedBinding;
use Dataphyre\Templating\ConditionalBinding;
use Dataphyre\Templating\RememberedBinding;
use Dataphyre\Templating\SearchQueryBinding;
use Dataphyre\Templating\SqlQueryBinding;
use Dataphyre\Templating\RenderedTemplate;
use Dataphyre\Templating\TemplateContract;
use Dataphyre\Templating\TemplatingState;
use Dataphyre\Templating\TemplateManifest;
use Dataphyre\Templating\TemplatePlan;
use Dataphyre\FulltextEngine\HydratedSearchHit;
use Dataphyre\FulltextEngine\HydratedSearchResults;
use Dataphyre\FulltextEngine\IndexDefinition;
use Dataphyre\FulltextEngine\IndexSyncReport;
use Dataphyre\FulltextEngine\SearchHit;
use Dataphyre\FulltextEngine\SearchResults;
use Dataphyre\FulltextEngine\Resolvers\TableDocumentResolver;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Reactor\ReactorValidator;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;
use Dataphyre\Access\OAuthClient\Manager as OAuthManager;
use Dataphyre\Access\OAuthClient\Provider as OAuthProvider;
use Dataphyre\Access\Jwt\JwtCodec;
use Dataphyre\Currency\CurrencyManager;
use Dataphyre\Currency\ExchangeQuote;
use Dataphyre\Currency\ExchangeRates;
use Dataphyre\Currency\ExchangeSnapshot;
use Dataphyre\Currency\Money;
use Dataphyre\Currency\StoredMoney;
use dataphyre\routing\compiled_route_dispatcher;

if(in_array('--help', $argv, true) || in_array('-h', $argv, true)){
	echo <<<'HELP'
Usage:
  php dev/tools/public/benchmark_hot_paths.php [scenario] [iterations] [warmup]

Arguments:
  scenario    Scenario name, or all. Default: all.
  iterations  Measurement iterations per scenario. Default: 300.
  warmup      Warmup iterations per scenario. Default: 50.

This contributor tool supports maintainer proof for Dataphyre production
hot-path changes. It emits JSON.

HELP;
	exit(0);
}

$scenario=$argv[1] ?? 'all';

function bench_stats(array $samples): array {
	sort($samples, SORT_NUMERIC);
	$count=count($samples);
	return [
		'iterations'=>$count,
		'min_ms'=>round($samples[0], 6),
		'p50_ms'=>round($samples[(int)floor(($count - 1) * 0.50)], 6),
		'p95_ms'=>round($samples[(int)floor(($count - 1) * 0.95)], 6),
		'max_ms'=>round($samples[$count - 1], 6),
		'avg_ms'=>round(array_sum($samples) / $count, 6),
	];
}

function bench_run(string $name, callable $callback, int $iterations, int $warmup=100): array {
	for($index=0; $index<$warmup; $index++){
		$callback();
	}

	gc_collect_cycles();
	$memoryBefore=memory_get_usage(true);
	$peakBefore=memory_get_peak_usage(true);
	$samples=[];
	for($index=0; $index<$iterations; $index++){
		$started=hrtime(true);
		$callback();
		$samples[]=(hrtime(true) - $started) / 1000000;
	}
	$memoryAfter=memory_get_usage(true);
	$peakAfter=memory_get_peak_usage(true);

	return [
		'name'=>$name,
		'stats'=>bench_stats($samples),
		'memory_delta_bytes'=>$memoryAfter - $memoryBefore,
		'peak_delta_bytes'=>$peakAfter - $peakBefore,
	];
}

function make_routes(int $count): array {
	$routes=[];
	for($index=0; $index<$count; $index++){
		$routes[]=[
			'methods'=>['GET'],
			'path'=>'/catalog/'.$index,
			'exact_path'=>'/catalog/'.$index,
			'handler'=>__FILE__,
			'defaults'=>['locale'=>'en'],
		];
	}
	return $routes;
}

function make_manifest(int $count): array {
	return [
		'version'=>1,
		'metadata'=>[
			'signature'=>str_repeat('a', 64),
			'sources'=>[],
		],
		'routes'=>make_routes($count),
	];
}

function make_named_manifest(int $count, bool $dynamic=false): array {
	$routes=[];
	$namedRoutes=[];
	for($index=0; $index<$count; $index++){
		$route=[
			'methods'=>['GET'],
			'name'=>'catalog.'.$index,
			'path'=>$dynamic ? '/catalog/{id}/'.$index : '/catalog/static/'.$index,
			'handler'=>__FILE__,
			'defaults'=>$dynamic ? ['id'=>$index] : [],
		];
		if(!$dynamic){
			$route['exact_path']='/catalog/static/'.$index;
		}
		$routes[]=$route;
		$namedRoutes[$route['name']]=$index;
	}
	return [
		'version'=>1,
		'metadata'=>[],
		'routes'=>$routes,
		'named_routes'=>$namedRoutes,
	];
}

function make_splat_route(): array {
	return [[
		'methods'=>['GET'],
		'path'=>'/files/{...path}',
		'path_regex'=>'#^/files/(?P<path>.*)$#',
		'splat_parameters'=>['path'],
		'handler'=>__FILE__,
	]];
}

function make_rows(int $count): array {
	$rows=[];
	for($index=0; $index<$count; $index++){
		$rows[]=[
			'id'=>$index,
			'name'=>'Product '.$index,
			'price'=>$index * 1.25,
			'active'=>($index % 2)===0,
		];
	}
	return $rows;
}

function make_schema_columns(int $count): array {
	$columns=[];
	for($index=0; $index<$count; $index++){
		$columns[]='col_'.$index;
		if(($index % 8)===0){
			$columns[]='col_'.$index;
		}
	}
	return $columns;
}

function make_schema_projection_columns(int $count): array {
	$columns=[];
	for($index=0; $index<$count; $index++){
		$columns[]='col_'.$index;
		if(($index % 5)===0){
			$columns[]='col_'.$index;
		}
	}
	return $columns;
}

function make_schema_cast_rows(int $count): array {
	$rows=[];
	for($index=0; $index<$count; $index++){
		$rows[]=[
			'id'=>(string)$index,
			'name'=>'Product '.$index,
			'active'=>($index % 2)===0 ? '1' : '0',
			'payload'=>'{"index":'.$index.',"ok":true}',
			'created_at'=>'2026-06-02 12:00:00',
		];
	}
	return $rows;
}

function make_table_definition_for_queries(): TableDefinition {
	return TableDefinition::for('public.products')
		->autoIncrement('id')
		->string('shop_id', 64)->notNull()
		->string('slug', 191)->notNull()
		->string('name', 255)->default('')
		->enum('status', [' draft ', 'active', 'archived', '', 'active'])
		->json('payload')
		->boolean('active')->default(true)
		->timestamp('created_at')->defaultCurrent()
		->timestamp('updated_at')->defaultCurrent()->onUpdateCurrent()
		->unique(['shop_id', 'slug'], 'uniq_products_shop_slug')
		->index(['shop_id', 'status', 'slug(191)'])
		->index(['created_at'])
		->projection('summary', ['id', 'shop_id', 'name', 'status']);
}

function make_query_spec_for_compile(bool $withHaving=false): QuerySpec {
	$spec=(new QuerySpec())
		->whereEq('shop_id', 42)
		->whereIn('status', ['active', 'draft', 'archived'])
		->whereNotNull('deleted_at')
		->groupBy(['shop_id', 'status'])
		->orderByDesc('created_at')
		->limit(50)
		->offset(100);
	if($withHaving){
		$spec->havingRaw('COUNT(*) > ?', [3]);
	}
	return $spec;
}

function make_bindings_with_traces(int $count): array {
	$bindings=[];
	for($index=0; $index<$count; $index++){
		$bindings[]=[
			'path'=>'item.'.$index,
			'ok'=>true,
			'trace'=>($index % 2)===0 ? [
				'binding_trace_id'=>'tpl_abc.b'.str_pad((string)$index, 4, '0', STR_PAD_LEFT),
				'duration_ms'=>$index / 1000,
			] : [],
		];
	}
	return $bindings;
}

function make_bindings_with_errors(int $count): array {
	$bindings=[];
	for($index=0; $index<$count; $index++){
		$bindings[]=[
			'path'=>'item.'.$index,
			'ok'=>($index % 5)!==0,
			'error'=>($index % 5)===0 ? 'Missing item '.$index : null,
		];
	}
	return $bindings;
}

function make_bindings_without_errors(int $count): array {
	$bindings=[];
	for($index=0; $index<$count; $index++){
		$bindings[]=[
			'path'=>'item.'.$index,
			'ok'=>true,
		];
	}
	return $bindings;
}

function make_binding_warnings(int $count): array {
	$warnings=[];
	for($index=0; $index<$count; $index++){
		$warnings[]=[
			'type'=>'unused_binding',
			'path'=>'item.'.$index,
		];
	}
	return $warnings;
}

function make_runtime_trace(int $count): RuntimeTrace {
	$bindings=[];
	$sqlTraces=[];
	for($index=0; $index<$count; $index++){
		$bindingTraceId='tpl_abc.b'.str_pad((string)$index, 4, '0', STR_PAD_LEFT);
		$fingerprint='fp_'.($index % 10);
		$bindings[]=[
			'path'=>'items.'.$index,
			'binding'=>'product_'.$index,
			'correlation'=>[
				'binding_trace_id'=>$bindingTraceId,
			],
			'source'=>[
				'driver'=>'sql',
				'target_type'=>'table',
				'target'=>'products',
			],
			'identity'=>[
				'query_fingerprint'=>$fingerprint,
				'source'=>'fingerprint',
				'mode'=>'repository',
			],
		];
		$sqlTraces[]=[
			'event'=>$index % 3===0 ? 'cache_store' : 'query_executed',
			'cache_status'=>$index % 2===0 ? 'hit' : 'miss',
			'context'=>[
				'binding_trace_id'=>$bindingTraceId,
				'query_fingerprint'=>$fingerprint,
				'query_identity_source'=>'fingerprint',
				'query_identity_mode'=>'repository',
				'query_target_type'=>'table',
				'query_target'=>'products',
			],
		];
	}
	return new RuntimeTrace('tpl_abc', 'bench.tpl', ['template_name'=>'bench.tpl'], $bindings, $sqlTraces);
}

function make_date_value(): DateValue {
	return new DateValue(new DateTimeImmutable('2026-06-02 14:15:16.123456', new DateTimeZone('America/Toronto')));
}

function make_transaction_success_result(): TransactionResult {
	return TransactionResult::success('primary', true, true, [
		'id'=>123,
		'status'=>'committed',
	], 2);
}

function make_transaction_failure_result(): TransactionResult {
	return TransactionResult::failure('primary', true, false, true, new RuntimeException('Deadlock retry exhausted.'), 3);
}

function make_uploaded_file(): UploadedFile {
	return UploadedFile::fromArray([
		'name'=>'Catalog.Export.Final.JPG',
		'type'=>'image/jpeg',
		'tmp_name'=>__FILE__,
		'error'=>UPLOAD_ERR_OK,
		'size'=>12345,
	]);
}

function make_module_definitions(int $count): array {
	$definitions=[];
	for($index=0; $index<$count; $index++){
		$definitions[]=[
			'module'=>'module_'.$index,
			'version'=>'1.'.($index % 10),
			'enabled'=>($index % 3)!==0,
			'directory'=>'/project/runtime/modules/module_'.$index,
			'common_directory'=>'/project/common/module_'.$index,
			'app_directory'=>$index % 2===0 ? '/project/app/module_'.$index : null,
			'kernel_entry'=>'/project/runtime/modules/module_'.$index.'/kernel/main.php',
			'framework_entry'=>'/project/runtime/modules/module_'.$index.'/Framework/Bootstrap.php',
			'framework_directory'=>'/project/runtime/modules/module_'.$index.'/Framework',
			'framework_namespace'=>'Module'.$index,
		];
	}
	return $definitions;
}

function make_applications(int $count): array {
	$applications=[];
	for($index=0; $index<$count; $index++){
		$applications['app_'.$index]=Application::legacy('app_'.$index, '/project/apps/app_'.$index, [
			'rootpath_file'=>'/project/apps/app_'.$index.'/rootpaths.php',
			'routes_file'=>'/project/apps/app_'.$index.'/routes.php',
			'compiled_routes_file'=>'/project/cache/routes/app_'.$index.'.php',
			'framework_bootstrap_file'=>'/project/apps/app_'.$index.'/framework.php',
			'legacy_bootstrap_file'=>'/project/apps/app_'.$index.'/application_bootstrap.php',
			'autoload'=>[
				'App'.$index.'\\'=>'/project/apps/app_'.$index.'/src',
				'App'.$index.'\\Domain\\'=>['/project/apps/app_'.$index.'/domain', '/project/common/domain'],
			],
			'options'=>[
				'environment'=>$index % 2===0 ? 'production' : 'staging',
				'priority'=>$index,
			],
		]);
	}
	return $applications;
}

function make_runtime_state(int $applicationCount=10, int $moduleCount=100): RuntimeState {
	return new RuntimeState(
		true,
		'/project',
		Application::legacy('app_0', '/project/apps/app_0'),
		[
			'app_0'=>'/project/apps/app_0',
			'app_1'=>'/project/apps/app_1',
			'app_2'=>'/project/apps/app_2',
		],
		new ApplicationCatalog('/project', make_applications($applicationCount)),
		ModuleCatalog::fromDefinitions(make_module_definitions($moduleCount))
	);
}

function make_bootstrap_plan_without_files(): BootstrapPlan {
	return new BootstrapPlan(
		'/project',
		new Application(
			'app_0',
			'/project/apps/app_0',
			null,
			null,
			null,
			null,
			null,
			['App0\\'=>'/project/apps/app_0/src'],
			['fallback_to_legacy_bootstrap'=>true]
		)
	);
}

function make_bootstrap_plans_without_files(int $count): array {
	$plans=[];
	for($index=0; $index<$count; $index++){
		$plans['app_'.$index]=new BootstrapPlan(
			'/project',
			new Application(
				'app_'.$index,
				'/project/apps/app_'.$index,
				null,
				null,
				null,
				null,
				null,
				['App'.$index.'\\'=>'/project/apps/app_'.$index.'/src'],
				['fallback_to_legacy_bootstrap'=>true]
			)
		);
	}
	return $plans;
}

function make_dialback_entries(int $count): array {
	$entries=[];
	for($index=0; $index<$count; $index++){
		$entries['catalog.event_'.$index]=[
			'trim',
			'strtolower',
			'not_a_function_'.$index,
			static fn(string $value): string => $value,
		];
	}
	return $entries;
}

function make_mutation_batch(int $count): MutationBatchResult {
	$results=[];
	for($index=0; $index<$count; $index++){
		if($index % 10===0){
			$results[]=MutationResult::fromRaw('update', false, ['table'=>'products', 'row'=>$index], 'Mutation '.$index.' failed.');
			continue;
		}
		$results[]=MutationResult::fromRaw($index % 3===0 ? 'insert' : 'update', $index % 3===0 ? (string)(1000 + $index) : 1, [
			'table'=>'products',
			'row'=>$index,
		]);
	}
	return new MutationBatchResult('upsert', $results, $count);
}

function make_page_result(int $count): PageResult {
	$items=[];
	for($index=0; $index<$count; $index++){
		$items['item_'.$index]=[
			'id'=>$index,
			'name'=>'Product '.$index,
			'active'=>$index % 2===0,
			'stock'=>$index * 3,
		];
	}
	return new PageResult($items, $count * 10, 3, $count);
}

function make_record(int $count): Record {
	$row=[];
	for($index=0; $index<$count; $index++){
		$row['col_'.$index]=$index;
	}
	return new Record($row);
}

function make_locale_definition(int $index): LocaleDefinition {
	return new LocaleDefinition(
		$index,
		$index % 2===0 ? 'en_CA' : 'fr_CA',
		$index % 3===0 ? 'dataphyre_bench' : null,
		$index % 3===1 ? '/catalog/product-'.$index : null,
		$index % 3===0 ? 'theme' : ($index % 3===1 ? 'local' : 'global'),
		'catalog.label_'.$index,
		'Localized label '.$index,
		'2026-06-02 12:00:00',
		'main',
		'abcdef'.$index
	);
}

function make_locale_definition_catalog(int $count): LocaleDefinitionCatalog {
	$entries=[];
	for($index=0; $index<$count; $index++){
		$entries[]=make_locale_definition($index);
	}
	return new LocaleDefinitionCatalog(['lang'=>'en_CA'], 250, 0, $entries);
}

function make_search_results(int $count): SearchResults {
	$results=[];
	for($index=0; $index<$count; $index++){
		$results[]=['doc-'.$index=>1.0 / ($index + 1)];
	}
	return SearchResults::fromKernelResponse('catalog', [
		'results'=>$results,
		'count'=>$count,
		'certainty'=>0.91,
		'time'=>0.004,
	]);
}

function make_hydrated_search_results(int $count): HydratedSearchResults {
	$hits=[];
	for($index=0; $index<$count; $index++){
		$hits[]=new HydratedSearchHit(
			new SearchHit('doc-'.$index, 1.0 / ($index + 1)),
			['id'=>'doc-'.$index, 'title'=>'Catalog item '.$index],
			$index % 10!==0
		);
	}
	return new HydratedSearchResults('catalog', $hits, $count, 0.91, 0.004);
}

function make_index_definition(int $index, string $type='json'): IndexDefinition {
	return new IndexDefinition(
		'catalog_'.$index,
		$type,
		'id',
		$index % 2===0 ? 'en' : 'fr',
		['fields'=>['title', 'description'], 'weight'=>$index % 5 + 1]
	);
}

function make_index_sync_report(int $count): IndexSyncReport {
	$report=new IndexSyncReport();
	for($index=0; $index<$count; $index++){
		$report->addCreated(make_index_definition($index, 'json'));
		$report->addUnchanged(make_index_definition($index + $count, 'sqlite'));
		$report->addMismatched(
			make_index_definition($index + ($count * 2), 'json'),
			make_index_definition($index + ($count * 2), 'sqlite')
		);
		$report->addPruned(make_index_definition($index + ($count * 3), 'json'));
		if($index % 5===0){
			$report->addFailed('legacy_'.$index, 'unavailable source');
		}
	}
	return $report;
}

function make_resolver_ids(int $count): array {
	$ids=[];
	for($index=0; $index<$count; $index++){
		$ids[]=' doc-'.$index.' ';
		if($index % 10===0){
			$ids[]='';
			$ids[]='   ';
		}
	}
	return $ids;
}

function make_reactor_validation_fixture(int $count): array {
	$state=['items'=>[]];
	$rules=[];
	for($index=0; $index<$count; $index++){
		$state['items'][$index]=[
			'name'=>$index % 7===0 ? '' : 'Item '.$index,
			'quantity'=>$index % 11===0 ? 'many' : (string)($index + 1),
			'status'=>$index % 13===0 ? 'archived' : 'active',
		];
		$rules['items.'.$index.'.name']=' required | string | min:3 ';
		$rules['items.'.$index.'.quantity']=[' required ', ' integer ', ' min:1 '];
		$rules['items.'.$index.'.status']='required|in:active, draft, disabled';
	}
	return [$state, $rules];
}

function make_reactor_snapshot_payload(int $count): array {
	$locked=[];
	for($index=0; $index<$count; $index++){
		$locked[]=$index;
		$locked[]=' field_'.$index.' ';
	}
	return [
		'component'=>' Catalog Editor ',
		'state'=>['page'=>1, 'filters'=>['status'=>'active']],
		'locked'=>$locked,
		'created_at'=>1780430000,
		'signature'=>'benchmark-signature',
	];
}

function make_mailer_message_payload(int $tagCount): array {
	$tags=[];
	for($index=0; $index<$tagCount; $index++){
		$tags[]=$index % 9===0 ? '' : 'campaign_'.$index;
	}
	return [
		'from'=>'DataphyreBench <no-reply@example.com>',
		'to'=>['Customer <customer@example.com>'],
		'subject'=>'Catalog update',
		'html'=>'<p>Hello</p>',
		'text'=>'Hello',
		'tags'=>$tags,
		'metadata'=>['campaign'=>'summer'],
	];
}

function make_send_result(): SendResult {
	return SendResult::success('benchmark', 202, 'Accepted', 'msg_bench_123', [
		'id'=>'remote-msg-123',
		'status'=>'queued',
		'headers'=>[
			'x-provider-trace'=>'trace-123',
			'x-delivery-region'=>'ca-central-1',
		],
	], [
		'queue'=>'mail',
		'attempt'=>2,
		'template'=>'receipt',
		'recipients'=>3,
	]);
}

function make_oauth_scopes(int $count): array {
	$scopes=[];
	for($index=0; $index<$count; $index++){
		$scopes[]=' scope_'.$index.' ';
		if($index % 8===0){
			$scopes[]='scope_'.$index;
			$scopes[]='';
		}
	}
	return $scopes;
}

function make_exchange_rates(int $count): ExchangeRates {
	$rates=[];
	$minorUnits=[];
	for($index=0; $index<$count; $index++){
		$currency='X'.str_pad((string)$index, 3, '0', STR_PAD_LEFT);
		$rates[$currency]=1.0 + ($index / 100);
		$minorUnits[$currency]=$index % 3;
	}
	return new ExchangeRates('USD', 'benchmark', 1780430000, $rates, $minorUnits);
}

function make_exchange_snapshot(int $count): ExchangeSnapshot {
	return new ExchangeSnapshot(make_exchange_rates($count), CurrencyManager::instance(), [
		'display_currency'=>'CAD',
		'display_language'=>'en_CA',
		'display_country'=>'CA',
	]);
}

function make_exchange_quote(): ExchangeQuote {
	return new ExchangeQuote('USD', 'USD', 'CAD', 2, 2, 1.37654321, 'benchmark', 1780430000);
}

function seed_currency_exchange_rates(): void {
	$_SESSION['exchange_rate_data']=[
		'data'=>[
			'USD'=>1.0,
			'CAD'=>1.35,
			'EUR'=>0.9,
			'JPY'=>150.0,
			'KWD'=>0.307,
		],
		'time'=>time(),
		'source'=>'benchmark',
	];
}

function make_money_value(): Money {
	return new Money(1234.567, 'usd');
}

function make_stored_money_value(): StoredMoney {
	return new StoredMoney(
		new Money(1234.567, 'USD'),
		new Money(1699.12, 'CAD'),
		make_exchange_snapshot(4),
		make_exchange_quote()
	);
}

final class RowTransformBenchmark {
	use TransformsRows;

	public function __construct(callable ...$transformers){
		foreach($transformers as $transformer){
			$this->addRowTransformer($transformer);
		}
	}

	public function run(mixed $result): mixed {
		return $this->transformQueuedResult($result);
	}
}

final class SearchBindingBenchmarkQuery {
	public function index(): string {
		return 'products';
	}

	public function fingerprint(): string {
		return 'search-fp-123';
	}

	public function executionState(): array {
		return [
			'term'=>'shoes',
			'filters'=>['status'=>'active'],
			'caching'=>['search-products', 'products'],
		];
	}
}

final class SqlBindingBenchmarkQuery {
	public function table(): string {
		return 'products';
	}

	public function fingerprint(): string {
		return 'sql-fp-123';
	}

	public function executionState(): array {
		return [
			'table'=>'products',
			'where'=>['status = ?'],
			'caching'=>['products', 'shops'],
		];
	}
}

final class ControllerMiddlewareBenchmark extends Controller {
	public function __construct(){
		$this->middleware('auth')->only('show', 'edit', 'update');
		$this->middleware('throttle:60,1');
		$this->middleware(['verified', 'can:update'])->except('index');
		$this->middleware(static fn(): bool => true)->only(['store', 'update']);
	}

	public function definitions(?string $method=null): array {
		return $this->mvcControllerMiddleware($method);
	}
}

$results=[];

if($scenario==='all' || $scenario==='route-exact-last'){
	$routes=make_routes(500);
	$results[]=bench_run(
		'route-exact-last-500',
		static fn() => compiled_route_dispatcher::match_routes_for_request($routes, 'GET', '/catalog/499', 'example.test'),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='route-exact-first'){
	$routes=make_routes(500);
	$results[]=bench_run(
		'route-exact-first-500',
		static fn() => compiled_route_dispatcher::match_routes_for_request($routes, 'GET', '/catalog/0', 'example.test'),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='route-splat-match'){
	$routes=make_splat_route();
	$results[]=bench_run(
		'route-splat-match',
		static fn() => compiled_route_dispatcher::match_routes_for_request($routes, 'GET', '/files/2026/invoices/a%20b.pdf', 'example.test'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='manifest-write'){
	$manifest=make_manifest(500);
	$target=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dataphyre-hot-path-manifest.php';
	$results[]=bench_run(
		'manifest-write-500',
		static function() use($manifest, $target): void {
			RouteCompiler::tryWriteManifestFile($target, $manifest);
		},
		250,
		25
	);
	@unlink($target);
}

if($scenario==='all' || $scenario==='route-url-static'){
	$results[]=bench_run(
		'route-url-static',
		static fn() => Route::url('/catalog/static'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='route-url-dynamic'){
	$results[]=bench_run(
		'route-url-dynamic',
		static fn() => Route::url('/catalog/{id}', ['id'=>42, 'tab'=>'details']),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='route-url-splat'){
	$results[]=bench_run(
		'route-url-splat',
		static fn() => Route::url('/files/{...path}', ['path'=>['2026', 'invoices', 'a b.pdf'], 'download'=>1]),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='route-url-domain-dynamic'){
	$results[]=bench_run(
		'route-url-domain-dynamic',
		static fn() => Route::url('/catalog/{id}', ['tenant'=>'acme', 'id'=>42, 'tab'=>'details'], [], '{tenant}.example.test'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='route-compile-dynamic'){
	$results[]=bench_run(
		'route-compile-dynamic',
		static fn() => Route::get('/catalog/{category}/{id?}/{...tail}', __FILE__)
			->whereNumber('id')
			->compile(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='named-url-static-last'){
	$manifest=make_named_manifest(500, false);
	$results[]=bench_run(
		'named-url-static-last-500',
		static fn() => RouteManifest::namedUrl($manifest, 'catalog.499'),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='named-url-dynamic-last'){
	$manifest=make_named_manifest(500, true);
	$results[]=bench_run(
		'named-url-dynamic-last-500',
		static fn() => RouteManifest::namedUrl($manifest, 'catalog.499', ['id'=>42], ['tab'=>'details']),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='binding-context-get-simple'){
	$context=new BindingContext('bench.tpl', false, [
		'title'=>'Dashboard',
		'user'=>[
			'name'=>'Ada',
			'role'=>'admin',
		],
	]);
	$results[]=bench_run(
		'binding-context-get-simple',
		static fn() => $context->get('title'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='binding-context-get-nested'){
	$context=new BindingContext('bench.tpl', false, [
		'title'=>'Dashboard',
		'user'=>[
			'name'=>'Ada',
			'role'=>'admin',
		],
	]);
	$results[]=bench_run(
		'binding-context-get-nested',
		static fn() => $context->get('user.name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='binding-context-has-simple'){
	$context=new BindingContext('bench.tpl', false, [
		'title'=>'Dashboard',
		'user'=>[
			'name'=>'Ada',
			'role'=>'admin',
		],
	]);
	$results[]=bench_run(
		'binding-context-has-simple',
		static fn() => $context->has('title'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='binding-context-has-nested'){
	$context=new BindingContext('bench.tpl', false, [
		'title'=>'Dashboard',
		'user'=>[
			'name'=>'Ada',
			'role'=>'admin',
		],
	]);
	$results[]=bench_run(
		'binding-context-has-nested',
		static fn() => $context->has('user.name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='callable-binding-resolve-zero-arg'){
	$context=new BindingContext('bench.tpl', false, ['title'=>'Dashboard']);
	$binding=CallableBinding::make(static fn() => 'Dashboard', 'title');
	$results[]=bench_run(
		'callable-binding-resolve-zero-arg',
		static fn() => $binding->resolve($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='callable-binding-resolve-context'){
	$context=new BindingContext('bench.tpl', false, ['title'=>'Dashboard']);
	$binding=CallableBinding::make(static fn(BindingContext $context): mixed => $context->get('title'), 'title');
	$results[]=bench_run(
		'callable-binding-resolve-context',
		static fn() => $binding->resolve($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='cached-binding-identity-zero-arg'){
	$context=new BindingContext('bench.tpl', false, ['title'=>'Dashboard']);
	$binding=CachedBinding::make(
		CallableBinding::make(static fn() => 'Dashboard', 'title'),
		static fn() => 'catalog:v1',
		'title'
	);
	$results[]=bench_run(
		'cached-binding-identity-zero-arg',
		static fn() => $binding->cacheIdentity($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='cached-binding-identity-context'){
	$context=new BindingContext('bench.tpl', false, ['tenant'=>'acme']);
	$binding=CachedBinding::make(
		CallableBinding::make(static fn() => 'Dashboard', 'title'),
		static fn(BindingContext $context): string => 'tenant:'.$context->get('tenant'),
		'title'
	);
	$results[]=bench_run(
		'cached-binding-identity-context',
		static fn() => $binding->cacheIdentity($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='remembered-binding-identity-context'){
	$context=new BindingContext('bench.tpl', false, ['tenant'=>'acme']);
	$binding=RememberedBinding::make(
		CallableBinding::make(static fn() => 'Dashboard', 'title'),
		static fn(BindingContext $context): string => 'tenant:'.$context->get('tenant'),
		600,
		[' catalog ', 'tenant', 'catalog'],
		'title'
	);
	$results[]=bench_run(
		'remembered-binding-identity-context',
		static fn() => $binding->cacheIdentity($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='remembered-binding-persistent-cache-context'){
	$context=new BindingContext('bench.tpl', false, ['tenant'=>'acme']);
	$binding=RememberedBinding::make(
		CallableBinding::make(static fn() => 'Dashboard', 'title'),
		static fn(BindingContext $context): string => 'tenant:'.$context->get('tenant'),
		600,
		[' catalog ', 'tenant', 'catalog'],
		'title'
	);
	$results[]=bench_run(
		'remembered-binding-persistent-cache-context',
		static fn() => $binding->persistentCache($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='conditional-binding-resolve-context-true'){
	$context=new BindingContext('bench.tpl', false, ['enabled'=>true, 'title'=>'Dashboard']);
	$binding=ConditionalBinding::when(
		CallableBinding::make(static fn(BindingContext $context): mixed => $context->get('title'), 'title'),
		static fn(BindingContext $context): bool => $context->get('enabled') === true
	);
	$results[]=bench_run(
		'conditional-binding-resolve-context-true',
		static fn() => $binding->resolve($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='conditional-binding-resolve-context-false'){
	$context=new BindingContext('bench.tpl', false, ['enabled'=>false, 'title'=>'Dashboard']);
	$binding=ConditionalBinding::when(
		CallableBinding::make(static fn(BindingContext $context): mixed => $context->get('title'), 'title'),
		static fn(BindingContext $context): bool => $context->get('enabled') === true,
		'fallback'
	);
	$results[]=bench_run(
		'conditional-binding-resolve-context-false',
		static fn() => $binding->resolve($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='api-callable-binding-resolve-context'){
	$request=Request::create('GET', '/v1/catalog', ['page'=>'1'], [], [], [], [], ['tenant'=>'acme']);
	$apiContext=new ApiContext($request, ['name'=>'catalog.index', 'parameters'=>['tenant'=>'acme']]);
	$context=new BindingContext('bench.tpl', false, [], [], [], [
		'api_context'=>$apiContext,
	]);
	$binding=new ApiCallableBinding(
		static fn(?ApiContext $apiContext, mixed $request, array $route, BindingContext $context): array => [
			'path'=>$request?->path(),
			'name'=>$route['name'] ?? null,
			'template'=>$context->templateName(),
			'method'=>$apiContext?->method(),
		],
		'api.catalog',
		'catalog.index'
	);
	$results[]=bench_run(
		'api-callable-binding-resolve-context',
		static fn() => $binding->resolve($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='api-callable-binding-identity-context'){
	$request=Request::create('GET', '/v1/catalog', ['page'=>'1'], [], [], [], [], ['tenant'=>'acme']);
	$apiContext=new ApiContext($request, ['name'=>'catalog.index', 'parameters'=>['tenant'=>'acme']]);
	$context=new BindingContext('bench.tpl', false, [], [], [], [
		'api_context'=>$apiContext,
	]);
	$binding=new ApiCallableBinding(
		static fn() => ['ok'=>true],
		'api.catalog',
		'catalog.index',
		static fn(BindingContext $context): string => 'api:'.$context->overrides()['api_context']->route()['name']
	);
	$results[]=bench_run(
		'api-callable-binding-identity-context',
		static fn() => $binding->cacheIdentity($context),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='api-context-input-top-level'){
	$request=Request::create('POST', '/v1/catalog', ['page'=>'1'], ['title'=>'Dashboard'], [], [], [], ['tenant'=>'acme']);
	$apiContext=new ApiContext($request, ['name'=>'catalog.index']);
	$results[]=bench_run(
		'api-context-input-top-level',
		static fn() => $apiContext->input('tenant'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='api-context-input-nested'){
	$request=Request::create('POST', '/v1/catalog', ['filter'=>['status'=>'active']], ['title'=>'Dashboard'], [], [], [], ['tenant'=>['slug'=>'acme']]);
	$apiContext=new ApiContext($request, ['name'=>'catalog.index']);
	$results[]=bench_run(
		'api-context-input-nested',
		static fn() => $apiContext->input('tenant.slug'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='api-context-all-custom-sources'){
	$request=Request::create(
		'POST',
		'/v1/catalog',
		['page'=>'1'],
		['title'=>'Dashboard'],
		[],
		['session'=>'abc'],
		['X-Tenant'=>'acme'],
		['tenant'=>'acme'],
		['REMOTE_ADDR'=>'127.0.0.1']
	);
	$apiContext=new ApiContext($request, ['name'=>'catalog.index']);
	$sources=[' ROUTE ', 'query', 'body', 'cookies', 'headers', 'server', 'route', 'invalid', ''];
	$results[]=bench_run(
		'api-context-all-custom-sources',
		static fn() => $apiContext->all($sources),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='security-scheme-guard-many'){
	$guards=[' jwt ', 'session', '', 'access', 42, 'api', 'jwt'];
	$options=[
		'scopes'=>['read', 'write', 'read', ''],
		'description'=>' Guarded endpoint ',
	];
	$results[]=bench_run(
		'security-scheme-guard-many',
		static fn() => SecurityScheme::guard('mixedAuth', $guards, [], $options)->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='security-scheme-to-array'){
	$scheme=SecurityScheme::guard('mixedAuth', ['jwt', 'session', 'access', 'api'], [], [
		'scopes'=>['read', 'write'],
		'description'=>'Guarded endpoint',
	]);
	$results[]=bench_run(
		'security-scheme-to-array',
		static fn() => $scheme->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='security-scheme-to-array-fresh'){
	$guards=[' jwt ', 'session', '', 'access', 42, 'api', 'jwt'];
	$options=[
		'scopes'=>['read', 'write', 'read', ''],
		'description'=>' Guarded endpoint ',
	];
	$results[]=bench_run(
		'security-scheme-to-array-fresh',
		static fn() => SecurityScheme::guard('mixedAuth', $guards, [], $options)->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='endpoint-methods-normalize-many'){
	$methods=[' get ', 'POST', 'post', 'Patch', 'DELETE', 'GET', 42, ''];
	$results[]=bench_run(
		'endpoint-methods-normalize-many',
		static fn() => Endpoint::methods($methods, '/v1/catalog/{id}', static fn(): array => ['ok'=>true]),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='action-arguments-resolve-positional'){
	$request=Request::create('GET', '/catalog/42/books/active/page/3');
	$callable=static fn($id, $category, $status, $page, $sort): array => [$id, $category, $status, $page, $sort];
	$routeParameters=[
		'id'=>'42',
		'category'=>'books',
		'status'=>'active',
		'page'=>'3',
		'sort'=>'name',
	];
	$results[]=bench_run(
		'action-arguments-resolve-positional',
		static fn() => ActionArguments::resolve($callable, $request, $routeParameters),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='binding-context-render-trace-present'){
	$context=new BindingContext('bench.tpl', false, [], [], [], [], [
		'render_trace_id'=>' tpl_abcdef123456 ',
	]);
	$results[]=bench_run(
		'binding-context-render-trace-present',
		static fn() => $context->renderTraceId(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='binding-context-render-trace-missing'){
	$context=new BindingContext('bench.tpl', false, [], [], [], [], []);
	$results[]=bench_run(
		'binding-context-render-trace-missing',
		static fn() => $context->renderTraceId(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='search-binding-metadata'){
	$binding=new SearchQueryBinding(
		new SearchBindingBenchmarkQuery(),
		'results',
		[
			'inherit_query_identity'=>true,
			'binding_cache'=>[
				'ttl'=>600,
				'names'=>['search-products', 'products', ''],
			],
		],
		'search.query.results'
	);
	$results[]=bench_run(
		'search-binding-metadata',
		static fn() => $binding->metadata(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='search-binding-metadata-fresh'){
	$results[]=bench_run(
		'search-binding-metadata-fresh',
		static fn() => (new SearchQueryBinding(
			new SearchBindingBenchmarkQuery(),
			'results',
			[
				'inherit_query_identity'=>true,
				'binding_cache'=>[
					'ttl'=>600,
					'names'=>['search-products', 'products', ''],
				],
			],
			'search.query.results'
		))->metadata(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sql-binding-metadata'){
	$binding=new SqlQueryBinding(
		new SqlBindingBenchmarkQuery(),
		'records',
		[
			'inherit_query_identity'=>true,
			'columns'=>['id', 'name'],
			'caching'=>true,
			'binding_cache'=>[
				'ttl'=>600,
				'names'=>['products', 'orders', ''],
			],
		],
		'sql.query.records'
	);
	$results[]=bench_run(
		'sql-binding-metadata',
		static fn() => $binding->metadata(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='rendered-template-binding-trace'){
	$template=new RenderedTemplate(
		'<p>ok</p>',
		'bench.tpl',
		[],
		[],
		[],
		false,
		null,
		null,
		null,
		make_bindings_with_traces(100)
	);
	$results[]=bench_run(
		'rendered-template-binding-trace-100',
		static fn() => $template->bindingTrace(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='rendered-template-binding-errors'){
	$template=new RenderedTemplate(
		'<p>ok</p>',
		'bench.tpl',
		[],
		[],
		[],
		false,
		null,
		null,
		null,
		make_bindings_with_errors(100)
	);
	$results[]=bench_run(
		'rendered-template-binding-errors-100',
		static fn() => $template->bindingErrors(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='rendered-template-has-binding-errors'){
	$template=new RenderedTemplate(
		'<p>ok</p>',
		'bench.tpl',
		[],
		[],
		[],
		false,
		null,
		null,
		null,
		make_bindings_with_errors(100)
	);
	$results[]=bench_run(
		'rendered-template-has-binding-errors-100',
		static fn() => $template->hasBindingErrors(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='rendered-template-has-no-binding-errors'){
	$template=new RenderedTemplate(
		'<p>ok</p>',
		'bench.tpl',
		[],
		[],
		[],
		false,
		null,
		null,
		null,
		make_bindings_without_errors(100)
	);
	$results[]=bench_run(
		'rendered-template-has-no-binding-errors-100',
		static fn() => $template->hasBindingErrors(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='rendered-template-has-no-binding-errors-fresh'){
	$results[]=bench_run(
		'rendered-template-has-no-binding-errors-fresh-100',
		static fn() => (new RenderedTemplate(
			'<p>ok</p>',
			'bench.tpl',
			[],
			[],
			[],
			false,
			null,
			null,
			null,
			make_bindings_without_errors(100)
		))->hasBindingErrors(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='rendered-template-has-binding-warnings'){
	$template=new RenderedTemplate(
		'<p>ok</p>',
		'bench.tpl',
		[],
		[],
		[],
		false,
		null,
		null,
		null,
		[],
		make_binding_warnings(25)
	);
	$results[]=bench_run(
		'rendered-template-has-binding-warnings-25',
		static fn() => $template->hasBindingWarnings(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-has-binding-errors'){
	$manifest=new TemplateManifest([
		'binding_errors'=>make_bindings_with_errors(25),
	]);
	$results[]=bench_run(
		'template-manifest-has-binding-errors-25',
		static fn() => $manifest->hasBindingErrors(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-has-no-binding-errors'){
	$manifest=new TemplateManifest([
		'binding_errors'=>[],
	]);
	$results[]=bench_run(
		'template-manifest-has-no-binding-errors',
		static fn() => $manifest->hasBindingErrors(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-has-binding-warnings'){
	$manifest=new TemplateManifest([
		'binding_warnings'=>make_binding_warnings(25),
	]);
	$results[]=bench_run(
		'template-manifest-has-binding-warnings-25',
		static fn() => $manifest->hasBindingWarnings(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-has-errors'){
	$manifest=new TemplateManifest([
		'errors'=>make_bindings_with_errors(25),
	]);
	$results[]=bench_run(
		'template-manifest-has-errors-25',
		static fn() => $manifest->hasErrors(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-has-contract-violations'){
	$manifest=new TemplateManifest([
		'contract_violations'=>make_bindings_with_errors(25),
	]);
	$results[]=bench_run(
		'template-manifest-has-contract-violations-25',
		static fn() => $manifest->hasContractViolations(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-summary'){
	$payload=[
		'template_name'=>'bench.tpl',
		'inline'=>false,
		'duration_ms'=>4.25,
		'failed'=>false,
		'strict_mode'=>true,
		'render_trace_id'=>'render-summary-123',
		'asset_policy'=>[
			'preload'=>[
				'styles'=>true,
				'scripts'=>true,
				'images'=>false,
				'fonts'=>true,
			],
			'scripts'=>[
				'strategy'=>'defer',
				'type'=>'module',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'anonymous',
			],
		],
		'templates'=>range(1, 12),
		'partials'=>range(1, 10),
		'components'=>range(1, 8),
		'assets'=>range(1, 14),
		'dependencies'=>range(1, 6),
		'translations'=>range(1, 9),
		'bindings'=>make_bindings_with_traces(40),
		'binding_trace'=>make_bindings_with_traces(40),
		'binding_errors'=>make_bindings_with_errors(5),
		'binding_warnings'=>make_binding_warnings(6),
		'binding_planner'=>range(1, 7),
		'contract_violations'=>range(1, 3),
		'undefined_variables'=>range(1, 4),
		'missing_references'=>range(1, 2),
	];
	$manifest=new TemplateManifest($payload);
	$results[]=bench_run(
		'template-manifest-summary',
		static fn() => $manifest->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-summary-fresh'){
	$payload=[
		'template_name'=>'bench.tpl',
		'inline'=>false,
		'duration_ms'=>4.25,
		'failed'=>false,
		'strict_mode'=>true,
		'render_trace_id'=>'render-summary-123',
		'asset_policy'=>[
			'preload'=>[
				'styles'=>true,
				'scripts'=>true,
				'images'=>false,
				'fonts'=>true,
			],
			'scripts'=>[
				'strategy'=>'defer',
				'type'=>'module',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'anonymous',
			],
		],
		'templates'=>range(1, 12),
		'partials'=>range(1, 10),
		'components'=>range(1, 8),
		'assets'=>range(1, 14),
		'dependencies'=>range(1, 6),
		'translations'=>range(1, 9),
		'bindings'=>make_bindings_with_traces(40),
		'binding_trace'=>make_bindings_with_traces(40),
		'binding_errors'=>make_bindings_with_errors(5),
		'binding_warnings'=>make_binding_warnings(6),
		'binding_planner'=>range(1, 7),
		'contract_violations'=>range(1, 3),
		'undefined_variables'=>range(1, 4),
		'missing_references'=>range(1, 2),
	];
	$results[]=bench_run(
		'template-manifest-summary-fresh',
		static fn() => (new TemplateManifest($payload))->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-asset-policy'){
	$manifest=new TemplateManifest([
		'asset_policy'=>[
			'preload'=>[
				'styles'=>true,
				'scripts'=>true,
				'images'=>false,
				'fonts'=>true,
			],
			'scripts'=>[
				'strategy'=>'defer',
				'type'=>'module',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'anonymous',
			],
		],
	]);
	$results[]=bench_run(
		'template-manifest-asset-policy',
		static fn() => $manifest->assetPolicy()->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-manifest-asset-policy-fresh'){
	$payload=[
		'asset_policy'=>[
			'preload'=>[
				'styles'=>true,
				'scripts'=>true,
				'images'=>false,
				'fonts'=>true,
			],
			'scripts'=>[
				'strategy'=>'defer',
				'type'=>'module',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'anonymous',
			],
		],
	];
	$results[]=bench_run(
		'template-manifest-asset-policy-fresh',
		static fn() => (new TemplateManifest($payload))->assetPolicy()->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-plan-summary'){
	$payload=[
		'template_name'=>'bench.tpl',
		'inline'=>false,
		'cache_mode'=>'filesystem',
		'all_templates'=>range(1, 16),
		'aggregate'=>[
			'data_paths'=>range(1, 30),
			'slot_names'=>range(1, 8),
			'partials'=>range(1, 10),
			'components'=>range(1, 7),
			'imports'=>range(1, 5),
			'layouts'=>range(1, 2),
			'assets'=>range(1, 18),
			'dependencies'=>range(1, 9),
		],
		'unresolved_references'=>range(1, 4),
		'asset_manifest'=>[
			'missing'=>['/assets/missing.css', '/assets/missing.js'],
		],
	];
	$plan=new TemplatePlan($payload);
	$results[]=bench_run(
		'template-plan-summary',
		static fn() => $plan->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-plan-summary-fresh'){
	$payload=[
		'template_name'=>'bench.tpl',
		'inline'=>false,
		'cache_mode'=>'filesystem',
		'all_templates'=>range(1, 16),
		'aggregate'=>[
			'data_paths'=>range(1, 30),
			'slot_names'=>range(1, 8),
			'partials'=>range(1, 10),
			'components'=>range(1, 7),
			'imports'=>range(1, 5),
			'layouts'=>range(1, 2),
			'assets'=>range(1, 18),
			'dependencies'=>range(1, 9),
		],
		'unresolved_references'=>range(1, 4),
		'asset_manifest'=>[
			'missing'=>['/assets/missing.css', '/assets/missing.js'],
		],
	];
	$results[]=bench_run(
		'template-plan-summary-fresh',
		static fn() => (new TemplatePlan($payload))->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-plan-asset-manifest'){
	$plan=new TemplatePlan([
		'asset_manifest'=>[
			'items'=>range(1, 20),
			'stylesheets'=>range(1, 4),
			'scripts'=>range(1, 6),
			'head_tags'=>['<link rel="stylesheet" href="/a.css">'],
			'body_tags'=>['<script src="/a.js"></script>'],
			'missing'=>['/assets/missing.css', '/assets/missing.js'],
			'policy'=>[
				'scripts'=>['strategy'=>'defer', 'type'=>'module'],
				'styles'=>['media'=>'screen'],
			],
			'signature'=>'plan-asset-manifest',
		],
	]);
	$results[]=bench_run(
		'template-plan-asset-manifest',
		static fn() => $plan->assetManifest()->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-plan-asset-manifest-fresh'){
	$payload=[
		'asset_manifest'=>[
			'items'=>range(1, 20),
			'stylesheets'=>range(1, 4),
			'scripts'=>range(1, 6),
			'head_tags'=>['<link rel="stylesheet" href="/a.css">'],
			'body_tags'=>['<script src="/a.js"></script>'],
			'missing'=>['/assets/missing.css', '/assets/missing.js'],
			'policy'=>[
				'scripts'=>['strategy'=>'defer', 'type'=>'module'],
				'styles'=>['media'=>'screen'],
			],
			'signature'=>'plan-asset-manifest',
		],
	];
	$results[]=bench_run(
		'template-plan-asset-manifest-fresh',
		static fn() => (new TemplatePlan($payload))->assetManifest()->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-plan-suggested-contract'){
	$plan=new TemplatePlan([
		'suggested_contract'=>[
			'required'=>['title', 'price', 'title', ' image '],
			'optional'=>['badge', 'image', 'badge', ''],
			'slots'=>['body', 'footer', 'body'],
			'defaults'=>['badge'=>'New', ' image '=>null],
			'types'=>['title'=>' string ', 'price'=>' float ', 'bad'=>' '],
			'allow_additional_data'=>false,
			'allow_additional_slots'=>true,
		],
	]);
	$results[]=bench_run(
		'template-plan-suggested-contract',
		static fn() => $plan->suggestedContract()->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-plan-suggested-contract-fresh'){
	$payload=[
		'suggested_contract'=>[
			'required'=>['title', 'price', 'title', ' image '],
			'optional'=>['badge', 'image', 'badge', ''],
			'slots'=>['body', 'footer', 'body'],
			'defaults'=>['badge'=>'New', ' image '=>null],
			'types'=>['title'=>' string ', 'price'=>' float ', 'bad'=>' '],
			'allow_additional_data'=>false,
			'allow_additional_slots'=>true,
		],
	];
	$results[]=bench_run(
		'template-plan-suggested-contract-fresh',
		static fn() => (new TemplatePlan($payload))->suggestedContract()->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-contract-from-array-alternating'){
	$definitionA=[
		'required'=>['title', 'price', 'title', ' image '],
		'optional'=>['badge', 'image', 'badge', ''],
		'slots'=>['body', 'footer', 'body'],
		'defaults'=>['badge'=>'New', ' image '=>null],
		'types'=>['title'=>' string ', 'price'=>' float ', 'bad'=>' '],
		'allow_additional_data'=>false,
		'allow_additional_slots'=>true,
	];
	$definitionB=[
		'required'=>['name', 'email', 'name', ' phone '],
		'optional'=>['avatar', 'phone', 'avatar', ''],
		'slots'=>['main', 'aside', 'main'],
		'defaults'=>['avatar'=>'/img.png', ' phone '=>null],
		'types'=>['name'=>' string ', 'email'=>' string ', 'phone'=>' string '],
		'allow_additional_data'=>true,
		'allow_additional_slots'=>false,
	];
	$toggle=false;
	$results[]=bench_run(
		'template-contract-from-array-alternating',
		static function() use ($definitionA, $definitionB, &$toggle): array {
			$toggle=!$toggle;
			return TemplateContract::fromArray($toggle ? $definitionA : $definitionB)->toArray();
		},
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='db-cache-names'){
	$results[]=bench_run(
		'db-cache-names',
		static fn() => DB::cacheNames('products', 'shops', 'products', '', 'orders', 'shops'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='db-cache-names-alternating'){
	$toggle=false;
	$results[]=bench_run(
		'db-cache-names-alternating',
		static function() use (&$toggle): array {
			$toggle=!$toggle;
			return $toggle
				? DB::cacheNames('products', 'shops', 'products', '', 'orders', 'shops')
				: DB::cacheNames('customers', 'orders', 'customers', '', 'shops', 'orders');
		},
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='db-invalidation-names'){
	$results[]=bench_run(
		'db-invalidation-names',
		static fn() => DB::invalidationNames('products', 'shops', 'products', '', 'orders', 'shops'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='db-invalidation-names-alternating'){
	$toggle=false;
	$results[]=bench_run(
		'db-invalidation-names-alternating',
		static function() use (&$toggle): array {
			$toggle=!$toggle;
			return $toggle
				? DB::invalidationNames('products', 'shops', 'products', '', 'orders', 'shops')
				: DB::invalidationNames('customers', 'orders', 'customers', '', 'shops', 'orders');
		},
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='db-merge-cache-names'){
	$results[]=bench_run(
		'db-merge-cache-names',
		static fn() => DB::mergeCacheNames([true, 'products'], 'shops', 'products', '', 'orders', 'shops'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='db-merge-invalidation-names'){
	$results[]=bench_run(
		'db-merge-invalidation-names',
		static fn() => DB::mergeInvalidationNames(['products'], 'shops', 'products', '', 'orders', 'shops'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='table-schema-construct'){
	$columns=make_schema_columns(80);
	$projection=make_schema_projection_columns(40);
	$casts=[
		'col_0'=>'integer',
		'col_1'=>'boolean',
		'col_2'=>'array',
		'col_3'=>'timestamp',
		'col_4'=>'double',
	];
	$results[]=bench_run(
		'table-schema-construct-80',
		static fn() => new TableSchema('products', $columns, ['summary'=>$projection], 'col_0', $casts),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='table-schema-columns-list'){
	$schema=new TableSchema('products', make_schema_columns(80));
	$columns=make_schema_projection_columns(40);
	$results[]=bench_run(
		'table-schema-columns-list-40',
		static fn() => $schema->columns($columns),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='table-schema-columns-string-last'){
	$schema=new TableSchema('products', make_schema_columns(80));
	$results[]=bench_run(
		'table-schema-columns-string-last',
		static fn() => $schema->columns('col_79'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='table-schema-cast-rows'){
	$schema=new TableSchema('products', ['id', 'name', 'active', 'payload', 'created_at'], [], 'id', [
		'id'=>'int',
		'active'=>'bool',
		'payload'=>'json',
		'created_at'=>'datetime',
	]);
	$rows=make_schema_cast_rows(100);
	$results[]=bench_run(
		'table-schema-cast-rows-100',
		static fn() => $schema->castRows($rows),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='table-schema-cast-rows-no-casts'){
	$schema=new TableSchema('products', ['id', 'name', 'active', 'payload', 'created_at'], [], 'id');
	$rows=make_schema_cast_rows(100);
	$results[]=bench_run(
		'table-schema-cast-rows-no-casts-100',
		static fn() => $schema->castRows($rows),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sql-error-unknown-column'){
	$knownColumns=make_schema_projection_columns(100);
	$results[]=bench_run(
		'sql-error-unknown-column-100',
		static fn() => SqlError::unknownColumn('products', 'missing_column', $knownColumns),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='record-except'){
	$record=make_record(100);
	$columns=[];
	for($index=0; $index<25; $index++){
		$columns[]='col_'.($index * 3);
	}
	$results[]=bench_run(
		'record-except-100-minus-25',
		static fn() => $record->except($columns),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='record-except-fresh'){
	$columns=[];
	for($index=0; $index<25; $index++){
		$columns[]='col_'.($index * 3);
	}
	$results[]=bench_run(
		'record-except-fresh-100-minus-25',
		static fn() => make_record(100)->except($columns),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='record-only'){
	$record=make_record(100);
	$columns=[];
	for($index=0; $index<25; $index++){
		$columns[]='col_'.($index * 3);
	}
	$results[]=bench_run(
		'record-only-100-select-25',
		static fn() => $record->only($columns),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='record-only-fresh'){
	$columns=[];
	for($index=0; $index<25; $index++){
		$columns[]='col_'.($index * 3);
	}
	$results[]=bench_run(
		'record-only-fresh-100-select-25',
		static fn() => make_record(100)->only($columns),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='currency-bridge-normalize-money'){
	$results[]=bench_run(
		'currency-bridge-normalize-money-fixed',
		static fn() => CurrencyBridge::normalizeMoneyMapping(' amount ', null, ' usd ', ' money ', 'products'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-minor-core'){
	$_SESSION['exchange_rate_data']=[
		'data'=>[
			'USD'=>1.0,
			'CAD'=>1.35,
			'EUR'=>0.9,
		],
		'time'=>time(),
		'source'=>'benchmark',
	];
	$results[]=bench_run(
		'currency-amount-to-minor-decimal-string',
		static fn() => \dataphyre\currency::amount_to_minor_units('1234.567', 'KWD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-minor-to-amount-string',
		static fn() => \dataphyre\currency::minor_units_to_amount(123456, 'USD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-convert-minor-units-cross-currency',
		static fn() => \dataphyre\currency::convert_minor_units(123456, 'USD', 'CAD'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-bridge-normalize-stored-money'){
	$definition=[
		'original_prefix'=>'original_',
		'base_prefix'=>'base_',
		'exchange_prefix'=>'exchange_',
		'base_currency'=>' cad ',
		'target'=>' stored_money ',
	];
	$results[]=bench_run(
		'currency-bridge-normalize-stored-money-prefixes',
		static fn() => CurrencyBridge::normalizeStoredMoneyMapping($definition, null, 'products'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-bridge-money-minor-projection'){
	$mapping=CurrencyBridge::normalizeMoneyMapping(' amount_minor ', ' currency ', null, ' money ', 'products');
	$row=[
		'amount_minor'=>2000,
		'currency'=>'usd',
	];
	$money=new Money('19.995', 'usd');
	$results[]=bench_run(
		'currency-bridge-apply-money-minor-row',
		static fn() => CurrencyBridge::applyMoneyMapping($row, $mapping, 'products'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-bridge-expand-money-minor-write',
		static fn() => CurrencyBridge::expandWriteFields(['money'=>$money], [$mapping], [], 'products'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-bridge-money-comparable'){
	$money=new Money('19.995', 'usd');
	$results[]=bench_run(
		'currency-bridge-money-comparable-int',
		static fn() => CurrencyBridge::normalizeComparableValue(1995, 'usd', 'products', 'amount_minor'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-bridge-money-comparable-string',
		static fn() => CurrencyBridge::normalizeComparableValue('19.95', 'usd', 'products', 'amount_minor'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-bridge-money-comparable-money',
		static fn() => CurrencyBridge::normalizeComparableValue($money, null, 'products', 'amount_minor'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='table-definition-create-queries'){
	$definition=make_table_definition_for_queries();
	$results[]=bench_run(
		'table-definition-create-queries',
		static fn() => $definition->createQueries(),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='table-definition-create-queries-fresh'){
	$results[]=bench_run(
		'table-definition-create-queries-fresh',
		static fn() => make_table_definition_for_queries()->createQueries(),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='table-definition-build'){
	$results[]=bench_run(
		'table-definition-build',
		static fn() => make_table_definition_for_queries(),
		10000,
		250
	);
}

if($scenario==='all' || $scenario==='execution-trace-from-array'){
	$payload=[
		'source'=>' sql ',
		'event'=>' query_executed ',
		'operation'=>' select ',
		'cache_names'=>[' products ', 'shops', 'products', '', 'orders', 'shops'],
		'invalidation_names'=>['products', ' products ', 'inventory', null, 'inventory'],
		'result_ok'=>true,
		'context'=>[
			'render_trace_id'=>' tpl_abc ',
			'binding_trace_id'=>' bind_123 ',
		],
		'query_fingerprint'=>'abc123',
		'timestamp'=>1717358400.25,
	];
	$results[]=bench_run(
		'execution-trace-from-array',
		static fn() => ExecutionTrace::fromArray($payload),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='execution-trace-context-accessors'){
	$trace=ExecutionTrace::fromArray([
		'context'=>[
			'render_trace_id'=>' tpl_abc ',
			'binding_trace_id'=>' bind_123 ',
			'query_fingerprint'=>' fp_123 ',
			'query_identity_mode'=>' repository ',
			'query_identity_source'=>' fingerprint ',
			'query_target_type'=>' table ',
			'query_target'=>' products ',
			'query_mode'=>' read ',
		],
	]);
	$results[]=bench_run(
		'execution-trace-context-accessors',
		static fn() => [
			$trace->renderTraceId(),
			$trace->bindingTraceId(),
			$trace->queryFingerprint(),
			$trace->queryIdentityMode(),
			$trace->queryIdentitySource(),
			$trace->queryTargetType(),
			$trace->queryTarget(),
			$trace->queryMode(),
		],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mutation-batch-successful'){
	$batch=make_mutation_batch(100);
	$results[]=bench_run(
		'mutation-batch-successful-100',
		static fn() => $batch->successful(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mutation-batch-first-error'){
	$batch=make_mutation_batch(100);
	$results[]=bench_run(
		'mutation-batch-first-error-100',
		static fn() => $batch->firstErrorMessage(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mutation-batch-failed-count'){
	$batch=make_mutation_batch(100);
	$results[]=bench_run(
		'mutation-batch-failed-count-100',
		static fn() => $batch->failedCount(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mutation-batch-ok'){
	$batch=make_mutation_batch(100);
	$results[]=bench_run(
		'mutation-batch-ok-100',
		static fn() => $batch->ok(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mutation-batch-failed'){
	$batch=make_mutation_batch(100);
	$results[]=bench_run(
		'mutation-batch-failed-100',
		static fn() => $batch->failed(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mutation-batch-json'){
	$batch=make_mutation_batch(100);
	$results[]=bench_run(
		'mutation-batch-json-100',
		static fn() => $batch->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='mutation-batch-json-fresh'){
	$results[]=bench_run(
		'mutation-batch-json-fresh-100',
		static fn() => make_mutation_batch(100)->jsonSerialize(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='mutation-result-json-update'){
	$result=MutationResult::fromRaw('update', 1, ['table'=>'products', 'row'=>42]);
	$results[]=bench_run(
		'mutation-result-json-update',
		static fn() => $result->jsonSerialize(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='transaction-result-json-success'){
	$result=make_transaction_success_result();
	$results[]=bench_run(
		'transaction-result-json-success',
		static fn() => $result->jsonSerialize(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='transaction-result-json-failure'){
	$result=make_transaction_failure_result();
	$results[]=bench_run(
		'transaction-result-json-failure',
		static fn() => $result->jsonSerialize(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='transaction-result-json-failure-fresh'){
	$results[]=bench_run(
		'transaction-result-json-failure-fresh',
		static fn() => make_transaction_failure_result()->jsonSerialize(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='page-result-map'){
	$page=make_page_result(100);
	$results[]=bench_run(
		'page-result-map-100',
		static fn() => $page->map(static fn(array $item): array => [
			'id'=>$item['id'],
			'label'=>$item['name'],
			'available'=>$item['active'] && $item['stock'] > 0,
		]),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='page-result-json'){
	$page=make_page_result(100);
	$results[]=bench_run(
		'page-result-json-100',
		static fn() => $page->jsonSerialize(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='page-result-json-fresh'){
	$results[]=bench_run(
		'page-result-json-fresh-100',
		static fn() => make_page_result(100)->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='page-result-pluck'){
	$page=make_page_result(100);
	$results[]=bench_run(
		'page-result-pluck-100',
		static fn() => $page->pluck('name', 'id'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='page-result-pluck-fresh'){
	$results[]=bench_run(
		'page-result-pluck-fresh-100',
		static fn() => make_page_result(100)->pluck('name', 'id'),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='page-result-key-by'){
	$page=make_page_result(100);
	$results[]=bench_run(
		'page-result-key-by-100',
		static fn() => $page->keyBy('id'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='page-result-key-by-fresh'){
	$results[]=bench_run(
		'page-result-key-by-fresh-100',
		static fn() => make_page_result(100)->keyBy('id'),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='query-spec-columns-list'){
	$columns=[' id ', 'name', '', 'products.id', 'name', 'status', 'status', 'created_at'];
	$results[]=bench_run(
		'query-spec-columns-list',
		static fn() => QuerySpec::columns($columns),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-columns-list-alternating'){
	$columnsA=[' id ', 'name', '', 'products.id', 'name', 'status', 'status', 'created_at'];
	$columnsB=[' sku ', 'name', 'sku', '', 'price', 'created_at', 'price', 'updated_at'];
	$toggle=false;
	$results[]=bench_run(
		'query-spec-columns-list-alternating',
		static function() use ($columnsA, $columnsB, &$toggle): array|string {
			$toggle=!$toggle;
			return QuerySpec::columns($toggle ? $columnsA : $columnsB);
		},
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-columns-string'){
	$results[]=bench_run(
		'query-spec-columns-string',
		static fn() => QuerySpec::columns(' id, name '),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-group-by-duplicates'){
	$groups=['shop_id', 'status', 'shop_id', 'created_at', 'status', 'products.id'];
	$results[]=bench_run(
		'query-spec-group-by-duplicates',
		static fn() => (new QuerySpec())->groupBy($groups),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-group-by-existing-duplicates'){
	$groups=['status', 'created_at', 'status', 'products.id'];
	$results[]=bench_run(
		'query-spec-group-by-existing-duplicates',
		static fn() => (new QuerySpec())
			->groupByRaw('shop_id')
			->groupByRaw('status')
			->groupByRaw('shop_id')
			->groupBy($groups),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-lock-raw-map'){
	$lock=[
		'mysql'=>' FOR UPDATE ',
		'postgresql'=>' FOR SHARE ',
		'sqlite'=>' ',
	];
	$results[]=bench_run(
		'query-spec-lock-raw-map',
		static fn() => (new QuerySpec())->lockRaw($lock),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-compile-where'){
	$spec=make_query_spec_for_compile(false);
	$results[]=bench_run(
		'query-spec-compile-where',
		static fn() => $spec->compile(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-compile-having'){
	$spec=make_query_spec_for_compile(true);
	$results[]=bench_run(
		'query-spec-compile-having',
		static fn() => $spec->compile(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-compile-lock'){
	$spec=make_query_spec_for_compile(false)->forUpdate();
	$results[]=bench_run(
		'query-spec-compile-lock',
		static fn() => $spec->compile(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='query-spec-compile-empty'){
	$spec=new QuerySpec();
	$results[]=bench_run(
		'query-spec-compile-empty',
		static fn() => $spec->compile(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-head-html-fallback'){
	$manifest=new AssetManifest([
		'head_tags'=>[
			'<link rel="stylesheet" href="/assets/app.css">',
			'<link rel="preload" href="/assets/app.js" as="script">',
			'<style>.app{display:block}</style>',
		],
	]);
	$results[]=bench_run(
		'asset-manifest-head-html-fallback',
		static fn() => $manifest->headHtml(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-head-html-present'){
	$manifest=new AssetManifest([
		'head_html'=>'<link rel="stylesheet" href="/assets/app.css">',
		'head_tags'=>[
			'<link rel="stylesheet" href="/assets/app.css">',
			'<link rel="preload" href="/assets/app.js" as="script">',
		],
	]);
	$results[]=bench_run(
		'asset-manifest-head-html-present',
		static fn() => $manifest->headHtml(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-body-html-fallback'){
	$manifest=new AssetManifest([
		'body_tags'=>[
			'<script src="/assets/app.js"></script>',
			'<script src="/assets/chunk.js" defer></script>',
		],
	]);
	$results[]=bench_run(
		'asset-manifest-body-html-fallback',
		static fn() => $manifest->bodyHtml(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-body-html-present'){
	$manifest=new AssetManifest([
		'body_html'=>'<script src="/assets/app.js"></script>',
		'body_tags'=>[
			'<script src="/assets/app.js"></script>',
			'<script src="/assets/chunk.js" defer></script>',
		],
	]);
	$results[]=bench_run(
		'asset-manifest-body-html-present',
		static fn() => $manifest->bodyHtml(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-html-fallback'){
	$manifest=new AssetManifest([
		'all_tags'=>[
			'<link rel="stylesheet" href="/assets/app.css">',
			'<script src="/assets/app.js"></script>',
			'<script src="/assets/chunk.js" defer></script>',
		],
	]);
	$results[]=bench_run(
		'asset-manifest-html-fallback',
		static fn() => $manifest->html(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-html-present'){
	$manifest=new AssetManifest([
		'html'=>'<link rel="stylesheet" href="/assets/app.css">'."\n".'<script src="/assets/app.js"></script>',
		'all_tags'=>[
			'<link rel="stylesheet" href="/assets/app.css">',
			'<script src="/assets/app.js"></script>',
		],
	]);
	$results[]=bench_run(
		'asset-manifest-html-present',
		static fn() => $manifest->html(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-has-missing'){
	$manifest=new AssetManifest([
		'missing'=>[
			'/assets/missing.css',
			'/assets/missing.js',
		],
	]);
	$results[]=bench_run(
		'asset-manifest-has-missing',
		static fn() => $manifest->hasMissingAssets(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-has-no-missing'){
	$manifest=new AssetManifest([
		'missing'=>[],
	]);
	$results[]=bench_run(
		'asset-manifest-has-no-missing',
		static fn() => $manifest->hasMissingAssets(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-summary'){
	$payload=[
		'items'=>range(1, 40),
		'stylesheets'=>range(1, 8),
		'scripts'=>range(1, 10),
		'images'=>range(1, 12),
		'fonts'=>range(1, 3),
		'preloads'=>range(1, 6),
		'head_items'=>range(1, 14),
		'body_items'=>range(1, 9),
		'missing'=>['/assets/missing.css', '/assets/missing.js'],
		'policy'=>[
			'preload'=>[
				'styles'=>true,
				'scripts'=>true,
				'images'=>false,
				'fonts'=>true,
			],
			'scripts'=>[
				'strategy'=>'defer',
				'type'=>'module',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'anonymous',
			],
		],
		'signature'=>'asset-summary-benchmark',
	];
	$manifest=new AssetManifest($payload);
	$results[]=bench_run(
		'asset-manifest-summary',
		static fn() => $manifest->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-manifest-summary-fresh'){
	$payload=[
		'items'=>range(1, 40),
		'stylesheets'=>range(1, 8),
		'scripts'=>range(1, 10),
		'images'=>range(1, 12),
		'fonts'=>range(1, 3),
		'preloads'=>range(1, 6),
		'head_items'=>range(1, 14),
		'body_items'=>range(1, 9),
		'missing'=>['/assets/missing.css', '/assets/missing.js'],
		'policy'=>[
			'preload'=>[
				'styles'=>true,
				'scripts'=>true,
				'images'=>false,
				'fonts'=>true,
			],
			'scripts'=>[
				'strategy'=>'defer',
				'type'=>'module',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'anonymous',
			],
		],
		'signature'=>'asset-summary-benchmark',
	];
	$results[]=bench_run(
		'asset-manifest-summary-fresh',
		static fn() => (new AssetManifest($payload))->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-policy-to-array-normalized'){
	$policy=AssetPolicy::fromArray([
		'preload'=>[
			'style'=>false,
			'scripts'=>true,
			'image'=>false,
			'font'=>true,
		],
		'scripts'=>[
			'strategy'=>'DEFER',
			'type'=>'MODULE',
		],
		'styles'=>[
			'media'=>' screen ',
		],
		'fonts'=>[
			'crossorigin'=>'use-credentials',
		],
	])->preload('css', 'img')->withoutPreload('js')->scriptAsync()->classicScripts()->styleMedia('print');
	$results[]=bench_run(
		'asset-policy-to-array-normalized',
		static fn() => $policy->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='asset-policy-from-array-alternating'){
	$definitionA=[
		'preload'=>[
			'styles'=>true,
			'scripts'=>true,
			'images'=>false,
			'fonts'=>true,
		],
		'scripts'=>[
			'strategy'=>'defer',
			'type'=>'module',
		],
		'styles'=>[
			'media'=>'screen',
		],
		'fonts'=>[
			'crossorigin'=>'anonymous',
		],
	];
	$definitionB=[
		'preload'=>[
			'style'=>false,
			'script'=>true,
			'image'=>true,
			'font'=>false,
		],
		'scripts'=>[
			'strategy'=>'async',
			'type'=>'classic',
		],
		'styles'=>[
			'media'=>'print',
		],
		'fonts'=>[
			'crossorigin'=>'none',
		],
	];
	$toggle=false;
	$results[]=bench_run(
		'asset-policy-from-array-alternating',
		static function() use ($definitionA, $definitionB, &$toggle): array {
			$toggle=!$toggle;
			return AssetPolicy::fromArray($toggle ? $definitionA : $definitionB)->summary();
		},
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='templating-state-asset-policy'){
	$state=TemplatingState::fromArray([
		'is_dev_mode'=>true,
		'cache_dir'=>'/tmp/dataphyre/templates',
		'global_context'=>[
			'app'=>'DataphyreBench',
			'locale'=>'en_CA',
		],
		'strict_mode'=>true,
		'template_contracts'=>[
			'catalog/card.tpl'=>[
				'required'=>['title', 'price'],
				'optional'=>['image', 'badge'],
			],
		],
		'asset_policy'=>[
			'preload'=>[
				'style'=>false,
				'scripts'=>true,
				'image'=>false,
				'font'=>true,
			],
			'scripts'=>[
				'strategy'=>'DEFER',
				'type'=>'MODULE',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'use-credentials',
			],
		],
	]);
	$results[]=bench_run(
		'templating-state-asset-policy',
		static fn() => $state->assetPolicy()->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='templating-state-asset-policy-fresh'){
	$payload=[
		'is_dev_mode'=>true,
		'cache_dir'=>'/tmp/dataphyre/templates',
		'global_context'=>[
			'app'=>'DataphyreBench',
			'locale'=>'en_CA',
		],
		'strict_mode'=>true,
		'template_contracts'=>[
			'catalog/card.tpl'=>[
				'required'=>['title', 'price'],
				'optional'=>['image', 'badge'],
			],
		],
		'asset_policy'=>[
			'preload'=>[
				'style'=>false,
				'scripts'=>true,
				'image'=>false,
				'font'=>true,
			],
			'scripts'=>[
				'strategy'=>'DEFER',
				'type'=>'MODULE',
			],
			'styles'=>[
				'media'=>'screen',
			],
			'fonts'=>[
				'crossorigin'=>'use-credentials',
			],
		],
	];
	$results[]=bench_run(
		'templating-state-asset-policy-fresh',
		static fn() => TemplatingState::fromArray($payload)->assetPolicy()->summary(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='template-contract-from-array-duplicates'){
	$keys=[];
	for($index=0; $index<120; $index++){
		$keys[]='field_'.($index % 24);
		$keys[]=' field_'.($index % 24).' ';
	}
	$slots=[];
	for($index=0; $index<80; $index++){
		$slots[]='slot_'.($index % 16);
		$slots[]=' slot_'.($index % 16).' ';
	}
	$definition=[
		'required'=>$keys,
		'optional'=>array_reverse($keys),
		'slots'=>$slots,
		'optional_slots'=>array_reverse($slots),
		'defaults'=>[
			'title'=>'Catalog',
			' page '=>1,
			5=>'ignored',
			''=>'ignored',
		],
		'types'=>[
			'title'=>' STRING ',
			'page'=>' INT ',
			'bad'=>' ',
			10=>'ignored',
		],
		'allow_additional_data'=>false,
		'allow_additional_slots'=>false,
	];
	$results[]=bench_run(
		'template-contract-from-array-duplicates',
		static fn() => TemplateContract::fromArray($definition)->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='template-contract-from-array-unique'){
	$keys=[];
	for($index=0; $index<240; $index++){
		$keys[]='field_'.$index;
	}
	$slots=[];
	for($index=0; $index<160; $index++){
		$slots[]='slot_'.$index;
	}
	$definition=[
		'required'=>$keys,
		'optional'=>array_reverse($keys),
		'slots'=>$slots,
		'optional_slots'=>array_reverse($slots),
		'defaults'=>[
			'title'=>'Catalog',
			'page'=>1,
			5=>'ignored',
			''=>'ignored',
		],
		'types'=>[
			'title'=>'string',
			'page'=>'int',
			'bad'=>' ',
			10=>'ignored',
		],
		'allow_additional_data'=>false,
		'allow_additional_slots'=>false,
	];
	$results[]=bench_run(
		'template-contract-from-array-unique',
		static fn() => TemplateContract::fromArray($definition)->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='runtime-trace-sql-arrays'){
	$trace=make_runtime_trace(100);
	$results[]=bench_run(
		'runtime-trace-sql-arrays-100',
		static fn() => $trace->sqlTraceArrays(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='runtime-trace-bindings-with-sql'){
	$trace=make_runtime_trace(100);
	$results[]=bench_run(
		'runtime-trace-bindings-with-sql-100',
		static fn() => $trace->bindingsWithSql(),
		1000,
		100
	);
}

if($scenario==='all' || $scenario==='runtime-trace-sql-for-binding'){
	$trace=make_runtime_trace(100);
	$results[]=bench_run(
		'runtime-trace-sql-for-binding-100',
		static fn() => $trace->sqlTracesForBinding('tpl_abc.b0099'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='runtime-trace-orphan-sql'){
	$trace=make_runtime_trace(100);
	$results[]=bench_run(
		'runtime-trace-orphan-sql-100',
		static fn() => $trace->orphanSqlTraces(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='runtime-trace-summary'){
	$trace=make_runtime_trace(100);
	$results[]=bench_run(
		'runtime-trace-summary-100',
		static fn() => $trace->summary(),
		1000,
		100
	);
}

if($scenario==='all' || $scenario==='runtime-trace-summary-fresh'){
	$results[]=bench_run(
		'runtime-trace-summary-fresh-100',
		static fn() => make_runtime_trace(100)->summary(),
		100,
		10
	);
}

if($scenario==='all' || $scenario==='uploaded-file-extension'){
	$file=make_uploaded_file();
	$results[]=bench_run(
		'uploaded-file-extension',
		static fn() => $file->clientExtension(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='uploaded-file-extension-fresh'){
	$results[]=bench_run(
		'uploaded-file-extension-fresh',
		static fn() => make_uploaded_file()->clientExtension(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-route-name'){
	$request=Request::create('GET', '/catalog', [], [], [], [], [], [])->setAttribute('route_name', 'catalog.products.index');
	$results[]=bench_run(
		'request-route-name',
		static fn() => $request->routeName(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-route-is-wildcard'){
	$request=Request::create('GET', '/catalog', [], [], [], [], [], [])->setAttribute('route_name', 'catalog.products.index');
	$results[]=bench_run(
		'request-route-is-wildcard',
		static fn() => $request->routeIs('admin.*', 'catalog.products.*'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-has-top-level'){
	$request=Request::create('GET', '/catalog', ['page'=>'1', 'sort'=>'name'], ['name'=>'Ada', 'active'=>'1'], [], [], [], []);
	$results[]=bench_run(
		'request-has-top-level',
		static fn() => $request->has(['page', 'name', 'active']),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-has-nested'){
	$request=Request::create('GET', '/catalog', ['filter'=>['status'=>'active']], ['user'=>['name'=>'Ada']], [], [], [], []);
	$results[]=bench_run(
		'request-has-nested',
		static fn() => $request->has(['filter.status', 'user.name']),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-filled-top-level'){
	$request=Request::create('GET', '/catalog', ['page'=>'1', 'sort'=>'name'], ['name'=>'Ada', 'active'=>'1'], [], [], [], []);
	$results[]=bench_run(
		'request-filled-top-level',
		static fn() => $request->filled(['page', 'name', 'active']),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-filled-nested'){
	$request=Request::create('GET', '/catalog', ['filter'=>['status'=>'active']], ['user'=>['name'=>'Ada']], [], [], [], []);
	$results[]=bench_run(
		'request-filled-nested',
		static fn() => $request->filled(['filter.status', 'user.name']),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-scalar-accessors-top-level'){
	$request=Request::create('GET', '/catalog', ['page'=>'12', 'ratio'=>'1.25'], ['active'=>'1'], [], [], [], []);
	$results[]=bench_run(
		'request-scalar-accessors-top-level',
		static fn() => [$request->boolean('active'), $request->integer('page'), $request->float('ratio')],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-scalar-accessors-nested'){
	$request=Request::create('GET', '/catalog', ['filter'=>['page'=>'12', 'ratio'=>'1.25']], ['flags'=>['active'=>'1']], [], [], [], []);
	$results[]=bench_run(
		'request-scalar-accessors-nested',
		static fn() => [$request->boolean('flags.active'), $request->integer('filter.page'), $request->float('filter.ratio')],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-all-repeated'){
	$request=Request::create(
		'POST',
		'/catalog',
		['filter'=>['page'=>'12', 'ratio'=>'1.25'], 'sort'=>'name'],
		['flags'=>['active'=>'1'], 'name'=>'Ada'],
		[],
		[],
		[],
		[]
	);
	$results[]=bench_run(
		'request-all-repeated',
		static fn() => [$request->all(), $request->all(), $request->all()],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-accepts-json'){
	$request=Request::create('GET', '/catalog', [], [], [], [], [
		'Accept'=>'text/html;q=0.7, application/json;q=0.9, */*;q=0.1',
	], []);
	$results[]=bench_run(
		'request-accepts-json',
		static fn() => $request->accepts('application/json'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-accepts-wildcard'){
	$request=Request::create('GET', '/catalog', [], [], [], [], [
		'Accept'=>'*/*',
	], []);
	$results[]=bench_run(
		'request-accepts-wildcard',
		static fn() => $request->accepts('text/html'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-accepts-json-parse'){
	$accept='text/html, application/xhtml+xml;q=0.9, application/json;q=0.8, */*;q=0.1';
	$results[]=bench_run(
		'request-accepts-json-parse',
		static fn() => Request::create('GET', '/catalog', [], [], [], [], ['Accept'=>$accept], [])->accepts('application/json'),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='request-accepts-json-parse-alternating'){
	$acceptA='text/html, application/xhtml+xml;q=0.9, application/json;q=0.8, */*;q=0.1';
	$acceptB='application/xml;q=0.7, application/json;q=0.9, text/plain;q=0.4';
	$toggle=false;
	$results[]=bench_run(
		'request-accepts-json-parse-alternating',
		static function() use ($acceptA, $acceptB, &$toggle): bool {
			$toggle=!$toggle;
			return Request::create('GET', '/catalog', [], [], [], [], ['Accept'=>$toggle ? $acceptA : $acceptB], [])->accepts('application/json');
		},
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='request-expects-json'){
	$request=Request::create('GET', '/catalog', [], [], [], [], [
		'Accept'=>'text/html;q=0.7, application/json;q=0.9, */*;q=0.1',
	], []);
	$results[]=bench_run(
		'request-expects-json',
		static fn() => $request->expectsJson(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-wants-json'){
	$request=Request::create('GET', '/catalog', [], [], [], [], [
		'Accept'=>'text/html;q=0.7, application/json;q=0.9, */*;q=0.1',
	], []);
	$results[]=bench_run(
		'request-wants-json',
		static fn() => $request->wantsJson(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-forwarded-values'){
	$request=Request::create('GET', '/catalog', [], [], [], [
		'REMOTE_ADDR'=>'10.0.0.5',
		'SERVER_PORT'=>'80',
	], [
		'X-Forwarded-Proto'=>'https, http',
		'X-Forwarded-Host'=>'shop.example.test, internal.example.test',
		'X-Forwarded-For'=>'203.0.113.10, 10.0.0.5',
	], []);
	$results[]=bench_run(
		'request-forwarded-values',
		static fn() => [$request->scheme(), $request->host(), $request->ip()],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-effective-method-post-override'){
	$request=Request::create('POST', '/catalog', [], ['_method'=>'PATCH'], [], [], [], []);
	$results[]=bench_run(
		'request-effective-method-post-override',
		static fn() => $request->effectiveMethod(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-effective-method-get'){
	$request=Request::create('GET', '/catalog', [], [], [], [], [], []);
	$results[]=bench_run(
		'request-effective-method-get',
		static fn() => $request->effectiveMethod(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-user-agent-header'){
	$request=Request::create('GET', '/catalog', [], [], [], [
		'HTTP_USER_AGENT'=>'ServerAgent/0',
	], [
		'User-Agent'=>'BenchAgent/1',
	], []);
	$results[]=bench_run(
		'request-user-agent-header',
		static fn() => $request->userAgent(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='request-content-flags'){
	$request=Request::create('POST', '/catalog', [], [], [], [], [
		'X-Requested-With'=>'XMLHttpRequest',
		'Content-Type'=>'application/vnd.api+json; charset=utf-8',
	], []);
	$results[]=bench_run(
		'request-content-flags',
		static fn() => [$request->ajax(), $request->isJson()],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='response-cookie-chain'){
	$response=Response::make('ok');
	$results[]=bench_run(
		'response-cookie-chain',
		static fn() => $response
			->withCookie('a', '1')
			->withCookie('b', '2')
			->withCookie('c', '3')
			->withCookie('d', '4'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mvc-redirect-cookie-chain'){
	$results[]=bench_run(
		'mvc-redirect-cookie-chain',
		static fn() => (new RedirectResult('/done'))
			->withCookie('a', '1')
			->withCookie('b', '2')
			->withCookie('c', '3')
			->withCookie('d', '4')
			->toResponse(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mvc-view-cookie-chain'){
	$view=ViewResult::make('bench.tpl');
	$results[]=bench_run(
		'mvc-view-cookie-chain',
		static fn() => $view
			->withCookie('a', '1')
			->withCookie('b', '2')
			->withCookie('c', '3')
			->withCookie('d', '4'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mvc-controller-middleware-all'){
	$controller=new ControllerMiddlewareBenchmark();
	$results[]=bench_run(
		'mvc-controller-middleware-all',
		static fn() => $controller->definitions(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mvc-controller-middleware-filtered'){
	$controller=new ControllerMiddlewareBenchmark();
	$results[]=bench_run(
		'mvc-controller-middleware-filtered',
		static fn() => $controller->definitions('update'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mvc-dispatcher-filter-middleware'){
	$dispatcher=(new \ReflectionClass(MvcDispatcher::class))->newInstanceWithoutConstructor();
	$filter=\Closure::bind(
		static fn(MvcDispatcher $dispatcher, array $middleware, array $excluded): array => $dispatcher->filterMiddleware($middleware, $excluded),
		null,
		MvcDispatcher::class
	);
	$middleware=[
		'auth',
		'verified',
		['alias'=>'can', 'parameters'=>['products.update']],
		['alias'=>'throttle', 'parameters'=>[60, 1]],
		['class'=>'App\\Http\\Middleware\\Audit'],
		static fn() => true,
	];
	$excluded=[
		'verified',
		['alias'=>'can', 'parameters'=>['products.update']],
		['class'=>'App\\Http\\Middleware\\Missing'],
	];
	$results[]=bench_run(
		'mvc-dispatcher-filter-middleware',
		static fn() => $filter($dispatcher, $middleware, $excluded),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mvc-money-helpers'){
	$results[]=bench_run(
		'mvc-money-convert-float',
		static fn() => \Dataphyre\Mvc\Mvc::moneyConvert(1234.56, 'USD', 'USD', false, false),
		100000,
		1000
	);
	$results[]=bench_run(
		'mvc-money-convert-decimal-string',
		static fn() => \Dataphyre\Mvc\Mvc::moneyConvert('1234.56', 'USD', 'USD', false, false),
		100000,
		1000
	);
	$results[]=bench_run(
		'mvc-money-round-float',
		static fn() => \Dataphyre\Mvc\Mvc::moneyRound(1234.567, 'USD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'mvc-money-round-decimal-string',
		static fn() => \Dataphyre\Mvc\Mvc::moneyRound('1234.567', 'USD'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='response-is-not-modified-etag'){
	$response=Response::make('ok')->withEtag('abc123');
	$request=Request::create('GET', '/catalog', [], [], [], [], [
		'If-None-Match'=>'"miss1", W/"miss2", "abc123"',
	], []);
	$results[]=bench_run(
		'response-is-not-modified-etag',
		static fn() => $response->isNotModified($request),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='url-value-to-array'){
	$url=new UrlValue('https://user:pass@example.test:8443/catalog/items?page=2&sort=name#details');
	$results[]=bench_run(
		'url-value-to-array',
		static fn() => $url->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='url-value-to-array-fresh'){
	$results[]=bench_run(
		'url-value-to-array-fresh',
		static fn() => (new UrlValue('https://user:pass@example.test:8443/catalog/items?page=2&sort=name#details'))->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='date-value-to-array'){
	$value=make_date_value();
	$results[]=bench_run(
		'date-value-to-array',
		static fn() => $value->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='date-value-to-array-fresh'){
	$results[]=bench_run(
		'date-value-to-array-fresh',
		static fn() => make_date_value()->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='vestra-object-url-fabric'){
	$reference=[
		'driver'=>'vestra',
		'object_id'=>123456789,
		'tenant'=>'dataphyre-bench-tenant',
		'fabric'=>[
			'blockid'=>123456789,
			'tenant_url_template'=>'/v/{tenant}/{rate}/{blockid}',
			'rate_source'=>'tenant_context',
		],
		'tokens'=>[
			'passkey'=>'secret',
		],
	];
	$parameters=[
		'token'=>'g1.testgrant',
		'w'=>640,
		'fit'=>'cover',
	];
	$results[]=bench_run(
		'vestra-object-url-fabric',
		static fn() => \dataphyre\vestra::object_url($reference, $parameters),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='vestra-asset-url-fabric'){
	$reference=[
		'driver'=>'vestra',
		'object_id'=>123456789,
		'tenant'=>'dataphyre-bench-tenant',
		'fabric'=>[
			'blockid'=>123456789,
			'tenant_url_template'=>'/v/{tenant}/{rate}/{blockid}',
			'rate_source'=>'tenant_context',
		],
		'tokens'=>[
			'passkey'=>'secret',
		],
	];
	$parameters=[
		'token'=>'g1.testgrant',
		'w'=>640,
		'fit'=>'cover',
	];
	$results[]=bench_run(
		'vestra-asset-url-fabric',
		static fn() => \dataphyre\vestra::asset_url($reference, 'webp', $parameters),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='vestra-client-object-url-fabric'){
	$reference=[
		'driver'=>'vestra',
		'object_id'=>123456789,
		'tenant'=>'dataphyre-bench-tenant',
		'fabric'=>[
			'blockid'=>123456789,
			'tenant_url_template'=>'/v/{tenant}/{rate}/{blockid}',
			'rate_source'=>'tenant_context',
		],
		'tokens'=>[
			'passkey'=>'secret',
		],
	];
	$parameters=[
		'token'=>'g1.testgrant',
		'w'=>640,
		'fit'=>'cover',
	];
	$results[]=bench_run(
		'vestra-client-object-url-fabric',
		static fn() => \Dataphyre\Vestra\Client::objectUrlFor($reference, $parameters),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='vestra-client-asset-url-fabric'){
	$reference=[
		'driver'=>'vestra',
		'object_id'=>123456789,
		'tenant'=>'dataphyre-bench-tenant',
		'fabric'=>[
			'blockid'=>123456789,
			'tenant_url_template'=>'/v/{tenant}/{rate}/{blockid}',
			'rate_source'=>'tenant_context',
		],
		'tokens'=>[
			'passkey'=>'secret',
		],
	];
	$parameters=[
		'token'=>'g1.testgrant',
		'w'=>640,
		'fit'=>'cover',
	];
	$results[]=bench_run(
		'vestra-client-asset-url-fabric',
		static fn() => \Dataphyre\Vestra\Client::assetUrl($reference, 'webp', $parameters),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='vestra-ingest-known-changes'){
	$reference=[
		'driver'=>'vestra',
		'object_id'=>123456789,
		'tenant'=>'dataphyre-bench-tenant',
		'fabric'=>[
			'blockid'=>123456789,
			'tenant_url_template'=>'/v/{tenant}/{rate}/{blockid}',
			'rate_source'=>'tenant_context',
		],
		'tokens'=>[
			'passkey'=>'secret',
		],
	];
	$html=str_repeat(
		'<img src="/assets/hero.jpg"><link href="/assets/app.css" rel="stylesheet"><script src="/assets/app.js"></script>',
		4
	);
	$knownChanges=[
		'/assets/hero.jpg'=>$reference,
		'/assets/app.css'=>$reference,
		'/assets/app.js'=>$reference,
	];
	$results[]=bench_run(
		'vestra-ingest-known-changes',
		static fn() => \dataphyre\vestra::ingest_resources($html, null, $knownChanges),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='env-get-has-repeated'){
	$values=[
		'bench/env/name'=>'DataphyreBench',
		'bench/env/debug'=>false,
		'bench/env/plan'=>'business',
	];
	for($index=0; $index<64; $index++){
		$values['bench/env/filler/'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	Env::set($values);
	$results[]=bench_run(
		'env-get-has-repeated',
		static fn() => [
			Env::get('bench/env/name'),
			Env::has('bench/env/debug'),
			Env::get('bench/env/missing', 'default'),
		],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='env-only-selection'){
	$values=[
		'bench/env/tenant'=>'demo-tenant',
		'bench/env/region'=>'ca',
		'bench/env/features'=>['catalog', 'checkout', 'media'],
	];
	for($index=0; $index<64; $index++){
		$values['bench/env/filler/'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	Env::set($values);
	$results[]=bench_run(
		'env-only-selection',
		static fn() => Env::only([
			'bench/env/tenant',
			'bench/env/region',
			'bench/env/missing',
			'bench/env/features',
		]),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='env-repository-scoped-read'){
	$repository=Env::scope('bench/env');
	$values=[
		'tenant'=>'demo-tenant',
		'plan'=>'business',
		'limits/images'=>250000,
	];
	for($index=0; $index<64; $index++){
		$values['filler/'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	$repository->set($values);
	$results[]=bench_run(
		'env-repository-scoped-read',
		static fn() => [
			$repository->get('tenant'),
			$repository->has('plan'),
			$repository->get('limits/images'),
		],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='env-repository-only-selection'){
	$repository=Env::scope('bench/env');
	$values=[
		'tenant'=>'demo-tenant',
		'region'=>'ca',
		'features'=>['catalog', 'checkout', 'media'],
	];
	for($index=0; $index<64; $index++){
		$values['filler/'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	$repository->set($values);
	$results[]=bench_run(
		'env-repository-only-selection',
		static fn() => $repository->only([
			'tenant',
			'region',
			'missing',
			'features',
		]),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='env-repository-scope-state'){
	$repository=Env::scope('bench/env_state');
	$values=[
		'tenant'=>'demo-tenant',
		'plan'=>'business',
		'limits/images'=>250000,
	];
	for($index=0; $index<64; $index++){
		$values['filler/'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	$repository->set($values);
	$present=Env::scope('bench/env_state');
	$missing=Env::scope('bench/env_state_missing');
	$root=Env::repository();
	$results[]=bench_run(
		'env-repository-scope-state',
		static fn() => [
			$present->has(),
			$present->isEmpty(),
			$missing->has(),
			$missing->isEmpty(),
			$root->has(),
			$root->isEmpty(),
		],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='env-repository-keys'){
	$repository=Env::scope('bench/env_keys');
	$values=[
		'tenant'=>'demo-tenant',
		'plan'=>'business',
		'limits/images'=>250000,
	];
	for($index=0; $index<64; $index++){
		$values['filler/'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	$repository->set($values);
	$root=Env::repository();
	$results[]=bench_run(
		'env-repository-keys',
		static fn() => [
			$repository->keys(),
			$root->keys(),
		],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='env-snapshot-to-array'){
	$snapshot=new \Dataphyre\EnvSnapshot('app', '/', [
		'name'=>'DataphyreBench',
		'env'=>'production',
		'debug'=>false,
		'cache'=>[
			'driver'=>'redis',
			'ttl'=>300,
		],
	]);
	$results[]=bench_run(
		'env-snapshot-to-array',
		static fn() => $snapshot->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='env-snapshot-to-array-fresh'){
	$results[]=bench_run(
		'env-snapshot-to-array-fresh',
		static fn() => (new \Dataphyre\EnvSnapshot('app', '/', [
			'name'=>'DataphyreBench',
			'env'=>'production',
			'debug'=>false,
			'cache'=>[
				'driver'=>'redis',
				'ttl'=>300,
			],
		]))->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='config-snapshot-to-array'){
	$snapshot=new ConfigSnapshot('modules/http', true, [
		'trusted'=>[
			'headers'=>['x-forwarded-for', 'x-forwarded-host'],
		],
		'limits'=>[
			'body'=>1048576,
			'files'=>12,
		],
	]);
	$results[]=bench_run(
		'config-snapshot-to-array',
		static fn() => $snapshot->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='config-snapshot-to-array-fresh'){
	$results[]=bench_run(
		'config-snapshot-to-array-fresh',
		static fn() => (new ConfigSnapshot('modules/http', true, [
			'trusted'=>[
				'headers'=>['x-forwarded-for', 'x-forwarded-host'],
			],
			'limits'=>[
				'body'=>1048576,
				'files'=>12,
			],
		]))->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='config-repository-only-selection'){
	$config=[
		'trusted'=>[
			'headers'=>['x-forwarded-for', 'x-forwarded-host'],
			'proxies'=>['10.0.0.1', '10.0.0.2'],
		],
		'limits'=>[
			'body'=>1048576,
			'files'=>12,
		],
		'features'=>[
			'compression'=>true,
			'uploads'=>true,
		],
	];
	for($index=0; $index<64; $index++){
		$config['filler_'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	Config::set('bench/config', $config);
	$repository=Config::scope('bench/config');
	$results[]=bench_run(
		'config-repository-only-selection',
		static fn() => $repository->only([
			'trusted/headers',
			'limits/body',
			'missing/path',
			'features/compression',
		]),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='config-only-selection'){
	$config=[
		'trusted'=>[
			'headers'=>['x-forwarded-for', 'x-forwarded-host'],
			'proxies'=>['10.0.0.1', '10.0.0.2'],
		],
		'limits'=>[
			'body'=>1048576,
			'files'=>12,
		],
		'features'=>[
			'compression'=>true,
			'uploads'=>true,
		],
	];
	for($index=0; $index<64; $index++){
		$config['filler_'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	Config::set('bench/static', $config);
	$results[]=bench_run(
		'config-only-selection',
		static fn() => Config::only([
			'bench/static/trusted/headers',
			'bench/static/limits/body',
			'bench/static/missing/path',
			'bench/static/features/compression',
		]),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='config-keys-nested'){
	$config=[
		'trusted'=>[
			'headers'=>['x-forwarded-for', 'x-forwarded-host'],
			'proxies'=>['10.0.0.1', '10.0.0.2'],
		],
		'limits'=>[
			'body'=>1048576,
			'files'=>12,
		],
		'features'=>[
			'compression'=>true,
			'uploads'=>true,
		],
	];
	for($index=0; $index<64; $index++){
		$config['filler_'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	Config::set('bench/keys', $config);
	$results[]=bench_run(
		'config-keys-nested',
		static fn() => Config::keys('bench/keys/trusted'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='config-repository-keys'){
	$config=[
		'trusted'=>[
			'headers'=>['x-forwarded-for', 'x-forwarded-host'],
			'proxies'=>['10.0.0.1', '10.0.0.2'],
		],
		'limits'=>[
			'body'=>1048576,
			'files'=>12,
		],
		'features'=>[
			'compression'=>true,
			'uploads'=>true,
		],
	];
	for($index=0; $index<64; $index++){
		$config['filler_'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	Config::set('bench/repository_keys', $config);
	$repository=Config::scope('bench/repository_keys/trusted');
	$results[]=bench_run(
		'config-repository-keys',
		static fn() => $repository->keys(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='config-repository-is-empty'){
	$config=[
		'trusted'=>[
			'headers'=>['x-forwarded-for', 'x-forwarded-host'],
			'proxies'=>['10.0.0.1', '10.0.0.2'],
		],
		'empty'=>[],
		'scalar'=>false,
	];
	for($index=0; $index<64; $index++){
		$config['filler_'.$index]=[
			'enabled'=>($index % 2)===0,
			'limit'=>$index * 100,
		];
	}
	Config::set('bench/repository_empty', $config);
	$present=Config::scope('bench/repository_empty/trusted');
	$empty=Config::scope('bench/repository_empty/empty');
	$scalar=Config::scope('bench/repository_empty/scalar');
	$missing=Config::scope('bench/repository_empty/missing');
	$results[]=bench_run(
		'config-repository-is-empty',
		static fn() => [
			$present->isEmpty(),
			$empty->isEmpty(),
			$scalar->isEmpty(),
			$missing->isEmpty(),
		],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='config-snapshot-get-nested'){
	$snapshot=new ConfigSnapshot(null, true, [
		'modules'=>[
			'http'=>[
				'trusted'=>[
					'headers'=>['x-forwarded-for', 'x-forwarded-host'],
				],
			],
		],
	]);
	$results[]=bench_run(
		'config-snapshot-get-nested',
		static fn() => $snapshot->get('/modules//http/trusted/headers/'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='session-get-top-level'){
	Session::flush();
	Session::put('name', 'Ada');
	$results[]=bench_run(
		'session-get-top-level',
		static fn() => Session::get('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='session-get-nested'){
	Session::flush();
	Session::put('profile.contact.email', 'ada@example.test');
	$results[]=bench_run(
		'session-get-nested',
		static fn() => Session::get('profile.contact.email'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='session-has-top-level'){
	Session::flush();
	Session::put('name', 'Ada');
	$results[]=bench_run(
		'session-has-top-level',
		static fn() => Session::has('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='session-has-nested'){
	Session::flush();
	Session::put('profile.contact.email', 'ada@example.test');
	$results[]=bench_run(
		'session-has-nested',
		static fn() => Session::has('profile.contact.email'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='session-forget-nested'){
	Session::flush();
	$results[]=bench_run(
		'session-forget-nested',
		static function(): void {
			Session::put('profile.contact.email', 'ada@example.test');
			Session::forget('profile.contact.email');
		},
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='validator-distinct-array-unique'){
	$codes=[];
	for($index=0; $index<200; $index++){
		$codes[]='sku-'.str_pad((string)$index, 4, '0', STR_PAD_LEFT);
	}
	$results[]=bench_run(
		'validator-distinct-array-unique',
		static fn() => Validator::make(['codes'=>$codes], ['codes'=>'array|distinct'])->passes(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='permission-rule-many-duplicates'){
	$rules=[];
	for($index=0; $index<120; $index++){
		$resource=$index % 24;
		$archive=$index % 12;
		$rules[]='Panel::Catalog/Product-'.$resource.' view, panel.catalog.product-'.$resource.'.edit';
		$rules[]=[
			'allow'=>['panel.catalog.product-'.$resource.'.view', '<panel/catalog/product-'.$resource.'/delete>'],
			'deny'=>'panel.catalog.product-'.$archive.'.archive',
			'roles'=>[' Admin ', 'role.Manager', 'admin'],
		];
	}
	$results[]=bench_run(
		'permission-rule-many-duplicates',
		static fn() => PermissionRule::many($rules),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='permission-set-allows-parent-wildcard'){
	$set=PermissionSet::compile([
		'panel.catalog.product.*',
		'-panel.catalog.product.archive',
		'panel.orders.view',
	]);
	$results[]=bench_run(
		'permission-set-allows-parent-wildcard',
		static fn() => $set->allows('panel.catalog.product.variant.price.update'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='permission-set-allows-super-wildcard'){
	$set=PermissionSet::compile(['*']);
	$results[]=bench_run(
		'permission-set-allows-super-wildcard',
		static fn() => $set->allows('panel.catalog.product.variant.price.update'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='permission-set-allows-many-mixed'){
	$set=PermissionSet::compile([
		'panel.catalog.product.*',
		'panel.orders.view',
		'-panel.catalog.product.archive',
		'panel.reports.sales.view',
	]);
	$checks=[];
	for($index=0; $index<80; $index++){
		$checks[]='panel.catalog.product.'.($index % 20).'.view';
		$checks[]='panel.catalog.product.'.($index % 20).'.archive';
		$checks[]='panel.orders.view';
		$checks[]='panel.reports.sales.view';
	}
	$results[]=bench_run(
		'permission-set-allows-many-mixed',
		static fn() => $set->allowsMany($checks),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='permission-namer-from-shield-many'){
	$permissions=[];
	for($index=0; $index<80; $index++){
		$resource='CatalogProduct'.($index % 20);
		$permissions[]='view_any_'.$resource;
		$permissions[]='update_'.$resource;
		$permissions[]='bulk_delete_'.$resource;
		$permissions[]='custom_publish_'.$resource;
	}
	$options=[
		'permission_prefix'=>'panel',
		'resource_prefix'=>'admin',
		'shield_operations'=>[
			'custom_publish'=>'action.publish',
		],
	];
	$results[]=bench_run(
		'permission-namer-from-shield-many',
		static fn() => PermissionNamer::fromShieldMany($permissions, $options),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='permission-namer-to-shield-many'){
	$permissions=[];
	for($index=0; $index<80; $index++){
		$resource='catalogproducts'.($index % 20);
		$permissions[]='panel.admin.'.$resource.'.view_any';
		$permissions[]='panel.admin.'.$resource.'.update';
		$permissions[]='panel.admin.'.$resource.'.delete_any';
		$permissions[]='panel.admin.'.$resource.'.action.publish';
	}
	$options=[
		'permission_prefix'=>'panel',
		'resource_prefix'=>'admin',
		'shield_operations'=>[
			'custom_publish'=>'action.publish',
		],
	];
	$results[]=bench_run(
		'permission-namer-to-shield-many',
		static fn() => PermissionNamer::toShieldMany($permissions, $options),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='permission-snapshot-diff-decisions'){
	$left=[
		'decisions'=>[],
		'roles'=>['admin', 'manager', 'support'],
		'rules'=>['panel.catalog.product.view', 'panel.catalog.product.update', '-panel.catalog.product.delete'],
	];
	$right=[
		'decisions'=>[],
		'roles'=>['admin', 'support', 'auditor'],
		'rules'=>['panel.catalog.product.view', 'panel.catalog.product.publish', '-panel.catalog.product.archive'],
	];
	for($index=0; $index<240; $index++){
		$left['decisions']['panel.catalog.product.'.$index.'.view']=($index % 3)!==0;
		$right['decisions']['panel.catalog.product.'.$index.'.view']=($index % 4)!==0;
		$right['decisions']['panel.catalog.product.'.$index.'.export']=($index % 5)===0;
	}
	$results[]=bench_run(
		'permission-snapshot-diff-decisions',
		static fn() => PermissionSnapshot::diff($left, $right),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='permission-manifest-diff-roles'){
	$left=[
		'roles'=>[],
		'catalog'=>[],
	];
	$right=[
		'roles'=>[],
		'catalog'=>[],
	];
	for($index=0; $index<180; $index++){
		$role='role.'.str_pad((string)$index, 3, '0', STR_PAD_LEFT);
		$left['roles'][$role]=[
			'panel.catalog.product.'.($index % 40).'.view',
			'panel.catalog.product.'.($index % 40).'.update',
		];
		$right['roles'][$role]=[
			'panel.catalog.product.'.($index % 40).'.view',
			'panel.catalog.product.'.($index % 45).'.export',
		];
		$left['catalog'][]=['permission'=>'panel.catalog.product.'.($index % 80).'.view'];
		$right['catalog'][]=['permission'=>'panel.catalog.product.'.($index % 80).'.view'];
		$right['catalog'][]=['permission'=>'panel.catalog.product.'.($index % 25).'.publish'];
	}
	for($index=180; $index<220; $index++){
		$right['roles']['role.'.str_pad((string)$index, 3, '0', STR_PAD_LEFT)]=[
			'panel.catalog.product.'.($index % 40).'.view',
		];
	}
	$results[]=bench_run(
		'permission-manifest-diff-roles',
		static fn() => PermissionManifest::diff($left, $right),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='permission-optimizer-analyze-shadowed'){
	$rules=['panel.*'];
	for($index=0; $index<120; $index++){
		$resource='panel.catalog.product.'.($index % 40);
		$rules[]=$resource.'.view';
		$rules[]=$resource.'.update';
		$rules[]='-'.$resource.'.delete';
		$rules[]=$resource.'.view';
	}
	$results[]=bench_run(
		'permission-optimizer-analyze-shadowed',
		static fn() => PermissionOptimizer::analyze($rules),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='permission-simulator-apply-removals'){
	$rules=[
		'permissions'=>[],
		'roles'=>[],
	];
	$changes=[
		'remove_permissions'=>[],
		'deny_permissions'=>[],
		'grant_roles'=>[],
		'remove_roles'=>[],
	];
	for($index=0; $index<160; $index++){
		$resource='panel.catalog.product.'.($index % 80);
		$rules['permissions'][]=$resource.'.view';
		$rules['permissions'][]=$resource.'.update';
		$rules['roles'][]='role.'.($index % 30);
		if(($index % 3)===0){
			$changes['remove_permissions'][]=$resource.'.view';
		}
		if(($index % 5)===0){
			$changes['deny_permissions'][]=$resource.'.archive';
		}
		if(($index % 7)===0){
			$changes['remove_roles'][]='role.'.($index % 30);
		}
	}
	$changes['grant_roles']=['role.auditor', 'role.support'];
	$results[]=bench_run(
		'permission-simulator-apply-removals',
		static fn() => PermissionSimulator::apply($rules, $changes),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='subject-resolver-roles-promoted-permissions'){
	$subject=[
		'roles'=>['admin', 'editor', 'support'],
		'permissions'=>[],
	];
	for($index=0; $index<180; $index++){
		$subject['permissions'][]='role.department.'.($index % 40);
		$subject['permissions'][]='group.team.'.($index % 25);
		$subject['permissions'][]='panel.catalog.product.'.($index % 80).'.view';
	}
	$results[]=bench_run(
		'subject-resolver-roles-promoted-permissions',
		static fn() => SubjectResolver::roles($subject),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='dialback-event-construct'){
	$callback=static fn() => true;
	$callbacks=[
		$callback,
		'trim',
		'not_a_function',
		[$callback, 'missing'],
		static fn(string $value): string => $value,
		null,
		[strtoupper(...), '__invoke'],
	];
	$results[]=bench_run(
		'dialback-event-construct',
		static fn() => new DialbackEvent('catalog.booted', $callbacks),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='client-address-to-array'){
	$address=ClientAddress::fromArray([
		'ip'=>'10.24.8.15',
		'remote_addr'=>'198.51.100.10',
		'source'=>'header',
		'source_header'=>'x-forwarded-for',
		'trusted_proxy'=>true,
		'trusted_headers'=>['x-forwarded-for', 'x-real-ip'],
		'trusted_proxies'=>['198.51.100.10', '198.51.100.11'],
	]);
	$results[]=bench_run(
		'client-address-to-array',
		static fn() => $address->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='client-address-to-array-fresh'){
	$details=[
		'ip'=>'10.24.8.15',
		'remote_addr'=>'198.51.100.10',
		'source'=>'header',
		'source_header'=>'x-forwarded-for',
		'trusted_proxy'=>true,
		'trusted_headers'=>['x-forwarded-for', 'x-real-ip'],
		'trusted_proxies'=>['198.51.100.10', '198.51.100.11'],
	];
	$results[]=bench_run(
		'client-address-to-array-fresh',
		static fn() => ClientAddress::fromArray($details)->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='module-catalog-to-array'){
	$catalog=ModuleCatalog::fromDefinitions(make_module_definitions(100));
	$results[]=bench_run(
		'module-catalog-to-array-100',
		static fn() => $catalog->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='module-catalog-to-array-fresh'){
	$results[]=bench_run(
		'module-catalog-to-array-fresh-100',
		static fn() => ModuleCatalog::fromDefinitions(make_module_definitions(100))->toArray(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='module-definition-to-array'){
	$definition=\Dataphyre\ModuleDefinition::fromArray([
		'module'=>'catalog',
		'version'=>'2.4.1',
		'enabled'=>true,
		'directory'=>'/srv/app/runtime/modules/catalog',
		'common_directory'=>'/srv/common/runtime/modules/catalog',
		'app_directory'=>'/srv/app/runtime/modules/catalog',
		'kernel_entry'=>'catalog.main.php',
		'framework_entry'=>'Bootstrap.php',
		'framework_directory'=>'Framework',
		'framework_namespace'=>'Catalog',
	]);
	$results[]=bench_run(
		'module-definition-to-array',
		static fn() => $definition->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='module-definition-to-array-fresh'){
	$payload=[
		'module'=>'catalog',
		'version'=>'2.4.1',
		'enabled'=>true,
		'directory'=>'/srv/app/runtime/modules/catalog',
		'common_directory'=>'/srv/common/runtime/modules/catalog',
		'app_directory'=>'/srv/app/runtime/modules/catalog',
		'kernel_entry'=>'catalog.main.php',
		'framework_entry'=>'Bootstrap.php',
		'framework_directory'=>'Framework',
		'framework_namespace'=>'Catalog',
	];
	$results[]=bench_run(
		'module-definition-to-array-fresh',
		static fn() => \Dataphyre\ModuleDefinition::fromArray($payload)->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='application-catalog-to-array'){
	$catalog=new ApplicationCatalog('/project', make_applications(100));
	$results[]=bench_run(
		'application-catalog-to-array-100',
		static fn() => $catalog->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='application-catalog-to-array-fresh'){
	$results[]=bench_run(
		'application-catalog-to-array-fresh-100',
		static fn() => (new ApplicationCatalog('/project', make_applications(100)))->toArray(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='runtime-state-summary'){
	$state=make_runtime_state(10, 100);
	$results[]=bench_run(
		'runtime-state-summary-100-modules',
		static fn() => $state->summary(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='runtime-state-summary-fresh'){
	$results[]=bench_run(
		'runtime-state-summary-fresh-100-modules',
		static fn() => make_runtime_state(10, 100)->summary(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='locale-definition-catalog-json'){
	$catalog=make_locale_definition_catalog(100);
	$results[]=bench_run(
		'locale-definition-catalog-json-100',
		static fn() => $catalog->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='locale-definition-catalog-json-fresh'){
	$results[]=bench_run(
		'locale-definition-catalog-json-fresh-100',
		static fn() => make_locale_definition_catalog(100)->jsonSerialize(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='locale-definition-json'){
	$definition=make_locale_definition(42);
	$results[]=bench_run(
		'locale-definition-json',
		static fn() => $definition->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='locale-definition-json-fresh'){
	$results[]=bench_run(
		'locale-definition-json-fresh',
		static fn() => make_locale_definition(42)->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-jaccard-similarity'){
	$document='durable canvas tote bag recycled cotton reinforced handle blue market grocery everyday carry washable';
	$query='blue recycled cotton grocery tote bag reinforced washable carry';
	$results[]=bench_run(
		'fulltext-jaccard-similarity',
		static fn() => \dataphyre\fulltext_engine\jaccard::similarity($document, $query),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-tokenize-string'){
	$text='Blue cotton cotton tote bag recycled market grocery blue handle washable grocery everyday carry durable cotton';
	$results[]=bench_run(
		'fulltext-tokenize-string',
		static fn() => \dataphyre\fulltext_engine::tokenize_string($text),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-search-hit-array'){
	$hit=new SearchHit('doc-42', 0.9182);
	$results[]=bench_run(
		'fulltext-search-hit-array',
		static fn() => $hit->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-search-hit-array-fresh'){
	$results[]=bench_run(
		'fulltext-search-hit-array-fresh',
		static fn() => (new SearchHit('doc-42', 0.9182))->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-hydrated-hit-array'){
	$hit=new HydratedSearchHit(
		new SearchHit('doc-42', 0.9182),
		['id'=>'doc-42', 'title'=>'Catalog item 42'],
		true
	);
	$results[]=bench_run(
		'fulltext-hydrated-hit-array',
		static fn() => $hit->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-hydrated-hit-array-fresh'){
	$results[]=bench_run(
		'fulltext-hydrated-hit-array-fresh',
		static fn() => (new HydratedSearchHit(
			new SearchHit('doc-42', 0.9182),
			['id'=>'doc-42', 'title'=>'Catalog item 42'],
			true
		))->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-search-results-accessors'){
	$searchResults=make_search_results(100);
	$results[]=bench_run(
		'fulltext-search-results-accessors-100',
		static fn() => [$searchResults->ids(), $searchResults->scores(), $searchResults->toArray()],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-search-results-accessors-fresh'){
	$results[]=bench_run(
		'fulltext-search-results-accessors-fresh-100',
		static function(): array {
			$searchResults=make_search_results(100);
			return [$searchResults->ids(), $searchResults->scores(), $searchResults->toArray()];
		},
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='fulltext-hydrated-results-accessors'){
	$hydratedResults=make_hydrated_search_results(100);
	$results[]=bench_run(
		'fulltext-hydrated-results-accessors-100',
		static fn() => [$hydratedResults->documents(), $hydratedResults->missingIds(), $hydratedResults->toArray()],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-hydrated-results-accessors-fresh'){
	$results[]=bench_run(
		'fulltext-hydrated-results-accessors-fresh-100',
		static function(): array {
			$hydratedResults=make_hydrated_search_results(100);
			return [$hydratedResults->documents(), $hydratedResults->missingIds(), $hydratedResults->toArray()];
		},
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='fulltext-index-sync-report-json'){
	$report=make_index_sync_report(25);
	$results[]=bench_run(
		'fulltext-index-sync-report-json-25',
		static fn() => $report->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-index-sync-report-json-fresh'){
	$results[]=bench_run(
		'fulltext-index-sync-report-json-fresh-25',
		static fn() => make_index_sync_report(25)->jsonSerialize(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='fulltext-index-definition-json'){
	$definition=make_index_definition(42, 'json');
	$results[]=bench_run(
		'fulltext-index-definition-json',
		static fn() => $definition->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='fulltext-index-definition-json-fresh'){
	$results[]=bench_run(
		'fulltext-index-definition-json-fresh',
		static fn() => make_index_definition(42, 'json')->jsonSerialize(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='table-document-resolver-resolve'){
	$resolver=new TableDocumentResolver('catalog_documents', 'id', ['id', 'title', 'title']);
	$ids=make_resolver_ids(100);
	$definition=make_index_definition(1, 'sql');
	$results[]=bench_run(
		'table-document-resolver-resolve-100',
		static fn() => $resolver->resolve($ids, $definition),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='table-document-resolver-resolve-fresh'){
	$ids=make_resolver_ids(100);
	$definition=make_index_definition(1, 'sql');
	$results[]=bench_run(
		'table-document-resolver-resolve-fresh-100',
		static fn() => (new TableDocumentResolver('catalog_documents', 'id', ['id', 'title', 'title']))->resolve($ids, $definition),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='reactor-validator-validate'){
	[$state, $rules]=make_reactor_validation_fixture(40);
	$results[]=bench_run(
		'reactor-validator-validate-40',
		static fn() => ReactorValidator::validate($state, $rules),
		5000,
		100
	);
}

if($scenario==='all' || $scenario==='reactor-snapshot-from'){
	$payload=make_reactor_snapshot_payload(100);
	$results[]=bench_run(
		'reactor-snapshot-from-100-locked',
		static fn() => ReactorSnapshot::from($payload),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='mailer-message-normalize-tags'){
	$payload=make_mailer_message_payload(100);
	$results[]=bench_run(
		'mailer-message-normalize-tags-100',
		static fn() => Message::normalize($payload),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='mailer-send-result-to-array'){
	$result=make_send_result();
	$results[]=bench_run(
		'mailer-send-result-to-array',
		static fn() => $result->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='mailer-send-result-to-array-fresh'){
	$results[]=bench_run(
		'mailer-send-result-to-array-fresh',
		static fn() => make_send_result()->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='oauth-provider-scopes'){
	$provider=new OAuthProvider('benchmark', [], OAuthManager::instance());
	$scopes=make_oauth_scopes(100);
	$results[]=bench_run(
		'oauth-provider-scopes-100',
		static fn() => $provider->scopes($scopes),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='jwt-codec-encode-allowed-algorithms'){
	$claims=['sub'=>'123', 'iat'=>1780430000, 'role'=>'admin'];
	$config=[
		'secret'=>'benchmark-secret',
		'algorithms'=>[' hs256 ', '', 'HS384', 'hs256', ' HS512 '],
	];
	$results[]=bench_run(
		'jwt-codec-encode-allowed-algorithms',
		static fn() => JwtCodec::encode($claims, $config),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='exchange-snapshot-to-array'){
	$snapshot=make_exchange_snapshot(100);
	$results[]=bench_run(
		'exchange-snapshot-to-array-100',
		static fn() => $snapshot->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='exchange-rates-to-array'){
	$rates=make_exchange_rates(100);
	$results[]=bench_run(
		'exchange-rates-to-array-100',
		static fn() => $rates->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='exchange-rates-to-array-fresh'){
	$results[]=bench_run(
		'exchange-rates-to-array-fresh-100',
		static fn() => make_exchange_rates(100)->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='exchange-quote-convert'){
	$quote=make_exchange_quote();
	$results[]=bench_run(
		'exchange-quote-convert',
		static fn() => $quote->convert(1234.56),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-amount-to-minor-units'){
	$results[]=bench_run(
		'currency-amount-to-minor-units-decimal-string',
		static fn() => \dataphyre\currency::amount_to_minor_units('1234.567', 'USD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-amount-to-minor-units-scientific-string',
		static fn() => \dataphyre\currency::amount_to_minor_units('1.234567e3', 'USD'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-minor-units-to-amount'){
	$results[]=bench_run(
		'currency-minor-units-to-amount-string',
		static fn() => \dataphyre\currency::minor_units_to_amount(123457, 'USD'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-source-minor-to-target-manual'){
	seed_currency_exchange_rates();
	$results[]=bench_run(
		'currency-source-minor-to-target-manual',
		static function(): int {
			$converted=\dataphyre\currency::convert(\dataphyre\currency::minor_units_to_amount(123456, 'USD'), 'USD', 'CAD', false, false);
			return \dataphyre\currency::amount_to_minor_units($converted, 'CAD');
		},
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-convert-major-units'){
	seed_currency_exchange_rates();
	$results[]=bench_run(
		'currency-convert-major-units-unformatted',
		static fn() => \dataphyre\currency::convert('1234.56', 'USD', 'CAD', false, false),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-convert-major-units-same-currency',
		static fn() => \dataphyre\currency::convert('1234.56', 'USD', 'USD', false, false),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-convert-major-units-formatted',
		static fn() => \dataphyre\currency::convert('1234.56', 'USD', 'CAD', true, false),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-convert-minor-units'){
	seed_currency_exchange_rates();
	$results[]=bench_run(
		'currency-convert-minor-units',
		static fn() => \dataphyre\currency::convert_minor_units(123456, 'USD', 'CAD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-convert-minor-units-large',
		static fn() => \dataphyre\currency::convert_minor_units(9007199254740993, 'USD', 'CAD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-convert-minor-units-three-decimal',
		static fn() => \dataphyre\currency::convert_minor_units(123, 'USD', 'KWD'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='exchange-quote-to-array'){
	$quote=make_exchange_quote();
	$results[]=bench_run(
		'exchange-quote-to-array',
		static fn() => $quote->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='exchange-quote-to-array-fresh'){
	$results[]=bench_run(
		'exchange-quote-to-array-fresh',
		static fn() => make_exchange_quote()->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='money-construct'){
	$results[]=bench_run(
		'money-construct-decimal-string',
		static fn() => new Money('1234.567', 'usd'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='money-to-array'){
	$money=make_money_value();
	$results[]=bench_run(
		'money-to-array',
		static fn() => $money->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='money-to-array-fresh'){
	$results[]=bench_run(
		'money-to-array-fresh',
		static fn() => make_money_value()->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='money-arithmetic'){
	$manager=CurrencyManager::instance();
	$money=new Money('1234.56', 'USD', $manager);
	$legacyAmount=1234.56;
	$results[]=bench_run(
		'money-multiply-minor-units',
		static fn() => $money->multiply(1.075),
		100000,
		1000
	);
	$results[]=bench_run(
		'money-multiply-float-reference',
		static fn() => new Money($manager->roundAmount($legacyAmount * 1.075, 'USD'), 'USD', $manager),
		100000,
		1000
	);
	$results[]=bench_run(
		'money-divide-minor-units',
		static fn() => $money->divide(3),
		100000,
		1000
	);
	$results[]=bench_run(
		'money-divide-float-reference',
		static fn() => new Money($manager->roundAmount($legacyAmount / 3, 'USD'), 'USD', $manager),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='money-split-allocate'){
	$manager=CurrencyManager::instance();
	$money=Money::fromMinor(123456, 'USD', $manager);
	$ratios=[
		'platform'=>7.5,
		'seller'=>82.5,
		'tax'=>10,
	];
	$results[]=bench_run(
		'money-split-25',
		static fn() => $money->split(25),
		10000,
		100
	);
	$results[]=bench_run(
		'money-split-decimal-roundtrip-reference-25',
		static fn() => $manager->splitAmount($money->decimalAmount(), $money->currency(), 25),
		10000,
		100
	);
	$results[]=bench_run(
		'money-allocate-3',
		static fn() => $money->allocate($ratios),
		10000,
		100
	);
	$results[]=bench_run(
		'money-allocate-decimal-roundtrip-reference-3',
		static fn() => $manager->allocateAmount($money->decimalAmount(), $money->currency(), $ratios),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='money-convert'){
	$manager=CurrencyManager::instance();
	seed_currency_exchange_rates();
	$money=Money::fromMinor(123456, 'USD', $manager);
	$snapshot=$manager->snapshot(false, 'benchmark');
	$quote=$snapshot->quoteOrFail($money->currency(), 'CAD');
	$overrides=$money->contextOverrides();
	$results[]=bench_run(
		'money-convert-manager',
		static fn() => $manager->convertMoney($money, 'CAD'),
		10000,
		100
	);
	$results[]=bench_run(
		'money-convert-manager-direct-minor-reference',
		static fn() => Money::fromMinor($manager->quoteOrFail($money->currency(), 'CAD')->convertMinorUnits($money->minorAmount()), 'CAD', $manager, $overrides),
		10000,
		100
	);
	$results[]=bench_run(
		'money-convert-snapshot',
		static fn() => $snapshot->convertMoney($money, 'CAD'),
		10000,
		100
	);
	$results[]=bench_run(
		'money-convert-snapshot-direct-minor-reference',
		static fn() => Money::fromMinor($quote->convertMinorUnits($money->minorAmount()), 'CAD', $manager, array_replace($snapshot->contextOverrides(), $money->contextOverrides())),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='stored-money-to-array'){
	$stored=make_stored_money_value();
	$results[]=bench_run(
		'stored-money-to-array',
		static fn() => $stored->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='stored-money-to-array-fresh'){
	$results[]=bench_run(
		'stored-money-to-array-fresh',
		static fn() => make_stored_money_value()->toArray(),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-manager-split-amount'){
	$manager=CurrencyManager::instance();
	$results[]=bench_run(
		'currency-manager-split-amount-25',
		static fn() => $manager->splitAmount(1234.56, ' usd ', 25),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='currency-manager-split-minor-units'){
	$manager=CurrencyManager::instance();
	$results[]=bench_run(
		'currency-manager-split-minor-units-25',
		static fn() => $manager->splitMinorUnits(123456, ' usd ', 25),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-kernel-split-minor-units-25',
		static fn() => \dataphyre\currency::split_minor_units(123456, 'USD', 25),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-manager-allocate-amount'){
	$manager=CurrencyManager::instance();
	$ratios=[
		'platform'=>7.5,
		'seller'=>82.5,
		'tax'=>10,
	];
	$results[]=bench_run(
		'currency-manager-allocate-amount-3',
		static fn() => $manager->allocateAmount('1234.56', ' usd ', $ratios),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='currency-manager-allocate-minor-units'){
	$manager=CurrencyManager::instance();
	$ratios=[
		'platform'=>7.5,
		'seller'=>82.5,
		'tax'=>10,
	];
	$results[]=bench_run(
		'currency-manager-allocate-minor-units-3',
		static fn() => $manager->allocateMinorUnits(123456, ' usd ', $ratios),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-kernel-allocate-minor-units-3',
		static fn() => \dataphyre\currency::allocate_minor_units(123456, 'USD', $ratios),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='currency-formatter'){
	$results[]=bench_run(
		'currency-formatter-normal',
		static fn() => \dataphyre\currency::formatter(1234.56, false, 'USD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-formatter-decimal-string',
		static fn() => \dataphyre\currency::formatter('1234.56', false, 'USD'),
		100000,
		1000
	);
	$results[]=bench_run(
		'currency-formatter-large-decimal-string',
		static fn() => \dataphyre\currency::formatter('90071992547409.93', false, 'USD'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='bootstrap-plan-summary'){
	$plan=make_bootstrap_plan_without_files();
	$results[]=bench_run(
		'bootstrap-plan-summary-no-files',
		static fn() => $plan->summary(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='bootstrap-catalog-to-array'){
	$catalog=new BootstrapCatalog('/project', make_bootstrap_plans_without_files(100));
	$results[]=bench_run(
		'bootstrap-catalog-to-array-100-no-files',
		static fn() => $catalog->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='bootstrap-catalog-to-array-fresh'){
	$results[]=bench_run(
		'bootstrap-catalog-to-array-fresh-100-no-files',
		static fn() => (new BootstrapCatalog('/project', make_bootstrap_plans_without_files(100)))->toArray(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='bootstrap-catalog-filter-views'){
	$catalog=new BootstrapCatalog('/project', make_bootstrap_plans_without_files(100));
	$results[]=bench_run(
		'bootstrap-catalog-filter-views-100-no-files',
		static fn() => [$catalog->bootableNames(), $catalog->unbootableNames()],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='bootstrap-catalog-filter-views-fresh'){
	$results[]=bench_run(
		'bootstrap-catalog-filter-views-fresh-100-no-files',
		static function(): array {
			$catalog=new BootstrapCatalog('/project', make_bootstrap_plans_without_files(100));
			return [$catalog->bootableNames(), $catalog->unbootableNames()];
		},
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='module-catalog-filter-views'){
	$catalog=ModuleCatalog::fromDefinitions(make_module_definitions(100));
	$results[]=bench_run(
		'module-catalog-filter-views-100',
		static fn() => [$catalog->enabled()->names(), $catalog->disabled()->names()],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='module-catalog-enabled-names'){
	$catalog=ModuleCatalog::fromDefinitions(make_module_definitions(100));
	$results[]=bench_run(
		'module-catalog-enabled-names-100',
		static fn() => [$catalog->enabledNames(), $catalog->disabledNames()],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='module-catalog-counts'){
	$catalog=ModuleCatalog::fromDefinitions(make_module_definitions(100));
	$results[]=bench_run(
		'module-catalog-counts-100',
		static fn() => [$catalog->enabledCount(), $catalog->disabledCount()],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='module-catalog-count-pair'){
	$catalog=ModuleCatalog::fromDefinitions(make_module_definitions(100));
	$results[]=bench_run(
		'module-catalog-count-pair-100',
		static fn() => $catalog->enabledDisabledCounts(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='module-catalog-count-pair-fresh'){
	$results[]=bench_run(
		'module-catalog-count-pair-fresh-100',
		static fn() => ModuleCatalog::fromDefinitions(make_module_definitions(100))->enabledDisabledCounts(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='module-catalog-counts-fresh'){
	$results[]=bench_run(
		'module-catalog-counts-fresh-100',
		static function(): array {
			$catalog=ModuleCatalog::fromDefinitions(make_module_definitions(100));
			return [$catalog->enabledCount(), $catalog->disabledCount()];
		},
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='module-catalog-enabled-names-fresh'){
	$results[]=bench_run(
		'module-catalog-enabled-names-fresh-100',
		static function(): array {
			$catalog=ModuleCatalog::fromDefinitions(make_module_definitions(100));
			return [$catalog->enabledNames(), $catalog->disabledNames()];
		},
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='dialback-catalog-to-array'){
	$catalog=new DialbackCatalog('catalog.', make_dialback_entries(50));
	$results[]=bench_run(
		'dialback-catalog-to-array-50',
		static fn() => $catalog->toArray(),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='dialback-catalog-to-array-fresh'){
	$results[]=bench_run(
		'dialback-catalog-to-array-fresh-50',
		static fn() => (new DialbackCatalog('catalog.', make_dialback_entries(50)))->toArray(),
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='dialback-catalog-filter-views'){
	$catalog=new DialbackCatalog('catalog.', make_dialback_entries(100));
	$selected=[
		'catalog.event_5',
		'catalog.event_15',
		'catalog.event_25',
		'catalog.event_35',
		'catalog.event_45',
		'catalog.event_55',
		'catalog.event_65',
		'catalog.event_75',
	];
	$results[]=bench_run(
		'dialback-catalog-filter-views-100',
		static fn() => [$catalog->scope('catalog.event_1')->names(), $catalog->only($selected)->names()],
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='dialback-catalog-filter-views-fresh'){
	$selected=[
		'catalog.event_5',
		'catalog.event_15',
		'catalog.event_25',
		'catalog.event_35',
		'catalog.event_45',
		'catalog.event_55',
		'catalog.event_65',
		'catalog.event_75',
	];
	$results[]=bench_run(
		'dialback-catalog-filter-views-fresh-100',
		static function() use ($selected): array {
			$catalog=new DialbackCatalog('catalog.', make_dialback_entries(100));
			return [$catalog->scope('catalog.event_1')->names(), $catalog->only($selected)->names()];
		},
		1000,
		50
	);
}

if($scenario==='all' || $scenario==='dialback-catalog-has'){
	$catalog=new DialbackCatalog('catalog.', make_dialback_entries(100));
	$results[]=bench_run(
		'dialback-catalog-has-100',
		static fn() => [
			$catalog->has('catalog.event_5'),
			$catalog->has(' catalog.event_55 '),
			$catalog->has('catalog.missing'),
		],
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-get-top-level'){
	$result=new SanitizationResult([
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	], []);
	$results[]=bench_run(
		'sanitization-result-get-top-level',
		static fn() => $result->get('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-get-nested'){
	$result=new SanitizationResult([
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	], []);
	$results[]=bench_run(
		'sanitization-result-get-nested',
		static fn() => $result->get('profile.contact.email'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-raw-top-level'){
	$result=new SanitizationResult([], [], [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	]);
	$results[]=bench_run(
		'sanitization-result-raw-top-level',
		static fn() => $result->raw('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-raw-top-level-fresh'){
	$results[]=bench_run(
		'sanitization-result-raw-top-level-fresh',
		static fn() => (new SanitizationResult([], [], [
			'name'=>'Ada',
			'active'=>true,
			'profile'=>['contact'=>['email'=>'ada@example.test']],
		]))->raw('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-raw-nested'){
	$result=new SanitizationResult([], [], [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	]);
	$results[]=bench_run(
		'sanitization-result-raw-nested',
		static fn() => $result->raw('profile.contact.email'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-has-top-level'){
	$result=new SanitizationResult([
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	], []);
	$results[]=bench_run(
		'sanitization-result-has-top-level',
		static fn() => $result->has('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-has-nested'){
	$result=new SanitizationResult([
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	], []);
	$results[]=bench_run(
		'sanitization-result-has-nested',
		static fn() => $result->has('profile.contact.email'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-only-nested'){
	$result=new SanitizationResult([
		'name'=>'Ada',
		'active'=>true,
		'profile'=>[
			'contact'=>[
				'email'=>'ada@example.test',
				'phone'=>'555-0100',
			],
			'address'=>[
				'city'=>'Montreal',
				'country'=>'CA',
			],
		],
	], []);
	$keys=[
		'profile.contact.email',
		'profile.contact.phone',
		'profile.address.city',
		'profile.address.country',
	];
	$results[]=bench_run(
		'sanitization-result-only-nested',
		static fn() => $result->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-only-nested-fresh'){
	$keys=[
		'profile.contact.email',
		'profile.address.city',
		'missing.path',
		'profile.contact.phone',
	];
	$results[]=bench_run(
		'sanitization-result-only-nested-fresh',
		static fn() => (new SanitizationResult([
			'name'=>'Ada',
			'active'=>true,
			'profile'=>[
				'contact'=>[
					'email'=>'ada@example.test',
					'phone'=>'555-0100',
				],
				'address'=>[
					'city'=>'Montreal',
					'country'=>'CA',
				],
			],
		], []))->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-only-top-level'){
	$result=new SanitizationResult([
		'name'=>'Ada',
		'active'=>true,
		'email'=>'ada@example.test',
		'phone'=>'555-0100',
		'city'=>'Montreal',
		'country'=>'CA',
	], []);
	$keys=['name', 'email', 'city', 'missing'];
	$results[]=bench_run(
		'sanitization-result-only-top-level',
		static fn() => $result->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-only-top-level-fresh'){
	$keys=['name', 'email', 'city', 'missing'];
	$results[]=bench_run(
		'sanitization-result-only-top-level-fresh',
		static fn() => (new SanitizationResult([
			'name'=>'Ada',
			'active'=>true,
			'email'=>'ada@example.test',
			'phone'=>'555-0100',
			'city'=>'Montreal',
			'country'=>'CA',
		], []))->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-except-nested'){
	$result=new SanitizationResult([
		'name'=>'Ada',
		'active'=>true,
		'profile'=>[
			'contact'=>[
				'email'=>'ada@example.test',
				'phone'=>'555-0100',
			],
			'address'=>[
				'city'=>'Montreal',
				'country'=>'CA',
			],
		],
	], []);
	$keys=[
		'profile.contact.email',
		'profile.address.country',
		'missing.path',
	];
	$results[]=bench_run(
		'sanitization-result-except-nested',
		static fn() => $result->except($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitization-result-except-nested-fresh'){
	$keys=[
		'profile.contact.email',
		'profile.address.country',
		'missing.path',
	];
	$results[]=bench_run(
		'sanitization-result-except-nested-fresh',
		static fn() => (new SanitizationResult([
			'name'=>'Ada',
			'active'=>true,
			'profile'=>[
				'contact'=>[
					'email'=>'ada@example.test',
					'phone'=>'555-0100',
				],
				'address'=>[
					'city'=>'Montreal',
					'country'=>'CA',
				],
			],
		], []))->except($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-get-top-level'){
	$bag=new InputBag(new SanitationManager(), [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	]);
	$results[]=bench_run(
		'input-bag-get-top-level',
		static fn() => $bag->get('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-get-nested'){
	$bag=new InputBag(new SanitationManager(), [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	]);
	$results[]=bench_run(
		'input-bag-get-nested',
		static fn() => $bag->get('profile.contact.email'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-only-nested'){
	$bag=new InputBag(new SanitationManager(), [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>[
			'contact'=>[
				'email'=>'ada@example.test',
				'phone'=>'555-0100',
			],
			'address'=>[
				'city'=>'Montreal',
				'country'=>'CA',
			],
		],
	]);
	$keys=[
		'profile.contact.email',
		'profile.contact.phone',
		'profile.address.city',
		'profile.address.country',
	];
	$results[]=bench_run(
		'input-bag-only-nested',
		static fn() => $bag->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-only-nested-fresh'){
	$keys=[
		'profile.contact.email',
		'profile.address.city',
		'missing.path',
		'profile.contact.phone',
	];
	$results[]=bench_run(
		'input-bag-only-nested-fresh',
		static fn() => (new InputBag(new SanitationManager(), [
			'name'=>'Ada',
			'active'=>true,
			'profile'=>[
				'contact'=>[
					'email'=>'ada@example.test',
					'phone'=>'555-0100',
				],
				'address'=>[
					'city'=>'Montreal',
					'country'=>'CA',
				],
			],
		]))->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-only-top-level'){
	$bag=new InputBag(new SanitationManager(), [
		'name'=>'Ada',
		'active'=>true,
		'email'=>'ada@example.test',
		'phone'=>'555-0100',
		'city'=>'Montreal',
		'country'=>'CA',
	]);
	$keys=['name', 'email', 'city', 'missing'];
	$results[]=bench_run(
		'input-bag-only-top-level',
		static fn() => $bag->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-only-top-level-fresh'){
	$keys=['name', 'email', 'city', 'missing'];
	$results[]=bench_run(
		'input-bag-only-top-level-fresh',
		static fn() => (new InputBag(new SanitationManager(), [
			'name'=>'Ada',
			'active'=>true,
			'email'=>'ada@example.test',
			'phone'=>'555-0100',
			'city'=>'Montreal',
			'country'=>'CA',
		]))->only($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-except-nested'){
	$bag=new InputBag(new SanitationManager(), [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>[
			'contact'=>[
				'email'=>'ada@example.test',
				'phone'=>'555-0100',
			],
			'address'=>[
				'city'=>'Montreal',
				'country'=>'CA',
			],
		],
	]);
	$keys=[
		'profile.contact.email',
		'profile.address.country',
		'missing.path',
	];
	$results[]=bench_run(
		'input-bag-except-nested',
		static fn() => $bag->except($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-has-top-level'){
	$bag=new InputBag(new SanitationManager(), [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	]);
	$results[]=bench_run(
		'input-bag-has-top-level',
		static fn() => $bag->has('name'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-has-nested'){
	$bag=new InputBag(new SanitationManager(), [
		'name'=>'Ada',
		'active'=>true,
		'profile'=>['contact'=>['email'=>'ada@example.test']],
	]);
	$results[]=bench_run(
		'input-bag-has-nested',
		static fn() => $bag->has('profile.contact.email'),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='input-bag-except-nested-fresh'){
	$keys=[
		'profile.contact.email',
		'profile.address.country',
		'missing.path',
	];
	$results[]=bench_run(
		'input-bag-except-nested-fresh',
		static fn() => (new InputBag(new SanitationManager(), [
			'name'=>'Ada',
			'active'=>true,
			'profile'=>[
				'contact'=>[
					'email'=>'ada@example.test',
					'phone'=>'555-0100',
				],
				'address'=>[
					'city'=>'Montreal',
					'country'=>'CA',
				],
			],
		]))->except($keys),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitation-schema-top-level'){
	$manager=new SanitationManager();
	$input=[
		'name'=>['Ada'],
		'email'=>['ada@example.test'],
		'role'=>['admin'],
		'locale'=>['en_CA'],
		'timezone'=>['America/Toronto'],
		'status'=>['active'],
		'city'=>['Montreal'],
		'country'=>['CA'],
	];
	$schema=[
		'name'=>'array',
		'email'=>'array',
		'role'=>'array',
		'locale'=>'array',
		'timezone'=>'array',
		'status'=>'array',
		'city'=>'array',
		'country'=>'array',
	];
	$results[]=bench_run(
		'sanitation-schema-top-level',
		static fn() => $manager->schema($input, $schema),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='sanitation-schema-nested'){
	$manager=new SanitationManager();
	$input=[
		'profile'=>[
			'name'=>['Ada'],
			'contact'=>[
				'email'=>['ada@example.test'],
				'phone'=>['555-0100'],
			],
			'address'=>[
				'city'=>['Montreal'],
				'country'=>['CA'],
			],
		],
		'meta'=>[
			'locale'=>['en_CA'],
			'timezone'=>['America/Toronto'],
		],
	];
	$schema=[
		'profile.name'=>'array',
		'profile.contact.email'=>'array',
		'profile.contact.phone'=>'array',
		'profile.address.city'=>'array',
		'profile.address.country'=>'array',
		'meta.locale'=>'array',
		'meta.timezone'=>'array',
	];
	$results[]=bench_run(
		'sanitation-schema-nested',
		static fn() => $manager->schema($input, $schema),
		10000,
		100
	);
}

if($scenario==='all' || $scenario==='sanitation-schema-wildcard-arrays'){
	$manager=new SanitationManager();
	$items=[];
	for($index=0; $index<40; $index++){
		$items[]=[
			'tags'=>['tag-'.$index, 'active'],
			'flags'=>['visible', 'indexed'],
		];
	}
	$input=['items'=>$items];
	$schema=[
		'items.*.tags'=>'array',
		'items.*.flags'=>'array',
	];
	$results[]=bench_run(
		'sanitation-schema-wildcard-arrays',
		static fn() => $manager->schema($input, $schema),
		5000,
		100
	);
}

if($scenario==='all' || $scenario==='sanitizer-builder-field-lists'){
	$manager=new SanitationManager();
	$fields=[' profile.name ', 'profile.email', '', ' profile.phone ', 'profile.email'];
	$uniqueFields=[' item.id ', 'item.sku', '', ' item.id '];
	$results[]=bench_run(
		'sanitizer-builder-field-lists',
		static fn() => $manager->string('value')
			->requiredWith(...$fields)
			->requiredWithAll(...$fields)
			->requiredWithout(...$fields)
			->requiredWithoutAll(...$fields)
			->presentWith(...$fields)
			->presentWithAll(...$fields)
			->presentWithout(...$fields)
			->presentWithoutAll(...$fields)
			->uniqueBy(...$uniqueFields)
			->uniqueByIgnoreCase(...$uniqueFields),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='sanitation-sanitize-detailed-rule-lists'){
	$manager=new SanitationManager();
	$rule='array'
		.'|required_with:profile.name,profile.email,profile.phone'
		.'|required_without:backup.name,backup.email'
		.'|present_with:flags.active,flags.visible'
		.'|present_without_all:meta.archived,meta.deleted'
		.'|required_if:status,active,pending'
		.'|exclude_unless:type,primary,secondary'
		.'|unique_by:item.id,item.sku';
	$options=[
		'present'=>false,
		'field'=>'items',
		'input'=>[],
		'context'=>[],
	];
	$results[]=bench_run(
		'sanitation-sanitize-detailed-rule-lists',
		static fn() => $manager->sanitizeDetailed(null, $rule, $options),
		100000,
		1000
	);
}

if($scenario==='all' || $scenario==='rows-one-transformer'){
	$rows=make_rows(1000);
	$pipeline=new RowTransformBenchmark(
		static function(array $row): array {
			$row['display']=$row['name'].' #'.$row['id'];
			return $row;
		}
	);
	$results[]=bench_run(
		'rows-one-transformer-1000',
		static fn() => $pipeline->run($rows),
		1000,
		100
	);
}

if($scenario==='all' || $scenario==='rows-two-transformers'){
	$rows=make_rows(1000);
	$pipeline=new RowTransformBenchmark(
		static function(array $row): array {
			$row['display']=$row['name'].' #'.$row['id'];
			return $row;
		},
		static function(array $row): array {
			$row['taxed_price']=$row['price'] * 1.15;
			return $row;
		}
	);
	$results[]=bench_run(
		'rows-two-transformers-1000',
		static fn() => $pipeline->run($rows),
		1000,
		100
	);
}

echo json_encode([
	'php'=>PHP_VERSION,
	'scenario'=>$scenario,
	'results'=>$results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

