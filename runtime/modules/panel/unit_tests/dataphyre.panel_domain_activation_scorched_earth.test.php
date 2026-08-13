<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\AutomationExecutor;
use Dataphyre\Panel\AutomationRegistry;
use Dataphyre\Panel\InMemoryAutomationStore;
use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\PanelAgentToolCatalog;
use Dataphyre\Panel\PanelDomainActivationApproval;
use Dataphyre\Panel\PanelDomainActivationPlan;
use Dataphyre\Panel\PanelDomainActivationReceipt;
use Dataphyre\Panel\PanelDomainActivationRuntime;
use Dataphyre\Panel\PanelDomainCommandContextResolver;
use Dataphyre\Panel\PanelDomainCommandDefinition;
use Dataphyre\Panel\PanelDomainCommandExecutor;
use Dataphyre\Panel\PanelDomainCommandInvocation;
use Dataphyre\Panel\PanelDomainCompilation;
use Dataphyre\Panel\PanelDomainCompiler;
use Dataphyre\Panel\PanelDomainMaterialization;
use Dataphyre\Panel\PanelDomainMaterializer;
use Dataphyre\Panel\PanelDomainMigrationExecutor;
use Dataphyre\Panel\PanelDomainRuntimeHost;
use Dataphyre\Panel\PanelFilesystemDomainActivationStore;
use Dataphyre\Panel\PanelInMemoryDomainActivationStore;
use Dataphyre\Panel\PanelLineageGraph;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelOperationsOs;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelRequestDomainCommandContextResolver;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelSemanticCatalog;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\WorkflowActor;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_activation_domain(string $version='1.0.0',bool $extraField=false):array {
	$fields=[
		'id'=>['type'=>'uuid','required'=>true,'nullable'=>false,'searchable'=>true,'sortable'=>true],
		'status'=>['type'=>'enum','enum'=>['open','review','closed'],'searchable'=>true,'sortable'=>true],
		'total'=>['type'=>'money','sortable'=>true],
	];
	if($extraField){$fields['priority']=['type'=>'integer','sortable'=>true];}
	return[
		'id'=>'commerce','version'=>$version,'label'=>'Commerce operations','entities'=>['order'=>['primary_key'=>'id','states'=>['open','review','closed'],'fields'=>$fields]],
		'policies'=>['operate'=>['effect'=>'allow','abilities'=>['domain.commerce.*'],'priority'=>100]],
		'commands'=>[
			'review'=>['entity'=>'order','operation'=>'review','risk'=>'high','reversible'=>true,'approval'=>1,'policy'=>'operate'],
			'close'=>['entity'=>'order','operation'=>'close','risk'=>'medium','policy'=>'operate'],
		],
		'workflows'=>['order_lifecycle'=>['entity'=>'order','initial'=>'open','states'=>['open','review','closed'],'transitions'=>[
			['name'=>'request_review','from'=>'open','to'=>'review','command'=>'review'],['name'=>'finish','from'=>'review','to'=>'closed','command'=>'close'],
		]]],
		'metrics'=>['order_count'=>['entity'=>'order','aggregation'=>'count','dimensions'=>['status']]],
		'queues'=>['review_queue'=>['entity'=>'order','states'=>['review']]],
		'surfaces'=>['orders'=>['kind'=>'resource','entity'=>'order']],
		'agents'=>['operator'=>['commands'=>['close']]],
	];
}

