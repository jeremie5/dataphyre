<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelInclusiveQualityMatrix;
use Dataphyre\Panel\PanelQualityCapabilityReport;
use Dataphyre\Panel\PanelQualityEvidence;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_evidence_profile():array { return ['id'=>'en','locale'=>'en-CA','script'=>'Latn','direction'=>'ltr','timezone'=>'America/Toronto','numbering_system'=>'latn','calendar'=>'gregory','plural_categories'=>['one','other'],'long_text_factor'=>1.0,'pseudo_locale'=>false,'representative'=>true]; }

/** @return array<string,mixed> */
function dp_panel_evidence_contract(string $id,string $execution='browser',array $replace=[]):array {
	$automation=match($execution){'browser','php'=>'fully_automated','adapter'=>'adapter',default=>'manual'};
	return array_replace(['id'=>$id,'label'=>$id,'domain'=>$execution==='browser'?'input':'assistive_technology','execution'=>$execution,'automation'=>$automation,'locale_scope'=>'all','locales'=>[],'required_capabilities'=>[$execution.'.ready'],'proves'=>['bounded behavior'],'does_not_prove'=>[],'settings'=>[],'max_millis'=>100],$replace);
}

/** @return array<string,mixed> */
function dp_panel_evidence_row(string $case,string $execution='browser',array $replace=[]):array {
	return array_replace(['case_id'=>$case,'status'=>'passed','execution'=>$execution,'executor'=>'test-runner','artifact'=>'artifacts/report.json','assertions'=>2,'duration_ms'=>10.25,'capabilities'=>[$execution.'.ready'],'notes'=>'Verified','observed_at'=>'2026-07-14T04:05:06Z'],$replace);
}

function dp_panel_evidence_case(PanelInclusiveQualityMatrix $matrix,string $contract):string { foreach($matrix->cases() as $case){ if(($case['contract']['id'] ?? null)===$contract){ return (string)$case['id']; } } throw new RuntimeException('Missing test case.'); }

/** @param array<string,mixed> $row @return array<string,mixed> */
function dp_panel_bound_evidence(PanelInclusiveQualityMatrix $matrix,array $row):array { $row['matrix_digest']=$matrix->digest(); return $row; }

