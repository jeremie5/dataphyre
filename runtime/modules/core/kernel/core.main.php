<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre Core initializing');

if(!defined('CFG')){
	heisenconstant('CFG', static fn(): array=>[]);
}

foreach(['core.global.php','helper_functions.php','language_additions.php','core_functions.php'] as $file){
	require_once __DIR__.'/'.$file;
}
require_once dirname(__DIR__).'/Framework/CoreKernelBootstrap.php';

$applicationReleasePreflight=dp_application_release_preflight_context();
$managedRuntimeBootstrap=dp_managed_runtime_bootstrap_context();
$applicationBootstrapOnly=dp_application_bootstrap_only_context();
$bootstrapFail=$applicationBootstrapOnly!==null
	? static fn(string $message)=>throw new RuntimeException($message)
	: static fn(string $message)=>pre_init_error($message);
\Dataphyre\CoreKernelBootstrap::validateSymbols(
	['dp_module_present','dp_module_required','dpvks','dpvk'],
	static fn(string $name): bool=>function_exists($name),
	static fn(): bool=>class_exists('\dataphyre\core', false),
	$bootstrapFail
);
$rootpaths=\Dataphyre\CoreKernelBootstrap::requireRootpaths(defined('ROOTPATH') ? ROOTPATH : null, $bootstrapFail);

dp_define_core_config('DP_CORE_CFG');
\dataphyre\core::load_plugins('pre_init');

\Dataphyre\CoreKernelBootstrap::validateBootstrapVersion(
	defined('BS_VERSION') ? (string)BS_VERSION : null,
	'2.0',
	$bootstrapFail
);
\Dataphyre\CoreKernelBootstrap::validatePlatform(
	PHP_INT_SIZE,
	!defined('IS_PRODUCTION') || IS_PRODUCTION===true,
	static fn(string $message)=>tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=$message, $S='warning'),
	$bootstrapFail
);

\Dataphyre\CoreKernelBootstrap::ensureConstant('ALLOW_OUTPUT_POSTPROCESSING', true, 'defined', 'define', $bootstrapFail);

$filesystemVerified=$applicationReleasePreflight!==null || $managedRuntimeBootstrap!==null || $applicationBootstrapOnly!==null
	? true : \Dataphyre\CoreKernelBootstrap::ensureVerified(
	(string)$rootpaths['dataphyre'],
	defined('APP') ? (string)APP : null,
	static fn(string $path): bool=>file_exists($path),
	static function(?string $application): bool {
		require_once __DIR__.'/flight_sheet.php';
		return \dataphyre\flight_sheet::install($application);
	},
	static fn(string $path)=>clearstatcache(true, $path)
);
\Dataphyre\CoreKernelBootstrap::ensureConstant('DP_VERIFIED', $filesystemVerified, 'defined', 'define', $bootstrapFail);
$installError=class_exists('\dataphyre\flight_sheet', false) ? \dataphyre\flight_sheet::last_error() : null;
$runMode=\Dataphyre\CoreKernelBootstrap::resolveRunMode(
	defined('RUN_MODE') ? (string)RUN_MODE : null,
	DP_VERIFIED===true,
	$installError,
	$bootstrapFail
);
\Dataphyre\CoreKernelBootstrap::ensureConstant('RUN_MODE', $runMode, 'defined', 'define', $bootstrapFail);

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Run mode is '.$runMode);
\Dataphyre\CoreKernelBootstrap::ensureConstant('DP_CORE_LOADED', true, 'defined', 'define', $bootstrapFail);

\Dataphyre\CoreKernelBootstrap::runDiagnostic(
	$runMode,
	static fn()=>require_once __DIR__.'/core.diagnostic.php',
	static function(): void {
		if(class_exists('\dataphyre\core\diagnostic', false)){
			\dataphyre\core\diagnostic::pre_tests();
		}
	}
);

\Dataphyre\CoreKernelBootstrap::ensureConstant(
	'REQUEST_USER_AGENT',
	isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown UA',
	'defined',
	'define',
	$bootstrapFail
);
\Dataphyre\CoreKernelBootstrap::validatePrivateKey(dpvk(), $bootstrapFail);