/** @return array<string,mixed> */
function dp_panel_activation_fixture(?PanelInMemoryDomainActivationStore $store=null,?object $migrationState=null):array {
	$domainKey=str_repeat('D',32);$activationKey=str_repeat('A',32);$approvalKey=str_repeat('R',32);$policyKey=str_repeat('P',32);$id='primary';
	$policy=new PanelPolicyControlPlane([$id=>$policyKey],true);$registry=new AutomationRegistry();$automation=new AutomationExecutor($registry,new InMemoryAutomationStore());$workflows=new WorkflowEngine(new InMemoryWorkflowStore());$agents=new PanelAgentToolCatalog();$semantics=new PanelSemanticCatalog();$lineage=new PanelLineageGraph();$manager=new PanelManager();
	$commandExecutor=new class implements PanelDomainCommandExecutor {public function execute(PanelDomainCommandInvocation $invocation):mixed{return['command'=>$invocation->command()->qualifiedName(),'actor'=>$invocation->actorId()];}};
	$resolver=new class implements PanelDomainCommandContextResolver {public function resolve(PanelDomainCommandDefinition $command,mixed $record,array $data,mixed $request,?Resource $resource):PanelDomainCommandInvocation{return new PanelDomainCommandInvocation($command,'tenant','actor','action-key',$data,'record',false,true);}};
	$materializer=new PanelDomainMaterializer($policy,$commandExecutor,$resolver);
	$host=new PanelDomainRuntimeHost($workflows,$registry,$automation,$policy,$agents,$semantics,$lineage,[$id=>$policyKey],$id,[$manager]);
	$store??=new PanelInMemoryDomainActivationStore();$migrationState??=(object)['migrated'=>0,'compensated'=>0];
	$migrations=new class($migrationState) implements PanelDomainMigrationExecutor {public function __construct(private object $state){}public function migrate(PanelDomainActivationPlan $plan,?PanelDomainCompilation $from,?PanelDomainCompilation $to):array{$this->state->migrated++;return['adapter'=>'test','step_count'=>count($plan->migrationSteps())];}public function compensate(PanelDomainActivationPlan $plan,array $receipt,?PanelDomainCompilation $from,?PanelDomainCompilation $to):void{$this->state->compensated++;}};
	$nonce=0;$runtime=new PanelDomainActivationRuntime(new PanelDomainCompiler(),$materializer,$host,$store,[$id=>$domainKey],[$id=>$activationKey],$id,[$id=>$approvalKey],$id,$migrations,static fn()=>new DateTimeImmutable('2026-07-16T12:00:00Z'),static function()use(&$nonce):string{return'nonce'.(++$nonce);});
	return compact('runtime','host','store','manager','workflows','registry','automation','policy','agents','semantics','lineage','materializer','domainKey','activationKey','approvalKey','policyKey','id','migrationState');
}

/** @param array<string,mixed> $fixture */
function dp_panel_activation_compile(array $fixture,string $version='1.0.0',bool $extraField=false):PanelDomainCompilation {
	return(new PanelDomainCompiler())->compile(dp_panel_activation_domain($version,$extraField))->sign($fixture['id'],$fixture['domainKey']);
}

test('domain activation materializes every native runtime atomically with signed replayable receipts',static function(Context $t):void {
	$fixture=dp_panel_activation_fixture();$runtime=$fixture['runtime'];$compiled=dp_panel_activation_compile($fixture);$plan=$runtime->preview($compiled);
	$t->same(0,$plan->approvalCount());$receipt=$runtime->activate($compiled,'deployer','install-1',$plan);$t->instanceOf(PanelDomainActivationReceipt::class,$receipt);$t->same(1,$receipt->revision());$t->isTrue($receipt->verify([$fixture['id']=>$fixture['activationKey']]));
	$t->isTrue($fixture['manager']->has('commerce.order'));$t->instanceOf(Resource::class,$fixture['manager']->get('commerce.order'));$t->isTrue($fixture['workflows']->definition('commerce_order_lifecycle')!==null);$t->isTrue($fixture['registry']->has('commerce_close'));$t->isTrue($fixture['agents']->has('commerce.close'));$t->same('commerce.order_count',$fixture['semantics']->metric('commerce.order_count')->id());$t->isTrue($fixture['lineage']->has('commerce:entity:order'));$t->isFalse($runtime->drift('commerce')['drifted']);
	$replay=$runtime->activate($compiled,'deployer','install-1',$plan);$t->same($receipt->digest(),$replay->digest());$t->same(1,$runtime->revision());$t->throws(static fn()=>$runtime->activate($compiled,'different','install-1',$plan),LogicException::class);
});

