<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCallbackReleaseDeploymentTransport;
use Dataphyre\Panel\PanelDeclarativeReleaseDeploymentAdapter;
use Dataphyre\Panel\PanelPolicyBundle;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelPolicyRequest;
use Dataphyre\Panel\PanelPlatformManifest;
use Dataphyre\Panel\PanelReleaseArtifact;
use Dataphyre\Panel\PanelReleaseControlPlane;
use Dataphyre\Panel\PanelReleaseDeploymentProfile;
use Dataphyre\Panel\PanelReleaseDeploymentTransport;
use Dataphyre\Panel\PanelReleaseExecutionEngine;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_deployment_context(string $phase='prepare',int $fence=9,int $attempt=1):array{return[
	'execution_id'=>'release_exec_example','deployment_id'=>'release_deploy_example','artifact'=>['id'=>'panel_v2','version'=>'2.0.0+build.7','digest'=>hash('sha256','artifact'),'digests'=>['code'=>hash('sha256','code')]],
	'ring'=>'canary','tenant_id'=>'Tenant:A','phase'=>$phase,'operation_key'=>hash('sha256','operation-'.$phase),'attempt'=>$attempt,'fence'=>$fence,'payload_redacted'=>true,
];}

function dp_panel_deployment_policy():PanelPolicyControlPlane{$policy=new PanelPolicyControlPlane([],false);$policy->register(PanelPolicyBundle::from(['id'=>'declarative_release','version'=>'1.0.0','rules'=>['release'=>['effect'=>'allow','abilities'=>['release.*'],'priority'=>100,'reason'=>'Release operator.']]]));return$policy;}
function dp_panel_deployment_request():PanelPolicyRequest{return new PanelPolicyRequest('Operator:A','release.execute','Tenant:A',null,null,'critical',['release_operator'],['release.*']);}

