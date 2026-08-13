<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelComplianceAutomation;
use Dataphyre\Panel\PanelComplianceCollectorRegistry;
use Dataphyre\Panel\PanelComplianceFrameworkCatalog;
use Dataphyre\Panel\PanelComplianceLedger;
use Dataphyre\Panel\PanelClosedLoopIntelligence;
use Dataphyre\Panel\PanelCounterfactualLab;
use Dataphyre\Panel\PanelDomainCommandExecutor;
use Dataphyre\Panel\PanelDomainCommandInvocation;
use Dataphyre\Panel\PanelDomainFabricCommandExecutor;
use Dataphyre\Panel\PanelDomainCompiler;
use Dataphyre\Panel\PanelFederationControlPlane;
use Dataphyre\Panel\PanelLineageGraph;
use Dataphyre\Panel\PanelLocalReplica;
use Dataphyre\Panel\PanelMarketplaceGovernance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelOperationsOs;
use Dataphyre\Panel\PanelOperatorModel;
use Dataphyre\Panel\PanelOperatorModelAdapter;
use Dataphyre\Panel\PanelOperatorProposal;
use Dataphyre\Panel\PanelOperatorRouter;
use Dataphyre\Panel\PanelOperatorRuntime;
use Dataphyre\Panel\PanelOperatorTask;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelProcessIntelligence;
use Dataphyre\Panel\PanelReleaseControlPlane;
use Dataphyre\Panel\PanelSemanticCatalog;
use Dataphyre\Panel\PanelStudioBranchManager;
use Dataphyre\Panel\PanelWorkGraph;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_os_platform_domain(string $version='1.0.0'):array{return[
	'id'=>'orders','version'=>$version,'label'=>'Order operations','entities'=>['order'=>['primary_key'=>'id','fields'=>[
		'id'=>['type'=>'uuid','required'=>true],'status'=>['type'=>'string'],'total'=>['type'=>'money'],
	]]],
	'commands'=>['review'=>['entity'=>'order','operation'=>'review']],
	'metrics'=>['order_count'=>['entity'=>'order','aggregation'=>'count','dimensions'=>['status']],'order_value'=>['entity'=>'order','aggregation'=>'sum','field'=>'total']],
	'surfaces'=>['orders'=>['kind'=>'resource','entity'=>'order']],
];}

/** @return array<string,mixed> */
function dp_panel_os_platform_config(string $master):array{return[
	'master_key'=>$master,
	'clock'=>static fn():string=>'2026-07-16T12:00:00Z',
	'policy_bundles'=>[['id'=>'operations','version'=>'1.0.0','rules'=>['allow'=>['effect'=>'allow','abilities'=>['*'],'priority'=>100,'reason'=>'Test operator.']]]],
	'domains'=>[dp_panel_os_platform_domain()],
	'metrics'=>['external.orders'=>['label'=>'External orders','entity'=>'order','aggregation'=>'count']],
	'federation_desired_state'=>['policy'=>hash('sha256','policy')],
	'operator_models'=>[[
		'model'=>['id'=>'configured','provider'=>'local','model'=>'governed','capabilities'=>['text','tools'],'regions'=>['ca'],'classifications'=>['internal'],'context_window'=>8192,'max_output_tokens'=>1024,'health'=>'ready'],
		'adapter'=>new class implements PanelOperatorModelAdapter {public function propose(PanelOperatorTask $task,PanelOperatorModel $model,array $toolManifest):PanelOperatorProposal|array{return['summary'=>'No mutation.','steps'=>[],'input_tokens'=>1,'output_tokens'=>1];}},
	]],
];}

/** @return array<string,false> */
function dp_panel_os_platform_disabled():array{return array_fill_keys(['operations','data','workflows','automation','authentication','notifications','media','localization','preferences','collaboration','relations','security','development','extensions','platform'],false);}

