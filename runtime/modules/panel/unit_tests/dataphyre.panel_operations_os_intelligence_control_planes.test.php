<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelComplianceEvidencePack;
use Dataphyre\Panel\PanelComplianceLedger;
use Dataphyre\Panel\PanelCounterfactualLab;
use Dataphyre\Panel\PanelDomainCompiler;
use Dataphyre\Panel\PanelFederationControlPlane;
use Dataphyre\Panel\PanelFederationNode;
use Dataphyre\Panel\PanelInMemoryWorkGraphStore;
use Dataphyre\Panel\PanelLineageGraph;
use Dataphyre\Panel\PanelLocalReplica;
use Dataphyre\Panel\PanelMarketplaceGovernance;
use Dataphyre\Panel\PanelMarketplaceReview;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPackageTrustReport;
use Dataphyre\Panel\PanelPolicyBundle;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelPolicyRequest;
use Dataphyre\Panel\PanelProcessIntelligence;
use Dataphyre\Panel\PanelReleaseArtifact;
use Dataphyre\Panel\PanelReleaseControlPlane;
use Dataphyre\Panel\PanelSemanticCatalog;
use Dataphyre\Panel\PanelSemanticMetric;
use Dataphyre\Panel\PanelStudioBranchManager;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioMergeResult;
use Dataphyre\Panel\PanelSyncDocument;
use Dataphyre\Panel\PanelSyncEnvelope;
use Dataphyre\Panel\PanelWorkGraph;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_os_cp_policy():PanelPolicyControlPlane {
	$plane=new PanelPolicyControlPlane([],false);
	$plane->register(PanelPolicyBundle::from(['id'=>'control_plane','version'=>'1.0.0','rules'=>[
		'allow'=>['effect'=>'allow','abilities'=>['release.*','marketplace.*'],'priority'=>10,'reason'=>'Control-plane operator.'],
	]]));
	return$plane;
}

function dp_panel_os_cp_request(string $ability='release.deploy',string $actor='Operator:1'):PanelPolicyRequest {
	return new PanelPolicyRequest($actor,$ability,'Tenant:1',null,null,'high',['operator'],['release.*','marketplace.*']);
}

/** @return array<string,mixed> */
function dp_panel_os_cp_domain():array{return[
	'id'=>'orders','version'=>'1.0.0','entities'=>['order'=>['primary_key'=>'id','fields'=>[
		'id'=>['type'=>'uuid','required'=>true],'status'=>['type'=>'string'],'total'=>['type'=>'money'],
	]]],
	'commands'=>['review'=>['entity'=>'order','operation'=>'review']],
	'metrics'=>['order_count'=>['entity'=>'order','aggregation'=>'count','dimensions'=>['status']],'order_value'=>['entity'=>'order','aggregation'=>'sum','field'=>'total']],
	'surfaces'=>['index'=>['kind'=>'resource','entity'=>'order']],
];}

