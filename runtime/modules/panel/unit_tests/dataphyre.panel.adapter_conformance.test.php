<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAdapterConformanceCase;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceContext;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelAdapterConformanceResult;
use Dataphyre\Panel\PanelAdapterConformanceSuite;
use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelAtomicAgentWorkflowStore;
use Dataphyre\Panel\PanelAtomicLeasedOperationStore;
use Dataphyre\Panel\PanelAtomicMigrationStore;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelDataSource;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelFilesystemCommandFabricStore;
use Dataphyre\Panel\PanelLocalMediaDisk;
use Dataphyre\Panel\PanelInMemoryCommandFabricStore;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelInMemoryTelemetryExporter;
use Dataphyre\Panel\InMemoryPanelAgentWorkflowStore;
use Dataphyre\Panel\PanelMemoryIamStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel adapter conformance contracts')
	->contract('panel.adapter-conformance', 1)
	->layer('contract')
	->risk('critical')
	->watches('module:panel', 'boundary:production-adapters')
	->through('conformance-catalog', 'capability-negotiation', 'probe-execution', 'sanitized-report')
	->tag('panel', 'platform', 'adapter-conformance')
	->group('panel-platform-contract');

test('reference data media and operation adapters satisfy production conformance packs', static function(Context $t): void {
	$runner=new PanelAdapterConformanceRunner();
	$data=new PanelArrayDataSource([
		['id'=>'one','name'=>'First','tenant_id'=>'north'],
		['id'=>'two','name'=>'Second','tenant_id'=>'north'],
	],['search_fields'=>['name']]);
	$dataReport=$runner->run(PanelAdapterConformanceCatalog::dataSource(),$data,[
		'query'=>PanelDataQuery::make()->tenant('north')->search('First')->limit(5),
		'known_id'=>'one','missing_id'=>'absent',
	]);
	$t->isTrue($dataReport->passed());
	$t->same(['total'=>3,'passed'=>3,'failed'=>0,'skipped'=>0,'assertions'=>8],array_diff_key($dataReport->summary(),['duration_ms'=>true]));

	$media=new PanelLocalMediaDisk($t->tempDirectory('panel-adapter-media'),'conformance');
	$mediaReport=$runner->run(PanelAdapterConformanceCatalog::mediaDisk(),$media,['allow_destructive'=>true,'namespace'=>'owned_probe']);
	$t->isTrue($mediaReport->passed());
	$t->same(2,$mediaReport->summary()['passed']);
	$t->same([], $media->list('owned_probe'));

	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-adapter-operations'));
	$storeReport=$runner->run(PanelAdapterConformanceCatalog::operationStore(),$store,['allow_destructive'=>true]);
	$t->isTrue($storeReport->passed());
	$t->same(1,$storeReport->summary()['passed']);
	$t->same([], $store->all());

	$leasedStore=new PanelAtomicLeasedOperationStore($t->tempDirectory('panel-adapter-leased'));
	$leasedReport=$runner->run(PanelAdapterConformanceCatalog::leasedOperationStore(),$leasedStore,['allow_destructive'=>true]);
	$t->isTrue($leasedReport->passed());
	$t->same(1,$leasedReport->summary()['passed']);
	$t->same([], $leasedStore->all());

	$migrations=new PanelAtomicMigrationStore($t->tempDirectory('panel-adapter-migrations'));
	$migrationReport=$runner->run(PanelAdapterConformanceCatalog::migrationStore(),$migrations,['allow_destructive'=>true,'scope'=>'adapter_migration_probe']);
	$t->isTrue($migrationReport->passed());
	$t->same(1,$migrationReport->summary()['passed']);

	$memoryFabric=$runner->run(PanelAdapterConformanceCatalog::commandFabricStore(),new PanelInMemoryCommandFabricStore(),['allow_destructive'=>true]);
	$filesystemFabric=$runner->run(PanelAdapterConformanceCatalog::commandFabricStore(),new PanelFilesystemCommandFabricStore($t->tempDirectory('panel-adapter-command-fabric')),['allow_destructive'=>true]);
	$t->isTrue($memoryFabric->passed());$t->isTrue($filesystemFabric->passed());$t->same(1,$memoryFabric->summary()['passed']);$t->same(1,$filesystemFabric->summary()['passed']);

	$telemetry=new PanelInMemoryTelemetryExporter(4);
	$telemetryReport=$runner->run(PanelAdapterConformanceCatalog::telemetryExporter(),$telemetry);
	$t->isTrue($telemetryReport->passed());
	$t->same(1,$telemetryReport->summary()['passed']);
	$t->same(1,count($telemetry->signals()));
	$t->same(1,$telemetry->manifest()['flushes']);
	json_encode([$dataReport,$mediaReport,$storeReport,$leasedReport,$migrationReport,$memoryFabric,$filesystemFabric,$telemetryReport],JSON_THROW_ON_ERROR);
})->tag('panel','adapter','conformance','production')->maxMillis(10000);

