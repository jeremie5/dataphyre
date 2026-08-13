<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelDomainCompilation;
use Dataphyre\Panel\PanelDomainCompiler;
use Dataphyre\Panel\PanelDomainDiagnostic;
use Dataphyre\Panel\PanelDomainDiff;
use Dataphyre\Panel\PanelDomainManifest;
use Dataphyre\Panel\PanelFilesystemWorkGraphStore;
use Dataphyre\Panel\PanelInMemoryWorkGraphStore;
use Dataphyre\Panel\PanelOperationsGuard;
use Dataphyre\Panel\PanelWorkEvent;
use Dataphyre\Panel\PanelWorkGraph;
use Dataphyre\Panel\PanelWorkItem;
use Dataphyre\Panel\PanelWorkReceipt;
use Dataphyre\Panel\PanelWorkState;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_os_domain(string $version='1.0.0'):array{return[
	'id'=>'commerce','version'=>$version,'label'=>'Commerce operations','description'=>'Governed order execution.',
	'entities'=>[
		'order'=>['primary_key'=>'id','states'=>['open','review','closed'],'fields'=>[
			'id'=>['type'=>'uuid','required'=>true,'nullable'=>false,'immutable'=>true,'searchable'=>true,'sortable'=>true,'classification'=>'internal'],
			'status'=>['type'=>'enum','enum'=>['open','review','closed'],'searchable'=>true,'sortable'=>true],
			'total'=>['type'=>'money','sortable'=>true,'classification'=>'confidential'],
			'notes'=>['type'=>'text'],
			'approved'=>['type'=>'boolean'],
			'payload'=>['type'=>'json'],
			'invoice'=>['type'=>'file'],
		]],
		'customer'=>['primary_key'=>'id','fields'=>['id'=>['type'=>'uuid','required'=>true],'market'=>['type'=>'string']]],
	],
	'relationships'=>[['id'=>'order_customer','from'=>'order','to'=>'customer','type'=>'belongs_to','cardinality'=>'many_to_one']],
	'policies'=>['operate'=>['effect'=>'allow','abilities'=>['work.*'],'priority'=>10]],
	'commands'=>[
		'review'=>['entity'=>'order','operation'=>'review','risk'=>'high','reversible'=>true,'approval'=>1,'policy'=>'operate'],
		'close'=>['entity'=>'order','operation'=>'close','risk'=>'medium'],
	],
	'workflows'=>['order_lifecycle'=>['entity'=>'order','initial'=>'open','states'=>['open','review','closed'],'transitions'=>[
		['name'=>'request_review','from'=>'open','to'=>'review','command'=>'review','sla_seconds'=>3600],
		['name'=>'finish','from'=>'review','to'=>'closed','command'=>'close'],
	]]],
	'metrics'=>[
		'order_count'=>['entity'=>'order','aggregation'=>'count','dimensions'=>['status']],
		'order_value'=>['entity'=>'order','aggregation'=>'sum','field'=>'total','dimensions'=>['status']],
	],
	'queues'=>['review_queue'=>['entity'=>'order','states'=>['review'],'priority'=>80,'sla_seconds'=>3600]],
	'surfaces'=>['orders'=>['kind'=>'resource','entity'=>'order'],'reviews'=>['kind'=>'queue','entity'=>'order','queue'=>'review_queue']],
	'agents'=>['order_operator'=>['commands'=>['review','close'],'instructions'=>'Keep changes reversible.','risk_ceiling'=>'high']],
	'metadata'=>['owner'=>'operations'],
];}

