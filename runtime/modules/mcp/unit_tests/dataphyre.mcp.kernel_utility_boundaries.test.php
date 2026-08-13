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

$utilityContracts=[];
foreach(McpKernelBoundaryHarness::utilityContractNames() as $contract){$utilityContracts[$contract]=[$contract];}
dataset('MCP kernel utility boundary contracts',$utilityContracts);

suite('MCP kernel utility boundary contracts')
	->tag('mcp','kernel','utility','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.kernel.utility-boundaries',1)
	->layer('contract')
	->risk('high')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.utility.php')
	->through('static config parser','safe path resolver','manifest summarizer','bounded process runner','SQL definition loader')
	->isolation('process');

test('each named utility boundary remains deterministic bounded and self-describing',static function(Context $t,string $name): void {
	$contract=(new McpKernelBoundaryHarness($t))->utilityContract($name);
	$t->same($name,$contract['contract']);
	if($name==='static PHP config parsing never executes expressions'){
		$t->same(['text','enabled','disabled','nothing','count','ratio','nested','dynamic'],$contract['literal_keys']);
		$t->same('value',$contract['nested_value']);
		$t->same('expression',$contract['dynamic_kind']);
		$t->same(['first','nested'],$contract['array_syntax_keys']);
		$t->same(['string'=>'string','true'=>'bool','false'=>'bool','null'=>'null','int'=>'int','float'=>'float','dynamic'=>'array'],$contract['scalar_types']);
		$t->same(['one','nested'],$contract['top_level_keys']);
		$t->same(['wrong opener'=>[],'unclosed'=>[]],$contract['invalid_top_level']);
		$t->same(4,$contract['split_count']);
		$t->same(["'outer'","['inner'=>'value']"],$contract['arrow_pair']);
		$t->same(["'escaped \\' key'",'"value => remains quoted"'],$contract['escaped_arrow_pair']);
		$t->same(['no return'=>true,'not an array'=>true,'empty expression'=>true,'unclosed array()'=>true],$contract['invalid_forms_are_null']);
		$t->same(['wrong bracket'=>null,'wrong enclosure'=>null,'unclosed'=>null,'nested closes at end'=>true],$contract['enclosures']);
		return;
	}
	if($name==='config shapes expose kinds while withholding unsafe values'){
		$t->same(['database','features'],$contract['json_keys']);
		$t->same(['first','nested','second'],$contract['php_keys']);
		$t->same([],$contract['missing_keys']);
		$t->same('invalid_json',$contract['invalid_json_kind']);
		$t->greaterThan(4,$contract['json_shape_count']);
		$t->same(4,$contract['bounded_shape_count']);
		$t->same(['associative'=>false,'unpreviewable'=>false,'flat list'=>true,'nested list'=>false,'object'=>false,'resource'=>false,'scalar'=>true],$contract['previewability']);
		$t->same(['expression'=>'call','list'=>'list','object'=>'object','bool'=>'bool','int'=>'int','float'=>'float','null'=>'null','scalar'=>'scalar'],$contract['kinds']);
		$t->same(['found'=>true,'value'=>'value'],$contract['found_value']);
		$t->same(['found'=>false,'value'=>null],$contract['missing_value']);
		$t->same(['same','other'],$contract['unique_paths']);
		return;
	}
	if($name==='repository paths remain bounded across files directories and symlinks'){
		$t->same(2,$contract['walk_count']);
		$t->isTrue($contract['walk_excludes_hygiene_paths']);
		$t->same(0,$contract['hygiene_walk_count']);
		$t->same(1,$contract['single_file_count']);
		$t->same(0,$contract['missing_count']);
		$t->same(0,$contract['unreadable_count']);
		$t->isTrue($contract['unreadable_exercised']);
		$t->isTrue($contract['safe_absolute_matches']);
		$t->isTrue($contract['prefix_sibling_rejected']);
		$t->isTrue($contract['empty_root_rejected']);
		$t->contains('Path is required.',$contract['path_errors']['empty']);
		$t->contains('Path parent does not exist:',$contract['path_errors']['missing parent']);
		$t->contains('Path escapes the Dataphyre workspace:',$contract['path_errors']['escape']);
		$t->same(['empty'=>'<absolute-path>','windows'=>'<absolute-path>','posix'=>'<absolute-path>'],array_intersect_key($contract['error_labels'],array_flip(['empty','windows','posix'])));
		$t->contains('...',$contract['error_labels']['relative']);
		$t->same('visible/a.txt',$contract['inside_relative']);
		$t->same(['root'=>'dataphyre','child'=>'dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php'],$contract['dataphyre_relative']);
		$t->same('/tmp/outside.php',$contract['outside_relative']);
		$t->isTrue($contract['symlink_contract']);
		return;
	}
	if($name==='legacy test manifests are summarized as inert data'){
		$t->isFalse($contract['invalid_manifest']['valid_json']);
		$t->same(['case_count'=>3,'returned_cases'=>1,'truncated'=>true,'has_custom_script'=>true],array_intersect_key($contract['valid_manifest'],array_flip(['case_count','returned_cases','truncated','has_custom_script'])));
		$t->isTrue($contract['valid_manifest']['expected_included']);
		$t->isTrue($contract['valid_manifest']['expected_withheld']);
		$t->same(['declarative'=>'declarative_assertion','custom'=>'custom_script','range'=>'numeric_range','array shape'=>'array_shape','array'=>'array','regex'=>'regex','scalar'=>'int'],$contract['shapes']);
		$t->same(['scalar'=>false,'direct'=>true,'nested'=>true],$contract['custom_script_detection']);
		$t->same(['direct'=>'access','common'=>'sql','missing'=>null],$contract['modules']);
		$t->same(['tracelog_handoff','tracelog_plotting','tracelog_plotting','tracelog','log'],$contract['tracelog_kinds']);
		return;
	}
	if($name==='source extractors understand calls includes and bounded snippets'){
		$t->same(['many'=>['mcp','sql'],'required'=>['routing','ignored'],'one'=>['panel']],$contract['modules']);
		$t->same(['orders','customers'],$contract['tables']);
		$t->count(2,$contract['includes']);
		$t->same(3,$contract['line_number']);
		$t->contains('...',$contract['snippets']['middle']);
		return;
	}
	if($name==='local commands are bounded redacted and explicit about stderr'){
		$t->contains('[REDACTED]',$contract['without_stderr']['stdout']);
		$t->same('',$contract['without_stderr']['stderr']);
		$t->contains('[REDACTED]',$contract['with_stderr']['stderr']);
		$t->same(7,$contract['nonzero_exit']);
		$t->isTrue(is_int($contract['signal_exit']));
		$t->same('Command timed out.',$contract['timeout_message']);
		$t->same('Unable to start command.',$contract['start_failure']);
		$t->same('Unable to start command.',$contract['invalid_root_failure']);
		$t->same('/contract/php',$contract['configured_binary']);
		$t->notEmpty($contract['default_binary']);
		$t->same('abcdefabcdef',$contract['bounded_read']);
		$t->contains('File not found:',$contract['missing_read_error']);
		return;
	}
	$t->same([
		'empty'=>'null','missing'=>'null','by id'=>'Dataphyre\\Database\\TableDefinition','by table'=>'Dataphyre\\Database\\TableDefinition',
		'direct instance'=>'Dataphyre\\Database\\TableDefinition','direct callable'=>'Dataphyre\\Database\\TableDefinition',
		'fallback callable'=>'Dataphyre\\Database\\TableDefinition','invalid'=>'null',
	],$contract['config_definition_types']);
	$t->greaterThan(0,$contract['runtime_definition_count']);
	$t->isTrue($contract['runtime_unknown_is_null']);
	$t->isTrue($contract['runtime_missing_file_is_null']);
	$t->same(['primary'=>['driver'=>'sqlite']],$contract['valid_sql_config']);
	$t->isTrue($contract['server_restored']);
	$t->contains('config_path must point to a repo-local sql.php file.',$contract['wrong_name_error']);
	$t->contains('SQL config did not return an array.',$contract['invalid_return_error']);
})->with('MCP kernel utility boundary contracts');