test('capability reports detect runtime facts and require sourced structured runner claims',static function(Context $t):void {
	$report=PanelQualityCapabilityReport::detect([
		'browser.dom'=>['status'=>'available','execution'=>'browser','source'=>'puppeteer-cdp','version'=>'Chrome 140'],
		'adapter.nvda'=>['status'=>'declared','execution'=>'adapter','source'=>'manual-adapter-catalog'],
		'browser.touch'=>['status'=>'unavailable','execution'=>'browser','source'=>null],
	]);
	$t->isTrue($report->supports('browser.dom'));
	$t->isTrue($report->supports('browser.dom','browser'));
	$t->isFalse($report->supports('browser.dom','adapter'));
	$t->isFalse($report->supports('adapter.nvda','adapter'));
	$t->isFalse($report->supports('missing'));
	$t->same('Chrome 140',$report->capability('browser.dom')['version']);
	$t->same(null,$report->capability('missing'));
	$t->isTrue($report->supports('php.json','php'));
	$t->same(64,strlen($report->fingerprint()));
	$payload=$report->jsonSerialize();
	$t->same('panel_quality_capability_report',$payload['type']);
	$t->same($report->fingerprint(),PanelQualityCapabilityReport::fromArray($payload)->fingerprint());
	$t->same(6,count(PanelQualityCapabilityReport::fromArray([])->capabilities()));
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['!'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['browser.dom'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['browser.dom'=>['status'=>'maybe','execution'=>'browser','source'=>'runner']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['browser.dom'=>['status'=>'available','execution'=>'robot','source'=>'runner']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['browser.dom'=>['status'=>'available','execution'=>'browser']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['browser.dom'=>['status'=>'available','execution'=>'browser','source'=>new stdClass()]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['browser.dom'=>['status'=>'available','execution'=>'browser','source'=>"bad\nsource"]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect(['browser.dom'=>['status'=>'available','execution'=>'browser','source'=>str_repeat('x',513)]]),InvalidArgumentException::class);
	$wide=[]; for($index=0;$index<123;$index++){ $wide['browser.cap'.$index]=['status'=>'unavailable','execution'=>'browser']; }
	$t->throws(static fn()=>PanelQualityCapabilityReport::detect($wide),LengthException::class);
})->tag('panel','quality','capabilities')->maxMillis(1000);

test('evidence records are deterministic bounded and artifact backed',static function(Context $t):void {
	$payload=dp_panel_evidence_row('suite.en.keyboard');
	$evidence=PanelQualityEvidence::fromArray($payload);
	$t->same('suite.en.keyboard',$evidence->caseId());
	$t->same('passed',$evidence->status());
	$t->same('browser',$evidence->execution());
	$t->same(2,$evidence->assertions());
	$t->same(10.25,$evidence->durationMs());
	$t->same(null,$evidence->matrixDigest());
	$t->same(['browser.ready'],$evidence->capabilities());
	$t->same('panel_quality_evidence',$evidence->jsonSerialize()['type']);
	$direct=new PanelQualityEvidence('suite.en.manual','blocked','manual',null,null,0,0.0,[],'Waiting for lab');
	$t->same('blocked',$direct->status());
	$notRun=PanelQualityEvidence::fromArray(['case_id'=>'suite.en.manual','status'=>'not_run','execution'=>'manual']);
	$t->same('not_run',$notRun->status());
	foreach([
		['case_id'=>'!'],['status'=>'unknown'],['execution'=>'robot'],['status'=>'passed','executor'=>null],['status'=>'passed','artifact'=>null],['status'=>'passed','assertions'=>0],['assertions'=>-1],['duration_ms'=>-1],['duration_ms'=>INF],['duration_ms'=>3600001],['capabilities'=>['!']],['executor'=>"bad\nrunner"],['artifact'=>str_repeat('x',2049)],['observed_at'=>'yesterday'],['observed_at'=>'2026-99-99T00:00:00Z'],['matrix_digest'=>str_repeat('g',64)]
	] as $index=>$replace){ $t->throws(static fn()=>PanelQualityEvidence::fromArray(array_replace($payload,$replace)),InvalidArgumentException::class,'invalid evidence variant '.$index); }
	$t->throws(static fn()=>PanelQualityEvidence::fromArray(array_replace($payload,['observed_at'=>null])),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelQualityEvidence('suite.en.keyboard','passed','browser',' runner ','artifact',1,1.0,[]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityEvidence::fromArray(array_replace($payload,['status'=>'not_run','assertions'=>1,'duration_ms'=>1])),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityEvidence::fromArray(array_replace($payload,['capabilities'=>array_map(static fn(int $i):string=>'cap.'.$i,range(1,33))])),InvalidArgumentException::class);
})->tag('panel','quality','evidence')->maxMillis(1000);

test('inclusive result gates automated evidence separately from adapter and manual declarations',static function(Context $t):void {
	$matrix=PanelInclusiveQualityMatrix::make('suite','/panel',['profiles'=>[dp_panel_evidence_profile()],'contracts'=>[
		dp_panel_evidence_contract('browser_ok'),
		dp_panel_evidence_contract('browser_missing'),
		dp_panel_evidence_contract('adapter_ok','adapter'),
		dp_panel_evidence_contract('manual_wait','manual',['required_capabilities'=>[]]),
	],'budgets'=>['max_cases'=>10,'max_evidence'=>10,'minimum_assertions'=>2]]);
	$capabilities=PanelQualityCapabilityReport::detect([
		'browser.ready'=>['status'=>'available','execution'=>'browser','source'=>'chromium'],
		'adapter.ready'=>['status'=>'available','execution'=>'adapter','source'=>'lab-adapter'],
	]);
	$result=$matrix->evaluate($capabilities,[
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'browser_ok'))),
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'adapter_ok'),'adapter',['capabilities'=>['adapter.ready']])),
		['case_id'=>dp_panel_evidence_case($matrix,'manual_wait'),'status'=>'blocked','execution'=>'manual','notes'=>'Scheduled'],
	],['max_automated_missing'=>1,'min_manual_passes'=>1]);
	$t->same(2,$result->automated()['total']);
	$t->same(1,$result->automated()['passed']);
	$t->same(1,$result->automated()['missing']);
	$t->same(2,$result->declaredManual()['total']);
	$t->same(1,$result->declaredManual()['passed']);
	$t->same(1,$result->declaredManual()['blocked']);
	$t->same([],$result->failures());
	$t->isTrue($result->passed());
	$t->same($result->rows(),$result->rows());
	$t->same('panel_inclusive_quality_result',$result->jsonSerialize()['type']);
	$t->same($matrix->digest(),$result->jsonSerialize()['matrix']['digest']);
})->tag('panel','quality','result')->maxMillis(1000);

test('inclusive result reports unavailable failed mismatched thin and over-budget evidence without false AT claims',static function(Context $t):void {
	$contracts=[
		dp_panel_evidence_contract('unavailable'),
		dp_panel_evidence_contract('explicit_failure'),
		dp_panel_evidence_contract('wrong_execution'),
		dp_panel_evidence_contract('thin'),
		dp_panel_evidence_contract('slow'),
		dp_panel_evidence_contract('missing_capability'),
		dp_panel_evidence_contract('manual_failure','manual',['required_capabilities'=>[]]),
	];
	$matrix=PanelInclusiveQualityMatrix::make('failures','/panel',['profiles'=>[dp_panel_evidence_profile()],'contracts'=>$contracts,'budgets'=>['max_cases'=>20,'max_evidence'=>20,'minimum_assertions'=>2]]);
	$capabilities=PanelQualityCapabilityReport::detect(['browser.ready'=>['status'=>'unavailable','execution'=>'browser']]);
	$evidence=[
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'explicit_failure'),'browser',['status'=>'failed'])),
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'wrong_execution'),'manual',['capabilities'=>['browser.ready']])),
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'thin'),'browser',['assertions'=>1])),
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'slow'),'browser',['duration_ms'=>101])),
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'missing_capability'),'browser',['capabilities'=>[]])),
		dp_panel_bound_evidence($matrix,dp_panel_evidence_row(dp_panel_evidence_case($matrix,'manual_failure'),'manual',['status'=>'failed','capabilities'=>[]])),
	];
	$result=$matrix->evaluate($capabilities,$evidence);
	$t->isFalse($result->passed());
	$t->same(5,$result->automated()['failed']);
	$t->same(1,$result->automated()['unavailable']);
	$t->same(1,$result->declaredManual()['failed']);
	$t->same(['automated_failures_exceeded','automated_evidence_missing','manual_failures_exceeded'],array_column($result->failures(),'code'));
	$issues=[]; foreach($result->rows() as $row){ foreach($row['issues'] as $issue){ $issues[]=$issue['code']; } }
	foreach(['capability_unavailable','execution_mismatch','assertion_budget','duration_budget','evidence_capability_missing'] as $issue){ $t->isTrue(in_array($issue,$issues,true)); }
})->tag('panel','quality','failure-semantics')->maxMillis(1000);