test('reference IAM and Studio stores satisfy their tenant-scoped production conformance packs', static function(Context $t): void {
	$runner=new PanelAdapterConformanceRunner();

	$iam=$runner->run(PanelAdapterConformanceCatalog::iamStore(),new PanelMemoryIamStore(),['allow_destructive'=>true]);
	$t->isTrue($iam->passed());
	$t->same(['total'=>2,'passed'=>2,'failed'=>0,'skipped'=>0],array_intersect_key($iam->summary(),array_flip(['total','passed','failed','skipped'])));

	$studio=$runner->run(PanelAdapterConformanceCatalog::studioStore(),new PanelInMemoryStudioStore(),['allow_destructive'=>true]);
	$t->isTrue($studio->passed());
	$t->same(['total'=>1,'passed'=>1,'failed'=>0,'skipped'=>0],array_intersect_key($studio->summary(),array_flip(['total','passed','failed','skipped'])));
})->tag('panel','adapter','conformance','iam','studio','tenant-isolation')->maxMillis(15000);

test('reference agent workflow stores satisfy fenced idempotent production conformance', static function(Context $t): void {
	$runner=new PanelAdapterConformanceRunner();
	$memory=$runner->run(PanelAdapterConformanceCatalog::agentWorkflowStore(),new InMemoryPanelAgentWorkflowStore(),['allow_destructive'=>true]);
	$t->isTrue($memory->passed());
	$t->same(['total'=>1,'passed'=>1,'failed'=>0,'skipped'=>0],array_intersect_key($memory->summary(),array_flip(['total','passed','failed','skipped'])));

	$atomic=$runner->run(PanelAdapterConformanceCatalog::agentWorkflowStore(),new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-adapter-agent-workflows')),['allow_destructive'=>true]);
	$t->isTrue($atomic->passed());
	$t->same(['total'=>1,'passed'=>1,'failed'=>0,'skipped'=>0],array_intersect_key($atomic->summary(),array_flip(['total','passed','failed','skipped'])));
	$t->notContains('must-not-survive',json_encode([$memory,$atomic],JSON_THROW_ON_ERROR));
})->tag('panel','adapter','conformance','agents','leases','idempotency')->maxMillis(15000);

test('destructive probes require explicit authority and skipped policy is visible', static function(Context $t): void {
	$report=(new PanelAdapterConformanceRunner())->run(
		PanelAdapterConformanceCatalog::mediaDisk(),
		new PanelLocalMediaDisk($t->tempDirectory('panel-adapter-skip'))
	);
	$t->same(['total'=>2,'passed'=>1,'failed'=>0,'skipped'=>1,'assertions'=>3],array_diff_key($report->summary(),['duration_ms'=>true]));
	$t->isFalse($report->passed());
	$t->isTrue($report->passed(true));
	$t->same('skipped',$report->results()[1]->status());
})->tag('panel','adapter','conformance','authority')->maxMillis(3000);

test('capability negotiation distinguishes required failures from optional skips', static function(Context $t): void {
	$adapter=new class implements PanelDataSource {
		public function query(PanelDataQuery $query): PanelDataResult { return PanelDataResult::normalize([],$query,'empty'); }
		public function find(string|int $id,?PanelDataQuery $scope=null): mixed { return null; }
		public function capabilities(): array { return []; }
	};
	$probe=static function(PanelDataSource $adapter,PanelAdapterConformanceContext $context):void { $context->truthy(true); };
	$suite=PanelAdapterConformanceSuite::make('negotiation',PanelDataSource::class)
		->add(PanelAdapterConformanceCase::make('required',$probe,capabilities:['transactions']))
		->add(PanelAdapterConformanceCase::make('optional',$probe,capabilities:['transactions'],optional:true));
	$report=(new PanelAdapterConformanceRunner())->run($suite,$adapter);
	$t->same(1,$report->summary()['failed']);
	$t->same(1,$report->summary()['skipped']);
	$t->same('capability_missing',$report->results()[0]->issues()[0]['code']);
	$t->contains('Missing: transactions',json_encode($report,JSON_THROW_ON_ERROR));
})->tag('panel','adapter','conformance','capabilities')->maxMillis(1000);

test('probe failures and evidence are bounded sanitized and machine readable', static function(Context $t): void {
	$adapter=new PanelArrayDataSource([]);
	$suite=PanelAdapterConformanceSuite::make('failure_probe',PanelDataSource::class,3,['api_token'=>'top-secret'])
		->add(PanelAdapterConformanceCase::make('assertions',static function(PanelDataSource $adapter,PanelAdapterConformanceContext $context):void{
			$context->same('expected','actual','mismatch','Deliberate mismatch.');
			$context->throws(static fn()=>throw new LogicException('expected'),LogicException::class);
			$context->evidenceValue('authorization','Bearer secret-token');
			throw new RuntimeException('password=super-secret');
		},tags:['security','failure'],maxMillis:5000));
	$report=(new PanelAdapterConformanceRunner())->run($suite,$adapter,['meta'=>['cookie'=>'session-secret']]);
	$result=$report->results()[0];
	$t->same('failed',$result->status());
	$t->same(2,$result->assertions());
	$t->same(2,count($result->issues()));
	$encoded=json_encode($report,JSON_THROW_ON_ERROR);
	$t->notContains('top-secret',$encoded);
	$t->notContains('secret-token',$encoded);
	$t->notContains('super-secret',$encoded);
	$t->notContains('session-secret',$encoded);
	$t->contains('[REDACTED]',$encoded);
})->tag('panel','adapter','conformance','redaction')->maxMillis(1000);

test('conformance definitions reject invalid contracts duplicate ids and wrong adapters', static function(Context $t): void {
	$t->throws(static fn()=>PanelAdapterConformanceSuite::make('',PanelDataSource::class),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterConformanceSuite::make('broken','Missing\\Adapter\\Contract'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAdapterConformanceCase::make('***',static fn()=>null),InvalidArgumentException::class);
	$case=PanelAdapterConformanceCase::make('same',static fn()=>null);
	$suite=PanelAdapterConformanceSuite::make('duplicates',PanelDataSource::class)->add($case);
	$t->throws(static fn()=>$suite->add($case),LogicException::class);
	$t->throws(static fn()=>(new PanelAdapterConformanceRunner())->run($suite,new stdClass()),InvalidArgumentException::class);
	$t->same('same',$case->id());
	$t->same('same',$case->label());
	$t->same(5000,$case->maxMillis());
	$t->same([], $case->tags());
	$t->isFalse($case->destructive());
	$t->isFalse($case->optional());
	$t->same('duplicates',$suite->name());
	$t->same(PanelDataSource::class,$suite->contract());
	$t->same(1,$suite->version());
	$t->same([], $suite->meta());
})->tag('panel','adapter','conformance','validation')->maxMillis(1000);

test('case context and result APIs normalize bound and redact untrusted adapter material', static function(Context $t): void {
	$context=new PanelAdapterConformanceContext(['explicit_null'=>null],['query'=>['ast'=>true]]);
	$t->same(null,$context->option('explicit_null','fallback'));
	$t->same('fallback',$context->option('missing','fallback'));
	$t->same(['query'=>['ast'=>true]],$context->capabilities());
	$context->skip('');
	$t->isTrue($context->skipped());
	$t->same('Probe skipped.',$context->skipReason());
	$context->throws(static fn()=>null,LogicException::class,'exception_missing');
	$context->throws(static fn()=>throw new RuntimeException('wrong'),LogicException::class);
	$t->same(2,$context->assertions());
	$t->same(2,count($context->issues()));
	$t->same('exception_missing',$context->issues()[0]['code']);
	$t->same('exception_type',$context->issues()[1]['code']);
	$t->throws(static fn()=>$context->throws(static fn()=>null,[]),InvalidArgumentException::class);
	$t->throws(static fn()=>$context->throws(static fn()=>null,stdClass::class),InvalidArgumentException::class);
	$t->throws(static fn()=>$context->evidenceValue('***','value'),InvalidArgumentException::class);
	for($i=0;$i<50;$i++){ $context->evidenceValue('key_'.$i,$i); }
	$context->evidenceValue('key_0','replaced');
	$t->same('replaced',$context->evidence()['key_0']);
	$t->throws(static fn()=>$context->evidenceValue('overflow','value'),LengthException::class);
	for($i=0;$i<110;$i++){ $context->check(false,'issue_'.$i,'Failure '.$i); }
	$t->same(100,count($context->issues()));
	$context->exception(new RuntimeException('api_token=secret-value'));
	$t->same(100,count($context->issues()));

	$case=PanelAdapterConformanceCase::make(' API.V2-Probe ',static fn(object $adapter,PanelAdapterConformanceContext $context)=>$context->evidenceValue('ran',true),'Custom label',['z.cap','a-cap','z.cap'],['security','API.V2'],true,true,0);
	$t->same('api_v2_probe',$case->id());
	$t->same('Custom label',$case->label());
	$t->same(['a_cap','z_cap'],$case->capabilities());
	$t->same(['api_v2','security'],$case->tags());
	$t->isTrue($case->destructive());
	$t->isTrue($case->optional());
	$t->same(1,$case->maxMillis());
	$runContext=new PanelAdapterConformanceContext();
	$case->run(new stdClass(),$runContext);
	$t->isTrue($runContext->evidence()['ran']);

	$result=new PanelAdapterConformanceResult($case,'passed',2,1.23456,[],['authorization'=>'Bearer result-secret'],'cookie=session-secret');
	$t->same('api_v2_probe',$result->id());
	$t->isTrue($result->passed());
	$t->same(2,$result->assertions());
	$t->same(1.23456,$result->durationMs());
	$t->same([], $result->issues());
	$t->same('Bearer result-secret',$result->evidence()['authorization']);
	$t->same('cookie=session-secret',$result->reason());
	$encoded=json_encode($result,JSON_THROW_ON_ERROR);
	$t->notContains('result-secret',$encoded);
	$t->notContains('session-secret',$encoded);
	$t->contains('[REDACTED]',$encoded);
	$t->throws(static fn()=>new PanelAdapterConformanceResult($case,'unknown',0,0),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAdapterConformanceResult($case,'passed',-1,0),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAdapterConformanceResult($case,'passed',0,INF),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAdapterConformanceResult($case,'failed',0,0,['named'=>[]]),InvalidArgumentException::class);
})->tag('panel','adapter','conformance','bounds','security')->maxMillis(2000);

test('runner negotiates nested flat manifest and absent capabilities and preserves failure precedence', static function(Context $t): void {
	$adapter=new class implements PanelDataSource {
		public function query(PanelDataQuery $query): PanelDataResult { return PanelDataResult::normalize([],$query,'capable'); }
		public function find(string|int $id,?PanelDataQuery $scope=null): mixed { return null; }
		public function capabilities(): array { return ['query'=>['ast'=>1],'scalar_string'=>'yes','scalar_array'=>['enabled'],'partial'=>[]]; }
	};
	$probe=static fn(PanelDataSource $adapter,PanelAdapterConformanceContext $context)=>$context->truthy(true);
	$suite=PanelAdapterConformanceSuite::make('nested_caps',PanelDataSource::class,0,['channel'=>'release'])
		->add(PanelAdapterConformanceCase::make('nested',$probe,capabilities:['query_ast']))
		->add(PanelAdapterConformanceCase::make('string',$probe,capabilities:['scalar_string']))
		->add(PanelAdapterConformanceCase::make('array',$probe,capabilities:['scalar_array']))
		->add(PanelAdapterConformanceCase::make('partial_missing',$probe,capabilities:['partial_child'],optional:true))
		->add(PanelAdapterConformanceCase::make('failed_skip',static function(PanelDataSource $adapter,PanelAdapterConformanceContext $context):void{ $context->same(1,2); $context->skip('cannot hide failure'); }));
	$report=(new PanelAdapterConformanceRunner())->run($suite,$adapter);
	$t->same(1,$suite->version());
	$t->same(['channel'=>'release'],$suite->meta());
	$t->same(['total'=>5,'passed'=>3,'failed'=>1,'skipped'=>1,'assertions'=>4],array_diff_key($report->summary(),['duration_ms'=>true]));
	$t->same('failed',$report->results()[4]->status());
	$t->isFalse($report->results()[4]->passed());
	$t->same('cannot hide failure',$report->results()[4]->reason());

	$manifestAdapter=new class implements JsonSerializable {
		public function jsonSerialize(): mixed { return []; }
		public function manifest(): array { return ['capabilities'=>['streaming'=>true]]; }
	};
	$manifestSuite=PanelAdapterConformanceSuite::make('manifest_caps',JsonSerializable::class)->add(PanelAdapterConformanceCase::make('stream',static fn(object $adapter,PanelAdapterConformanceContext $context)=>$context->truthy(true),capabilities:['streaming']));
	$t->isTrue((new PanelAdapterConformanceRunner())->run($manifestSuite,$manifestAdapter)->passed());
	$plain=new class implements JsonSerializable { public function jsonSerialize(): mixed { return []; } };
	$t->same('failed',(new PanelAdapterConformanceRunner())->run($manifestSuite,$plain)->results()[0]->status());
	$t->isTrue((new PanelAdapterConformanceRunner())->run($manifestSuite,$plain,['capabilities'=>['streaming'=>true]])->passed());
})->tag('panel','adapter','conformance','negotiation')->maxMillis(2000);

test('runner records duration budget violations without aborting later probes', static function(Context $t): void {
	$adapter=new PanelArrayDataSource([]);
	$suite=PanelAdapterConformanceSuite::make('duration',PanelDataSource::class)
		->add(PanelAdapterConformanceCase::make('slow',static function(PanelDataSource $adapter,PanelAdapterConformanceContext $context):void{ usleep(20000); },maxMillis:1))
		->add(PanelAdapterConformanceCase::make('after',static fn(PanelDataSource $adapter,PanelAdapterConformanceContext $context)=>$context->truthy(true)));
	$report=(new PanelAdapterConformanceRunner())->run($suite,$adapter);
	$t->same('failed',$report->results()[0]->status());
	$t->same('duration_exceeded',$report->results()[0]->issues()[0]['code']);
	$t->same('passed',$report->results()[1]->status());
})->tag('panel','adapter','conformance','duration')->maxMillis(1000);