test('activation gates upgrades, executes structural migrations, detects drift, and reconciles independently',static function(Context $t):void {
	$state=(object)['migrated'=>0,'compensated'=>0];$fixture=dp_panel_activation_fixture(null,$state);$runtime=$fixture['runtime'];$v1=dp_panel_activation_compile($fixture);$runtime->activate($v1,'deployer','v1');
	$v2=dp_panel_activation_compile($fixture,'2.0.0',true);$plan=$runtime->preview($v2);$t->same(1,$plan->approvalCount());$t->isTrue(count($plan->migrationSteps())>0);$t->throws(static fn()=>$runtime->activate($v2,'deployer','v2',$plan),LogicException::class);$approval=$runtime->issueApproval($plan,'reviewer');$receipt=$runtime->activate($v2,'deployer','v2',$plan,[$approval],1);$t->same(2,$receipt->revision());$t->same(1,$state->migrated);
	$fixture['workflows']->unregister('commerce_order_lifecycle');$t->isTrue($runtime->drift('commerce')['drifted']);$reconcile=$runtime->preview($v2,'reconcile');$approval=$runtime->issueApproval($reconcile,'reviewer');$runtime->reconcile('commerce','deployer','reconcile-1',$reconcile,[$approval],2);$t->isFalse($runtime->drift('commerce')['drifted']);$t->isTrue($fixture['workflows']->definition('commerce_order_lifecycle')!==null);
});

test('version rollback, deactivation, restart recovery, and signed contract hydration remain fail closed',static function(Context $t):void {
	$fixture=dp_panel_activation_fixture();$runtime=$fixture['runtime'];$v1=dp_panel_activation_compile($fixture);$runtime->activate($v1,'deployer','v1');$store=$fixture['store'];
	$restarted=dp_panel_activation_fixture($store);$t->same($v1->digest(),$restarted['runtime']->activeCompilation('commerce')?->digest());$t->isTrue($restarted['manager']->has('commerce.order'));$t->isFalse($restarted['runtime']->drift('commerce')['drifted']);
	$deactivation=$restarted['runtime']->previewDeactivation('commerce');$t->same(2,$deactivation->approvalCount());$a=$restarted['runtime']->issueApproval($deactivation,'reviewer-a');$b=$restarted['runtime']->issueApproval($deactivation,'reviewer-b');$receipt=$restarted['runtime']->deactivate('commerce','deployer','off-1',$deactivation,[$a,$b],1);$t->same('deactivate',$receipt->operation());$t->isFalse($restarted['manager']->has('commerce.order'));$t->same(null,$restarted['runtime']->activeCompilation('commerce'));$t->same($v1->digest(),$restarted['runtime']->compilationAt('commerce','1.0.0')->digest());
	$t->same($deactivation->digest(),PanelDomainActivationPlan::hydrate($deactivation->jsonSerialize())->digest());$t->same($a->digest(),PanelDomainActivationApproval::hydrate($a->jsonSerialize())->digest());$t->same($receipt->digest(),PanelDomainActivationReceipt::hydrate($receipt->jsonSerialize())->digest());$corrupt=$receipt->jsonSerialize();$corrupt['digest']=str_repeat('0',64);$t->throws(static fn()=>PanelDomainActivationReceipt::hydrate($corrupt),UnexpectedValueException::class);
});

test('operations os persists activated domains and panel surfaces attach generated resources transactionally',static function(Context $t):void {
	$root=$t->tempDirectory('panel-domain-activation-os');$config=['master_key'=>str_repeat('M',48),'domains'=>[dp_panel_activation_domain()],'activate_domains'=>true];$os=PanelOperationsOs::fromConfig($root,$config);$t->same(1,$os->activation()->revision());$t->same($os->compilation('commerce')->digest(),$os->activation()->activeCompilation('commerce')?->digest());
	$platform=PanelPlatform::make(['operations_os.runtime'=>$os,'operations_os.activation'=>$os->activation(),'operations_os.command_fabric'=>$os->commandFabric()]);$panel=PanelInstance::make('domain-surface')->usePlatform($platform);$t->same($os->activation(),$panel->domainActivation());$t->same($os->commandFabric(),$panel->commandFabric());$t->isTrue($panel->manager()->has('commerce.order'));$panel->withoutPlatform();$t->isFalse($panel->manager()->has('commerce.order'));
	$restarted=PanelOperationsOs::fromConfig($root,$config);$t->same(1,$restarted->activation()->revision());$t->same($os->compilation('commerce')->digest(),$restarted->compilation('commerce')->digest());$t->isFalse($restarted->activation()->drift('commerce')['drifted']);$t->same('panel_domain_activation_runtime_manifest',$restarted->activation()->jsonSerialize()['type']);
});

