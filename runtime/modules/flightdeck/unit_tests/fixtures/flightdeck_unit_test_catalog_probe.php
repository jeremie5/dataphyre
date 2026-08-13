<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$root=rtrim((string)($argv[1] ?? ''), '/\\');
if($root==='' || !is_dir($root.'/runtime/modules')){
	fwrite(STDERR, "Dataphyre root is unavailable.\n");
	exit(1);
}

define('ROOTPATH', [
	'root'=>$root.'/',
	'common_root'=>$root.'/',
	'common_dataphyre'=>$root.'/',
	'common_dataphyre_runtime'=>$root.'/runtime/',
	'dataphyre'=>$root.'/',
]);
define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST', true);
define('DATAPHYRE_MODULE_POLICY', [
	'enabled'=>['core'=>true,'dpanel'=>true,'flightdeck'=>true],
	'disabled'=>[],
	'core_implicit'=>true,
]);

require_once $root.'/runtime/modules/testing/tooling/bootstrap.php';
require_once $root.'/runtime/modules/core/kernel/autoloader.php';
if(!function_exists('dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; function tracelog(mixed ...$arguments): void {}');
}
\dataphyre\autoloader::register($root.'/runtime/modules');
\dataphyre\autoloader::register_framework_modules(['dpanel','flightdeck']);
require_once $root.'/runtime/modules/flightdeck/kernel/surfaces/dpanel.php';

$probeContext=new \Dataphyre\Test\Context('Flightdeck catalog probe');
$surface=$probeContext->nonPublic('dataphyre_flightdeck_dpanel_surface');
$testFile='C:/workspace/runtime/modules/testing/unit_tests/dataphyre.catalog.test.php';
$inventory=[
	'test_cases'=>2,
	'json_manifests'=>0,
	'json_test_cases'=>0,
	'code_files'=>1,
	'code_test_cases'=>2,
	'code_grouped_cases'=>2,
	'code_dependent_cases'=>0,
	'code_skipped_files'=>0,
	'code_discovery_errors'=>0,
	'malformed'=>0,
	'modules'=>['testing'=>2],
	'code_suites'=>['Readable catalog'=>1],
	'code_case_catalog'=>[
		[
			'suite'=>'Readable catalog',
			'name'=>'catalog exposes the named contract',
			'module'=>'testing',
			'file'=>$testFile,
			'tags'=>['catalog','visibility'],
			'groups'=>['framework-coverage'],
		],
		[
			'suite'=>'',
			'name'=>'legacy case remains discoverable',
			'module'=>'testing',
			'file'=>$testFile,
			'tags'=>[],
			'groups'=>[],
		],
		'invalid catalog entry',
	],
	'files'=>[[
		'path'=>$testFile,
		'module'=>'testing',
		'kind'=>'code',
		'cases'=>2,
		'case_definitions'=>[
			['suite'=>'Readable catalog','name'=>'catalog exposes the named contract'],
			['suite'=>'','name'=>'legacy case remains discoverable'],
		],
	]],
];

$queue=$surface->invoke('manifest_test_queue_from_inventory', $inventory, ['testing']);
echo json_encode([
	'queue'=>$queue,
	'label'=>$surface->invoke('unit_test_worker_job_label', $queue[0]),
	'html'=>$surface->invoke('test_inventory_card', ['test_inventory'=>$inventory]),
	'client_script'=>$surface->invoke('client_script'),
	'style'=>$surface->invoke('style'),
], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