\Dataphyre\CoreKernelBootstrap::prepareRequest(
	$runMode,
	static fn(): int=>\dataphyre\core::get_server_load_level(),
	static fn()=>\dataphyre\core::check_delayed_requests_lock(),
	'session_status',
	static fn(): int=>\dataphyre\core::$server_load_level,
	static fn(string $message, string $mode)=>\dataphyre\core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $message, $mode),
	$applicationReleasePreflight===null && $managedRuntimeBootstrap===null && $applicationBootstrapOnly===null
		&& \Dataphyre\CoreKernelBootstrap::loadSheddingEnabled(DP_CORE_CFG)
);

\Dataphyre\CoreKernelBootstrap::ensureConstant('REQUEST_IP_ADDRESS', \dataphyre\core::get_client_ip(), 'defined', 'define', $bootstrapFail);
tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Client IP is '.REQUEST_IP_ADDRESS);

\Dataphyre\CoreKernelBootstrap::configureSession(
	$applicationBootstrapOnly!==null
		? 'application-bootstrap-only'
		: ($applicationReleasePreflight===null ? $runMode : 'application-release-preflight'),
	DP_CORE_CFG,
	'session_status',
	'ini_set',
	'session_start',
	$bootstrapFail,
	static fn(string $message)=>tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=$message, $S='warning'),
	static fn(string $message, string $mode)=>\dataphyre\core::unavailable(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $message, $mode)
);

if(!defined('DP_MEMORY_LIMIT_INITIALIZED')){
	$memoryOverride=getenv('DATAPHYRE_MEMORY_LIMIT');
	$memoryLimit=\Dataphyre\CoreKernelBootstrap::configuredMemoryLimit($memoryOverride, DP_CORE_CFG);
	\Dataphyre\CoreKernelBootstrap::configureMemory($memoryLimit, [
		'debugbar_available'=>class_exists('dataphyre_flightdeck_debugbar', false),
		'debugbar_enabled'=>static fn(): bool=>dataphyre_flightdeck_debugbar::enabled()===true,
		'apply_debugbar'=>static fn()=>dataphyre_flightdeck_debugbar::apply_configured_memory_limit(),
		'ini_get'=>'ini_get',
		'ini_set'=>'ini_set',
		'memory_usage'=>static fn(): int=>memory_get_usage(true),
		'warn'=>static fn(string $message)=>tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=$message, $S='warning'),
		'fail'=>$bootstrapFail,
	]);
	\Dataphyre\CoreKernelBootstrap::ensureConstant('DP_MEMORY_LIMIT_INITIALIZED', true, 'defined', 'define', $bootstrapFail);
}
if(!defined('DP_MAX_EXECUTION_TIME_INITIALIZED')){
	\Dataphyre\CoreKernelBootstrap::configureExecutionTime(
		DP_CORE_CFG['max_execution_time'] ?? 30,
		'ini_set',
		$bootstrapFail
	);
	\Dataphyre\CoreKernelBootstrap::ensureConstant('DP_MAX_EXECUTION_TIME_INITIALIZED', true, 'defined', 'define', $bootstrapFail);
}
\Dataphyre\CoreKernelBootstrap::configureTimezone(
	(string)(DP_CORE_CFG['timezone'] ?? 'UTC'),
	'date_default_timezone_set',
	$bootstrapFail
);

	\Dataphyre\CoreKernelBootstrap::loadModules(
		$runMode,
		'dp_module_present',
		static function(string $path, bool $_once): void {
			require_once $path;
		}
	);

\dataphyre\core::load_plugins('post_init');
if($applicationBootstrapOnly===null){
	\Dataphyre\CoreKernelBootstrap::finishRequest($runMode, static fn()=>\dataphyre\core::set_http_headers());
}

\Dataphyre\CoreKernelBootstrap::runDiagnostic(
	$runMode,
	static function(): void {},
	static function(): void {
		if(class_exists('\dataphyre\core\diagnostic', false)){
			\dataphyre\core\diagnostic::post_tests();
		}
	}
);

unset($applicationReleasePreflight, $managedRuntimeBootstrap, $applicationBootstrapOnly, $bootstrapFail, $file, $filesystemVerified, $installError, $memoryLimit, $memoryOverride, $rootpaths, $runMode, $T, $S);
tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Dataphyre has finished initializing, '.(DP_CORE_CFG['public_app_name'] ?? 'the application').' will now take over');