test('panel platform replacement restores the previous activation attachment when the candidate collides',static function(Context $t):void {
	$previous=dp_panel_activation_fixture();$previousCompilation=dp_panel_activation_compile($previous);$previous['runtime']->activate($previousCompilation,'deployer','previous-domain');
	$candidate=dp_panel_activation_fixture();$candidateManifest=dp_panel_activation_domain();$candidateManifest['id']='candidate';$candidateCompilation=(new PanelDomainCompiler())->compile($candidateManifest)->sign($candidate['id'],$candidate['domainKey']);$candidate['runtime']->activate($candidateCompilation,'deployer','candidate-domain');
	$previousPlatform=PanelPlatform::make(['operations_os.activation'=>$previous['runtime']]);$candidatePlatform=PanelPlatform::make(['operations_os.activation'=>$candidate['runtime']]);$panel=PanelInstance::make('activation-replacement-rollback');$panel->register(Resource::make('candidate.order'));
	$panel->usePlatform($previousPlatform);$t->isTrue($panel->manager()->has('commerce.order'));$t->same(1,$panel->platformState()['revision']);
	$t->throws(static fn()=>$panel->replacePlatform($candidatePlatform),LogicException::class);
	$t->same($previousPlatform,$panel->platform());$t->same(1,$panel->platformState()['revision']);$t->isTrue($panel->manager()->has('commerce.order'));$t->isTrue($panel->manager()->has('candidate.order'));
})->tag('panel','domain-activation','platform','replacement','rollback','atomicity')->isolation('case')->maxMillis(12000);

test('request domain command contexts derive only trusted tenant actor record and transport state',static function(Context $t):void {
	$resolver=new PanelRequestDomainCommandContextResolver();
	$command=PanelDomainCommandDefinition::from('commerce','1.0.0','close',[
		'entity'=>'order','operation'=>'close','policy'=>'operate',
		'input'=>['reason'=>['type'=>'string']],
	]);
	$resource=Resource::make('commerce.order');
	$request=PanelRequest::fromArray([
		'method'=>'post','operation'=>'action','tenant'=>'tenant-one','user'=>PanelSecurityContext::make('operator-one'),
		'headers'=>['Idempotency-Key'=>'request-key'],'input'=>['_confirmed'=>true],
	]);
	$invocation=$resolver->resolve($command,['id'=>'order-one'],['reason'=>'complete','_idempotency_key'=>'ignored','_confirmed'=>true],$request,$resource);
	$t->same('tenant-one',$invocation->tenantId());$t->same('operator-one',$invocation->actorId());$t->same('request-key',$invocation->idempotencyKey());$t->same('order-one',$invocation->recordId());$t->same(['reason'=>'complete'],$invocation->input());$t->isTrue($invocation->confirmed());$t->same('commerce.order',$invocation->context()['panel_resource']);$t->same('POST',$invocation->context()['http_method']);
	$t->same('panel_request_domain_command_context_resolver',$resolver->jsonSerialize()['type']);

	$t->throws(static fn()=>$resolver->resolve($command,null,[],new stdClass(),null),LogicException::class);
	$t->throws(static fn()=>$resolver->resolve($command,null,[],PanelRequest::fromArray(['user'=>'actor','headers'=>['idempotency-key'=>'key']]),null),LogicException::class);
	$t->throws(static fn()=>$resolver->resolve($command,null,[],PanelRequest::fromArray(['tenant'=>'tenant','headers'=>['idempotency-key'=>'key']]),null),LogicException::class);
	$t->throws(static fn()=>$resolver->resolve($command,null,[],PanelRequest::fromArray(['tenant'=>'tenant','user'=>'actor']),null),LogicException::class);

	$internals=$t->nonPublic($resolver);
	$t->same('security',$internals->invoke('actor',PanelSecurityContext::make('security')));
	$t->same('workflow',$internals->invoke('actor',WorkflowActor::from('workflow')));
	$t->same('42',$internals->invoke('actor',42));
	$t->same('array-actor',$internals->invoke('actor',['actor_id'=>'array-actor']));
	$t->same('auth-method',$internals->invoke('actor',new class {public function getAuthIdentifier():string{return'auth-method';}}));
	$t->same('id-method',$internals->invoke('actor',new class {public function id():string{return'id-method';}}));
	$t->throws(static fn()=>$internals->invoke('actor',null),LogicException::class);
	$t->throws(static fn()=>$internals->invoke('actor',' '),LogicException::class);

	$routeRecord=PanelRequest::fromArray(['record'=>'route-record']);$emptyRequest=PanelRequest::fromArray([]);
	$t->same('route-record',$internals->invoke('recordId',['id'=>'array-record'],$routeRecord));
	$t->same('array-record',$internals->invoke('recordId',['id'=>'array-record'],$emptyRequest));
	$t->same('scalar-record',$internals->invoke('recordId','scalar-record',$emptyRequest));
	$t->same(null,$internals->invoke('recordId',new stdClass(),$emptyRequest));
});