test('Operations OS composition root binds every control plane without serializing trust roots',static function(Context $t):void{
	$master='operations-os-master-key-000000000000000000000000';$os=PanelOperationsOs::fromConfig($t->tempDirectory('panel-operations-os'),dp_panel_os_platform_config($master));
	$t->instanceOf(PanelDomainCompiler::class,$os->domainCompiler());$t->instanceOf(PanelWorkGraph::class,$os->workGraph());$t->instanceOf(PanelPolicyControlPlane::class,$os->policy());$t->instanceOf(PanelOperatorRuntime::class,$os->operator());$t->instanceOf(PanelSemanticCatalog::class,$os->semantics());$t->instanceOf(PanelLineageGraph::class,$os->lineage());$t->instanceOf(PanelProcessIntelligence::class,$os->processIntelligence());$t->instanceOf(PanelClosedLoopIntelligence::class,$os->intelligence());$t->same($os->commandFabric(),$os->intelligence()->fabric());$t->same($os->policy(),$os->intelligence()->policy());$t->instanceOf(PanelCounterfactualLab::class,$os->counterfactuals());$t->instanceOf(PanelComplianceLedger::class,$os->compliance());$t->instanceOf(PanelFederationControlPlane::class,$os->federation());$t->instanceOf(PanelReleaseControlPlane::class,$os->releases());$t->instanceOf(PanelMarketplaceGovernance::class,$os->marketplace());$t->instanceOf(PanelStudioBranchManager::class,$os->studioBranches());
	$t->same('configured',$os->operator()->router()->model('configured')->id());$t->same('orders',$os->compilation('orders')->domainId());$t->same($os->compilation('orders')->digest(),$os->compilationAt('orders','1.0.0')->digest());$t->same(1,count($os->compilationHistory('orders')));$t->isTrue($os->verifyCompilation($os->compilation('orders')));
	$t->isFalse($os->diffDomains('orders','orders')->changed());
	$t->same('orders.order_count',$os->semantics()->metric('orders.order_count')->id());$t->same('external.orders',$os->semantics()->metric('external.orders')->id());$t->isTrue($os->lineage()->has('orders:entity:order'));$t->same(hash('sha256','policy'),$os->federation()->jsonSerialize()['desired_state']['policy']);
	$replica=$os->replica('Operator:1');$t->instanceOf(PanelLocalReplica::class,$replica);$t->same('One',$replica->change('Order:1',[['path'=>'name','value'=>'One']])->get('name'));
	$work=$os->workGraph()->create('Tenant:1',['id'=>'case:1','title'=>'Review'],'Operator:1','create');$t->same('case:1',$work->item()?->id());$control=$os->compliance()->registerControl('policy_review',['framework'=>'soc2'],'Operator:1');$t->same('soc2',$control['framework']);
	$status=$os->status();$t->same(1,$status['domain_history_depth']['orders']);$t->same(0,$status['intelligence_revision']);$t->isTrue($status['compliance_chain_verified']);$manifest=$os->jsonSerialize();$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$t->notContains($master,$encoded);$t->isFalse(str_contains($encoded,'operations-os-master-key'));$t->isTrue($manifest['security']['default_deny']);$t->isTrue($manifest['security']['encrypted_intelligence_evidence']);$t->isTrue($manifest['capabilities']['closed_loop_intelligence']);$t->same('panel_closed_loop_intelligence_manifest',$manifest['components']['closed_loop_intelligence']['type']);$t->same('panel_operations_os_manifest',$manifest['type']);
})->tag('panel','operations-os','composition','trust-root')->isolation('case')->maxMillis(10000);

test('domain installation retains immutable version history and produces migration-aware version diffs',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('panel-domain-history'),dp_panel_os_platform_config(str_repeat('h',48)));$first=$os->compilation('orders');$next=dp_panel_os_platform_domain('2.0.0');unset($next['metrics']['order_value']);$next['entities']['order']['fields']['reference']=['type'=>'string'];$second=$os->installDomain($next);
	$t->same('2.0.0',$os->compilation('orders')->domainVersion());$t->same(2,count($os->compilationHistory('orders')));$t->same($first->digest(),$os->compilationAt('orders','1.0.0')->digest());$t->same($second->digest(),$os->compilationAt('orders','2.0.0')->digest());$t->isTrue($os->diffDomainVersions('orders','1.0.0','2.0.0')->changed());
	$t->throws(static fn()=>$os->semantics()->metric('orders.order_value'),OutOfBoundsException::class);$t->same('orders.order_count',$os->semantics()->metric('orders.order_count')->id());$semanticRevision=$os->semantics()->revision();$lineageRevision=$os->lineage()->revision();$t->same($second->digest(),$os->installDomain($next)->digest());$t->same($semanticRevision,$os->semantics()->revision());$t->same($lineageRevision,$os->lineage()->revision());
	$mutated=$next;$mutated['label']='Illegally changed';$t->throws(static fn()=>$os->installDomain($mutated),LogicException::class);$t->same($second->digest(),$os->compilation('orders')->digest());$t->throws(static fn()=>$os->compilationAt('orders','9.0.0'),OutOfBoundsException::class);$t->throws(static fn()=>$os->compilationHistory('missing'),OutOfBoundsException::class);
})->tag('panel','operations-os','domain-history','immutability')->isolation('case')->maxMillis(10000);