test('semantic catalog evaluates every aggregation with declared dimensions and portable filters',static function(Context $t):void{
	$rows=[
		['status'=>'open','market'=>'ca','amount'=>10,'customer'=>'a','paid'=>true],
		['status'=>'open','market'=>'ca','amount'=>20,'customer'=>'b','paid'=>false],
		['status'=>'closed','market'=>'us','amount'=>40,'customer'=>'a','paid'=>true],
	];
	$catalog=new PanelSemanticCatalog();
	$definitions=[
		'orders'=>['aggregation'=>'count','dimensions'=>['status']],
		'value'=>['aggregation'=>'sum','field'=>'amount'],
		'value_by_status'=>['aggregation'=>'sum','field'=>'amount','dimensions'=>['status']],
		'average'=>['aggregation'=>'average','field'=>'amount'],
		'minimum'=>['aggregation'=>'minimum','field'=>'amount'],
		'maximum'=>['aggregation'=>'maximum','field'=>'amount'],
		'customers'=>['aggregation'=>'distinct_count','field'=>'customer'],
		'paid_ratio'=>['aggregation'=>'ratio','numerator_filter'=>['paid'=>true],'denominator_filter'=>['status'=>['open','closed']]],
	];
	foreach($definitions as$id=>$definition){$catalog->register(PanelSemanticMetric::from($id,['entity'=>'order']+$definition));}
	$t->same(3,$catalog->query('orders',$rows,[])[0]['value']+$catalog->query('orders',$rows,[])[1]['value']);
	$t->same(30.0,(float)$catalog->query('value_by_status',$rows,['status'])[1]['value']);
	$t->same(70.0,(float)$catalog->query('value',$rows,[])[0]['value']);
	$t->same(70.0/3,(float)$catalog->query('average',$rows,[])[0]['value']);
	$t->same(10,$catalog->query('minimum',$rows,[])[0]['value']);
	$t->same(40,$catalog->query('maximum',$rows,[])[0]['value']);
	$t->same(2,$catalog->query('customers',$rows,[])[0]['value']);
	$t->same(2/3,$catalog->query('paid_ratio',$rows,[])[0]['value']);
	$filtered=PanelSemanticMetric::from('canadian',['entity'=>'order','aggregation'=>'count','filter'=>['market'=>['ca']]]);$t->same(2,$filtered->evaluate($rows)[0]['value']);
	$empty=PanelSemanticMetric::from('empty',['entity'=>'order','aggregation'=>'average','field'=>'amount']);$t->same(null,$empty->evaluate([])[0]['value']);
	$t->same('value',$catalog->metric('value')->id());$t->same(8,$catalog->revision());$t->isTrue(strlen($catalog->fingerprint())===64);$t->same('panel_semantic_catalog_manifest',$catalog->jsonSerialize()['type']);
	$t->throws(static fn()=>$catalog->register(PanelSemanticMetric::from('value',['entity'=>'order'])),LogicException::class);$catalog->register(PanelSemanticMetric::from('value',['entity'=>'order']),true);$catalog->remove('value')->remove('missing');$t->throws(static fn()=>$catalog->metric('value'),OutOfBoundsException::class);
	$t->throws(static fn()=>new PanelSemanticMetric('bad','Bad','order','unknown'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSemanticMetric('bad','Bad','order','sum'),InvalidArgumentException::class);
	$t->throws(static fn()=>$filtered->evaluate($rows,['market']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSemanticMetric::from('bad',['entity'=>'order','filter'=>['bad path'=>true]]),InvalidArgumentException::class);
})->tag('panel','operations-os','semantics','aggregations')->isolation('case')->maxMillis(6000);

test('field lineage imports domain compilations traverses impact and rejects derivation cycles',static function(Context $t):void{
	$graph=new PanelLineageGraph();$graph->node('field:order.total','field','Order total')->node('metric:revenue','metric','Revenue')->node('surface:dashboard','surface','Dashboard');
	$graph->edge('field:order.total','metric:revenue','aggregates')->edge('metric:revenue','surface:dashboard','derives');
	$t->same('field:order.total',$graph->upstream('surface:dashboard')[1]['id']);$t->same('surface:dashboard',$graph->downstream('field:order.total')[1]['id']);
	$impact=$graph->impact('field:order.total');$t->same(2,$impact['affected_count']);$t->same(1,$impact['affected_by_kind']['metric']);
	$t->throws(static fn()=>$graph->edge('surface:dashboard','field:order.total','transforms'),LogicException::class);$t->same(2,count($graph->jsonSerialize()['edges']));
	$graph->node('metric:revenue','metric','Net revenue',[],true)->edge('field:order.total','metric:revenue','aggregates',['expression'=>'sum'],true);$t->same('Net revenue',$graph->get('metric:revenue')['label']);
	$t->throws(static fn()=>$graph->node('metric:revenue','metric','Again'),LogicException::class);$t->throws(static fn()=>$graph->edge('missing','metric:revenue'),OutOfBoundsException::class);$t->throws(static fn()=>$graph->edge('metric:revenue','metric:revenue'),InvalidArgumentException::class);
	$compiled=(new PanelDomainCompiler())->compile(dp_panel_os_cp_domain());$imported=PanelLineageGraph::fromCompilation($compiled);$t->isTrue($imported->has('field:order.total'));$t->isTrue($imported->has('metric:order_value'));$t->isTrue(in_array('metric:order_value',array_column($imported->downstream('field:order.total'),'id'),true));
	$graph->forgetPrefix('metric:');$t->isFalse($graph->has('metric:revenue'));$revision=$graph->revision();$graph->forgetPrefix('absent:');$t->same($revision,$graph->revision());$t->throws(static fn()=>$graph->get('missing'),OutOfBoundsException::class);
})->tag('panel','operations-os','lineage','impact')->isolation('case')->maxMillis(6000);

test('process intelligence preserves microsecond durations variants bottlenecks and conformance',static function(Context $t):void{
	$now='2026-07-16T12:00:00.000000Z';$graph=new PanelWorkGraph(new PanelInMemoryWorkGraphStore(),static fn():bool=>true,static function()use(&$now):string{return$now;});
	$graph->create('Tenant:1',['id'=>'case:1','title'=>'Case'],'Actor:1','create');$now='2026-07-16T12:00:00.250000Z';$graph->assign('Tenant:1','case:1','Analyst:1','Actor:1',1,'assign');$now='2026-07-16T12:00:00.750000Z';$graph->transition('Tenant:1','case:1','review','Actor:1',2,'review');
	$report=(new PanelProcessIntelligence())->analyze($graph->timeline('Tenant:1'),[['from'=>'create','to'=>'assign']]);
	$t->same(1,$report['case_count']);$t->same(3,$report['event_count']);$t->same(1,$report['variant_count']);$t->same(1,$report['conformance']['violation_count']);
	$t->same(.5,$report['bottlenecks'][0]['average_seconds']);$t->same(.25,$report['bottlenecks'][1]['average_seconds']);$t->same('assign>transition',$report['bottlenecks'][0]['transition']);
	$t->same(0,(new PanelProcessIntelligence())->analyze([])['case_count']);$t->same('panel_process_intelligence_manifest',(new PanelProcessIntelligence())->jsonSerialize()['type']);
	$t->throws(static fn()=>(new PanelProcessIntelligence())->analyze([[]]),InvalidArgumentException::class);$t->throws(static fn()=>(new PanelProcessIntelligence())->analyze([],['bad']),InvalidArgumentException::class);
})->tag('panel','operations-os','process-intelligence','conformance')->isolation('case')->maxMillis(6000);

test('counterfactual laboratory ranks deterministic multi-run interventions and fails on invalid objectives',static function(Context $t):void{
	$lab=new PanelCounterfactualLab(static function(array $baseline,array $intervention,string $seed,int $run):array{return['score'=>(float)$baseline['score']+(float)($intervention['boost']??0)+$run,'nested'=>['cost'=>(float)$baseline['cost']-(float)($intervention['saving']??0)]];},10);
	$report=$lab->compare(['score'=>10,'cost'=>20],['small'=>['boost'=>1,'saving'=>1],'large'=>['boost'=>4,'saving'=>3]],['score','nested.cost'],2,'seed');
	$t->same('large',$report['recommended']);$t->same(2,count($report['scenarios']));$t->same(4.5,$report['scenarios'][0]['metrics']['score']['delta']);$t->isTrue($report['side_effect_free']);$t->same('panel_counterfactual_lab_manifest',$lab->jsonSerialize()['type']);
	$t->throws(static fn()=>$lab->compare([],[],['score']),InvalidArgumentException::class);$t->throws(static fn()=>$lab->compare([],['x'=>[]],[],1),InvalidArgumentException::class);$t->throws(static fn()=>$lab->compare([],['x'=>[]],['missing'],1),UnexpectedValueException::class);$t->throws(static fn()=>new PanelCounterfactualLab(static fn():array=>[],0),InvalidArgumentException::class);
})->tag('panel','operations-os','counterfactuals','simulation')->isolation('case')->maxMillis(6000);

test('continuous compliance persists signed evidence legal holds and hold-aware retention',static function(Context $t):void{
	$key=str_repeat('c',32);$now='2026-07-16T12:00:00.000000Z';$ledger=new PanelComplianceLedger($t->tempDirectory('panel-compliance'),['primary'=>$key],'primary',static fn():bool=>true,static function()use(&$now):string{return$now;},100);
	$control=$ledger->registerControl('access_review',['title'=>'Access review','framework'=>'soc2','owner'=>'Owner:1','automated'=>true],'Admin:1');$t->same('soc2',$control['framework']);$t->throws(static fn()=>$ledger->registerControl('access_review',[],'Admin:1'),LogicException::class);
	$t->throws(static fn()=>$ledger->record('missing','passed',[],'Agent:1'),OutOfBoundsException::class);$t->throws(static fn()=>$ledger->hold('bad_hold','Unknown','Admin:1','missing'),OutOfBoundsException::class);
	for($index=1;$index<=101;$index++){$now=sprintf('2026-07-16T12:%02d:%02d.000000Z',intdiv($index%3600,60),$index%60);$ledger->record('access_review',$index%2===0?'passed':'observed',['index'=>$index,'token'=>'secret'],'Agent:1','automation');}
	$t->isTrue($ledger->verify());$manifest=$ledger->jsonSerialize();$t->same(101,$manifest['sequence']);$t->same(100,$manifest['evidence_count']);$t->notContains('secret',json_encode($manifest,JSON_THROW_ON_ERROR));
	$hold=$ledger->hold('case_hold','Investigation','Counsel:1','access_review');$t->same(null,$hold['released_at']);$ledger->record('access_review','passed',['held'=>true],'Agent:1');$t->same(101,$ledger->jsonSerialize()['evidence_count']);
	$pack=$ledger->pack(['access_review']);$t->instanceOf(PanelComplianceEvidencePack::class,$pack);$t->isTrue($pack->verify(['primary'=>$key]));$t->isFalse($pack->verify(['primary'=>str_repeat('x',32)]));$t->same(1,count($pack->payload()['active_holds']));$t->isTrue(strlen($pack->digest())===64);
	$t->same($pack->digest(),$pack->jsonSerialize()['digest']);
	$released=$ledger->releaseHold('case_hold','Counsel:2');$t->isTrue(is_string($released['released_at']));$t->throws(static fn()=>$ledger->releaseHold('case_hold','Counsel:2'),OutOfBoundsException::class);$t->throws(static fn()=>$ledger->pack(['missing']),OutOfBoundsException::class);
	$t->throws(static fn()=>PanelComplianceEvidencePack::sign([],'primary','short'),InvalidArgumentException::class);
	$denied=new PanelComplianceLedger($t->tempDirectory('panel-compliance-denied'),['primary'=>$key],'primary');$t->throws(static fn()=>$denied->registerControl('one',[],'Admin:1'),LogicException::class);
})->tag('panel','operations-os','compliance','retention','legal-hold')->isolation('case')->maxMillis(20000);

test('fleet federation verifies attestations rejects replay reconciles drift and restores checkpoints',static function(Context $t):void{
	$key=str_repeat('f',32);$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$plane=new PanelFederationControlPlane(['primary'=>$key],$clock);$digest=hash('sha256','desired');
	$node=PanelFederationNode::sign('node_a','production','ca',1,'2026-07-16T11:59:00Z','2026-07-16T12:10:00Z',['deploy','sync'],['policy'=>$digest],['zone'=>'one'],'primary',$key);
	$t->isTrue($node->verify(['primary'=>$key],$now));$t->same($node->digest(),PanelFederationNode::hydrate($node->jsonSerialize())->digest());$plane->ingest($node)->desired(['policy'=>$digest]);$t->same([],$plane->reconcile());$t->isTrue($plane->quorum('deploy',1)['met']);$t->isFalse($plane->quorum('deploy',2)['met']);
	$t->throws(static fn()=>$plane->ingest($node),LogicException::class);$plane->desired(['policy'=>hash('sha256','next')]);$t->same('converge',$plane->reconcile()[0]['action']);$revision=$plane->revision();$plane->desired(['policy'=>hash('sha256','next')]);$t->same($revision,$plane->revision());
	$checkpoint=$plane->checkpoint();$restored=(new PanelFederationControlPlane(['primary'=>$key],$clock))->restore($checkpoint);$t->same($plane->revision(),$restored->revision());$t->same($plane->jsonSerialize()['desired_state'],$restored->jsonSerialize()['desired_state']);
	$bad=$checkpoint;$bad['revision']++;$t->throws(static fn()=>(new PanelFederationControlPlane(['primary'=>$key],$clock))->restore($bad),UnexpectedValueException::class);
	$future=PanelFederationNode::sign('future','production','ca',1,'2026-07-16T13:00:00Z','2026-07-16T14:00:00Z',['deploy'],['policy'=>$digest],[],'primary',$key);$t->throws(static fn()=>$plane->ingest($future),LogicException::class);
	$corrupt=$node->jsonSerialize();$corrupt['digest']=str_repeat('0',64);$t->throws(static fn()=>PanelFederationNode::hydrate($corrupt),UnexpectedValueException::class);
	$now='2026-07-16T12:11:00.000000Z';$t->same('wait_for_heartbeat',$plane->reconcile()[0]['action']);$t->isFalse($plane->quorum('deploy',1)['met']);
	$t->throws(static fn()=>new PanelFederationControlPlane([]),InvalidArgumentException::class);$t->throws(static fn()=>PanelFederationNode::sign('x','prod','ca',0,'2026-07-16T12:00:00Z','2026-07-16T13:00:00Z',[],[],[],'primary',$key),InvalidArgumentException::class);
})->tag('panel','operations-os','federation','attestation','checkpoint')->isolation('case')->maxMillis(7000);

test('release control enforces signed artifacts rings health promotion rollback pause and deterministic flags',static function(Context $t):void{
	$key=str_repeat('r',32);$policy=dp_panel_os_cp_policy();$request=dp_panel_os_cp_request();$now='2026-07-16T12:00:00.000000Z';$root=$t->tempDirectory('panel-releases');$plane=new PanelReleaseControlPlane($root,['primary'=>$key],$policy,static function()use(&$now):string{return$now;});
	$gates=[['metric'=>'latency','operator'=>'lte','threshold'=>100],['metric'=>'uptime','operator'=>'gte','threshold'=>99],['metric'=>'error','operator'=>'lt','threshold'=>1],['metric'=>'throughput','operator'=>'gt','threshold'=>10],['metric'=>'replicas','operator'=>'eq','threshold'=>5]];$health=['latency'=>90,'uptime'=>99.9,'error'=>.1,'throughput'=>20,'replicas'=>5];
	$one=PanelReleaseArtifact::sign('panel_1','1.0.0',['code'=>hash('sha256','one')],[['name'=>'dataphyre','version'=>'1.0.0']],['builder'=>'ci','source_digest'=>hash('sha256','source')],$now,'primary',$key);$two=PanelReleaseArtifact::sign('panel_2','2.0.0',['code'=>hash('sha256','two')],[['name'=>'dataphyre','version'=>'2.0.0']],['builder'=>'ci','source_digest'=>hash('sha256','source2')],$now,'primary',$key);
	$t->isTrue($one->verify(['primary'=>$key]));$t->same($one->digest(),PanelReleaseArtifact::hydrate($one->jsonSerialize())->digest());$plane->register($one,$request)->register($two,$request)->ring('canary',10,1000,$gates,$request)->ring('production',20,9000,$gates,$request);
	$blocked=$plane->deploy('panel_1','canary',['latency'=>200],$request,'blocked');$t->same('blocked',$blocked['status']);$active=$plane->deploy('panel_1','canary',$health,$request,'one-canary');$t->same('active',$active['status']);$t->isTrue($plane->deploy('panel_1','canary',$health,$request,'one-canary')['replayed']);$t->throws(static fn()=>$plane->deploy('panel_2','canary',$health,$request,'one-canary'),LogicException::class);
	$promoted=$plane->promote('panel_1','canary',$health,$request,'one-production');$t->same('production',$promoted['ring']);$t->throws(static fn()=>$plane->promote('panel_1','production',$health,$request,'no-next'),OutOfBoundsException::class);
	$plane->deploy('panel_2','canary',$health,$request,'two-canary');$rolled=$plane->rollback('canary',$request,'rollback');$t->same('panel_1',$rolled['artifact_id']);$t->isTrue($plane->rollback('canary',$request,'rollback')['replayed']);$t->throws(static fn()=>$plane->rollback('production',$request,'rollback-prod'),LogicException::class);
	$plane->pause('production',true,$request);$t->throws(static fn()=>$plane->deploy('panel_2','production',$health,$request,'paused'),LogicException::class);$plane->pause('production',false,$request);
	$plane->flag('new_board',['off'=>5000,'on'=>5000],['market'=>'ca'],$request);$variant=$plane->evaluateFlag('new_board','User:1',['market'=>'ca']);$t->isTrue(in_array($variant,['off','on'],true));$t->same($variant,$plane->evaluateFlag('new_board','User:1',['market'=>'ca']));$t->same('off',$plane->evaluateFlag('new_board','User:1',['market'=>'us']));
	$t->same(2,$plane->jsonSerialize()['artifact_count']);$reopened=new PanelReleaseControlPlane($root,['primary'=>$key],$policy,static fn():string=>$now);$t->same(2,$reopened->jsonSerialize()['artifact_count']);
	$corrupt=$one->jsonSerialize();$corrupt['digest']=str_repeat('0',64);$t->throws(static fn()=>PanelReleaseArtifact::hydrate($corrupt),UnexpectedValueException::class);$t->throws(static fn()=>$plane->flag('bad',['on'=>1],[],$request),InvalidArgumentException::class);$t->throws(static fn()=>$plane->evaluateFlag('missing','User:1'),OutOfBoundsException::class);
})->tag('panel','operations-os','releases','rings','rollback')->isolation('case')->maxMillis(10000);

test('local-first replicas merge vector clocks retain conflict evidence reject replay and restore checkpoints',static function(Context $t):void{
	$key=str_repeat('s',32);$allow=static fn():bool=>true;$a=new PanelLocalReplica('Actor:A',['primary'=>$key],'primary',$allow,static fn():string=>'2026-07-16T12:00:00Z');$b=new PanelLocalReplica('Actor:B',['primary'=>$key],'primary',$allow,static fn():string=>'2026-07-16T12:00:01Z');
	$a->change('Order:1',[['op'=>'set','path'=>'profile.name','value'=>'Alpha'],['op'=>'set','path'=>'total','value'=>10]]);$b->change('Order:1',[['op'=>'set','path'=>'profile.name','value'=>'Beta']]);$fromA=$a->envelope();$fromB=$b->envelope(['Order:1']);
	$t->isTrue($fromA->verify(['primary'=>$key]));$t->same($fromA->digest(),PanelSyncEnvelope::hydrate($fromA->jsonSerialize())->digest());$mergeA=$a->merge($fromB);$mergeB=$b->merge($fromA);$t->same(1,$mergeA['new_conflicts']);$t->same(1,$mergeB['new_conflicts']);$t->same('Beta',$a->document('Order:1')?->get('profile.name'));$t->same('Beta',$b->document('Order:1')?->get('profile.name'));
	$t->throws(static fn()=>$a->merge($fromB),LogicException::class);$b->change('Order:1',[['op'=>'delete','path'=>'profile.name']]);$a->merge($b->envelope());$t->same(null,$a->document('Order:1')?->get('profile.name'));$t->same(['total'=>10],$a->document('Order:1')?->materialize());
	$checkpoint=$a->checkpoint();$restored=(new PanelLocalReplica('Actor:A',['primary'=>$key],'primary',$allow))->restore($checkpoint);$t->same($a->document('Order:1')?->fingerprint(),$restored->document('Order:1')?->fingerprint());$t->same(1,$restored->jsonSerialize()['document_count']);
	$document=PanelSyncDocument::empty('Doc:1')->apply('Actor:A',[['path'=>'name','value'=>'One']]);$t->same($document->fingerprint(),PanelSyncDocument::restore($document->jsonSerialize())->fingerprint());$t->throws(static fn()=>$document->merge(PanelSyncDocument::empty('Other:1')),InvalidArgumentException::class);$t->throws(static fn()=>$document->apply('Actor:A',[]),InvalidArgumentException::class);
	$corrupt=$fromA->jsonSerialize();$corrupt['digest']=str_repeat('0',64);$t->throws(static fn()=>PanelSyncEnvelope::hydrate($corrupt),UnexpectedValueException::class);$bad=$checkpoint;$bad['sequence']=-1;$t->throws(static fn()=>(new PanelLocalReplica('Actor:A',['primary'=>$key],'primary',$allow))->restore($bad),UnexpectedValueException::class);
	$wrong=new PanelLocalReplica('Actor:C',['primary'=>str_repeat('x',32)],'primary',$allow);$t->throws(static fn()=>$wrong->merge($fromA),LogicException::class);$denied=new PanelLocalReplica('Actor:D',['primary'=>$key],'primary');$t->throws(static fn()=>$denied->change('Doc:1',[['path'=>'name','value'=>'x']]),LogicException::class);
})->tag('panel','operations-os','local-first','crdt','replay')->isolation('case')->maxMillis(7000);

test('marketplace governance requires trust provenance compatibility sandboxing and independent approval',static function(Context $t):void{
	$policy=dp_panel_os_cp_policy();$governance=new PanelMarketplaceGovernance($policy,2,['filesystem.*','secrets.read'],static fn():string=>'2026-07-16T12:00:00Z');$package=PanelPackageManifest::make('order_tools')->version('1.0.0');$trusted=new PanelPackageTrustReport([],['trusted'=>1,'blocked'=>0]);$request=dp_panel_os_cp_request('marketplace.review');
	$submission=['permissions'=>['orders.read'],'sbom'=>[['name'=>'library','version'=>'1.0.0']],'provenance'=>['builder'=>'ci','source_digest'=>hash('sha256','source')],'compatibility'=>['passed'=>true],'network_allowlist'=>['api.example.test'],'token'=>'super-private-token-value'];
	$review=$governance->review($package,$trusted,$submission,$request);$t->same('candidate',$review->jsonSerialize()['status']);$t->same(0,$review->riskScore());$t->throws(static fn()=>$governance->approve($review,['Reviewer:1'],$request),LogicException::class);$t->throws(static fn()=>$governance->approve($review,['Operator:1','Reviewer:1'],$request),LogicException::class);
	$approved=$governance->approve($review,['Reviewer:1','Reviewer:2'],$request);$t->same('approved',$approved->jsonSerialize()['status']);$activation=$governance->activation('order_tools');$t->same(false,$activation['sandbox']['secret_access']);$t->same(['orders.read'],$activation['permissions']);$t->notContains('super-private-token-value',json_encode($governance,JSON_THROW_ON_ERROR));
	$critical=$governance->review($package,$trusted,array_replace($submission,['permissions'=>['filesystem.read']]),$request);$t->same('rejected',$critical->jsonSerialize()['status']);$t->throws(static fn()=>$critical->approve(['Reviewer:1','Reviewer:2']),LogicException::class);
	$quarantine=$governance->review($package,$trusted,array_replace($submission,['sbom'=>[['name'=>'a','version'=>'1','vulnerability_severity'=>'high'],['name'=>'b','version'=>'1','vulnerability_severity'=>'high']]]),$request);$t->same('quarantined',$quarantine->jsonSerialize()['status']);
	$untrusted=new PanelPackageTrustReport([],['trusted'=>0,'blocked'=>1]);$rejected=$governance->review($package,$untrusted,$submission,$request);$t->same('rejected',$rejected->jsonSerialize()['status']);$t->throws(static fn()=>$governance->activation('missing'),LogicException::class);
	$t->throws(static fn()=>new PanelMarketplaceGovernance($policy,0),InvalidArgumentException::class);$t->throws(static fn()=>new PanelMarketplaceReview('bad','1.0.0','bad','candidate',0,[],[],[],'2026-07-16T12:00:00.000000Z',2),InvalidArgumentException::class);
})->tag('panel','operations-os','marketplace','supply-chain')->isolation('case')->maxMillis(7000);

test('Studio branches provide optimistic commits path conflicts independent review and checkpoint restore',static function(Context $t):void{
	$clock=static fn():string=>'2026-07-16T12:00:00Z';$manager=new PanelStudioBranchManager(static fn():bool=>true,$clock,1);$document=PanelStudioDocument::make('tenant-one','seller-editor','Seller editor');$base=PanelStudioDefinition::from(['kind'=>'page','key'=>'root','properties'=>['label'=>'Base'],'children'=>[]]);
	$manager->initialize($document,$base,'Developer:1')->branch('tenant-one','seller-editor','feature','main','Developer:1');$feature=PanelStudioDefinition::from(['kind'=>'page','key'=>'root','properties'=>['label'=>'Feature'],'children'=>[]]);$main=PanelStudioDefinition::from(['kind'=>'page','key'=>'root','properties'=>['label'=>'Main'],'children'=>[]]);
	$commit=$manager->commit('tenant-one','seller-editor','feature',$feature,$base->hash(),'Developer:1','Feature label');$t->same(1,$commit['version']);$manager->commit('tenant-one','seller-editor','main',$main,$base->hash(),'Developer:2','Main label');
	$manual=$manager->merge('tenant-one','seller-editor','feature','main','manual','Developer:1',['Reviewer:1']);$t->instanceOf(PanelStudioMergeResult::class,$manual);$t->isFalse($manual->resolved());$t->isTrue(count($manual->conflicts())>=1);$t->same(['Reviewer:1'],$manual->approvers());
	$t->same($manual->fingerprint(),$manual->jsonSerialize()['fingerprint']);$t->same('feature',$manual->jsonSerialize()['source_branch']);
	$resolved=$manager->merge('tenant-one','seller-editor','feature','main','theirs','Developer:1',['Reviewer:1']);$t->isTrue($resolved->resolved());$t->same('Feature',$manager->head('tenant-one','seller-editor')->root()['properties']['label']);$t->same(2,count($manager->branches('tenant-one','seller-editor')));
	$checkpoint=$manager->checkpoint();$restored=(new PanelStudioBranchManager(static fn():bool=>true,$clock,1))->restore($checkpoint);$t->same($manager->head('tenant-one','seller-editor')->hash(),$restored->head('tenant-one','seller-editor')->hash());$t->same(2,$restored->jsonSerialize()['branch_count']);
	$t->throws(static fn()=>$manager->commit('tenant-one','seller-editor','feature',$main,$base->hash(),'Developer:1','Stale'),LogicException::class);$t->throws(static fn()=>$manager->merge('tenant-one','seller-editor','feature','main','ours','Developer:1',['Developer:1']),LogicException::class);$t->throws(static fn()=>$manager->branch('tenant-one','seller-editor','main','main','Developer:1'),InvalidArgumentException::class);
	$bad=$checkpoint;$bad['fingerprint']=str_repeat('0',64);$t->throws(static fn()=>(new PanelStudioBranchManager(static fn():bool=>true,$clock,1))->restore($bad),UnexpectedValueException::class);$denied=new PanelStudioBranchManager();$t->throws(static fn()=>$denied->initialize(PanelStudioDocument::make('tenant','doc','Doc'),$base,'Developer:1'),LogicException::class);
})->tag('panel','operations-os','studio','branches','merge')->isolation('case')->maxMillis(7000);