test('domain command and materialization contracts remain inspectable executable and integrity bound',static function(Context $t):void {
	$fixture=dp_panel_activation_fixture();$compiled=dp_panel_activation_compile($fixture);$materializer=$fixture['materializer'];$materialization=$materializer->materialize($compiled);
	$command=$materialization->commands()['close'];$t->same('operate',$command->policy());$t->same([],$command->input());$t->same([],$command->effects());$t->same([],$command->metadata());$t->isTrue(isset($command->inputSchema()['properties']));
	$t->throws(static fn()=>PanelDomainCommandDefinition::hydrate([]),UnexpectedValueException::class);$t->same($command->fingerprint(),PanelDomainCommandDefinition::hydrate($command->jsonSerialize())->fingerprint());
	$corrupt=$command->jsonSerialize();$corrupt['qualified_name']='commerce.wrong';$t->throws(static fn()=>PanelDomainCommandDefinition::hydrate($corrupt),UnexpectedValueException::class);$corrupt=$command->jsonSerialize();$corrupt['ability']='domain.commerce.wrong';$t->throws(static fn()=>PanelDomainCommandDefinition::hydrate($corrupt),UnexpectedValueException::class);
	$invocation=new PanelDomainCommandInvocation($command,'tenant','actor','secret-idempotency',['reason'=>'done'],'order-one',false,true,['token'=>'secret']);
	$t->same(64,strlen($invocation->fingerprint()));$serialized=$invocation->jsonSerialize();$t->same(hash('sha256','secret-idempotency'),$serialized['idempotency_key_hash']);$t->isFalse(isset($serialized['idempotency_key']));
	$t->same(['close','review'],array_keys($materialization->commands()));$t->isTrue(isset($materialization->queues()['review_queue']));$t->isTrue(isset($materialization->surfaces()['orders']));$t->isTrue($materialization->commandsExecutable());$t->same('panel_domain_materialization_manifest',$materialization->jsonSerialize()['type']);$t->same('panel_domain_materializer_manifest',$materializer->jsonSerialize()['type']);

	$fixture['host']->activate($materialization);
	$result=$t->nonPublic($materializer)->invoke('execute',$invocation);$t->same('commerce.close',$result['command']);
	$approvalInvocation=new PanelDomainCommandInvocation($materialization->commands()['review'],'tenant','actor','approval-key',[],'order-one',false,true);
	$t->throws(fn()=>$t->nonPublic($materializer)->invoke('execute',$approvalInvocation),LogicException::class);
	$highRisk=PanelDomainCommandDefinition::from('commerce','1.0.0','urgent_close',['entity'=>'order','operation'=>'close','risk'=>'high','policy'=>'operate']);
	$unconfirmed=new PanelDomainCommandInvocation($highRisk,'tenant','actor','confirm-key',[],'order-one');
	$t->throws(fn()=>$t->nonPublic($materializer)->invoke('execute',$unconfirmed),LogicException::class);
	$t->throws(fn()=>$t->nonPublic(new PanelDomainMaterializer())->invoke('execute',$invocation),LogicException::class);
});

