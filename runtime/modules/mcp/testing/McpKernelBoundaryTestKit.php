<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Mcp\Testing;

use Dataphyre\Test\Context;
use Dataphyre\Test\Contracts\TestContext;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TempWorkspace;

require_once __DIR__.'/McpTestKit.php';

/**
 * Adversarial contracts for MCP kernel seams that are intentionally not public
 * protocol tools. Tests consume named evidence instead of repeating reflection,
 * temporary-repository construction, path manipulation, or parser fixtures.
 */
final class McpKernelBoundaryHarness {
	private NonPublicAccess $kernel;

	public function __construct(private TestContext $context) {
		new McpKernelHarness($context);
		$this->kernel=$context->nonPublic(new \dataphyre_mcp_server(dirname(\Dataphyre\Test\dataphyre_path()),[]));
	}

	/** @return list<string> */
	public static function utilityContractNames(): array {
		return [
			'static PHP config parsing never executes expressions',
			'config shapes expose kinds while withholding unsafe values',
			'repository paths remain bounded across files directories and symlinks',
			'legacy test manifests are summarized as inert data',
			'source extractors understand calls includes and bounded snippets',
			'local commands are bounded redacted and explicit about stderr',
			'SQL definitions load from every supported static declaration shape',
		];
	}

	/** @return array<string,mixed> */
	public function utilityContract(string $contract): array {
		if(!in_array($contract,self::utilityContractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP utility boundary contract: '.$contract);
		}
		$evidence=match($contract){
			'static PHP config parsing never executes expressions'=>$this->staticPhpConfigContract(),
			'config shapes expose kinds while withholding unsafe values'=>$this->configShapeContract(),
			'repository paths remain bounded across files directories and symlinks'=>$this->repositoryPathContract(),
			'legacy test manifests are summarized as inert data'=>$this->legacyManifestContract(),
			'source extractors understand calls includes and bounded snippets'=>$this->sourceExtractorContract(),
			'local commands are bounded redacted and explicit about stderr'=>$this->localCommandContract(),
			'SQL definitions load from every supported static declaration shape'=>$this->sqlDefinitionContract(),
		};
		return ['contract'=>$contract]+$evidence;
	}

	/** @return list<string> */
	public static function dataContractNames(): array {
		return [
			'config inventory is bounded and literal previews are redacted',
			'storage config reports static driver shape only',
			'SQL catalogs resolve static tables schemas and cluster aliases',
			'SQL planner rejects unsafe syntax and bounds eligible reads',
			'SQL readiness remains a non-executing policy artifact',
		];
	}

	/** @return array<string,mixed> */
	public function dataContract(string $contract): array {
		if(!in_array($contract,self::dataContractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP data boundary contract: '.$contract);
		}
		$evidence=match($contract){
			'config inventory is bounded and literal previews are redacted'=>$this->configInventoryContract(),
			'storage config reports static driver shape only'=>$this->storageConfigContract(),
			'SQL catalogs resolve static tables schemas and cluster aliases'=>$this->sqlCatalogContract(),
			'SQL planner rejects unsafe syntax and bounds eligible reads'=>$this->sqlPlannerContract(),
			'SQL readiness remains a non-executing policy artifact'=>$this->sqlReadinessContract(),
		};
		return ['contract'=>$contract]+$evidence;
	}

	/** @return list<string> */
	public static function sourceContractNames(): array {
		return [
			'application discovery distinguishes layouts configs and namespace confidence',
			'package manifests are normalized without dependency resolution',
			'API and PHP source catalogs remain static bounded and syntax-aware',
			'token navigation has explicit edge semantics',
		];
	}

	/** @return array<string,mixed> */
	public function sourceContract(string $contract): array {
		if(!in_array($contract,self::sourceContractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP source boundary contract: '.$contract);
		}
		$evidence=match($contract){
			'application discovery distinguishes layouts configs and namespace confidence'=>$this->applicationCatalogContract(),
			'package manifests are normalized without dependency resolution'=>$this->packageManifestContract(),
			'API and PHP source catalogs remain static bounded and syntax-aware'=>$this->sourceCatalogContract(),
			'token navigation has explicit edge semantics'=>$this->tokenNavigationContract(),
		};
		return ['contract'=>$contract]+$evidence;
	}

	/** @return list<string> */
	public static function clientBriefContractNames(): array {
		return [
			'ordinary app builds receive a first-page-only brief',
			'elevated inspection briefs retain audit context without executing work',
			'brief compaction replaces repeated handoff detail with stable pointers',
		];
	}

	/** @return array<string,mixed> */
	public function clientBriefContract(string $contract): array {
		if(!in_array($contract,self::clientBriefContractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP client brief contract: '.$contract);
		}
		$evidence=match($contract){
			'ordinary app builds receive a first-page-only brief'=>$this->appBuilderBriefContract(),
			'elevated inspection briefs retain audit context without executing work'=>$this->elevatedBriefContract(),
			'brief compaction replaces repeated handoff detail with stable pointers'=>$this->briefCompactionContract(),
		};
		return ['contract'=>$contract]+$evidence;
	}

	/** @return list<string> */
	public static function clientStartPackContractNames(): array {
		return [
			'start-pack profiles keep detail and policy context proportional',
			'next detail selection follows continuation write verification and control state',
			'builder summaries derive readiness previews and continuation state',
			'discovery compaction is bounded and rejects malformed rows',
			'workflow summaries normalize recommendations and next-action fallbacks once',
		];
	}

	/** @return array<string,mixed> */
	public function clientStartPackContract(string $contract): array {
		if(!in_array($contract,self::clientStartPackContractNames(),true)){
			throw new \InvalidArgumentException('Unknown MCP client start-pack contract: '.$contract);
		}
		$evidence=match($contract){
			'start-pack profiles keep detail and policy context proportional'=>$this->startPackProfileContract(),
			'next detail selection follows continuation write verification and control state'=>$this->nextDetailSelectionContract(),
			'builder summaries derive readiness previews and continuation state'=>$this->builderSummaryContract(),
			'discovery compaction is bounded and rejects malformed rows'=>$this->discoveryCompactionContract(),
			'workflow summaries normalize recommendations and next-action fallbacks once'=>$this->workflowSummaryContract(),
		};
		return ['contract'=>$contract]+$evidence;
	}

	/** @return array<string,mixed> */
	private function staticPhpConfigContract(): array {
		$literal=$this->kernel->invoke('php_config_literal_array',<<<'PHP'
<?php
return [
	'text'=>'hello',
	'enabled'=>true,
	'disabled'=>false,
	'nothing'=>null,
	'count'=>-12,
	'ratio'=>1.25,
	'nested'=>array('child'=>'value'),
	'dynamic'=>getenv('NEVER_EXECUTE_THIS'),
];
PHP);
		$arraySyntax=$this->kernel->invoke('php_array_entries_flexible',"array('first'=>1, 'nested'=>array('second'=>2), dynamic_key()=>3, 'list item')");
		$scalarKinds=[];
		foreach([
			'string'=>"'value'",
			'true'=>'true',
			'false'=>'false',
			'null'=>'null',
			'int'=>'-7',
			'float'=>'2.5',
			'dynamic'=>'getenv("VALUE")',
		] as $name=>$expression){
			$scalarKinds[$name]=get_debug_type($this->kernel->invoke('php_literal_scalar_from_expression',$expression));
		}
		$topLevel=$this->kernel->invoke('top_level_php_array_entries',"['one'=>1, 'nested'=>['arrow'=>'inside'], dynamic()=>2, 'list']");
		$invalidTopLevel=[
			'wrong opener'=>$this->kernel->invoke('top_level_php_array_entries','not-an-array'),
			'unclosed'=>$this->kernel->invoke('top_level_php_array_entries',"['unclosed'=>true"),
		];
		$split=$this->kernel->invoke('split_top_level_php_expressions',<<<'PHP'
'a,b'=>1, "x,y"=>[1,2], call(a,b), 'tail'=>2
PHP);
		$this->kernel->invoke('split_top_level_php_expressions',<<<'PHP'
'escaped \' quote'=>1, "escaped \" quote"=>2
PHP);
		$arrow=$this->kernel->invoke('split_top_level_arrow',"'outer'=>['inner'=>'value']");
		$escapedArrow=$this->kernel->invoke('split_top_level_arrow',<<<'PHP'
'escaped \' key'=>"value => remains quoted"
PHP);
		$enclosure="['quoted ] value', ['nested'=>\"escaped \\\" ]\"]]";
		$nestedEnclosureOffset=$this->kernel->invoke('matching_enclosure_offset',$enclosure,0,'[',']');
		return [
			'literal_keys'=>array_keys(is_array($literal) ? $literal : []),
			'nested_value'=>$literal['nested']['child'] ?? null,
			'dynamic_kind'=>$literal['dynamic']['__unpreviewable_expression'] ?? null,
			'array_syntax_keys'=>array_keys(is_array($arraySyntax) ? $arraySyntax : []),
			'scalar_types'=>$scalarKinds,
			'top_level_keys'=>array_keys(is_array($topLevel) ? $topLevel : []),
			'invalid_top_level'=>$invalidTopLevel,
			'split_count'=>count(is_array($split) ? $split : []),
			'arrow_pair'=>$arrow,
			'escaped_arrow_pair'=>$escapedArrow,
			'invalid_forms_are_null'=>[
				'no return'=>$this->kernel->invoke('php_config_literal_array','<?php $value=1;')===null,
				'not an array'=>$this->kernel->invoke('php_literal_array_from_expression','getenv("VALUE")')===null,
				'empty expression'=>$this->kernel->invoke('php_array_entries_flexible','')===null,
				'unclosed array()'=>$this->kernel->invoke('php_array_entries_flexible',"array('x'=>1")===null,
			],
			'enclosures'=>[
				'wrong bracket'=>$this->kernel->invoke('matching_bracket_offset','value',0),
				'wrong enclosure'=>$this->kernel->invoke('matching_enclosure_offset','value',0,'[',']'),
				'unclosed'=>$this->kernel->invoke('matching_enclosure_offset','[value',0,'[',']'),
				'nested closes at end'=>$nestedEnclosureOffset===strlen($enclosure)-1,
			],
		];
	}

	/** @return array<string,mixed> */
	private function configShapeContract(): array {
		$workspace=$this->workspace('mcp-config-shapes');
		$json=$workspace->file('config/settings.json',(string)json_encode([
			'database'=>['host'=>'localhost','flags'=>[true,false]],
			'features'=>[['name'=>'one'],['name'=>'two']],
		],JSON_THROW_ON_ERROR));
		$invalidJson=$workspace->file('config/invalid.json','{invalid');
		$php=$workspace->file('config/settings.php',"<?php return ['first'=>1,'nested'=>['second'=>2],'first'=>3];");
		$captured=$this->kernel->capture('collect_config_shape',[
			'list'=>[['secret'=>'hidden'],2],
			'plain'=>'value',
		], '', [], 4);
		$previewResource=fopen('php://memory','rb');
		$previewValues=[
			'associative'=>['key'=>'value'],
			'unpreviewable'=>['__unpreviewable_expression'=>'expression'],
			'flat list'=>['one',2,true],
			'nested list'=>[['one']],
			'object'=>(object)['value'=>1],
			'resource'=>$previewResource,
			'scalar'=>'value',
		];
		$preview=[];
		foreach($previewValues as $name=>$value){$preview[$name]=$this->kernel->invoke('is_previewable_config_value',$value);}
		if(is_resource($previewResource)){fclose($previewResource);}
		$kindValues=[
			'expression'=>['__unpreviewable_expression'=>'call'],
			'list'=>[1,2],
			'object'=>['name'=>'value'],
			'bool'=>true,
			'int'=>1,
			'float'=>1.5,
			'null'=>null,
			'scalar'=>'value',
		];
		$kinds=[];
		foreach($kindValues as $name=>$value){$kinds[$name]=$this->kernel->invoke('config_value_kind',$value);}
		$unique=$this->kernel->invoke('unique_config_shape_paths',[
			['path'=>'','kind'=>'ignored'],
			['path'=>'same','kind'=>'first'],
			['path'=>'same','kind'=>'last'],
			['path'=>'other','kind'=>'value'],
		]);
		return [
			'json_keys'=>$this->kernel->invoke('extract_config_keys',$json),
			'php_keys'=>$this->kernel->invoke('extract_config_keys',$php),
			'missing_keys'=>$this->kernel->invoke('extract_config_keys',$workspace->path('config/missing.php')),
			'invalid_json_kind'=>$this->kernel->invoke('json_config_shape',$invalidJson,8)[0]['kind'] ?? null,
			'json_shape_count'=>count($this->kernel->invoke('json_config_shape',$json,20)),
			'bounded_shape_count'=>count(is_array($captured->argument('paths')) ? $captured->argument('paths') : []),
			'previewability'=>$preview,
			'kinds'=>$kinds,
			'found_value'=>$this->kernel->invoke('config_value_at_path',['outer'=>['inner'=>'value']],'outer.inner'),
			'missing_value'=>$this->kernel->invoke('config_value_at_path',['outer'=>'scalar'],'outer.inner'),
			'unpreviewable_value'=>$this->kernel->invoke('config_value_at_path',['dynamic'=>['__unpreviewable_expression'=>'call']],'dynamic'),
			'unique_paths'=>array_keys($unique),
		];
	}

	/** @return array<string,mixed> */
	private function repositoryPathContract(): array {
		$workspace=$this->workspace('mcp-repository-paths');
		$first=$workspace->file('visible/a.txt','alpha');
		$workspace->file('visible/b.txt','beta');
		$workspace->file('visible/c.txt','gamma');
		$workspace->file('.git/hidden.txt','hidden');
		$workspace->file('cdn_content/direct/assets/hidden.txt','hidden');
		$unreadable=$workspace->directory('unreadable');
		$unreadableWalk=iterator_to_array($this->kernel->invoke(
			'all_files',$unreadable,2,static fn(string $path): bool=>false,
		),false);
		$walked=iterator_to_array($this->kernel->invoke('all_files',$workspace->root(),2),false);
		$hygieneWalk=array_merge(
			iterator_to_array($this->kernel->invoke('all_files',$workspace->path('.git'),2),false),
			iterator_to_array($this->kernel->invoke('all_files',$workspace->path('cdn_content/direct/assets'),2),false),
		);
		$single=iterator_to_array($this->kernel->invoke('all_files',$first,2),false);
		$missing=iterator_to_array($this->kernel->invoke('all_files',$workspace->path('missing'),2),false);
		$safeAbsolute=$this->kernel->invoke('safe_repo_path',$first);
		$safeMissingLeaf=$this->kernel->invoke('safe_repo_path',$workspace->path('visible/new.txt'));
		$customAccess=$this->context->nonPublic(new \dataphyre_mcp_server($workspace->root(),[]));
		$insideRelative=$customAccess->invoke('relative_path',$first);
		$dataphyreRoot=\Dataphyre\Test\dataphyre_path();
		$dataphyreRelative=[
			'root'=>$customAccess->invoke('relative_path',$dataphyreRoot),
			'child'=>$customAccess->invoke('relative_path',$dataphyreRoot.'/runtime/modules/mcp/kernel/dataphyre_mcp.php'),
		];

		$symlinkContract=true;
		$symlinkExercised=false;
		$linkCommon=$workspace->directory('link-common');
		$realDataphyre=$workspace->directory('real-dataphyre');
		$realChild=$workspace->file('real-dataphyre/kernel.php','<?php');
		$link=$linkCommon.'/dataphyre';
		if(function_exists('symlink') && @symlink($realDataphyre,$link)){
			$symlinkExercised=true;
			$symlinkAccess=$this->context->nonPublic(new \dataphyre_mcp_server($workspace->directory('server-root'),[]));
			$symlinkAccess->writeProperty('common_root',$linkCommon);
			$symlinkContract=$symlinkAccess->invoke('path_is_within_dataphyre_real_root',$realChild)
				&& $symlinkAccess->invoke('relative_path',$realDataphyre)==='dataphyre'
				&& $symlinkAccess->invoke('relative_path',$realChild)==='dataphyre/kernel.php';
		}

		return [
			'walk_count'=>count($walked),
			'walk_excludes_hygiene_paths'=>count(array_filter($walked,static fn(string $path): bool=>str_contains(str_replace('\\','/',$path),'/.git/') || str_contains(str_replace('\\','/',$path),'/cdn_content/direct/assets/')))===0,
			'hygiene_walk_count'=>count($hygieneWalk),
			'single_file_count'=>count($single),
			'missing_count'=>count($missing),
			'unreadable_count'=>count($unreadableWalk),
			'unreadable_exercised'=>true,
			'safe_absolute_matches'=>$safeAbsolute===str_replace('\\','/',realpath($first) ?: $first),
			'safe_missing_leaf'=>$safeMissingLeaf,
			'prefix_sibling_rejected'=>!$customAccess->invoke('path_is_within_root',$workspace->root().'-sibling',$workspace->root()),
			'empty_root_rejected'=>!$customAccess->invoke('path_is_within_root','/outside',''),
			'path_errors'=>[
				'empty'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('safe_repo_path','')),
				'missing parent'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('safe_repo_path',$workspace->path('missing/leaf.txt'))),
				'escape'=>$this->exceptionMessage(fn()=>$customAccess->invoke('safe_repo_path','/tmp/outside-mcp-contract.txt')),
			],
			'error_labels'=>[
				'empty'=>$this->kernel->invoke('path_error_label',''),
				'windows'=>$this->kernel->invoke('path_error_label','C:/Users/private/file.php'),
				'posix'=>$this->kernel->invoke('path_error_label','/home/private/file.php'),
				'relative'=>$this->kernel->invoke('path_error_label',str_repeat('relative-segment/',20).'file.php'),
			],
			'inside_relative'=>$insideRelative,
			'dataphyre_relative'=>$dataphyreRelative,
			'outside_relative'=>$customAccess->invoke('relative_path','/tmp/outside.php'),
			'symlink_contract'=>$symlinkContract,
			'symlink_exercised'=>$symlinkExercised,
		];
	}

	/** @return array<string,mixed> */
	private function legacyManifestContract(): array {
		$workspace=$this->workspace('mcp-unit-manifests');
		$invalid=$workspace->file('invalid.json','{invalid');
		$manifest=$workspace->file('rich.json',(string)json_encode([
			'invalid-case',
			[
				'name'=>'rich contract',
				'function'=>'contract_function',
				'file'=>['resolver'=>'fixture.php'],
				'file_dynamic'=>'legacy resolver',
				'args'=>[1,2],
				'expected'=>[
					['assert'=>['same'=>true]],
					['custom_script'=>'never execute'],
					['min'=>1,'max'=>3],
					['array','string'],
					'regex:/ready/',
					42,
				],
				'max_millis'=>50,
			],
			['name'=>'truncated case','file'=>'helper.php','expected'=>null],
		],JSON_THROW_ON_ERROR));
		$summary=$this->kernel->invoke('unit_test_manifest_summary',$manifest,2,true);
		$withoutExpected=$this->kernel->invoke('unit_test_manifest_summary',$manifest,5,false);
		$shapes=[];
		foreach([
			'declarative'=>['assert'=>[]],
			'custom'=>['custom_script'=>'never'],
			'range'=>['min'=>1,'max'=>2],
			'array shape'=>['array','string'],
			'array'=>['value'=>'x'],
			'regex'=>'regex:/x/',
			'scalar'=>42,
		] as $name=>$value){$shapes[$name]=$this->kernel->invoke('unit_test_expected_shape',$value);}
		return [
			'invalid_manifest'=>$this->kernel->invoke('unit_test_manifest_summary',$invalid,2,false),
			'valid_manifest'=>[
				'case_count'=>$summary['case_count'] ?? 0,
				'returned_cases'=>$summary['returned_cases'] ?? 0,
				'truncated'=>$summary['truncated'] ?? false,
				'has_custom_script'=>$summary['has_custom_script'] ?? false,
				'helper_files'=>$summary['helper_files'] ?? [],
				'expected_shapes'=>$summary['cases'][0]['expected_shapes'] ?? [],
				'expected_included'=>array_key_exists('expected',$summary['cases'][0] ?? []),
				'expected_withheld'=>!array_key_exists('expected',$withoutExpected['cases'][0] ?? []),
			],
			'shapes'=>$shapes,
			'custom_script_detection'=>[
				'scalar'=>$this->kernel->invoke('contains_unit_test_custom_script','value'),
				'direct'=>$this->kernel->invoke('contains_unit_test_custom_script',['custom_script'=>'never']),
				'nested'=>$this->kernel->invoke('contains_unit_test_custom_script',['outer'=>[['custom_script'=>'never']]]),
			],
			'modules'=>[
				'direct'=>$this->kernel->invoke('module_from_unit_test_path','dataphyre/runtime/modules/access/unit_tests/access.json'),
				'common'=>$this->kernel->invoke('module_from_unit_test_path','common/dataphyre/runtime/modules/sql/unit_tests/sql.json'),
				'missing'=>$this->kernel->invoke('module_from_unit_test_path','unit_tests/unknown.json'),
			],
			'tracelog_kinds'=>array_map(fn(string $path): string=>$this->kernel->invoke('tracelog_artifact_kind',$path),[
				'logs/tracelog_handoff.json','logs/tracelog_plotting.html','plotting.dat','logs/tracelog.txt','logs/ordinary.log',
			]),
		];
	}

	/** @return array<string,mixed> */
	private function sourceExtractorContract(): array {
		$source=<<<'PHP'
<?php
load_framework_modules(['mcp', "sql"]);
dp_module_required('routing', 'ignored');
load_framework_module("panel");
sql_table('orders'); sql_table("customers");
require_once __DIR__.'/first.php';
include ($dynamic.'/second.php');
PHP;
		$modules=[
			'many'=>$this->kernel->invoke('extract_module_names_from_calls',$source,'load_framework_modules'),
			'required'=>$this->kernel->invoke('extract_module_names_from_calls',$source,'dp_module_required'),
			'one'=>$this->kernel->invoke('extract_module_names_from_calls',$source,'load_framework_module'),
		];
		$long='prefix '.str_repeat('before ',30).'MATCH '.str_repeat('after ',30).' suffix';
		$middle=(int)strpos($long,'MATCH');
		return [
			'modules'=>$modules,
			'tables'=>$this->kernel->invoke('extract_string_arguments',$source,'sql_table'),
			'includes'=>$this->kernel->invoke('extract_include_expressions',$source),
			'snippets'=>[
				'start'=>$this->kernel->invoke('snippet_around',$long,0,6,40),
				'middle'=>$this->kernel->invoke('snippet_around',$long,$middle,5,40),
				'end'=>$this->kernel->invoke('snippet_around',$long,strlen($long)-6,6,40),
			],
			'line_number'=>$this->kernel->invoke('line_number_for_offset',"one\ntwo\nthree",8),
		];
	}

	/** @return array<string,mixed> */
	private function localCommandContract(): array {
		$workspace=$this->workspace('mcp-local-command');
		$file=$workspace->file('bounded.txt',str_repeat('abcdef',20));
		$php=\Dataphyre\Test\PhpRuntime::binary();
		$command=[$php,'-r','fwrite(STDOUT,"token=secret-value"); fwrite(STDERR,"customer_id=42");'];
		$withoutStderr=$this->kernel->invoke('run_command',$command,5000,false);
		$withStderr=$this->kernel->invoke('run_command',$command,5000,true);
		$nonzero=$this->kernel->invoke('run_command',[$php,'-r','exit(7);'],5000,true);
		$signalled=$this->kernel->invoke('run_command',[$php,'-r','if(function_exists("posix_kill")){posix_kill(getmypid(),15); usleep(50000);} exit(9);'],5000,true);
		$timeout=$this->exceptionMessage(fn()=>$this->kernel->invoke('run_command',[$php,'-r','usleep(50000);'],1,true));
		$invalidRootAccess=$this->context->nonPublic(new \dataphyre_mcp_server($workspace->root(),[]));
		$invalidRootAccess->writeProperty('root',$workspace->path('missing-working-directory'));
		$invalidRootFailure=$this->exceptionMessage(fn()=>$invalidRootAccess->invoke('run_command',[$php,'-r','exit(0);'],5000,true));
		$startFailure=$this->exceptionMessage(fn()=>$this->kernel->invoke(
			'run_command',
			[$php,'-r','exit(0);'],
			5000,
			true,
			static fn(array $command,array $descriptor,string $root): array=>['process'=>false,'pipes'=>[]],
		));
		$previous=getenv('DATAPHYRE_MCP_PHP_BINARY');
		putenv('DATAPHYRE_MCP_PHP_BINARY=/contract/php');
		try{$configuredBinary=$this->kernel->invoke('php_binary');}
		finally{
			if($previous===false){putenv('DATAPHYRE_MCP_PHP_BINARY');}
			else{putenv('DATAPHYRE_MCP_PHP_BINARY='.$previous);}
		}
		return [
			'without_stderr'=>$withoutStderr,
			'with_stderr'=>$withStderr,
			'nonzero_exit'=>$nonzero['exit_code'] ?? null,
			'signal_exit'=>$signalled['exit_code'] ?? null,
			'timeout_message'=>$timeout,
			'start_failure'=>$startFailure,
			'invalid_root_failure'=>$invalidRootFailure,
			'configured_binary'=>$configuredBinary,
			'default_binary'=>$this->kernel->invoke('php_binary'),
			'bounded_read'=>$this->kernel->invoke('read_repo_text',$file,12),
			'missing_read_error'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('read_repo_text',$workspace->path('missing.txt'),12)),
		];
	}

	/** @return array<string,mixed> */
	private function sqlDefinitionContract(): array {
		$workspace=$this->workspace('mcp-sql-definitions');
		$byId=$workspace->file('definitions/by-id.php',<<<'PHP'
<?php
use Dataphyre\Database\TableDefinition;
return [
	'contract-id'=>static fn(string $table, ?string $definitionId=null): TableDefinition=>TableDefinition::for($table),
	'orders'=>TableDefinition::for('orders_direct'),
];
PHP);
		$directInstance=$workspace->file('definitions/direct-instance.php',<<<'PHP'
<?php
use Dataphyre\Database\TableDefinition;
return TableDefinition::for('direct_instance');
PHP);
		$directCallable=$workspace->file('definitions/direct-callable.php',<<<'PHP'
<?php
use Dataphyre\Database\TableDefinition;
return static fn(string $table, ?string $definitionId=null): TableDefinition=>TableDefinition::for($table);
PHP);
		$fallback=$workspace->file('definitions/fallback.php',<<<'PHP'
<?php
use Dataphyre\Database\TableDefinition;
return [
	'noise'=>'not-a-definition',
	'fallback'=>static function(string $table): TableDefinition {
		if(func_num_args()>1){throw new ArgumentCountError('one argument contract');}
		return TableDefinition::for($table);
	},
];
PHP);
		$invalid=$workspace->file('definitions/invalid.php',"<?php return ['noise'=>'not-a-definition'];");
		$relative=fn(string $path): string=>$this->kernel->invoke('relative_path',$path);
		$configDefinitions=[
			'empty'=>$this->kernel->invoke('load_config_table_definition','','orders'),
			'missing'=>$this->kernel->invoke('load_config_table_definition',$relative($workspace->path('definitions/missing.php')),'orders'),
			'by id'=>$this->kernel->invoke('load_config_table_definition',$relative($byId),'orders','contract-id'),
			'by table'=>$this->kernel->invoke('load_config_table_definition',$relative($byId),'orders'),
			'direct instance'=>$this->kernel->invoke('load_config_table_definition',$relative($directInstance),'orders'),
			'direct callable'=>$this->kernel->invoke('load_config_table_definition',$relative($directCallable),'orders'),
			'fallback callable'=>$this->kernel->invoke('load_config_table_definition',$relative($fallback),'orders','missing-id'),
			'invalid'=>$this->kernel->invoke('load_config_table_definition',$relative($invalid),'orders'),
		];

		$manifest=$this->kernel->invoke('sql_runtime_table_manifest');
		$runtimeDefinitions=[];
		foreach(array_keys($manifest) as $table){
			$runtimeDefinitions[(string)$table]=$this->kernel->invoke('load_runtime_table_definition',(string)$table);
		}
		$runtimeDefinitions['unknown']=$this->kernel->invoke('load_runtime_table_definition','not_a_runtime_table');
		$missingRuntimeAccess=$this->context->nonPublic(new \dataphyre_mcp_server(dirname(\Dataphyre\Test\dataphyre_path()),[]));
		$missingRuntimeAccess->writeProperty('common_root',$workspace->root());
		$knownTable=(string)(array_key_first($manifest) ?? 'sessions');
		$missingRuntime=$missingRuntimeAccess->invoke('load_runtime_table_definition',$knownTable);

		$validSql=$workspace->file('valid/sql.php',"<?php return ['primary'=>['driver'=>'sqlite']];");
		$invalidSql=$workspace->file('invalid/sql.php',"<?php return 'not-an-array';");
		$wrongName=$workspace->file('valid/not-sql.php','<?php return [];');
		$beforeServer=$_SERVER;
		$validConfig=$this->kernel->invoke('read_sql_config',$relative($validSql));
		return [
			'config_definition_types'=>array_map(static fn(mixed $definition): string=>get_debug_type($definition),$configDefinitions),
			'runtime_definition_count'=>count(array_filter($runtimeDefinitions,static fn(mixed $definition): bool=>$definition instanceof \Dataphyre\Database\TableDefinition)),
			'runtime_unknown_is_null'=>$runtimeDefinitions['unknown']===null,
			'runtime_missing_file_is_null'=>$missingRuntime===null,
			'valid_sql_config'=>$validConfig,
			'server_restored'=>$_SERVER===$beforeServer,
			'wrong_name_error'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('read_sql_config',$relative($wrongName))),
			'invalid_return_error'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('read_sql_config',$relative($invalidSql))),
		];
	}

	/** @return array<string,mixed> */
	private function configInventoryContract(): array {
		$workspace=$this->workspace('mcp-data-boundaries');
		$configDirectory=$workspace->directory('fixtures/config');
		for($index=0;$index<82;$index++){
			$workspace->file('fixtures/config/config-'.str_pad((string)$index,2,'0',STR_PAD_LEFT).'.php',"<?php return ['index'=>{$index}];");
		}
		$readme=$workspace->file('fixtures/config/readme.txt','not a config source');
		$json=$workspace->file('fixtures/config/preview.json',(string)json_encode([
			'safe'=>'visible',
			'password'=>'never-return',
			'nested'=>['token'=>'never-return'],
			'object'=>['child'=>'not-a-scalar-list'],
			'list'=>[1,null,true],
		],JSON_THROW_ON_ERROR));
		$dynamic=$workspace->file('fixtures/config/dynamic.php','<?php return getenv("MCP_CONFIG_MUST_NOT_EXECUTE");');
		$plainPhp=$workspace->file('plain.php',"<?php return ['safe'=>'visible'];");
		$relative=fn(string $path): string=>$this->kernel->invoke('relative_path',$path);
		$inventory=$this->kernel->invoke('list_config_keys',$relative($configDirectory));
		$extensionFiltered=$this->kernel->invoke('list_config_keys',$relative($readme));
		$shape=$this->kernel->invoke('read_config_shape',['path'=>$relative($json),'max_paths'=>2]);
		$preview=$this->kernel->invoke('config_value_preview',[
			'path'=>$relative($json),
			'keys'=>['','password','nested.token','missing','object','safe','list'],
			'max_values'=>20,
		]);
		$parseFailure=$this->kernel->invoke('config_value_preview',['path'=>$relative($dynamic),'keys'=>['safe']]);
		return [
			'inventory_count'=>count($inventory['configs'] ?? []),
			'inventory_paths'=>array_column($inventory['configs'] ?? [],'path'),
			'extension_filtered_count'=>count($extensionFiltered['configs'] ?? []),
			'shape'=>[
				'type'=>$shape['type'] ?? null,
				'path_count'=>$shape['path_count'] ?? null,
				'truncated'=>$shape['truncated'] ?? null,
				'values_returned'=>$shape['values_returned'] ?? null,
			],
			'preview_values'=>$preview['values'] ?? [],
			'preview_returned_count'=>$preview['returned_count'] ?? null,
			'parse_failure'=>$parseFailure,
			'errors'=>[
				'config-like path'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('read_config_shape',['path'=>$relative($plainPhp)])),
				'preview path'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('config_value_preview',['path'=>$relative($plainPhp),'keys'=>['safe']])),
				'empty keys'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('config_value_preview',['path'=>$relative($json),'keys'=>[]])),
			],
		];
	}

	/** @return array<string,mixed> */
	private function storageConfigContract(): array {
		$workspace=$this->workspace('mcp-storage-boundaries');
		$config=$workspace->file('storage.php',<<<'PHP'
<?php
return [
	'default_disk'=>'local',
	'disks'=>[
		'ignored'=>getenv('MCP_STORAGE_MUST_NOT_EXECUTE'),
		'local'=>[
			'driver'=>'local',
			'root'=>'/private/path',
			'password'=>'never-return',
		],
	],
];
PHP);
		$summary=$this->kernel->invoke('storage_config_summary',['config_path'=>$this->kernel->invoke('relative_path',$config)]);
		return [
			'execution'=>$summary['execution'] ?? null,
			'default_disk'=>$summary['default_disk'] ?? null,
			'disk_count'=>$summary['disk_count'] ?? null,
			'disks'=>$summary['disks'] ?? [],
			'driver_count'=>count($summary['available_driver_classes'] ?? []),
		];
	}

	/** @return array<string,mixed> */
	private function sqlCatalogContract(): array {
		$workspace=$this->workspace('mcp-sql-catalog-boundaries');
		$definition=$workspace->file('definitions/orders.php',<<<'PHP'
<?php
use Dataphyre\Database\TableDefinition;
return [
	'contract-definition'=>static fn(string $table, ?string $definitionId=null): TableDefinition=>TableDefinition::for($table),
];
PHP);
		$definitionRelative=$this->kernel->invoke('relative_path',$definition);
		$configSource="<?php\nreturn [\n"
			."\t'default_cluster'=>'primary',\n"
			."\t'datacenters'=>[\n"
			."\t\t'east'=>['dbms_clusters'=>[\n"
			."\t\t\t'primary'=>['dbms'=>'postgresql','password'=>'never-return'],\n"
			."\t\t\t'fallback'=>'legacy-shape',\n"
			."\t\t]],\n"
			."\t],\n"
			."\t'tables'=>[\n"
			."\t\t''=>[],\n"
			."\t\t'contract.orders'=>['cluster'=>'primary','multipoint_writes'=>true,'caching'=>[], 'definition_file'=>".var_export($definitionRelative,true).",'definition_id'=>'contract-definition'],\n"
			."\t\t'contract.scalar'=>'primary',\n"
			."\t],\n"
			."];\n";
		$config=$workspace->file('config/sql.php',$configSource);
		$configRelative=$this->kernel->invoke('relative_path',$config);
		$transcript=(new McpScenario($this->context))->exchangeSharded([
			McpScenario::request(1,'tools/call',['name'=>'dataphyre_sql_tables_list','arguments'=>[
				'include_runtime_manifest'=>false,
				'include_config_tables'=>true,
				'config_path'=>$configRelative,
			]]),
			McpScenario::request(2,'tools/call',['name'=>'dataphyre_sql_schema_read','arguments'=>[
				'table'=>'contract.orders',
				'config_path'=>$configRelative,
				'include_create_sql'=>true,
			]]),
			McpScenario::request(3,'tools/call',['name'=>'dataphyre_sql_clusters_list','arguments'=>[
				'config_path'=>$configRelative,
			]]),
		],120000);
		$tables=$transcript->toolPayload(1);
		$schema=$transcript->toolPayload(2);
		$clusters=$transcript->toolPayload(3);
		return [
			'table_names'=>array_column($tables['tables'] ?? [],'table'),
			'table_entries'=>$tables['tables'] ?? [],
			'schema'=>[
				'table'=>$schema['table'] ?? null,
				'registered'=>$schema['registered'] ?? null,
				'columns'=>$schema['schema']['columns'] ?? null,
				'has_create_queries'=>array_key_exists('create_queries',$schema['definition'] ?? []),
			],
			'clusters'=>$clusters,
			'missing_table_error'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('read_sql_schema',['table'=>''])),
		];
	}

	/** @return array<string,mixed> */
	private function sqlPlannerContract(): array {
		$eligible=$this->kernel->invoke('sql_query_plan',[
			'sql'=>'SELECT orders.id FROM orders JOIN customers ON customers.id=orders.customer_id',
			'max_rows'=>25,
			'allowed_tables'=>[' orders ','customers','orders',''],
		]);
		$unsafe=$this->kernel->invoke('sql_query_plan',[
			'sql'=>'UPDATE orders SET note="delete; from secrets"; SELECT * FROM audit_log -- review',
			'max_rows'=>5,
			'allowed_tables'=>['orders'],
		]);
		$oversized=$this->kernel->invoke('sql_query_plan',[
			'sql'=>'SELECT * FROM reports LIMIT 500',
			'max_rows'=>10,
			'allowed_tables'=>['reports'],
		]);
		$noTable=$this->kernel->invoke('sql_query_plan',['sql'=>'SELECT 1']);
		$quoted=$this->kernel->invoke('sql_without_quoted_strings',<<<'SQL'
SELECT 'escaped \' delete', "double join", `quoted_table` FROM orders
SQL);
		return [
			'eligible'=>$eligible,
			'unsafe'=>$unsafe,
			'oversized'=>$oversized,
			'no_table'=>$noTable,
			'missing_sql_error'=>$this->exceptionMessage(fn()=>$this->kernel->invoke('sql_query_plan',['sql'=>''])),
			'unknown_kind'=>$this->kernel->invoke('sql_statement_kind','123'),
			'quoted_mask'=>[
				'length_preserved'=>strlen($quoted)===strlen(<<<'SQL'
SELECT 'escaped \' delete', "double join", `quoted_table` FROM orders
SQL),
				'contains_literal_words'=>str_contains($quoted,'delete') || str_contains($quoted,'double join') || str_contains($quoted,'quoted_table'),
			],
			'trailing_semicolon_is_single'=>!$this->kernel->invoke('sql_has_multiple_statements','SELECT 1;'),
			'blocked'=>$this->kernel->invoke('sql_blocked_verbs','SELECT * FROM source INTO OUTFILE target; DELETE FROM source'),
			'referenced'=>$this->kernel->invoke('sql_referenced_tables','SELECT * FROM app.orders JOIN customers ON 1=1 JOIN customers ON 1=1'),
			'limits'=>[
				'missing'=>$this->kernel->invoke('sql_limit_value','SELECT * FROM orders'),
				'present'=>$this->kernel->invoke('sql_limit_value','SELECT * FROM orders LIMIT 12'),
			],
			'bounded'=>[
				'added'=>$this->kernel->invoke('sql_bounded_preview','SELECT * FROM orders;',null,10),
				'capped'=>$this->kernel->invoke('sql_bounded_preview','SELECT * FROM orders LIMIT 50;',50,10),
				'kept'=>$this->kernel->invoke('sql_bounded_preview','SELECT * FROM orders LIMIT 5;',5,10),
			],
		];
	}

	/** @return array<string,mixed> */
	private function sqlReadinessContract(): array {
		$defaults=$this->kernel->invoke('sql_runtime_readiness_plan',['config_path'=>'','cluster'=>'']);
		$named=$this->kernel->invoke('sql_runtime_readiness_plan',['config_path'=>'dataphyre/config/sql.php','cluster'=>'primary']);
		return [
			'defaults'=>[
				'config_path'=>$defaults['config_path'] ?? null,
				'cluster'=>$defaults['cluster'] ?? null,
				'execution'=>$defaults['execution'] ?? null,
				'database_connection'=>$defaults['database_connection'] ?? null,
			],
			'named'=>[
				'config_path'=>$named['config_path'] ?? null,
				'cluster'=>$named['cluster'] ?? null,
			],
			'denied_outputs'=>$defaults['denied_future_outputs'] ?? [],
		];
	}

	/** @return array<string,mixed> */
	private function applicationCatalogContract(): array {
		$workspace=$this->workspace('mcp-application-catalog');
		$workspace->file('applications/not-an-application.txt','file entries are ignored');
		$workspace->directory('applications/.hidden');
		$workspace->file('applications/alpha/backend/dataphyre/config/mvc.php',<<<'PHP'
<?php return ['namespace'=>'Alpha\\Controllers'];
PHP);
		$workspace->file('applications/alpha/backend/dataphyre/config/panel.php','<?php return [];');
		$workspace->file('applications/alpha/backend/dataphyre/config/sql.php','<?php return [];');
		$workspace->file('applications/alpha/backend/dataphyre/config/storage.php','<?php return [];');
		$workspace->file('applications/alpha/backend/dataphyre/config/notes.txt','ignored');
		$workspace->directory('applications/alpha/backend/dataphyre/config/nested');
		$workspace->directory('applications/alpha/backend/dataphyre/Framework');
		$workspace->directory('applications/alpha/backend/dataphyre/plugins');
		$workspace->directory('applications/alpha/backend/dataphyre/unit_tests');
		$workspace->file('applications/beta/dataphyre/config/mvc.php',<<<'PHP'
<?php return ["namespace"=>"Beta\\Models"];
PHP);
		$workspace->directory('applications/gamma');
		$access=$this->context->nonPublic(new \dataphyre_mcp_server($workspace->root(),[]));
		$all=$access->invoke('application_catalog',['limit'=>20]);
		$scoped=$access->invoke('application_catalog',['scope'=>'applications/alpha','include_config_files'=>false,'limit'=>1]);
		$items=[];
		foreach($all['items'] ?? [] as $item){$items[(string)($item['application_id'] ?? '')]=$item;}
		return [
			'ids'=>array_keys($items),
			'layouts'=>array_map(static fn(array $item): string=>(string)($item['detected_layout'] ?? ''),$items),
			'namespaces'=>array_map(static fn(array $item): array=>$item['namespace_hint'] ?? [],$items),
			'alpha'=>array_intersect_key($items['alpha'] ?? [],array_flip([
				'dataphyre_root','config_files','config_file_count','has_mvc_config','has_panel_config','has_sql_config','has_storage_config',
				'framework_path_exists','plugins_path_exists','unit_tests_path_exists',
			])),
			'scoped'=>[
				'scope'=>$scoped['scope'] ?? null,
				'candidate_count'=>$scoped['candidate_count'] ?? null,
				'config_files'=>$scoped['items'][0]['config_files'] ?? null,
			],
		];
	}

	/** @return array<string,mixed> */
	private function packageManifestContract(): array {
		$workspace=$this->workspace('mcp-package-manifests');
		$composer=$workspace->file('packages/backend/composer.json',(string)json_encode([
			'name'=>'contract/backend',
			'require'=>['z/package'=>['complex'=>true],''=>'ignored','a/package'=>'^1.0','php'=>'^8.4'],
			'require-dev'=>'not-an-array',
		],JSON_THROW_ON_ERROR));
		$package=$workspace->file('packages/frontend/package.json',(string)json_encode([
			'name'=>'contract-frontend',
			'dependencies'=>'not-an-array',
			'devDependencies'=>['z-tool'=>'2','a-tool'=>'1'],
			'peerDependencies'=>['peer'=>null],
			'scripts'=>['test'=>'never executed'],
		],JSON_THROW_ON_ERROR));
		$relative=fn(string $path): string=>$this->kernel->invoke('relative_path',$path);
		$summary=$this->kernel->invoke('read_package_metadata',['paths'=>[$relative($package),$relative($composer),$relative($package)],'limit'=>10]);
		$workspaceKernel=$this->context->nonPublic(new \dataphyre_mcp_server($workspace->root(),[]));
		$boundedScan=$workspaceKernel->invoke('read_package_metadata',['limit'=>1]);
		$manifests=[];
		foreach($summary['manifests'] ?? [] as $manifest){$manifests[(string)($manifest['name'] ?? '')]=$manifest;}
		return [
			'manifest_count'=>$summary['manifest_count'] ?? null,
			'bounded_scan_count'=>$boundedScan['manifest_count'] ?? null,
			'backend_require'=>$manifests['contract/backend']['require'] ?? null,
			'backend_require_dev'=>$manifests['contract/backend']['require_dev'] ?? null,
			'frontend_dependencies'=>$manifests['contract-frontend']['dependencies'] ?? null,
			'frontend_dev_dependencies'=>$manifests['contract-frontend']['dev_dependencies'] ?? null,
			'frontend_scripts'=>$manifests['contract-frontend']['script_names'] ?? null,
			'direct_dependency_map'=>$this->kernel->invoke('dependency_map',[''=>'ignored','z'=>['complex'=>true],'a'=>'1']),
		];
	}

	/** @return array<string,mixed> */
	private function sourceCatalogContract(): array {
		$workspace=$this->workspace('mcp-source-catalogs');
		$api=$workspace->file('api/routes.php',<<<'PHP'
<?php
get /* token gap */ ('/v1/orders', [OrderController::class, 'index']);
$router->post('/orders/{id}', static fn()=>null);
Api::delete('/v2/orders/{id}', 'delete-handler');
methods(['get', 'post'], '/v2/items', ['ItemController', 'dispatch']);
methods(dynamic_methods(), '/v2/fallback', null);
get($dynamicPath, null);
OpenApiDocument();
PHP);
		$workspace->file('api/readme.txt','ignored');
		$source=$workspace->file('source/ContractSource.php',<<<'PHP'
<?php
namespace Contract\Source;
final class Service {
	public static function build(array $input=[]): self { return new self(); }
	protected function guarded(): void {}
	function implicitVisibility(): string { return 'ready'; }
}
function helper(int $value): int { return $value; }
PHP);
		$workspace->file('source/readme.txt','ignored');
		$relative=fn(string $path): string=>$this->kernel->invoke('relative_path',$path);
		$apiDirectory=$this->kernel->invoke('api_docs_static_summary',['paths'=>[$relative($workspace->path('api'))],'limit'=>1]);
		$apiFileThenMissing=$this->kernel->invoke('api_docs_static_summary',[
			'paths'=>[$relative($api),$relative($workspace->path('api/missing.php'))],
			'limit'=>1,
		]);
		$sourceDirectory=$this->kernel->invoke('source_api_summary',['paths'=>[$relative($workspace->path('source'))],'limit'=>1]);
		$sourceFileThenMissing=$this->kernel->invoke('source_api_summary',[
			'paths'=>[$relative($source),$relative($workspace->path('source/missing.php'))],
			'limit'=>1,
		]);
		$endpoints=$this->kernel->invoke('api_endpoint_declarations_from_file',$api);
		$unclosedTokens=token_get_all("<?php get /* gap */ ('/v1/unclosed'");
		$unclosedIndex=$this->tokenIndex($unclosedTokens,'get');
		return [
			'api_bounds'=>[$apiDirectory['scanned_files'] ?? null,$apiFileThenMissing['scanned_files'] ?? null],
			'endpoints'=>$endpoints['endpoints'] ?? [],
			'openapi_count'=>count($endpoints['openapi_surfaces'] ?? []),
			'unclosed_call'=>$this->kernel->invoke('call_arguments_after_token',$unclosedTokens,$unclosedIndex),
			'source_bounds'=>[$sourceDirectory['file_count'] ?? null,$sourceFileThenMissing['file_count'] ?? null],
			'source_file'=>$sourceDirectory['files'][0] ?? [],
		];
	}

	/** @return array<string,mixed> */
	private function tokenNavigationContract(): array {
		$whitespace=[[T_STRING,'before',1],[T_WHITESPACE,' ',1],[T_COMMENT,'/* gap */',1]];
		$visibility=['{',[T_FUNCTION,'function',1]];
		return [
			'next_token_exhausted'=>$this->kernel->invoke('next_token_text',[[T_WHITESPACE,' ',1]],0,T_STRING),
			'previous_id_at_start'=>$this->kernel->invoke('previous_meaningful_token_id',[],0),
			'previous_index_at_start'=>$this->kernel->invoke('previous_meaningful_token_index',[],0),
			'next_index_after_gaps'=>$this->kernel->invoke('next_meaningful_token_index',$whitespace,0),
			'next_index_exhausted'=>$this->kernel->invoke('next_meaningful_token_index',$whitespace,2),
			'default_visibility'=>$this->kernel->invoke('function_visibility',$visibility,1),
			'static_at_start'=>$this->kernel->invoke('function_is_static',[[T_FUNCTION,'function',1]],0),
		];
	}

	/** @return array<string,mixed> */
	private function startPackProfileContract(): array {
		$detail=$this->kernel->invoke('mcp_task_start_pack_export',[
			'task'=>'Build an orders admin CRUD application with filters actions and focused verification',
			'target'=>'generic',
			'payload_profile'=>'detail',
			'include_deep_context'=>false,
			'limit'=>1,
			'entities'=>['Order'],
			'application_path'=>'applications/commerce',
			'app_namespace'=>'Commerce',
		]);
		$policy=$this->kernel->invoke('mcp_task_start_pack_export',[
			'task'=>'Build a patient access application with tenant-scoped records and focused verification',
			'payload_profile'=>'builder',
			'limit'=>1,
			'entities'=>['Patient'],
			'fields'=>[
				'Patient'=>[
					'tenant_id'=>['type'=>'integer'],
					'email'=>['type'=>'string'],
					'secret_token'=>['type'=>'string'],
				],
			],
			'application_path'=>'applications/health',
			'app_namespace'=>'Health',
		]);
		$notes=is_array($policy['context_policy']['governance_notes'] ?? null)
			? $policy['context_policy']['governance_notes']
			: [];
		return [
			'detail'=>[
				'profile'=>$detail['startup_lane']['payload_profile'] ?? null,
				'next_read'=>$detail['startup_lane']['next_read'] ?? null,
				'has_operating_contract'=>array_key_exists('application_agent_operating_contract',$detail),
				'deep_context_inline'=>$detail['deep_context']['included_inline'] ?? null,
				'has_status_board'=>array_key_exists('status_board',$detail),
			],
			'policy'=>[
				'status'=>$notes['status'] ?? null,
				'default_lane'=>$notes['default_lane'] ?? null,
				'open_only_for_count'=>count(is_array($notes['open_only_for'] ?? null) ? $notes['open_only_for'] : []),
				'has_compact_sensitivity'=>array_key_exists('data_sensitivity_summary',$policy['app_builder_lane'] ?? $policy['builder_response'] ?? []),
				'detail_page'=>$policy['builder_first_read']['next_detail_page']['page'] ?? null,
			],
		];
	}

	/** @return array<string,mixed> */
	private function nextDetailSelectionContract(): array {
		$scenarios=[
			'continuation'=>[['status'=>'continue_entity_chunks'],[],[],[]],
			'deferred entities'=>[[],[],['deferred_count'=>2],[]],
			'prewrite path'=>[['action'=>'Resolve application_path placeholders'],[],[],[]],
			'ready writes'=>[[],['ready_for_app_owned_writes'=>true],[],[]],
			'verification evidence'=>[['action'=>'Collect focused verification evidence'],[],[],[]],
			'policy controls'=>[[],[],[],['status'=>'app_owned_policy_attention']],
			'default planning'=>[[],[],[],[]],
		];
		$choices=[];
		foreach($scenarios as $name=>$arguments){
			$choices[$name]=$this->kernel->invoke('mcp_app_builder_next_detail_page',...$arguments);
		}
		return ['choices'=>$choices];
	}

	/** @return array<string,mixed> */
	private function builderSummaryContract(): array {
		$existing=['status'=>'provided_by_builder','ready_for_app_owned_writes'=>true];
		$provided=$this->kernel->invoke('mcp_app_builder_resolved_write_readiness',['write_readiness'=>$existing]);
		$ready=$this->kernel->invoke('mcp_app_builder_resolved_write_readiness',[],null);
		$blocked=$this->kernel->invoke('mcp_app_builder_resolved_write_readiness',[
			'scaffold_completion_summary'=>['complete'=>true,'deferred_entities'=>[]],
			'prewrite_checklist'=>['prewrite_blockers'=>[['id'=>'confirm_path','action'=>'Confirm application path']]],
		]);
		$planning=[
			'planned_entities'=>['Order'],
			'deferred_entities'=>['Invoice'],
			'truncated'=>true,
			'continuation_calls'=>[[
				'tool'=>'dataphyre_app_builder_plan_generate',
				'chunk'=>2,
				'arguments'=>[
					'entities'=>['Invoice'],
					'field_scope'=>'reuse_fields_from_original',
					'dependency_context'=>[],
				],
			]],
		];
		$continuing=$this->kernel->invoke('mcp_app_builder_scaffold_completion_summary',$planning);
		$legacyContinuation=$this->kernel->invoke('mcp_app_builder_next_continuation_summary',[
			'continuation_calls'=>[['chunk'=>3,'entities'=>['LegacyEntity']]],
		]);
		$missingContinuation=$this->kernel->invoke('mcp_app_builder_next_continuation_summary',[
			'continuation_calls'=>[['arguments'=>['entities'=>[]]]],
		]);
		$bundleFiles=[];
		for($index=1;$index<=5;$index++){
			$bundleFiles[]='applications/example/backend/dataphyre/Framework/Schema/Entity'.$index.'.php';
			$bundleFiles[]='applications/example/backend/dataphyre/panel/resources/entity-'.$index.'.php';
			$bundleFiles[]='applications/example/backend/dataphyre/unit_tests/entity-'.$index.'.test.php';
		}
		$fallbackFiles=array_map(static fn(int $index): string=>'applications/example/file-'.$index.'.php',range(1,13));
		$lane=$this->kernel->invoke('app_builder_lane','Build an orders admin application',[
			'entities'=>['Order'],
			'application_path'=>'applications/commerce',
			'app_namespace'=>'Commerce',
		]);
		$lane['data_model']=array_merge(['malformed-model'],is_array($lane['data_model'] ?? null) ? $lane['data_model'] : []);
		$start=$this->kernel->invoke('mcp_app_builder_start_summary',$lane);
		return [
			'readiness'=>[
				'provided'=>$provided,
				'ready'=>$ready,
				'blocked'=>$blocked,
			],
			'completion'=>[
				'empty'=>$this->kernel->invoke('mcp_app_builder_scaffold_completion_summary','not-planning'),
				'continuing'=>$continuing,
				'legacy_continuation'=>$legacyContinuation,
				'missing_continuation'=>$missingContinuation,
			],
			'first_read'=>$this->kernel->invoke('mcp_app_builder_first_read',[]),
			'empty_verification'=>$this->kernel->invoke('mcp_app_builder_compact_verification_handoff',[]),
			'file_previews'=>[
				'bundled'=>$this->kernel->invoke('mcp_app_builder_compact_file_preview',$bundleFiles),
				'fallback'=>$this->kernel->invoke('mcp_app_builder_compact_file_preview',$fallbackFiles),
			],
			'data_model'=>[
				'explicit'=>$this->kernel->invoke('mcp_app_builder_data_model_summary',['data_model_summary'=>'Orders use the app-owned repository contract.']),
				'fallback'=>$this->kernel->invoke('mcp_app_builder_data_model_summary',['data_model'=>['malformed-model']]),
				'start_model_count'=>count(is_array($start['data_model'] ?? null) ? $start['data_model'] : []),
			],
			'paths'=>[
				'found'=>$this->kernel->invoke('mcp_first_path_matching',['Framework/Schema/Order.php','Framework/Record/Order.php'],'/Record/'),
				'missing'=>$this->kernel->invoke('mcp_first_path_matching',['Framework/Schema/Order.php'],'/Repository/'),
			],
			'default_budget'=>$this->kernel->invoke('mcp_app_builder_payload_budget','  '),
		];
	}

	/** @return array<string,mixed> */
	private function discoveryCompactionContract(): array {
		$finder=$this->kernel->invoke('mcp_compact_finder_summary',[
			'finder_type'=>'tools',
			'matches'=>[
				'malformed-row',
				[
					'name'=>'dataphyre_orders_inspect',
					'id'=>'orders.inspect',
					'kind'=>'tool',
					'group'=>'inspection',
					'module'=>'orders',
					'fetch_tool'=>'dataphyre_tool_manifest',
					'audience_scope'=>'application',
					'title'=>str_repeat('Orders inspection ',16),
					'description'=>"Static\n\tbounded   inspection metadata",
					'path'=>'runtime/modules/orders',
					'match_reasons'=>['name','group','module','description','ignored'],
					'score'=>42,
				],
				['uri'=>'dataphyre://orders','description'=>'Orders resource'],
			],
		]);
		$policyLane=$this->kernel->invoke('mcp_app_builder_compact_lane',
			['entities'=>['Patient']],
			[
				'data_sensitivity_summary'=>['has_sensitive_signals'=>true,'categories'=>range(1,12)],
				'policy_decision_register'=>['status'=>'required','decisions'=>range(1,5)],
			],
			[],
			[],
			[],
			['status'=>'app_owned_policy_attention']
		);
		return [
			'finder'=>$finder,
			'policy_lane'=>$policyLane,
			'text'=>[
				'short_budget'=>$this->kernel->invoke('mcp_compact_text','  keep   spacing readable  ',3),
				'truncated'=>$this->kernel->invoke('mcp_compact_text','abcdefghijk',8),
			],
		];
	}

	/** @return array<string,mixed> */
	private function workflowSummaryContract(): array {
		$valid=[
			'workflow'=>'app_builder',
			'title'=>'Build an application',
			'score'=>91,
			'ready'=>true,
			'matched_terms'=>['build','application'],
			'recommended_tool'=>'dataphyre_mcp_workflow_handoff_pack_export',
			'recommended_arguments'=>['workflow'=>'app_builder'],
		];
		$recommendation=$this->kernel->invoke('mcp_workflow_recommendation_summary',[
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'contract-protocol',
			'recommendations'=>[$valid,'malformed-row'],
			'app_builder_entrypoint'=>'dataphyre_app_builder_plan_generate',
			'app_builder_next_action'=>['status'=>'ready_for_app_owned_writes','handoff_pages'=>range(1,6)],
		],'Build an app',true);
		$fallback=$this->kernel->invoke('mcp_workflow_handoff_summary',[
			'task'=>'Build an app',
			'selected_workflow'=>'app_builder',
			'selected_score'=>91,
			'include_frames'=>true,
			'handoff_pack'=>['ready_to_run'=>true],
			'recommendation'=>[
				'app_builder_entrypoint'=>'dataphyre_app_builder_plan_generate',
				'app_builder_next_action'=>['status'=>'fallback_action'],
				'recommendations'=>['malformed-row',$valid],
			],
		]);
		$primary=$this->kernel->invoke('mcp_workflow_handoff_summary',[
			'app_builder_next_action'=>['current_status'=>'primary_action'],
			'recommendation'=>['app_builder_next_action'=>['status'=>'ignored_fallback']],
		]);
		return [
			'recommendation'=>$recommendation,
			'fallback'=>$fallback,
			'primary'=>$primary,
			'scalar_action'=>$this->kernel->invoke('mcp_workflow_compact_app_builder_next_action','not_applicable'),
			'non_array_recommendations'=>$this->kernel->invoke('mcp_workflow_compact_recommendations','not-a-list'),
		];
	}

	/** @return array<string,mixed> */
	private function appBuilderBriefContract(): array {
		$brief=$this->kernel->invoke('mcp_agent_brief_export',[
			'task'=>'Build an orders admin CRUD application with filters actions and verification',
			'target'=>'unsupported-client',
			'limit'=>2,
			'application_path'=>'applications/commerce',
			'app_namespace'=>'Commerce',
		]);
		return [
			'export_type'=>$brief['export_type'] ?? null,
			'execution'=>$brief['execution'] ?? null,
			'target'=>$brief['target'] ?? null,
			'selected_workflow'=>$brief['selected_workflow'] ?? null,
			'first_read_title'=>$brief['builder_first_read']['title'] ?? null,
			'next_action'=>$brief['app_builder_next_action'] ?? [],
			'has_builder_view'=>array_key_exists('builder_view',$brief),
			'has_policy_attention'=>array_key_exists('policy_attention',$brief),
			'context_links'=>array_keys($brief['context_links'] ?? []),
		];
	}

	/** @return array<string,mixed> */
	private function elevatedBriefContract(): array {
		$brief=$this->kernel->invoke('mcp_agent_brief_export',[
			'task'=>'Audit framework internals security privacy compliance and release-facing governance',
			'target'=>'codex',
			'limit'=>2,
		]);
		$ordinary=$this->kernel->invoke('mcp_agent_brief_export',[
			'task'=>'Inspect routing module source APIs',
			'target'=>'cursor',
			'limit'=>1,
		]);
		return [
			'export_type'=>$brief['export_type'] ?? null,
			'execution'=>$brief['execution'] ?? null,
			'target'=>$brief['target'] ?? null,
			'inspection_active'=>$brief['inspection_view']['active'] ?? null,
			'app_builder_active'=>$brief['app_builder_lane']['active'] ?? null,
			'enterprise_audit'=>$brief['enterprise_audit'] ?? null,
			'next_actions'=>$brief['next_actions'] ?? [],
			'has_operating_contract'=>array_key_exists('application_agent_operating_contract',$brief),
			'has_context_sources'=>array_key_exists('source_documents',$brief['agent_context'] ?? []),
			'ordinary'=>[
				'target'=>$ordinary['target'] ?? null,
				'inspection_active'=>$ordinary['inspection_view']['active'] ?? null,
				'has_enterprise_audit'=>array_key_exists('enterprise_audit',$ordinary),
				'has_operating_contract'=>array_key_exists('application_agent_operating_contract',$ordinary),
				'has_context_sources'=>array_key_exists('source_documents',$ordinary['agent_context'] ?? []),
				'governance_notes'=>$ordinary['governance_notes'] ?? null,
			],
		];
	}

	/** @return array<string,mixed> */
	private function briefCompactionContract(): array {
		$summaryFields=[];
		foreach(['files_summary','schema_summary','panel_fields_summary','filters_summary','actions_summary','verification_evidence_summary'] as $field){
			$summaryFields[$field]=['count'=>2];
		}
		$firstRead=$summaryFields+[
			'naming_contract'=>[
				'owner'=>'application',
				'class_names'=>'StudlyCase',
				'paths_and_tables'=>'snake_case',
				'mappings'=>array_map(static fn(int $index): array=>[
					'entity'=>'Entity'.$index,
					'class_base'=>'Entity'.$index,
					'table'=>'entities_'.$index,
					'panel_resource'=>'Entity'.$index.'Resource',
					'panel_manifest'=>'entities-'.$index.'.php',
				],range(1,5)),
			],
			'write_readiness'=>['handoff_fields'=>range(1,6),'not_required'=>['raw detail']],
			'scaffold_completion_summary'=>[
				'owner'=>'application','complete'=>false,'status'=>'continuation_required','planned_count'=>2,'deferred_count'=>2,
				'planned_entities'=>['One','Two'],'deferred_entities'=>['Three','Four'],'next_action'=>'continue',
				'next_continuation'=>['available'=>true,'tool'=>'dataphyre_app_builder_plan_generate','chunk'=>2,'entities'=>['Three','Four'],'argument_source'=>'queue','repeat_until'=>'complete'],
				'continuation_queue'=>[['chunk'=>2],['chunk'=>3]],
			],
			'verification_handoff'=>[
				'owner'=>'application','status'=>'ready','tools'=>range(1,8),'copy_safe_fields'=>range(1,5),'done_when'=>'all focused checks pass',
			],
			'app_path_context'=>[
				'application_id'=>'commerce','application_path'=>'applications/commerce','dataphyre_root'=>'applications/commerce/dataphyre',
				'framework_path'=>'applications/commerce/dataphyre/Framework','panel_resource_namespace'=>'Commerce\\Panel',
				'placeholder_mode'=>false,'path_confidence'=>'explicit',
				'discovery_hint'=>['status'=>'known','next_tool'=>'none','then_supply'=>'path','accepted_forms'=>range(1,8)],
			],
			'open_details'=>['raw'=>'discarded'],
		];
		$compactFirstRead=$this->kernel->invoke('mcp_agent_brief_compact_first_read',$firstRead);
		$resumeCursor=[
			'phase'=>'implementation','read'=>'builder_first_read','next_tool'=>'dataphyre_app_builder_plan_generate','argument_source'=>'first_read',
			'blocker_id'=>'none','action_source'=>'next_action','then_read'=>'verification','first_batch'=>['one.php'],
			'copy_forward'=>range(1,6),'write_start_packet'=>'packet','write_source'=>'recipe','open_full_skeletons'=>'full','after_write'=>'verify',
		];
		$readyPacket=[
			'can_write_now'=>true,'status'=>'ready','first_batch'=>['one.php','two.php'],'write_queue'=>'implementation.items','evidence_to_collect'=>'verification.items',
			'first_probe'=>[
				'id'=>'probe.local-conventions','inspect_globs'=>range(1,5),'signals'=>range(1,6),'capture_fields'=>range(1,6),'apply_to'=>range(1,6),
			],
		];
		$readyAction=$this->kernel->invoke('mcp_agent_brief_compact_next_action',[
			'status'=>'ready','action'=>'write','next_tool'=>'tool','argument_source'=>'source','resume_cursor'=>$resumeCursor,'write_start_packet'=>$readyPacket,
		],true);
		$deferredAction=$this->kernel->invoke('mcp_agent_brief_compact_next_action',[
			'status'=>'blocked','resume_cursor'=>$resumeCursor,'write_start_packet'=>['can_write_now'=>false,'status'=>'blocked'],
		],true);
		$pointerAction=$this->kernel->invoke('mcp_agent_brief_compact_next_action',['status'=>'ready','resume_cursor'=>$resumeCursor],false);
		$countCursor=$this->kernel->invoke('mcp_agent_brief_compact_resume_cursor',['phase'=>'verification','copy_forward_count'=>'3']);
		$appPath=$this->kernel->invoke('mcp_agent_brief_compact_app_path_context',$firstRead['app_path_context']);
		$policyPayload=$this->kernel->invoke('mcp_agent_brief_compact_app_payload',
			'Build policy-aware app resources','generic','feature',true,[],[],[
				'status'=>'app_owned_policy_attention','mode'=>'review','categories'=>range(1,12),
			],true
		);
		return [
			'budget'=>$this->kernel->invoke('mcp_agent_brief_payload_budget'),
			'governance_notes'=>[
				'array'=>$this->kernel->invoke('mcp_agent_brief_governance_notes',['governance_notes'=>['status'=>'ready']]),
				'default'=>$this->kernel->invoke('mcp_agent_brief_governance_notes',[]),
				'compact_array'=>$this->kernel->invoke('mcp_agent_brief_compact_governance_notes',['status'=>'ready'],['security']),
				'compact_default'=>$this->kernel->invoke('mcp_agent_brief_compact_governance_notes','none triggered',['release']),
			],
			'first_read'=>$compactFirstRead,
			'ready_action'=>$readyAction,
			'deferred_action'=>$deferredAction,
			'pointer_action'=>$pointerAction,
			'count_cursor'=>$countCursor,
			'app_path'=>$appPath,
			'policy_payload'=>$policyPayload,
		];
	}

	/** @param array<int,mixed> $tokens */
	private function tokenIndex(array $tokens,string $name): int {
		foreach($tokens as $index=>$token){
			if(is_array($token) && $token[0]===T_STRING && strtolower($token[1])===strtolower($name)){return $index;}
		}
		throw new \RuntimeException('Token not found: '.$name);
	}

	private function workspace(string $prefix): TempWorkspace {
		return $this->context->workspaceIn(\Dataphyre\Test\dataphyre_path('cache'),$prefix);
	}

	private function exceptionMessage(callable $operation): string {
		try{$operation();}
		catch(\Throwable $error){return $error->getMessage();}
		return '';
	}
}
