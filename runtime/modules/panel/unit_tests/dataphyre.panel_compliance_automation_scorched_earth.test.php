<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelCallbackComplianceCollector;
use Dataphyre\Panel\PanelComplianceAutomation;
use Dataphyre\Panel\PanelComplianceCollectionContext;
use Dataphyre\Panel\PanelComplianceCollectionRun;
use Dataphyre\Panel\PanelComplianceCollectorRegistry;
use Dataphyre\Panel\PanelComplianceFrameworkCatalog;
use Dataphyre\Panel\PanelComplianceFrameworkPack;
use Dataphyre\Panel\PanelComplianceLedger;
use Dataphyre\Panel\PanelComplianceObservation;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelOperationsOs;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_compliance_profile():PanelComplianceFrameworkPack {
	return PanelComplianceFrameworkPack::make('operations_profile','1.0','Operations evidence profile','https://example.test/controls',[
		'access_review'=>['title'=>'Access review evidence','references'=>['OPS AC-1'],'domains'=>['access'],'evidence_requirements'=>['Current review result']],
		'backup_restore'=>['title'=>'Backup restore evidence','references'=>['OPS RC-1'],'domains'=>['recovery'],'evidence_requirements'=>['Current restoration result'],'crosswalks'=>[['framework'=>'operations_profile','control'=>'access_review','relation'=>'related']]],
	],['source_checked_at'=>'2026-07-16T12:00:00Z','coverage_scope'=>'complete_host_mapping','metadata'=>['owner'=>'platform']]);
}

/** @return array{automation:PanelComplianceAutomation,ledger:PanelComplianceLedger,registry:PanelComplianceCollectorRegistry,catalog:PanelComplianceFrameworkCatalog,key:string} */
function dp_panel_compliance_fixture(Context $t,string &$now,array $limits=[]):array {
	$key=str_repeat('K',32);$ledger=new PanelComplianceLedger($t->tempDirectory('panel-compliance-automation'),['primary'=>$key],'primary',static fn():bool=>true,static function()use(&$now):string{return$now;},1000);
	$registry=new PanelComplianceCollectorRegistry();$catalog=(new PanelComplianceFrameworkCatalog())->register(dp_panel_compliance_profile());
	$automation=new PanelComplianceAutomation($ledger,$registry,$catalog,static function()use(&$now):string{return$now;},$limits);
	return compact('automation','ledger','registry','catalog','key');
}

function dp_panel_compliance_collector(string $id,string $version,string $status='satisfied',int $maxAge=300):PanelCallbackComplianceCollector {
	return new PanelCallbackComplianceCollector($id,$version,static function(PanelComplianceCollectionContext $context)use($status,$maxAge):PanelComplianceObservation {
		return PanelComplianceObservation::make($status,['control'=>$context->frameworkControlId(),'sample_count'=>3,'api_token'=>'must-not-survive'],['observed_at'=>$context->requestedAt(),'max_age_seconds'=>$maxAge,'subject'=>$context->subject(),'source_reference'=>'host:'.$context->frameworkControlId(),'max_evidence_items'=>$context->maxEvidenceItems()]);
	},['read_only'=>true,'freshness'=>true],['implementation'=>'fixture']);
}

