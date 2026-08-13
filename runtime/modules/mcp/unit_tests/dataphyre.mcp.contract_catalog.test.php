<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpContractFixture;
use Dataphyre\Mcp\Testing\McpContractProbe;
use Dataphyre\Mcp\Testing\McpContractParserProbe;
use Dataphyre\Mcp\Testing\McpKernelHarness;
use Dataphyre\Mcp\Contracts\SourceContractIndex;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';
require_once dirname(__DIR__).'/testing/McpContractTestKit.php';

suite('MCP source-derived Dataphyre contract catalog')
	->tag('mcp','contracts','testkit','source-index','coverage')
	->group('framework-coverage')
	->contract('mcp.contracts.source-catalog',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp','type:Dataphyre\\Mcp\\Contracts\\ContractSource')
	->through('PHP token index','declarative JSON index','normalized catalog','test manifest compatibility tools')
	->isolation('process')
	->maxMillis(180000);

test('static discovery recognizes every contract family without loading source',static function(Context $t): void {
	$fixture=new McpContractFixture($t);
	$catalog=$fixture->catalog()->catalog(['limit'=>200]);
	$ids=McpContractProbe::ids($catalog);

	foreach([
		'php:Fixture\\Ledger\\Contracts\\LedgerStore',
		'php:Fixture\\Ledger\\LedgerStoreDecorator',
		'serialized:ledger_manifest',
		'serialized:ledger_checkpoint',
		'serialized:ledger_event',
		'test:ledger.store.behavior@2',
		'test:ledger.store.behavior@3',
		'test:ledger.store.dynamic@ContractVersions::BEHAVIOR',
		'legacy:'.McpContractFixture::JSON_TEST,
		'legacy:'.McpContractFixture::INVALID_JSON_TEST,
	] as $id){$t->contains($id,$ids,$id);}
	$t->notContains('test:ledger.fixture-only@99',$ids,'fluent metadata inside a test body is not a registered TestKit declaration');

	$t->same(['legacy_test_manifest'=>2,'php_type_contract'=>2,'serialized_contract'=>3,'test_contract'=>3],$catalog['kind_summary']);
	$t->same('not_executed',$catalog['execution']);
	$t->same('php_tokens_and_declarative_json',$catalog['source_strategy']);
	$t->same([], $catalog['diagnostics']['parse_failures']);
	$t->greaterThan(0,$catalog['diagnostics']['skipped_php_files']);
	$t->isFalse($fixture->markerExists(),'contract source files must never execute');

	$dynamic=(new McpContractProbe($fixture->catalog()))->record('test:ledger.store.dynamic@ContractVersions::BEHAVIOR');
	$t->isFalse($dynamic['version_resolved']);
	$t->same('ContractVersions::BEHAVIOR',$dynamic['version_expression']);
	$t->same('high',$dynamic['metadata_variants'][0]['risk']);
});

test('type descriptors separate production implementations from test doubles',static function(Context $t): void {
	$fixture=new McpContractFixture($t);
	$contract=(new McpContractProbe($fixture->catalog(['ledger'])))->record('php:Fixture\\Ledger\\Contracts\\LedgerStore');

	$t->same('interface',$contract['symbol_kind']);
	$t->same('Persists and replays an ordered tenant ledger.',$contract['description']);
	$t->same(3,$contract['method_count']);
	$t->same(['append','count','read'],array_column($contract['methods'],'name'));
	$t->same(2,$contract['implementation_count']);
	$t->same(1,$contract['test_implementation_count']);
	$t->same([
		'Fixture\\Ledger\\LedgerStoreDecorator',
		'Fixture\\Ledger\\PdoLedgerStore',
		'Fixture\\Ledger\\Tests\\LedgerStoreDouble',
	],McpContractProbe::implementationNames($contract));
	$t->same(['production','production','test_support'],array_column($contract['implemented_by'],'source_scope'));
	$t->same([
		'test:ledger.store.behavior@2',
		'test:ledger.store.dynamic@ContractVersions::BEHAVIOR',
	],array_column($contract['executable_evidence'],'id'));
	$t->isFalse($fixture->markerExists());
});

test('queries stay partial deterministic paginated and version-aware',static function(Context $t): void {
	$fixture=new McpContractFixture($t);
	$catalog=$fixture->catalog(['ledger']);
	$page=$catalog->catalog(['modules'=>['ledger'],'kinds'=>['test_contract'],'query'=>'ledger.store','limit'=>2]);

	$t->same(['ledger'],$page['diagnostics']['scope_modules']);
	$t->same(3,$page['counts']['matched']);
	$t->same(2,$page['counts']['returned']);
	$t->isTrue($page['pagination']['has_more']);
	$t->same(2,$page['pagination']['next_offset']);
	$t->same(1,$page['version_health']['unresolved_test_contract_count']);
	$t->same(1,$page['version_health']['conflict_count']);
	$t->same(['2','3'],$page['version_health']['conflicts'][0]['versions']);
	$t->same($page['inventory_fingerprint'],$catalog->catalog(['limit'=>1])['inventory_fingerprint']);
	$parser=new McpContractParserProbe($t,$fixture->source(['ledger']));
	$t->same('conventional',$parser->conventionalSerializedConfidence());
	$t->same(['value'=>null,'resolved'=>false,'expression'=>'$dynamic'],$parser->unresolvedLiteral());
	$t->isTrue($parser->nearbyValueIsMissing());
	$t->same('Fixture\\Ledger\\LedgerEvent',$parser->relativeNameUsesNamespace());

	$ambiguous=$catalog->describe('ledger.store.behavior');
	$t->same('ambiguous',$ambiguous['status']);
	$t->count(2,$ambiguous['candidates']);
	$t->throwsLike(fn()=> $catalog->describe('missing.contract'),OutOfBoundsException::class,'was not found');
	$t->throwsLike(fn()=> $catalog->catalog(['kinds'=>['unknown']]),InvalidArgumentException::class,'Unknown contract kinds');
	$t->throwsLike(fn()=> $catalog->testFile('runtime/modules/ledger/unit_tests/missing.test.php'),OutOfBoundsException::class,'was not found');
	$t->throwsLike(fn()=> new SourceContractIndex($fixture->repositoryRoot()),InvalidArgumentException::class,'must contain runtime/modules');

	$missing=$fixture->source(['missing'])->snapshot();
	$t->same(['missing'],$missing['diagnostics']['missing_modules']);
	$t->isTrue($fixture->source([],1)->snapshot()['diagnostics']['truncated']);
	$t->isFalse($fixture->markerExists());
});

test('code and JSON test tools preserve one safe self-describing inventory',static function(Context $t): void {
	$fixture=new McpContractFixture($t);
	$kernel=(new McpKernelHarness($t))->useRepositoryRoot($fixture->repositoryRoot());

	$code=$kernel->invoke('list_unit_test_manifests',[
		'modules'=>['ledger'],'kind'=>'code','contract'=>'test:ledger.store.behavior@2','limit'=>10,
	]);
	$t->same(1,$code['matched_manifest_count']);
	$t->same($fixture->repoPath(McpContractFixture::CODE_TEST),$code['manifests'][0]['path']);
	$t->same('code',$code['manifests'][0]['kind']);
	$t->contains('--owner=ledger',$code['manifests'][0]['runtime_metadata_command']);
	$json=$kernel->invoke('list_unit_test_manifests',['modules'=>['ledger'],'kind'=>'json','limit'=>10]);
	$t->same(1,$json['matched_manifest_count']);
	$t->same('json',$json['manifests'][0]['kind']);
	$t->same([],$json['manifests'][0]['helper_files']);
	$t->isFalse($json['manifests'][0]['has_custom_script']);

	$codeSummary=$kernel->invoke('read_unit_test_manifest',[
		'path'=>$fixture->repoPath(McpContractFixture::CODE_TEST),'max_cases'=>1,
	]);
	$t->same('not_executed',$codeSummary['execution']);
	$t->same(1,$codeSummary['returned_cases']);
	$t->same(1,$codeSummary['declared_case_count']);
	$t->same('reports malformed records',$codeSummary['cases'][0]['name']);
	$t->count(2,$codeSummary['contracts']);

	$jsonSummary=$kernel->invoke('read_unit_test_manifest',[
		'path'=>$fixture->repoPath(McpContractFixture::JSON_TEST),'include_expected'=>true,
	]);
	$t->same('ledger',$jsonSummary['module']);
	$t->same('fixture_ledger_smoke',$jsonSummary['cases'][0]['function']);
	$t->same(['ok'=>true],$jsonSummary['cases'][0]['expected']);
	$t->throwsLike(
		fn()=> $kernel->invoke('read_unit_test_manifest',['path'=>'dataphyre/runtime/modules/ledger/Framework/Noise.php']),
		InvalidArgumentException::class,
		'unit_tests/*.test.php or unit_tests/*.json'
	);
	$t->isFalse($fixture->markerExists());
});

test('public contract tools expose stable records and explicit safety boundaries',static function(Context $t): void {
	$fixture=new McpContractFixture($t);
	$kernel=(new McpKernelHarness($t))->useRepositoryRoot($fixture->repositoryRoot());
	$resource=$kernel->invoke('read_resource',['uri'=>'dataphyre://contracts']);
	$t->same('application/json',$resource['contents'][0]['mimeType']);
	$t->same('dataphyre_contract_index',json_decode($resource['contents'][0]['text'],true,512,JSON_THROW_ON_ERROR)['resource_type']);

	$catalog=$kernel->invoke('contract_catalog',[
		'modules'=>['ledger'],'kinds'=>['php_type_contract'],'query'=>'LedgerStore','limit'=>10,
	]);
	$t->same('read_only_source_contract_metadata',$catalog['contract_safety']['classification']);
	$t->isFalse($catalog['contract_safety']['source_required']);
	$t->isFalse($catalog['contract_safety']['reflection_used']);
	$t->isFalse($catalog['contract_safety']['eval_used']);
	$t->contains('test discovery execution',$catalog['contract_safety']['not_performed']);
	$t->throwsLike(
		fn()=> $kernel->invoke('contract_catalog',[]),
		InvalidArgumentException::class,
		'modules must name at least one'
	);
	$t->throwsLike(
		fn()=> $kernel->invoke('list_unit_test_manifests',[]),
		InvalidArgumentException::class,
		'modules must name at least one'
	);
	$t->throwsLike(
		fn()=> $kernel->invoke('contract_describe',['id'=>'unqualified-contract-name']),
		InvalidArgumentException::class,
		'modules must provide an owning runtime module'
	);

	$descriptor=$kernel->invoke('contract_describe',[
		'id'=>'php:Fixture\\Ledger\\Contracts\\LedgerStore','modules'=>['ledger'],
	]);
	$t->same('found',$descriptor['status']);
	$t->same('Fixture\\Ledger\\Contracts\\LedgerStore',$descriptor['contract']['name']);
	$t->same('not_executed',$descriptor['execution']);
	$t->same('read_only_source_contract_metadata',$descriptor['contract_safety']['classification']);
	$calls=[
		'dataphyre_unit_tests_list'=>['modules'=>['ledger'],'kind'=>'code','limit'=>5],
		'dataphyre_unit_test_manifest_read'=>['path'=>$fixture->repoPath(McpContractFixture::CODE_TEST),'max_cases'=>2],
		'dataphyre_contract_catalog'=>['modules'=>['ledger'],'kinds'=>['php_type_contract'],'limit'=>5],
		'dataphyre_contract_describe'=>['id'=>'php:Fixture\\Ledger\\Contracts\\LedgerStore','modules'=>['ledger']],
	];
	foreach($calls as $tool=>$arguments){
		$response=$kernel->invoke('call_tool',['name'=>$tool,'arguments'=>$arguments]);
		$t->same('text',$response['content'][0]['type'],$tool);
		$t->isTrue(is_array(json_decode($response['content'][0]['text'],true)),$tool);
	}
	$t->isFalse($fixture->markerExists());
});
