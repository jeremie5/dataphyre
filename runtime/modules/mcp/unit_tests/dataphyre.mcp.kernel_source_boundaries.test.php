<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpKernelBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpKernelBoundaryTestKit.php';

$sourceContracts=[];
foreach(McpKernelBoundaryHarness::sourceContractNames() as $contract){$sourceContracts[$contract]=[$contract];}
dataset('MCP kernel source boundary contracts',$sourceContracts);

suite('MCP kernel source boundary contracts')
	->tag('mcp','kernel','application','package','source','token','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.kernel.source-boundaries',1)
	->layer('contract')
	->risk('high')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.source.php')
	->through('application catalog','package manifest normalizer','API token scanner','PHP API scanner','token navigation')
	->isolation('process');

test('each named source boundary remains static bounded syntax-aware and self-describing',static function(Context $t,string $name): void {
	$contract=(new McpKernelBoundaryHarness($t))->sourceContract($name);
	$t->same($name,$contract['contract']);
	if($name==='application discovery distinguishes layouts configs and namespace confidence'){
		$t->same(['alpha','beta','gamma'],$contract['ids']);
		$t->same(['alpha'=>'dataphyre_backend_root','beta'=>'direct_dataphyre_root','gamma'=>'no_dataphyre_root_detected'],$contract['layouts']);
		$t->same('Alpha',$contract['namespaces']['alpha']['namespace']);
		$t->same('static_mvc_namespace',$contract['namespaces']['alpha']['confidence']);
		$t->same('Beta',$contract['namespaces']['beta']['namespace']);
		$t->same('static_mvc_namespace',$contract['namespaces']['beta']['confidence']);
		$t->same('Gamma',$contract['namespaces']['gamma']['namespace']);
		$t->same('fallback_guess',$contract['namespaces']['gamma']['confidence']);
		$t->same('applications/alpha/backend/dataphyre',$contract['alpha']['dataphyre_root']);
		$t->same(['config/mvc.php','config/panel.php','config/sql.php','config/storage.php'],$contract['alpha']['config_files']);
		$t->same(4,$contract['alpha']['config_file_count']);
		$t->isTrue($contract['alpha']['has_mvc_config'] && $contract['alpha']['has_panel_config'] && $contract['alpha']['has_sql_config'] && $contract['alpha']['has_storage_config']);
		$t->isTrue($contract['alpha']['framework_path_exists'] && $contract['alpha']['plugins_path_exists'] && $contract['alpha']['unit_tests_path_exists']);
		$t->same(['scope'=>'alpha','candidate_count'=>1,'config_files'=>[]],$contract['scoped']);
		return;
	}
	if($name==='package manifests are normalized without dependency resolution'){
		$t->same(2,$contract['manifest_count']);
		$t->same(1,$contract['bounded_scan_count']);
		$t->same(['a/package'=>'^1.0','php'=>'^8.4','z/package'=>'[complex]'],$contract['backend_require']);
		$t->same([],$contract['backend_require_dev']);
		$t->same([],$contract['frontend_dependencies']);
		$t->same(['a-tool'=>'1','z-tool'=>'2'],$contract['frontend_dev_dependencies']);
		$t->same(['test'],$contract['frontend_scripts']);
		$t->same(['a'=>'1','z'=>'[complex]'],$contract['direct_dependency_map']);
		return;
	}
	if($name==='API and PHP source catalogs remain static bounded and syntax-aware'){
		$t->same([1,1],$contract['api_bounds']);
		$t->same(['/v1/orders','/orders/{id}','/v2/orders/{id}','/v2/items','/v2/fallback'],array_column($contract['endpoints'],'path'));
		$t->same([['GET'],['POST'],['DELETE'],['GET','POST'],['METHODS']],array_column($contract['endpoints'],'methods'));
		$t->same(['function_or_method','object_or_group_call','static:Api','function_or_method','function_or_method'],array_column($contract['endpoints'],'call'));
		$t->same(1,$contract['openapi_count']);
		$t->same(null,$contract['unclosed_call']);
		$t->same([1,1],$contract['source_bounds']);
		$t->same('Contract\\Source',$contract['source_file']['namespace']);
		$t->same(['Service'],array_column($contract['source_file']['classes'],'name'));
		$t->same(['build','guarded','implicitVisibility'],array_column($contract['source_file']['classes'][0]['methods'],'name'));
		$t->same(['public','protected','public'],array_column($contract['source_file']['classes'][0]['methods'],'visibility'));
		$t->same([true,false,false],array_column($contract['source_file']['classes'][0]['methods'],'static'));
		$t->same(['helper'],array_column($contract['source_file']['functions'],'name'));
		return;
	}
	$t->same('',$contract['next_token_exhausted']);
	$t->same(null,$contract['previous_id_at_start']);
	$t->same(null,$contract['previous_index_at_start']);
	$t->same(null,$contract['next_index_after_gaps']);
	$t->same(null,$contract['next_index_exhausted']);
	$t->same('public',$contract['default_visibility']);
	$t->isFalse($contract['static_at_start']);
})->with('MCP kernel source boundary contracts');