test('inclusive result rejects unknown duplicate malformed and oversized evidence',static function(Context $t):void {
	$matrix=PanelInclusiveQualityMatrix::make('guard','/panel',['profiles'=>[dp_panel_evidence_profile()],'contracts'=>[dp_panel_evidence_contract('one')],'budgets'=>['max_cases'=>2,'max_evidence'=>1]]);
	$capabilities=PanelQualityCapabilityReport::detect(['browser.ready'=>['status'=>'available','execution'=>'browser','source'=>'browser']]);
	$case=dp_panel_evidence_case($matrix,'one');
	$t->throws(static fn()=>$matrix->evaluate($capabilities,[dp_panel_evidence_row('guard.p2.en.c7.missing')]),InvalidArgumentException::class);
	$bound=dp_panel_bound_evidence($matrix,dp_panel_evidence_row($case));
	$t->throws(static fn()=>$matrix->evaluate($capabilities,[$bound,$bound],['max_evidence'=>2]),InvalidArgumentException::class);
	$t->throws(static fn()=>$matrix->evaluate($capabilities,[new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>$matrix->evaluate($capabilities,[$bound,$bound]),OverflowException::class);
})->tag('panel','quality','evidence-guards')->maxMillis(1000);

test('automated evidence is bound to the exact matrix digest and stale reports fail closed',static function(Context $t):void {
	$profile=dp_panel_evidence_profile();
	$contract=dp_panel_evidence_contract('stable_case');
	$matrix=PanelInclusiveQualityMatrix::make('binding','/panel',['profiles'=>[$profile],'contracts'=>[$contract]]);
	$capabilities=PanelQualityCapabilityReport::detect(['browser.ready'=>['status'=>'available','execution'=>'browser','source'=>'browser']]);
	$case=dp_panel_evidence_case($matrix,'stable_case');
	$bound=dp_panel_bound_evidence($matrix,dp_panel_evidence_row($case));
	$t->isTrue($matrix->evaluate($capabilities,[$bound])->passed());
	$t->throws(static fn()=>$matrix->evaluate($capabilities,[dp_panel_evidence_row($case)]),UnexpectedValueException::class);
	$changed=PanelInclusiveQualityMatrix::make('binding','/panel',['profiles'=>[$profile],'contracts'=>[array_replace($contract,['max_millis'=>99])]]);
	$t->same($case,dp_panel_evidence_case($changed,'stable_case'));
	$t->isFalse(hash_equals($matrix->digest(),$changed->digest()));
	$t->throws(static fn()=>$changed->evaluate($capabilities,[$bound]),UnexpectedValueException::class);
	$manualMatrix=PanelInclusiveQualityMatrix::make('manual_binding','/panel',['profiles'=>[$profile],'contracts'=>[dp_panel_evidence_contract('manual_case','manual',['required_capabilities'=>[]])]]);
	$manualCase=dp_panel_evidence_case($manualMatrix,'manual_case');
	$manualBound=dp_panel_bound_evidence($manualMatrix,dp_panel_evidence_row($manualCase,'manual',['capabilities'=>[]]));
	$t->isTrue($manualMatrix->evaluate($capabilities,[$manualBound])->passed());
	$t->throws(static fn()=>$manualMatrix->evaluate($capabilities,[dp_panel_evidence_row($manualCase,'manual',['capabilities'=>[]])]),UnexpectedValueException::class);
	$manualChanged=PanelInclusiveQualityMatrix::make('manual_binding','/panel',['profiles'=>[$profile],'contracts'=>[dp_panel_evidence_contract('manual_case','manual',['required_capabilities'=>[],'proves'=>['changed manual claim']])]]);
	$t->same($manualCase,dp_panel_evidence_case($manualChanged,'manual_case'));
	$t->throws(static fn()=>$manualChanged->evaluate($capabilities,[$manualBound]),UnexpectedValueException::class);
})->tag('panel','quality','evidence','security')->maxMillis(1000);