test('release deployment profiles cover five targets with deterministic credential free rollout contracts',static function(Context $t):void{
	$profiles=[
		PanelReleaseDeploymentProfile::kubernetes('prod_k8s',['namespace'=>'shop','workload_kind'=>'Deployment','workload'=>'panel','container'=>'web','cluster_ref'=>'primary'],'canary',['canary_percent'=>5]),
		PanelReleaseDeploymentProfile::nomad('prod_nomad',['namespace'=>'default','job'=>'panel','group'=>'web','task'=>'php','region'=>'global']),
		PanelReleaseDeploymentProfile::ecs('prod_ecs',['region'=>'ca-central-1','cluster'=>'panel-prod','service'=>'panel-web','container'=>'php']),
		PanelReleaseDeploymentProfile::compose('edge_compose',['project'=>'panel','service'=>'web','context_ref'=>'edge']),
		PanelReleaseDeploymentProfile::filesystem('bare_metal',['root_ref'=>'panel_releases','current_link'=>'current','health_ref'=>'local']),
	];
	$t->same(['kubernetes','nomad','ecs','compose','filesystem'],array_map(static fn(PanelReleaseDeploymentProfile $profile):string=>$profile->driver(),$profiles));
	$t->same('stage_workload_revision',$profiles[0]->action('prepare'));$t->same('rollback_job_version',$profiles[1]->action('rollback'));$t->same('update_service_revision',$profiles[2]->action('activate'));$t->same('verify_service_release',$profiles[3]->action('verify'));$t->same('switch_current_release',$profiles[4]->action('activate'));
	$t->same($profiles[0]->digest(),PanelReleaseDeploymentProfile::kubernetes('prod_k8s',['workload'=>'panel','container'=>'web','cluster_ref'=>'primary','namespace'=>'shop','workload_kind'=>'Deployment'],'canary',['canary_percent'=>5])->digest());
	$features=PanelPlatformManifest::inspect()->domain('operations_os')['features'];foreach(['release_deployment_transport','callback_release_deployment_transport','release_deployment_profile','declarative_release_deployment_adapter']as$feature){$t->isTrue($features[$feature]);}
	$manifest=json_encode($profiles,JSON_THROW_ON_ERROR);$t->contains('credentials_exposed',$manifest);$t->notContains('password',$manifest);$t->notContains('/srv/',$manifest);
	$t->throws(static fn()=>new PanelReleaseDeploymentProfile('unknown','target',[]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseDeploymentProfile::kubernetes('bad target',['namespace'=>'shop','workload_kind'=>'Deployment','workload'=>'panel','container'=>'web']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseDeploymentProfile::kubernetes('prod',['namespace'=>'shop','workload_kind'=>'Job','workload'=>'panel','container'=>'web']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseDeploymentProfile::kubernetes('prod',['namespace'=>'shop','workload_kind'=>'Deployment','workload'=>'panel']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseDeploymentProfile::ecs('prod',['region'=>'ca','cluster'=>'x','service'=>'x','container'=>'x','token'=>'secret']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseDeploymentProfile::nomad('prod',['namespace'=>'d','job'=>'j','group'=>'g','task'=>'t'],'canary',['canary_percent'=>0]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseDeploymentProfile::compose('prod',['project'=>'p','service'=>'s'],'rolling',['verify_timeout_seconds'=>1]),InvalidArgumentException::class);
	$t->throws(static fn()=>$profiles[0]->action('destroy'),InvalidArgumentException::class);
})->tag('panel','release','deployment','profiles','targets')->group('framework-coverage');

test('declarative deployment requests are deterministic signed fenced and identity redacted',static function(Context $t):void{
	$key=str_repeat('D',48);$profile=PanelReleaseDeploymentProfile::kubernetes('prod',['namespace'=>'shop','workload_kind'=>'Deployment','workload'=>'panel','container'=>'web'],'canary',['canary_percent'=>10]);$requests=[];
	$transport=new PanelCallbackReleaseDeploymentTransport('signed_worker',static function(array $request)use(&$requests,$key):array{$requests[]=$request;return PanelDeclarativeReleaseDeploymentAdapter::sealReceipt($request,true,$request['phase'].'_complete',['token'=>'private-result','revision'=>'42'],'primary',$key);});
	$adapter=new PanelDeclarativeReleaseDeploymentAdapter($profile,$transport,['old'=>str_repeat('O',48),'primary'=>$key],'primary');$context=dp_panel_deployment_context();$preview=$adapter->preview('prepare',$context);$t->same($preview,$adapter->preview('prepare',$context));
	$t->same('stage_workload_revision',$preview['intent']['action']);$t->same(9,$preview['idempotency']['fence']);$t->same(hash('sha256',$context['operation_key']),$preview['idempotency']['operation_key_hash']);
	$encoded=json_encode([$preview,$adapter],JSON_THROW_ON_ERROR);$t->notContains($context['operation_key'],$encoded);$t->notContains('Tenant:A',$encoded);$t->notContains('release_exec_example',$encoded);$t->notContains($key,$encoded);$t->contains('integrity_keys_exposed',$encoded);
	$t->same(hash_hmac('sha256',$preview['request_digest'],$key),$preview['integrity']['signature']);
	$result=$adapter->execute('prepare',$context);$t->isTrue($result['ok']);$t->same('prepare_complete',$result['code']);$t->same($preview['request_digest'],$result['request_digest']);$t->isTrue($result['result_redacted']);$t->notContains('private-result',json_encode($result,JSON_THROW_ON_ERROR));$t->same(1,count($requests));
	$t->instanceOf(PanelReleaseDeploymentTransport::class,$adapter->transport());$t->same($profile,$adapter->profile());
	$t->throws(static fn()=>$adapter->preview('destroy',$context),InvalidArgumentException::class);
	foreach([
		array_replace($context,['payload_redacted'=>false]),array_replace($context,['attempt'=>0]),array_replace($context,['fence'=>0]),array_replace($context,['phase'=>'verify']),array_replace($context,['operation_key'=>'']),array_replace($context,['artifact'=>[]]),
	]as$invalid){$t->throws(static fn()=>$adapter->preview('prepare',$invalid),InvalidArgumentException::class);}
})->tag('panel','release','deployment','signatures','redaction','fencing')->group('framework-coverage');

test('declarative deployment rejects stale mismatched tampered and untrusted receipts',static function(Context $t):void{
	$key=str_repeat('D',48);$other=str_repeat('X',48);$profile=PanelReleaseDeploymentProfile::ecs('prod',['region'=>'ca-central-1','cluster'=>'panel','service'=>'web','container'=>'php']);$context=dp_panel_deployment_context();
	$mode='valid';$stale=null;$transport=new PanelCallbackReleaseDeploymentTransport('receipt_probe',static function(array $request)use(&$mode,&$stale,$key,$other):array{
		if($mode==='empty')return[];
		if($mode==='stale'&&is_array($stale))return$stale;
		$receipt=PanelDeclarativeReleaseDeploymentAdapter::sealReceipt($request,$mode!=='rejected',$mode==='rejected'?'target_rejected':'completed',[],$mode==='untrusted'?'other':'primary',$mode==='untrusted'?$other:$key);
		if($mode==='tampered')$receipt['fence']++;
		return$receipt;
	});$adapter=new PanelDeclarativeReleaseDeploymentAdapter($profile,$transport,['primary'=>$key],'primary');
	$t->isTrue($adapter->execute('prepare',$context)['ok']);$mode='rejected';$t->isFalse($adapter->execute('prepare',$context)['ok']);
	$mode='tampered';$t->throws(static fn()=>$adapter->execute('prepare',$context),UnexpectedValueException::class);
	$mode='untrusted';$t->throws(static fn()=>$adapter->execute('prepare',$context),UnexpectedValueException::class);
	$mode='empty';$t->throws(static fn()=>$adapter->execute('prepare',$context),UnexpectedValueException::class);
	$staleRequest=$adapter->preview('prepare',dp_panel_deployment_context('prepare',8));$stale=PanelDeclarativeReleaseDeploymentAdapter::sealReceipt($staleRequest,true,'completed',[],'primary',$key);$mode='stale';$t->throws(static fn()=>$adapter->execute('prepare',$context),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelDeclarativeReleaseDeploymentAdapter::sealReceipt([],true,'ok',[],'primary',$key),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelDeclarativeReleaseDeploymentAdapter::sealReceipt($staleRequest,true,'bad code',[],'primary',$key),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDeclarativeReleaseDeploymentAdapter($profile,$transport,[],'primary'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDeclarativeReleaseDeploymentAdapter($profile,$transport,['primary'=>'short'],'primary'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelDeclarativeReleaseDeploymentAdapter($profile,$transport,['primary'=>$key],'missing'),InvalidArgumentException::class);
})->tag('panel','release','deployment','receipt-binding','stale','tamper')->group('framework-coverage');

test('declarative deployment adapter drives durable activation and automatic rollback',static function(Context $t):void{
	$key=str_repeat('R',48);$receiptKey=str_repeat('D',48);$root=$t->tempDirectory('declarative-release-engine');$policy=dp_panel_deployment_policy();$request=dp_panel_deployment_request();$clock=static fn():string=>'2026-07-16T12:00:00.000000Z';$rejectVerify=false;$calls=[];
	$profile=PanelReleaseDeploymentProfile::nomad('prod_nomad',['namespace'=>'default','job'=>'panel','group'=>'web','task'=>'php']);
	$transport=new PanelCallbackReleaseDeploymentTransport('nomad_control',static function(array $deployment)use(&$rejectVerify,&$calls,$receiptKey):array{$calls[]=['phase'=>$deployment['phase'],'fence'=>$deployment['idempotency']['fence'],'operation'=>$deployment['idempotency']['operation_key_hash']];$ok=!($rejectVerify&&$deployment['phase']==='verify');return PanelDeclarativeReleaseDeploymentAdapter::sealReceipt($deployment,$ok,$ok?$deployment['phase'].'_complete':'health_regressed',[],'deploy',$receiptKey);});
	$adapter=new PanelDeclarativeReleaseDeploymentAdapter($profile,$transport,['deploy'=>$receiptKey],'deploy');$plane=new PanelReleaseControlPlane($root.'/control',['primary'=>$key],$policy,$clock);$artifact=PanelReleaseArtifact::sign('panel_v2','2.0.0',['code'=>hash('sha256','panel-v2')],[['name'=>'dataphyre','version'=>'2.0.0']],['builder'=>'deployment-test'],$clock(),'primary',$key);$plane->register($artifact,$request)->ring('canary',10,1000,[],$request);
	$engine=new PanelReleaseExecutionEngine($root.'/engine',$plane,$policy,$adapter,['primary'=>$key],'primary',$clock);$active=$engine->execute('panel_v2','canary',[],$request,'deploy-active');$t->same('active',$active['status']);$t->same(['prepare','activate','verify'],array_column($calls,'phase'));
	$rejectVerify=true;$rolled=$engine->execute('panel_v2','canary',[],$request,'deploy-rollback');$t->same('rolled_back',$rolled['status']);$t->same(['prepare','activate','verify','prepare','activate','verify','rollback'],array_column($calls,'phase'));
	$t->same('completed',$rolled['steps']['rollback']['status']);$t->contains('panel_declarative_release_deployment_adapter',json_encode($engine,JSON_THROW_ON_ERROR));$t->notContains($receiptKey,json_encode([$engine,$active,$rolled],JSON_THROW_ON_ERROR));
})->tag('panel','release','deployment','execution-engine','rollback','integration')->isolation('case')->group('framework-coverage')->maxMillis(18000);
