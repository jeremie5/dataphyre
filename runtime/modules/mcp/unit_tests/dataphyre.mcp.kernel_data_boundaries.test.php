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

$dataContracts=[];
foreach(McpKernelBoundaryHarness::dataContractNames() as $contract){$dataContracts[$contract]=[$contract];}
dataset('MCP kernel data boundary contracts',$dataContracts);

suite('MCP kernel data boundary contracts')
	->tag('mcp','kernel','config','storage','sql','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.kernel.data-boundaries',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.inspection.data.php')
	->through('static config inventory','redacted config preview','storage shape reader','SQL schema catalog','SQL safety planner')
	->isolation('process');

test('each named data boundary remains static redacted bounded and self-describing',static function(Context $t,string $name): void {
	$contract=(new McpKernelBoundaryHarness($t))->dataContract($name);
	$t->same($name,$contract['contract']);
	if($name==='config inventory is bounded and literal previews are redacted'){
		$t->same(80,$contract['inventory_count']);
		$t->same([],array_values(array_filter($contract['inventory_paths'],static fn(string $path): bool=>str_ends_with($path,'.txt'))));
		$t->same(0,$contract['extension_filtered_count']);
		$t->same(['type'=>'json','path_count'=>2,'truncated'=>true,'values_returned'=>false],$contract['shape']);
		$t->same(2,$contract['preview_returned_count']);
		$t->same(['password','nested.token','missing','object','safe','list'],array_column($contract['preview_values'],'path'));
		$t->same([true,true,false,false,false,false],array_column($contract['preview_values'],'redacted'));
		$t->same('Unable to parse config as a literal array.',$contract['parse_failure']['error']);
		$t->same([],$contract['parse_failure']['values']);
		$t->contains('path must look like a config file.',$contract['errors']['config-like path']);
		$t->contains('path must look like a config file.',$contract['errors']['preview path']);
		$t->contains('keys must be a non-empty array',$contract['errors']['empty keys']);
		return;
	}
	if($name==='storage config reports static driver shape only'){
		$t->same('not_executed',$contract['execution']);
		$t->same('local',$contract['default_disk']);
		$t->same(1,$contract['disk_count']);
		$t->same('local',$contract['disks'][0]['name']);
		$t->same('local',$contract['disks'][0]['driver']);
		$t->same(['driver','root','password'],array_column($contract['disks'][0]['option_keys'],'key'));
		$t->same([false,false,true],array_column($contract['disks'][0]['option_keys'],'redacted'));
		$t->greaterThan(0,$contract['driver_count']);
		return;
	}
	if($name==='SQL catalogs resolve static tables schemas and cluster aliases'){
		$t->same(['contract.orders','contract.scalar'],$contract['table_names']);
		$t->same('primary',$contract['table_entries'][0]['cluster']);
		$t->isTrue($contract['table_entries'][0]['multipoint_writes']);
		$t->isTrue($contract['table_entries'][0]['has_caching_policy']);
		$t->same(null,$contract['table_entries'][1]['cluster']);
		$t->same(['table'=>'contract.orders','registered'=>true,'columns'=>[],'has_create_queries'=>true],$contract['schema']);
		$t->same('primary',$contract['clusters']['default_cluster']);
		$t->same(['primary','fallback'],array_column($contract['clusters']['datacenters'][0]['clusters'],'name'));
		$t->same(['postgresql',null],array_column($contract['clusters']['datacenters'][0]['clusters'],'dbms'));
		$t->same(['','contract.orders','contract.scalar'],array_column($contract['clusters']['tables'],'table'));
		$t->contains('table is required.',$contract['missing_table_error']);
		return;
	}
	if($name==='SQL planner rejects unsafe syntax and bounds eligible reads'){
		$t->isTrue($contract['eligible']['eligibility']['eligible_for_future_unsafe_read_runner']);
		$t->same('SELECT orders.id FROM orders JOIN customers ON customers.id=orders.customer_id LIMIT 25',$contract['eligible']['bounded_sql_preview']);
		$t->isFalse($contract['unsafe']['eligibility']['eligible_for_future_unsafe_read_runner']);
		$t->contains('multiple_statements_not_allowed',$contract['unsafe']['eligibility']['issues']);
		$t->contains('comments_require_manual_review',$contract['unsafe']['eligibility']['issues']);
		$t->contains('blocked_sql_verb:update',$contract['unsafe']['eligibility']['issues']);
		$t->contains('referenced_table_not_allowed',$contract['unsafe']['eligibility']['issues']);
		$t->contains('limit_exceeds_max_rows',$contract['oversized']['eligibility']['issues']);
		$t->contains('no_referenced_tables_detected',$contract['no_table']['eligibility']['issues']);
		$t->contains('sql is required.',$contract['missing_sql_error']);
		$t->same('unknown',$contract['unknown_kind']);
		$t->same(['length_preserved'=>true,'contains_literal_words'=>false],$contract['quoted_mask']);
		$t->isTrue($contract['trailing_semicolon_is_single']);
		$t->same(['delete','into_file'],$contract['blocked']);
		$t->same(['app.orders','customers'],$contract['referenced']);
		$t->same(['missing'=>null,'present'=>12],$contract['limits']);
		$t->same(['added'=>'SELECT * FROM orders LIMIT 10','capped'=>'SELECT * FROM orders LIMIT 10','kept'=>'SELECT * FROM orders LIMIT 5'],$contract['bounded']);
		return;
	}
	$t->same(['config_path'=>'<sql-config>','cluster'=>'<cluster>','execution'=>'not_executed','database_connection'=>'not_opened'],$contract['defaults']);
	$t->same(['config_path'=>'dataphyre/config/sql.php','cluster'=>'primary'],$contract['named']);
	$t->contains('raw credentials',$contract['denied_outputs']);
})->with('MCP kernel data boundary contracts');