test('domain runtime host restores exact checkpoints and rolls back partial signed policy contributions',static function(Context $t):void {
	$fixture=dp_panel_activation_fixture();$materialization=$fixture['materializer']->materialize(dp_panel_activation_compile($fixture));$host=$fixture['host'];$host->activate($materialization);
	$t->same($materialization,$host->active('commerce'));$t->same(['commerce'],array_keys($host->activeDomains()));$checkpoint=$host->checkpoint();
	$t->throws(static fn()=>$host->restore([]),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['active']=['wrong'=>$materialization];$t->throws(static fn()=>$host->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['workflows']=null;$t->throws(static fn()=>$host->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['policy']=null;$t->throws(static fn()=>$host->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['policy']='invalid';$t->throws(static fn()=>$host->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['agents']='invalid';$t->throws(static fn()=>$host->restore($invalid),InvalidArgumentException::class);
	$invalid=$checkpoint;$invalid['managers']=['999'=>['manager'=>$fixture['manager'],'checkpoint'=>[]]];$t->throws(static fn()=>$host->restore($invalid),InvalidArgumentException::class);
	$host->restore($checkpoint);$t->same($materialization,$host->active('commerce'));

	$failing=dp_panel_activation_fixture();$original=$failing['materializer']->materialize(dp_panel_activation_compile($failing));$foreign=$original->policyBundle()?->sign('foreign',str_repeat('X',32));
	$untrusted=new PanelDomainMaterialization($original->compilation(),$failing['materializer']->fingerprint(),$original->resources(),$original->workflows(),$original->commands(),$original->automationActions(),$foreign,$original->agentTools(),$original->queues(),$original->surfaces(),true);
	$t->throws(static fn()=>$failing['host']->activate($untrusted),LogicException::class);$t->same([],$failing['host']->activeDomains());$t->isFalse($failing['manager']->has('commerce.order'));
});

test('activation rollback history and structural migration compensation preserve prior state',static function(Context $t):void {
	$state=(object)['migrated'=>0,'compensated'=>0];$fixture=dp_panel_activation_fixture(null,$state);$runtime=$fixture['runtime'];$v1=dp_panel_activation_compile($fixture);$runtime->activate($v1,'deployer','v1');
	$v2=dp_panel_activation_compile($fixture,'2.0.0',true);$upgrade=$runtime->preview($v2);$approval=$runtime->issueApproval($upgrade,'reviewer');$runtime->activate($v2,'deployer','v2',$upgrade,[$approval],1);$t->same(['1.0.0','2.0.0'],array_map(static fn(PanelDomainCompilation $item):string=>$item->domainVersion(),$runtime->history('commerce')));
	$rollback=$runtime->preview($v1,'rollback');$approvals=[];for($index=0;$index<$rollback->approvalCount();$index++){$approvals[]=$runtime->issueApproval($rollback,'rollback-reviewer-'.$index);}$receipt=$runtime->rollback('commerce','1.0.0','deployer','rollback-v1',$rollback,$approvals,2);$t->same('rollback',$receipt->operation());$t->same('1.0.0',$runtime->activeCompilation('commerce')?->domainVersion());

	$failureState=(object)['migrated'=>0,'compensated'=>0];$failure=dp_panel_activation_fixture(null,$failureState);$failureRuntime=$failure['runtime'];$base=dp_panel_activation_compile($failure);$failureRuntime->activate($base,'deployer','base');
	$nextDomain=dp_panel_activation_domain('2.0.0',true);$nextDomain['entities']['shipment']=['primary_key'=>'id','states'=>[],'fields'=>['id'=>['type'=>'uuid','required'=>true,'nullable'=>false]]];
	$next=(new PanelDomainCompiler())->compile($nextDomain)->sign($failure['id'],$failure['domainKey']);$plan=$failureRuntime->preview($next);$failure['manager']->register(Resource::make('commerce.shipment'));
	$approvals=[];for($index=0;$index<$plan->approvalCount();$index++){$approvals[]=$failureRuntime->issueApproval($plan,'failure-reviewer-'.$index);}
	$t->throws(static fn()=>$failureRuntime->activate($next,'deployer','collision',$plan,$approvals,1),LogicException::class);$t->same(1,$failureState->migrated);$t->same(1,$failureState->compensated);$t->same($base->digest(),$failureRuntime->activeCompilation('commerce')?->digest());
});

test('domain activation stores expose bounded change feeds and redacted manifests',static function(Context $t):void {
	$memory=new PanelInMemoryDomainActivationStore();$memory->transaction(static function(array &$state):string{$state['revision']=1;return'ok';},'domain.test',['domain_id'=>'commerce']);$changes=$memory->changesSince();$t->same(1,$changes['cursor']);$t->same('domain.test',$changes['changes'][0]['type']);$t->same('panel_in_memory_domain_activation_store',$memory->jsonSerialize()['type']);
	$filesystem=new PanelFilesystemDomainActivationStore($t->tempDirectory('panel-domain-activation-store'));$filesystem->transaction(static function(array &$state):void{$state['revision']=1;},'domain.test',['domain_id'=>'commerce']);$changes=$filesystem->changesSince();$t->same(1,$changes['cursor']);$t->same('panel_filesystem_domain_activation_store',$filesystem->jsonSerialize()['type']);
});