test('Operations OS configuration fails closed for malformed trust adapters domains and policies',static function(Context $t):void{
	$root=static fn(string $name):string=>$t->tempDirectory($name);$master=str_repeat('m',48);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-short'),['master_key'=>'short']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig("bad\0root",['master_key'=>$master]),InvalidArgumentException::class);
	$blockedRoot=$root('os-root-file').'/state';file_put_contents($blockedRoot,'blocked');$t->throws(static fn()=>PanelOperationsOs::fromConfig($blockedRoot.'/child',['master_key'=>$master]),RuntimeException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-policy'),['master_key'=>$master,'policy'=>new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-bundles'),['master_key'=>$master,'policy_bundles'=>'bad']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-store'),['master_key'=>$master,'work_store'=>new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-router'),['master_key'=>$master,'operator_router'=>new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-models'),['master_key'=>$master,'operator_models'=>[['model'=>[],'adapter'=>new stdClass()]]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-metrics'),['master_key'=>$master,'metrics'=>['bad'=>'not-map']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-desired'),['master_key'=>$master,'federation_desired_state'=>['policy'=>'bad']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-domains'),['master_key'=>$master,'domains'=>['bad']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($root('os-keys'),['master_key'=>$master,'domain_keys'=>['trusted'=>str_repeat('d',32)],'domain_key_id'=>'missing']),InvalidArgumentException::class);
	$badClock=PanelOperationsOs::fromConfig($root('os-clock'),['master_key'=>$master,'clock'=>static fn():array=>[]]);$t->throws(static fn()=>$badClock->status(),UnexpectedValueException::class);
	$valid=PanelOperationsOs::fromConfig($root('os-constructor'),['master_key'=>$master]);$t->throws(static fn()=>new PanelOperationsOs($valid->domainCompiler(),$valid->workGraph(),$valid->policy(),$valid->operator(),$valid->semantics(),$valid->lineage(),$valid->processIntelligence(),$valid->counterfactuals(),$valid->compliance(),$valid->federation(),$valid->releases(),$valid->marketplace(),$valid->studioBranches(),['trusted'=>str_repeat('d',32)],'missing',['sync'=>str_repeat('s',32)],'sync'),InvalidArgumentException::class);
})->tag('panel','operations-os','configuration','fail-closed')->isolation('case')->maxMillis(12000);

test('platform and Panel facades expose one cohesive Operations OS dependency graph',static function(Context $t):void{
	$master='platform-master-key-000000000000000000000000000';$config=['state_root'=>$t->tempDirectory('panel-os-platform'),'operations_os'=>dp_panel_os_platform_config($master)]+dp_panel_os_platform_disabled();$platform=PanelPlatform::defaults($config);$runtime=$platform->operationsOs();
	$t->same($runtime->domainCompiler(),$platform->operationsDomainCompiler());$t->same($runtime->workGraph(),$platform->workGraph());$t->same($runtime->policy(),$platform->policyControlPlane());$t->same($runtime->operator(),$platform->operatorRuntime());$t->same($runtime->semantics(),$platform->semanticCatalog());$t->same($runtime->lineage(),$platform->lineageGraph());$t->same($runtime->processIntelligence(),$platform->processIntelligence());$t->same($runtime->intelligence(),$platform->closedLoopIntelligence());$t->same($runtime->counterfactuals(),$platform->counterfactualLab());$t->same($runtime->compliance(),$platform->complianceLedger());$t->same($runtime->complianceAutomation(),$platform->complianceAutomation());$t->same($runtime->compliance(),$platform->complianceAutomation()->ledger());$t->same($runtime->federation(),$platform->federationControlPlane());$t->same($runtime->releases(),$platform->releaseControlPlane());$t->same($runtime->marketplace(),$platform->marketplaceGovernance());$t->same($runtime->studioBranches(),$platform->studioBranches());$t->instanceOf(PanelLocalReplica::class,$platform->localReplica('Operator:1'));
	$domain=$platform->manifest()->domain('operations_os');$t->isTrue($domain['ready']);$t->isTrue($domain['features']['compliance_automation']);$t->isTrue(in_array('operations_os.compliance_automation',$domain['required_services'],true));$t->isTrue($domain['cohesion']['valid']);$t->same([],$domain['cohesion']['mismatches']);$t->isTrue(in_array('operations_os',$platform->jsonSerialize()['metadata']['enabled_domains'],true));$t->notContains($master,json_encode($platform,JSON_THROW_ON_ERROR));
	$surface=Panel::make('operations-surface')->usePlatform($platform);$t->same($runtime,$surface->operationsOs());$t->same($runtime->compliance(),$surface->complianceLedger());$t->same($runtime->complianceAutomation(),$surface->complianceAutomation());$t->instanceOf(PanelLocalReplica::class,$surface->localReplica('Operator:2'));
	Panel::default()->replacePlatform($platform);$t->same($runtime,Panel::operationsOs());$t->same($runtime->compliance(),Panel::complianceLedger());$t->same($runtime->complianceAutomation(),Panel::complianceAutomation());$t->instanceOf(PanelLocalReplica::class,Panel::localReplica('Operator:3'));Panel::withoutPlatform();
})->tag('panel','operations-os','platform','facade','cohesion')->isolation('case')->maxMillis(12000);