test('operations guard and domain manifests normalize portable deterministic contracts',static function(Context $t):void{
	$t->same('Actor:One',PanelOperationsGuard::identifier(' Actor:One '));
	$t->same(['Actor:One','actor.two'],PanelOperationsGuard::identifiers(['actor.two','Actor:One','Actor:One']));
	$t->same(['alpha','beta'],PanelOperationsGuard::names(['Beta','alpha','beta']));
	$t->same(['*','operator.*'],PanelOperationsGuard::abilityPatterns(['operator.*','*']));
	$t->same(['*','admin'],PanelOperationsGuard::roles(['ADMIN','*']));
	$t->isTrue(PanelOperationsGuard::abilityMatches('operator.*','operator.plan'));
	$t->isFalse(PanelOperationsGuard::abilityMatches('operator.*','release.deploy'));
	$t->same('2026-07-16T12:00:00.000000Z',PanelOperationsGuard::instant('2026-07-16T08:00:00-04:00'));
	$t->same('2026-07-16T12:00:00.000000Z',PanelOperationsGuard::instant(new DateTimeImmutable('2026-07-16T12:00:00Z')));
	$t->same(['a'=>1,'b'=>2],PanelOperationsGuard::canonical(['b'=>2,'a'=>1]));
	$t->same(2,PanelOperationsGuard::valueAt(['a'=>['b'=>2]],'a.b'));
	$t->same('fallback',PanelOperationsGuard::valueAt([], 'missing','fallback'));
	$t->same(1.5,PanelOperationsGuard::finite(1.5));
	$t->same('Visible',PanelOperationsGuard::safeMetadata(['password'=>'hidden','value'=>'Visible'])['value']);
	$t->throws(static fn()=>PanelOperationsGuard::identifier('bad id'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsGuard::instant('definitely-not-an-instant'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsGuard::name('1bad'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsGuard::label("bad\0label"),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsGuard::identifiers(array_fill(0,513,'a')),LengthException::class);
	$t->throws(static fn()=>PanelOperationsGuard::object([1,2]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsGuard::finite(NAN),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsGuard::canonical(new stdClass()),InvalidArgumentException::class);

	$manifest=PanelDomainManifest::from(dp_panel_os_domain());
	$t->same('commerce',$manifest->id());$t->same('1.0.0',$manifest->version());$t->same('Commerce operations',$manifest->label());
	$t->same('uuid',$manifest->section('entities')['order']['fields']['id']['type']);
	$t->same($manifest->fingerprint(),PanelDomainManifest::from(dp_panel_os_domain())->fingerprint());
	$t->same(1,$manifest->jsonSerialize()['schema_version']);
	$t->throws(static fn()=>$manifest->section('unknown'),OutOfBoundsException::class);
	$diagnostic=new PanelDomainDiagnostic('entities.order','missing','Missing relation','warning');
	$t->same('entities.order',$diagnostic->path());$t->same('missing',$diagnostic->code());$t->same('Missing relation',$diagnostic->message());$t->same('warning',$diagnostic->severity());$t->isFalse($diagnostic->error());
	$t->same('warning',$diagnostic->jsonSerialize()['severity']);
	$t->throws(static fn()=>new PanelDomainDiagnostic('bad path','bad','Message'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDomainDiagnostic('root','bad','Message','fatal'),InvalidArgumentException::class);
	$t->same('invalid_root',PanelDomainManifest::diagnose('bad')[0]->code());
	$t->same('invalid_root',PanelDomainManifest::diagnose([1,2])[0]->code());
	$t->same('entities_required',PanelDomainManifest::diagnose(['id'=>'empty','version'=>'1.0.0'])[0]->code());
	$invalid=dp_panel_os_domain();$invalid['entities']['order']['primary_key']='missing';$invalid['relationships'][0]['to']='missing';$invalid['commands']['review']['policy']='missing';$invalid['workflows']['order_lifecycle']['initial']='missing';$invalid['workflows']['order_lifecycle']['transitions'][0]['command']='missing';$invalid['metrics']['order_count']['dimensions'][]='missing';$invalid['queues']['review_queue']['entity']='missing';$invalid['surfaces']['reviews']['queue']='missing';$invalid['agents']['order_operator']['commands'][]='missing';
	$t->isTrue(count(PanelDomainManifest::diagnose($invalid))>=9);
	$invalidType=dp_panel_os_domain();$invalidType['entities']['order']['fields']['id']['type']='quantum';$t->same('field_type_invalid',PanelDomainManifest::diagnose($invalidType)[0]->code());
	$t->throws(static fn()=>PanelDomainManifest::from($invalid),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDomainManifest::from(dp_panel_os_domain()+['unknown'=>true]),InvalidArgumentException::class);
})->tag('panel','operations-os','domain','guard','scorched-earth')->isolation('case')->maxMillis(5000);

test('domain compiler emits signed runtime artifacts and migration aware structural diffs',static function(Context $t):void{
	$compiler=new PanelDomainCompiler();$first=$compiler->compile(dp_panel_os_domain());$key=str_repeat('d',32);$signed=$first->sign('primary',$key);
	$t->same('commerce',$signed->domainId());$t->same('1.0.0',$signed->domainVersion());$t->same($first->sourceFingerprint(),$signed->sourceFingerprint());$t->same($compiler->fingerprint(),$signed->compilerFingerprint());$t->isTrue($signed->signed());$t->same('primary',$signed->keyId());$t->isTrue($signed->verify(['primary'=>$key]));$t->isFalse($signed->verify(['primary'=>str_repeat('x',32)]));
	$t->same('money',$signed->artifact('resources')['order']['columns']['total']['type']);$t->same('textarea',$signed->artifact('resources')['order']['fields']['notes']['type']);$t->same('checkbox',$signed->artifact('resources')['order']['fields']['approved']['type']);$t->same('structured',$signed->artifact('resources')['order']['fields']['payload']['type']);$t->same('file',$signed->artifact('resources')['order']['fields']['invoice']['type']);
	$t->same($signed->artifact('source'),$signed->artifacts()['source']);
	$t->isTrue($signed->artifact('runtime')['default_deny']);$t->same($signed->digest(),PanelDomainCompilation::hydrate($signed->jsonSerialize())->digest());
	$t->same($compiler->fingerprint(),$compiler->jsonSerialize()['fingerprint']);$t->isTrue(in_array('resources',$compiler->jsonSerialize()['outputs'],true));$t->same([], $compiler->diagnose(dp_panel_os_domain()));
	$t->throws(static fn()=>$signed->artifact('missing'),OutOfBoundsException::class);
	$t->throws(static fn()=>$first->sign('primary','short'),InvalidArgumentException::class);
	$corrupt=$signed->jsonSerialize();$corrupt['digest']=str_repeat('0',64);$t->throws(static fn()=>PanelDomainCompilation::hydrate($corrupt),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelDomainCompilation::hydrate([]),UnexpectedValueException::class);
	$t->throws(static fn()=>new PanelDomainCompilation('bad id','1.0.0',str_repeat('a',64),str_repeat('b',64),[]),InvalidArgumentException::class);

	$next=dp_panel_os_domain('2.0.0');unset($next['entities']['order']['fields']['notes']);$next['entities']['order']['fields']['reference']=['type'=>'string'];$next['commands']['review']['approval']=2;$next['relationships'][]=['id'=>'customer_orders','from'=>'customer','to'=>'order'];
	$second=$compiler->compile($next);$diff=$compiler->diff($first,$second);
	$t->instanceOf(PanelDomainDiff::class,$diff);$t->isTrue($diff->changed());$t->isTrue($diff->breaking());$t->same(['order'],$diff->sections()['entities']['changed']);$t->isTrue(count($diff->migrationSteps())>=3);$t->isTrue($diff->jsonSerialize()['breaking']);
	$same=$compiler->diff($first,$compiler->compile(dp_panel_os_domain()));$t->isFalse($same->changed());$t->isFalse($same->breaking());
	$other=dp_panel_os_domain();$other['id']='other';$t->throws(static fn()=>$compiler->diff($first,$compiler->compile($other)),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDomainDiff('bad',str_repeat('a',64),[],[],false),InvalidArgumentException::class);
})->tag('panel','operations-os','domain-compiler','migration','signatures')->isolation('case')->maxMillis(5000);

test('universal work graph provides queues timelines replay relationships and real undo',static function(Context $t):void{
	$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$store=new PanelInMemoryWorkGraphStore();$graph=new PanelWorkGraph($store,static fn():bool=>true,$clock,100,100);
	$one=$graph->create('tenant:One',['id'=>'case:1','type'=>'case','title'=>'Risk review','state'=>'open','priority'=>80,'queue'=>'risk','assignee'=>'Analyst:1','subject'=>['type'=>'order','id'=>'SO:1'],'due_at'=>'2026-07-16T11:00:00Z','tags'=>['urgent','vip'],'data'=>['amount'=>100,'password'=>'redacted']],'Actor:1','create-one');
	$two=$graph->create('tenant:One',['id'=>'case:2','title'=>'Routine review','priority'=>20,'queue'=>'risk','tags'=>['routine']],'Actor:1','create-two');
	$t->instanceOf(PanelWorkReceipt::class,$one);$t->same('case:1',$one->item()?->id());$t->same('order',$one->item()?->subjectType());$t->same('SO:1',$one->item()?->subjectId());$t->isTrue($one->item()?->overdue($now)??false);$t->isFalse($two->item()?->overdue($now)??true);$t->isFalse($one->replayed());$asReplay=$one->asReplay();$t->isTrue($asReplay->replayed());$t->same($asReplay,$asReplay->asReplay());
	$t->same(false,$one->jsonSerialize()['replayed']);
	$replay=$graph->create('tenant:One',['id'=>'case:1','type'=>'case','title'=>'Risk review','state'=>'open','priority'=>80,'queue'=>'risk','assignee'=>'Analyst:1','subject'=>['type'=>'order','id'=>'SO:1'],'due_at'=>'2026-07-16T11:00:00Z','tags'=>['urgent','vip'],'data'=>['amount'=>100,'password'=>'redacted']],'Actor:1','create-one');$t->isTrue($replay->replayed());
	$t->throws(static fn()=>$graph->create('tenant:One',['id'=>'case:3'],'Actor:1','create-one'),LogicException::class);
	$t->same(['case:1','case:2'],array_map(static fn(PanelWorkItem $item):string=>$item->id(),$graph->queue('tenant:One',['queue'=>'risk'])));
	$t->same(['case:1'],array_map(static fn(PanelWorkItem $item):string=>$item->id(),$graph->queue('tenant:One',['states'=>['open'],'assignee'=>'Analyst:1','subject_type'=>'order','subject_id'=>'SO:1','tags'=>['urgent'],'overdue'=>true,'search'=>'risk'])));
	$now='2026-07-16T12:01:00.000000Z';$assigned=$graph->assign('tenant:One','case:2','Analyst:2','Actor:1',1,'assign-two');$t->same(2,$assigned->item()?->version());
	$moved=$graph->move('tenant:One','case:2','priority','Actor:1',2,'move-two');$t->same('priority',$moved->item()?->queue());
	$transition=$graph->transition('tenant:One','case:2','review','Actor:1',3,'transition-two',['details'=>['reason'=>'triage']]);$t->same('review',$transition->item()?->state());
	$comment=$graph->comment('tenant:One','case:2','Needs a second look.','Actor:1','comment-two');$t->same('comment',$comment->event()->operation());$t->isFalse($comment->event()->reversible());
	$t->throws(static fn()=>$graph->undo('tenant:One',$comment->event()->id(),'Actor:1',5,'undo-comment'),LogicException::class);
	$link=$graph->link('tenant:One','case:1','case:2','blocks','Actor:1','link-one-two');$t->same(1,count($graph->links('tenant:One','case:1','out','blocks')));
	$unlink=$graph->unlink('tenant:One','case:1','case:2','blocks','Actor:1','unlink-one-two');$t->same(0,count($graph->links('tenant:One','case:2','in')));
	$undoUnlink=$graph->undo('tenant:One',$unlink->event()->id(),'Reviewer:1',$unlink->item()?->version()??0,'undo-unlink');$t->same(1,count($graph->links('tenant:One','case:1')));$t->isTrue($undoUnlink->event()->details()['restored_edge']);
	$undoLink=$graph->undo('tenant:One',$link->event()->id(),'Reviewer:1',$undoUnlink->item()?->version()??0,'undo-link');$t->same(0,count($graph->links('tenant:One','case:1')));$t->isFalse($undoLink->event()->details()['restored_edge']);
	$t->same('case:1',$graph->get('tenant:One','case:1')?->id());$t->same(null,$graph->get('tenant:One','missing'));
	$t->isTrue(count($graph->timeline('tenant:One'))>=10);$t->same('case:1',$graph->at('tenant:One','case:1',1)?->id());$t->same(null,$graph->at('tenant:One','case:1',0));$t->isTrue($graph->verifyAudit('tenant:One'));
	$sla=$graph->sla('tenant:One',$now);$t->same(2,$sla['total']);$t->same(1,$sla['overdue']);$t->same(0,$sla['unassigned']);$t->same('panel_work_graph_manifest',$graph->jsonSerialize()['type']);
	$t->same($store,$graph->store());$t->isTrue(count($store->changesSince('tenant:One')['changes'])>=10);$t->same('panel_in_memory_work_graph_store',$store->jsonSerialize()['type']);
	$t->throws(static fn()=>$graph->mutate('tenant:One','case:2','edit',[], 'Actor:1',1,'stale'),LogicException::class);
	$t->throws(static fn()=>$graph->link('tenant:One','case:1','case:1','blocks','Actor:1','self'),InvalidArgumentException::class);
	$t->throws(static fn()=>$graph->unlink('tenant:One','case:1','case:2','blocks','Actor:1','missing-edge'),OutOfBoundsException::class);
	$t->throws(static fn()=>$graph->links('tenant:One','case:1','sideways'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelWorkGraph($store,static fn():bool=>true,null,99,100),InvalidArgumentException::class);
})->tag('panel','operations-os','work-graph','undo','idempotency')->isolation('case')->maxMillis(7000);

test('work graph stores and value restoration fail closed on malformed or unauthorized state',static function(Context $t):void{
	$now='2026-07-16T12:00:00.000000Z';$item=PanelWorkItem::make('tenant:2',['id'=>'item:1','title'=>'One'],$now);$t->same($item->jsonSerialize(),PanelWorkItem::restore($item->jsonSerialize())->jsonSerialize());$next=$item->evolve(['title'=>'Two','state'=>'closed','priority'=>500,'queue'=>'done','assignee'=>'User:1','due_at'=>null,'tags'=>['done'],'data'=>['ok'=>true]],$now);$t->same(100,$next->priority());$t->same(2,$next->version());
	$t->throws(static fn()=>$item->evolve(['unknown'=>true],$now),InvalidArgumentException::class);$t->throws(static fn()=>PanelWorkItem::restore([]),UnexpectedValueException::class);
	$event=PanelWorkEvent::make(1,'tenant:2','item:1','create','Actor:1',$now,null,$item->jsonSerialize(),['created'=>true],true,'corr:1','cause:1',str_repeat('0',64));$t->isTrue($event->verify());$t->same($event->hash(),PanelWorkEvent::restore($event->jsonSerialize())->hash());$corrupt=$event->jsonSerialize();$corrupt['hash']=str_repeat('0',64);$t->throws(static fn()=>PanelWorkEvent::restore($corrupt),UnexpectedValueException::class);
	$state=PanelWorkState::empty('tenant:2');$state['items'][$item->id()]=$item->jsonSerialize();$state['events'][$event->id()]=$event->jsonSerialize();$state['sequence']=1;PanelWorkState::assert($state,'tenant:2');$bad=$state;$bad['sequence']=0;$t->throws(static fn()=>PanelWorkState::assert($bad,'tenant:2'),UnexpectedValueException::class);
	$memory=new PanelInMemoryWorkGraphStore();$memory->seed('tenant:2',$state);$t->same(1,$memory->read('tenant:2')['sequence']);
	$filesystem=new PanelFilesystemWorkGraphStore($t->tempDirectory('panel-work-graph'),16);$filesystem->transaction('tenant:2',static function(array &$working)use($item,$event):string{$working['items'][$item->id()]=$item->jsonSerialize();$working['events'][$event->id()]=$event->jsonSerialize();$working['sequence']=1;return'ok';},'seed',['item'=>'item:1']);$t->same(1,$filesystem->read('tenant:2')['sequence']);$t->same('seed',$filesystem->changesSince('tenant:2',0,10)['changes'][0]['type']);$t->same('panel_filesystem_work_graph_store',$filesystem->jsonSerialize()['type']);
	$denied=new PanelWorkGraph(new PanelInMemoryWorkGraphStore(),static fn():bool=>false,static fn():string=>$now);$t->throws(static fn()=>$denied->create('tenant:2',['id'=>'x'],'Actor:1','x'),LogicException::class);
	$brokenAuth=new PanelWorkGraph(new PanelInMemoryWorkGraphStore(),static function():bool{throw new RuntimeException('down');},static fn():string=>$now);$t->throws(static fn()=>$brokenAuth->create('tenant:2',['id'=>'x'],'Actor:1','x'),LogicException::class);
	$badClock=new PanelWorkGraph(new PanelInMemoryWorkGraphStore(),static fn():bool=>true,static fn():array=>[]);$t->throws(static fn()=>$badClock->create('tenant:2',['id'=>'x'],'Actor:1','x'),UnexpectedValueException::class);
})->tag('panel','operations-os','work-graph','storage','security')->isolation('case')->maxMillis(7000);