test('first-party compliance profiles are source-bound reference maps without certification claims',static function(Context $t):void {
	$catalog=PanelComplianceFrameworkCatalog::firstParty();$manifest=$catalog->jsonSerialize();
	$t->same(4,$manifest['pack_count']);$t->same(29,$manifest['control_count']);$t->isTrue($catalog->has('nist_csf_2'));$t->same(4,count($catalog->packs()));$t->same(4,$catalog->revision());$t->same([],$catalog->danglingCrosswalks());$t->isFalse($manifest['claims']['certification']);$t->isFalse($manifest['claims']['legal_advice']);
	foreach(['nist_csf_2','gdpr','hipaa_security_rule','pci_dss']as$id){$pack=$catalog->get($id);$t->isTrue($pack->title()!=='');$t->same('2026-07-16T00:00:00.000000Z',$pack->sourceCheckedAt());$t->same('reference_profile',$pack->coverageScope());$t->isTrue(str_starts_with($pack->sourceUrl(),'https://'));$t->isFalse($pack->jsonSerialize()['claims']['certification']);$t->isTrue(strlen($pack->fingerprint())===64);}
	$crosswalk=$catalog->crosswalks('nist_csf_2','protect');$t->isTrue(count($crosswalk)>=3);$t->same('related',$crosswalk[0]['relation']);$t->isFalse($crosswalk[0]['equivalence_claimed']);
	$t->throws(static fn()=>PanelComplianceFrameworkPack::make('bad','1','Bad','http://example.test', ['one'=>['references'=>['X']]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelComplianceFrameworkPack::make('bad','1','Bad','https://user:pass@example.test', ['one'=>['references'=>['X']]]),InvalidArgumentException::class);
})->tag('panel','compliance','frameworks','crosswalks','scorched-earth')->isolation('case')->maxMillis(8000);

test('collector registry pins provenance rejects collisions and passes production conformance',static function(Context $t):void {
	$secret='collector-secret-value';$collector=new PanelCallbackComplianceCollector('access_probe','2026.7.16',static function(PanelComplianceCollectionContext $context):PanelComplianceObservation{return PanelComplianceObservation::make('satisfied',['ok'=>true],['observed_at'=>$context->requestedAt(),'max_age_seconds'=>60,'subject'=>$context->subject()]);},['read_only'=>true],['api_token'=>$secret]);
	$registry=(new PanelComplianceCollectorRegistry())->register($collector,'security',100);$t->same($collector,$registry->get('access_probe'));$t->same(1,$registry->revision());$t->notContains($secret,json_encode($registry,JSON_THROW_ON_ERROR));$t->same('[REDACTED]',$collector->jsonSerialize()['metadata']['api_token']);
	$registry->register($collector,'ignored',0);$t->same(1,$registry->revision());$t->throws(static fn()=>$registry->register(dp_panel_compliance_collector('access_probe','2.0')),LogicException::class);
	$context=new PanelComplianceCollectionContext('profile.access','profile','access','tenant:one','2026-07-16T12:00:00Z','2026-07-16T12:01:00Z',str_repeat('a',64),['REF'],['nested'=>['value'=>7]],['region'=>'ca'],32);
	$t->same('profile.access',$context->controlId());$t->same('profile',$context->frameworkId());$t->same('2026-07-16T12:01:00.000000Z',$context->deadlineAt());$t->same(str_repeat('a',64),$context->planFingerprint());$t->same(['REF'],$context->references());$t->same(['nested'=>['value'=>7]],$context->input());$t->same(7,$context->inputValue('nested.value'));$t->same(['region'=>'ca'],$context->attributes());$t->same('panel_compliance_collection_context',$context->jsonSerialize()['type']);
	$observation=$collector->collect($context);$hydrated=PanelComplianceObservation::fromArray($observation->jsonSerialize());$t->same($observation->digest(),$hydrated->digest());$t->same($observation->sourceReference(),$hydrated->sourceReference());$t->same($observation->evidence(),$hydrated->evidence());$t->isTrue($hydrated->freshAt('2026-07-16T12:00:30Z'));
	$report=(new PanelAdapterConformanceRunner())->run(PanelAdapterConformanceCatalog::complianceCollector(),$collector,['collection_context'=>$context,'forbidden_fragments'=>[$secret]]);$t->isTrue($report->passed());$t->same(2,$report->summary()['passed']);$t->isTrue($report->summary()['assertions']>=10);$t->isTrue($registry->has('access_probe'));$t->same($collector,$registry->collectors()['access_probe']);$registry->forget('access_probe');$t->isFalse($registry->has('access_probe'));
})->tag('panel','compliance','collector','registry','conformance','security','scorched-earth')->isolation('case')->maxMillis(8000);

test('collection plans hide inputs pin every dependency and detect collector drift',static function(Context $t):void {
	$now='2026-07-16T12:00:00Z';$fixture=dp_panel_compliance_fixture($t,$now);$fixture['registry']->register(dp_panel_compliance_collector('access_probe','1'))->register(dp_panel_compliance_collector('backup_probe','1'));
	$options=['generated_at'=>$now,'deadline_at'=>'2026-07-16T12:10:00Z','subject'=>'tenant:one','input'=>['region'=>'ca','api_token'=>'private'],'inputs'=>['operations_profile.backup_restore'=>['snapshot'=>'nightly']]];
	$bindings=['operations_profile.access_review'=>'access_probe','operations_profile.backup_restore'=>['backup_probe']];$first=$fixture['automation']->plan(['operations_profile'],$bindings,$options);$second=$fixture['automation']->plan(['operations_profile'],$bindings,$options);
	$t->same($first->fingerprint(),$second->fingerprint());$t->same(2,count($first->entries()));$t->isFalse($first->jsonSerialize()['inputs_serialized']);$encoded=json_encode($first,JSON_THROW_ON_ERROR);$t->notContains('private',$encoded);$t->notContains('nightly',$encoded);$t->same('[REDACTED]',$first->inputFor('operations_profile.access_review')['api_token']);$t->same('nightly',$first->inputFor('operations_profile.backup_restore')['snapshot']);$t->same([],$first->drift($fixture['catalog'],$fixture['registry']));
	$fixture['registry']->register(dp_panel_compliance_collector('access_probe','2'),'host',0,true);$drift=$first->drift($fixture['catalog'],$fixture['registry']);$t->same('collector_fingerprint_changed',$drift[0]['code']);$t->isTrue($first->expiredAt('2026-07-16T12:10:01Z'));
	$t->same('2026-07-16T12:00:00.000000Z',$first->generatedAt());$missingFramework=$first->drift(new PanelComplianceFrameworkCatalog(),$fixture['registry']);$t->same('framework_missing',$missingFramework[0]['code']);
	$t->throws(static fn()=>$fixture['automation']->plan(['missing']),OutOfBoundsException::class);$t->throws(static fn()=>$fixture['automation']->plan(['operations_profile'],['*'=>'missing']),OutOfBoundsException::class);
})->tag('panel','compliance','plan','fingerprints','redaction','drift','scorched-earth')->isolation('case')->maxMillis(8000);

test('automation records typed evidence signs runs and reports current evidence coverage',static function(Context $t):void {
	$now='2026-07-16T12:00:00Z';$fixture=dp_panel_compliance_fixture($t,$now);$fixture['registry']->register(dp_panel_compliance_collector('access_probe','1','satisfied',300))->register(dp_panel_compliance_collector('backup_probe','1','not_applicable',300));
	$plan=$fixture['automation']->plan(['operations_profile'],['operations_profile.access_review'=>'access_probe','operations_profile.backup_restore'=>'backup_probe'],['generated_at'=>$now,'deadline_at'=>'2026-07-16T12:05:00Z','subject'=>'tenant:one']);
	$run=$fixture['automation']->collect($plan,'operator:one',['run_id'=>'daily_evidence']);$t->instanceOf(PanelComplianceCollectionRun::class,$run);$t->isTrue($run->verify(['primary'=>$fixture['key']]));$t->isFalse($run->verify(['primary'=>str_repeat('x',32)]));$t->same('daily_evidence',$run->runId());$t->same($plan->fingerprint(),$run->planFingerprint());$t->same(2,$run->summary()['observation_count']);$t->same(2,$run->summary()['control_count']);$t->isTrue($run->summary()['all_positive']);
	$hydrated=PanelComplianceCollectionRun::hydrate($run->jsonSerialize());$t->same($run->digest(),$hydrated->digest());$t->isTrue($hydrated->verify(['primary'=>$fixture['key']]));
	$t->same(2,count($fixture['ledger']->controls()));$t->same(2,count($fixture['ledger']->latestEvidence()));$t->isTrue($fixture['ledger']->verify());$pack=$fixture['ledger']->pack();$t->isTrue($pack->verify(['primary'=>$fixture['key']]));$t->notContains('must-not-survive',json_encode($pack,JSON_THROW_ON_ERROR));
	$manual=$fixture['ledger']->registerControl('manual.review',['title'=>'Manual review','framework'=>'operations_profile','owner'=>'operator:one'],'operator:one');$t->same('manual.review',$manual['id']);$t->throws(static fn()=>$fixture['ledger']->registerControl('manual.review',['title'=>'Duplicate'],'operator:one'),LogicException::class);
	$hold=$fixture['ledger']->hold('investigation','Preserve review evidence','operator:one','manual.review');$t->same('manual.review',$hold['control_id']);$released=$fixture['ledger']->releaseHold('investigation','operator:one');$t->notNull($released['released_at']);$t->throws(static fn()=>$fixture['ledger']->releaseHold('investigation','operator:one'),OutOfBoundsException::class);
	$coverage=$fixture['automation']->coverage($run,$now);$summary=$coverage->summary();$t->same(64,strlen($coverage->fingerprint()));$t->same(2,count($coverage->controls()));$t->same(10000,$summary['evidence_coverage_basis_points']);$t->isTrue($summary['all_controls_observed']);$t->isTrue($summary['no_negative_observations']);$t->isFalse($coverage->jsonSerialize()['claims']['compliance']);
})->tag('panel','compliance','automation','ledger','signed-run','coverage','scorched-earth')->isolation('case')->maxMillis(12000);

test('collector failures are isolated redacted and never upgraded to positive evidence',static function(Context $t):void {
	$now='2026-07-16T12:00:00Z';$fixture=dp_panel_compliance_fixture($t,$now);$throwing=new PanelCallbackComplianceCollector('throwing_probe','1',static function():PanelComplianceObservation{throw new RuntimeException('password=catastrophic-secret');});$fixture['registry']->register($throwing)->register(dp_panel_compliance_collector('negative_probe','1','not_satisfied'));
	$plan=$fixture['automation']->plan(['operations_profile'],['operations_profile.access_review'=>'throwing_probe','operations_profile.backup_restore'=>'negative_probe'],['generated_at'=>$now,'deadline_at'=>'2026-07-16T12:05:00Z']);$run=$fixture['automation']->collect($plan,'operator:one');$results=$run->results();
	$t->same('error',$results[0]['status']);$t->same('not_satisfied',$results[1]['status']);$t->same(1,$run->summary()['statuses']['error']);$t->same(1,$run->summary()['statuses']['not_satisfied']);$t->isFalse($run->summary()['all_positive']);$t->notContains('catastrophic-secret',json_encode($run,JSON_THROW_ON_ERROR));$t->isTrue($fixture['ledger']->verify());
	$coverage=$fixture['automation']->coverage($run,$now);$t->isFalse($coverage->summary()['no_negative_observations']);
})->tag('panel','compliance','failure-isolation','redaction','negative-evidence','scorched-earth')->isolation('case')->maxMillis(10000);

test('stale evidence and dependency drift remain explicit gaps with deterministic deltas',static function(Context $t):void {
	$now='2026-07-16T12:00:00Z';$fixture=dp_panel_compliance_fixture($t,$now);$fixture['registry']->register(dp_panel_compliance_collector('short_probe','1','satisfied',1));
	$plan=$fixture['automation']->plan(['operations_profile'],['*'=>'short_probe'],['generated_at'=>$now,'deadline_at'=>'2026-07-16T12:05:00Z']);$run=$fixture['automation']->collect($plan,'operator:one');$fresh=$fixture['automation']->coverage($run,$now);$stale=$fixture['automation']->coverage($run,'2026-07-16T12:00:02Z');
	$t->same(0,$fresh->summary()['stale_controls']);$t->same(2,$stale->summary()['stale_controls']);$t->same(0,$stale->summary()['evidence_coverage_basis_points']);$delta=$stale->drift($fresh);$t->same(2,count($delta['new_gaps']));$t->same(2,count($delta['status_changes']));$t->isFalse($delta['compliance_claimed']);
	$fixture['registry']->register(dp_panel_compliance_collector('short_probe','2'),'host',0,true);$drifted=$fixture['automation']->collect($plan,'operator:one');$t->same(2,count($drifted->payload()['dependency_drift']));$t->same(2,$drifted->summary()['statuses']['error']);$t->same(0,$drifted->summary()['observation_count']);
	$error=$t->nonPublic($fixture['automation'])->invoke('errorObservation',['subject'=>'tenant:one'],['id'=>'short_probe','fingerprint'=>str_repeat('f',64)],'dependency_drift',$now,null);$t->same('error',$error['status']);$t->same('short_probe',$error['collector_id']);$t->same(64,strlen($error['observation_digest']));
})->tag('panel','compliance','freshness','drift','fail-closed','scorched-earth')->isolation('case')->maxMillis(12000);

test('collector budgets fail closed and ledger control definitions cannot drift silently',static function(Context $t):void {
	$now='2026-07-16T12:00:00Z';$fixture=dp_panel_compliance_fixture($t,$now,['collector_millis'=>1,'run_millis'=>1000]);$slow=new PanelCallbackComplianceCollector('slow_probe','1',static function(PanelComplianceCollectionContext $context):PanelComplianceObservation{usleep(3000);return PanelComplianceObservation::make('satisfied',['late'=>true],['observed_at'=>$context->requestedAt(),'max_age_seconds'=>60,'subject'=>$context->subject()]);});$fixture['registry']->register($slow);
	$plan=$fixture['automation']->plan(['operations_profile'],['*'=>'slow_probe'],['generated_at'=>$now,'deadline_at'=>'2026-07-16T12:05:00Z']);$run=$fixture['automation']->collect($plan,'operator:one');$t->same(2,$run->summary()['statuses']['error']);$t->same(2,$run->summary()['observation_count']);
	$control=$fixture['ledger']->control('operations_profile.access_review');$t->notNull($control);$fixture['ledger']->ensureControl('operations_profile.access_review',['title'=>$control['title'],'framework'=>$control['framework'],'owner'=>$control['owner'],'frequency'=>$control['frequency'],'automated'=>$control['automated'],'metadata'=>$control['metadata']],'operator:two');
	$t->throws(static fn()=>$fixture['ledger']->ensureControl('operations_profile.access_review',['title'=>'Changed','framework'=>'operations_profile','owner'=>'compliance:automation'],'operator:two'),LogicException::class);
})->tag('panel','compliance','budgets','control-drift','scorched-earth')->isolation('case')->maxMillis(12000);

test('Operations OS composes one compliance automation graph without exposing trust material',static function(Context $t):void {
	$master=str_repeat('M',48);$collector=dp_panel_compliance_collector('host_probe','1');$os=PanelOperationsOs::fromConfig($t->tempDirectory('panel-compliance-os'),['master_key'=>$master,'compliance_collectors'=>[$collector]]);$automation=$os->complianceAutomation();
	$t->same($os->compliance(),$automation->ledger());$t->same($collector,$automation->collectors()->get('host_probe'));$t->same(4,$automation->frameworks()->jsonSerialize()['pack_count']);$manifest=$os->jsonSerialize();$t->isTrue($manifest['capabilities']['collector_driven_compliance']);$t->isTrue($manifest['security']['secrets_serialized']===false);$t->notContains($master,json_encode($manifest,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($t->tempDirectory('panel-compliance-os-invalid'),['master_key'=>$master,'compliance_collectors'=>[new stdClass()]]),InvalidArgumentException::class);
})->tag('panel','compliance','operations-os','composition','security','scorched-earth')->isolation('case')->maxMillis(15000);