test('platform readiness detects split Operations OS graphs and local replica factory violations',static function(Context $t):void{
	$master=str_repeat('p',48);$platform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-os-split'),'operations_os'=>dp_panel_os_platform_config($master)]+dp_panel_os_platform_disabled());$original=$platform->semanticCatalog();$platform->register('operations_os.semantics',new PanelSemanticCatalog(),true);$domain=$platform->manifest()->domain('operations_os');$t->isFalse($domain['ready']);$t->same(['operations_os.runtime.semantics'],$domain['cohesion']['mismatches']);$platform->register('operations_os.semantics',$original,true);$t->isTrue($platform->manifest()->ready('operations_os'));
	$originalAutomation=$platform->complianceAutomation();$splitAutomation=new PanelComplianceAutomation($platform->complianceLedger(),new PanelComplianceCollectorRegistry(),PanelComplianceFrameworkCatalog::firstParty());$platform->register('operations_os.compliance_automation',$splitAutomation,true);$domain=$platform->manifest()->domain('operations_os');$t->isFalse($domain['ready']);$t->same(['operations_os.runtime.compliance_automation'],$domain['cohesion']['mismatches']);$platform->register('operations_os.compliance_automation',$originalAutomation,true);$t->isTrue($platform->manifest()->ready('operations_os'));
	$missing=new PanelPlatform();$t->throws(static fn()=>$missing->localReplica('Actor:1'),LogicException::class);$invalid=(new PanelPlatform())->register('operations_os.local_replica_factory',static fn():stdClass=>new stdClass());$t->throws(static fn()=>$invalid->localReplica('Actor:1'),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-os-short-platform'),'operations_os'=>['master_key'=>'short']]+dp_panel_os_platform_disabled()),InvalidArgumentException::class);
})->tag('panel','operations-os','platform','split-graph')->isolation('case')->maxMillis(10000);

test('Operations OS composes optional marketplace trust services and detects split transparency graphs',static function(Context $t):void{
	$clock=static fn():string=>'2026-07-16T12:00:00.000000Z';$signatureVerifier=static fn(string $payload,string $keyId,string $signature,string $role):bool=>true;
	$verifier=new \Dataphyre\Panel\PanelPackageTransparencyVerifier($signatureVerifier,['shopiro_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock]);$operations=dp_panel_os_platform_config(str_repeat('t',48));$operations['marketplace_transparency_verifier']=$verifier;$operations['marketplace_clock']=$clock;
	$platform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-os-marketplace-trust'),'operations_os'=>$operations]+dp_panel_os_platform_disabled());$runtime=$platform->operationsOs();$network=$runtime->marketplaceTrustNetwork();
	$t->isTrue($runtime->hasMarketplaceTrustNetwork());$t->same($network,$platform->marketplaceTrustNetwork());$t->same($runtime->marketplace()->revocationRegistry(),$platform->marketplaceRevocations());$t->same($runtime->marketplace()->publisherTrustRegistry(),$platform->marketplacePublishers());$t->same($network,$platform->marketplaceRevocations()->network());$t->same($network,$platform->marketplacePublishers()->network());
	$domain=$platform->manifest()->domain('operations_os');foreach(['marketplace_transparency_merkle','marketplace_transparency_log','marketplace_transparency_verifier','marketplace_trust_network','marketplace_revocations','marketplace_publisher_profiles']as$feature){$t->isTrue($domain['features'][$feature]);}$t->isTrue($domain['ready']);
	$splitVerifier=new \Dataphyre\Panel\PanelPackageTransparencyVerifier($signatureVerifier,['shopiro_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock]);$split=new \Dataphyre\Panel\PanelPackageMarketplaceTrustNetwork($t->tempDirectory('panel-os-marketplace-split'),$splitVerifier,$clock);$platform->register('operations_os.marketplace_trust_network',$split,true);$domain=$platform->manifest()->domain('operations_os');$t->isFalse($domain['ready']);$t->isTrue(in_array('operations_os.marketplace_trust.runtime',$domain['cohesion']['mismatches'],true));
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($t->tempDirectory('panel-os-marketplace-invalid'),['master_key'=>str_repeat('m',48),'marketplace_transparency_verifier'=>new stdClass()]),InvalidArgumentException::class);
})->tag('panel','operations-os','marketplace','transparency','cohesion')->isolation('case')->maxMillis(12000);

test('Operations OS routes host domain delegates and exposes the complete transactional activation lifecycle',static function(Context $t):void{
	$master=str_repeat('d',48);$delegate=new class implements PanelDomainCommandExecutor {public function execute(PanelDomainCommandInvocation $invocation):mixed{return['command'=>$invocation->command()->qualifiedName()];}};
	$config=dp_panel_os_platform_config($master);$config['domain_command_executor']=$delegate;$os=PanelOperationsOs::fromConfig($t->tempDirectory('panel-os-domain-lifecycle'),$config);$routes=$os->commandFabric()->registry()->jsonSerialize()['routes'];$t->same('operations_os.domains',$routes['domain.*'][0]['contributor']);

	$v1=dp_panel_os_platform_domain('1.0.0');$install=$os->previewDomainActivation($v1);$issued=$os->approveDomainActivation($install,'reviewer-unused');$t->same($install->fingerprint(),$issued->planFingerprint());$receipt=$os->activateDomain($v1,'deployer','activate-v1',$install);$t->same('activate',$receipt->operation());
	$manager=new PanelManager();$os->attachManager($manager);$t->isTrue($manager->has('orders.order'));

	$v2=dp_panel_os_platform_domain('2.0.0');$upgrade=$os->previewDomainActivation($v2);$approvals=[];for($index=0;$index<$upgrade->approvalCount();$index++){$approvals[]=$os->approveDomainActivation($upgrade,'upgrade-reviewer-'.$index);}$os->activateDomain($v2,'deployer','activate-v2',$upgrade,$approvals,1);$t->same('2.0.0',$os->compilation('orders')->domainVersion());
	$rollback=$os->previewDomainActivation($os->compilationAt('orders','1.0.0'),'rollback');$approvals=[];for($index=0;$index<$rollback->approvalCount();$index++){$approvals[]=$os->approveDomainActivation($rollback,'rollback-reviewer-'.$index);}$rolled=$os->rollbackDomain('orders','1.0.0','deployer','rollback-v1',$rollback,$approvals,2);$t->same('rollback',$rolled->operation());$t->same('1.0.0',$os->compilation('orders')->domainVersion());
	$reconcile=$os->previewDomainActivation($os->compilation('orders'),'reconcile');$approvals=[];for($index=0;$index<$reconcile->approvalCount();$index++){$approvals[]=$os->approveDomainActivation($reconcile,'reconcile-reviewer-'.$index);}$reconciled=$os->reconcileDomain('orders','deployer','reconcile-v1',$reconcile,$approvals,3);$t->same('reconcile',$reconciled->operation());
	$deactivation=$os->activation()->previewDeactivation('orders');$approvals=[];for($index=0;$index<$deactivation->approvalCount();$index++){$approvals[]=$os->approveDomainActivation($deactivation,'deactivate-reviewer-'.$index);}$deactivated=$os->deactivateDomain('orders','deployer','deactivate-v1',$deactivation,$approvals,4);$t->same('deactivate',$deactivated->operation());$t->isFalse($manager->has('orders.order'));

	$untrusted=(new PanelDomainCompiler())->compile(dp_panel_os_platform_domain('3.0.0'))->sign('foreign',str_repeat('X',32));$t->throws(static fn()=>$os->previewDomainActivation($untrusted),LogicException::class);
	$adapter=new PanelDomainFabricCommandExecutor($os->commandFabric());$invalid=dp_panel_os_platform_config(str_repeat('i',48));$invalid['domain_command_executor']=$adapter;$t->throws(static fn()=>PanelOperationsOs::fromConfig($t->tempDirectory('panel-os-domain-adapter-invalid'),$invalid),InvalidArgumentException::class);
})->tag('panel','operations-os','domain','activation','fabric')->isolation('case')->maxMillis(18000);
