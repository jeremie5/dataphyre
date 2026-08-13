<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCallbackReleaseDeploymentAdapter;
use Dataphyre\Panel\PanelPolicyBundle;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelPolicyRequest;
use Dataphyre\Panel\PanelReleaseArtifact;
use Dataphyre\Panel\PanelReleaseControlPlane;
use Dataphyre\Panel\PanelReleaseExecutionEngine;
use Dataphyre\Panel\PanelReleaseExecutionInterrupted;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_release_execution_policy():PanelPolicyControlPlane {
	$policy=new PanelPolicyControlPlane([],false);$policy->register(PanelPolicyBundle::from(['id'=>'release_execution','version'=>'1.0.0','rules'=>['release'=>['effect'=>'allow','abilities'=>['release.*'],'priority'=>100,'reason'=>'Release execution operator.']]]));return$policy;
}

function dp_panel_release_execution_request(string $actor='Operator:A'):PanelPolicyRequest {
	return new PanelPolicyRequest($actor,'release.execute','Tenant:A',null,null,'critical',['release_operator'],['release.*']);
}

function dp_panel_release_execution_artifact(string $id,string $key,string $at='2026-07-16T12:00:00.000000Z'):PanelReleaseArtifact {
	return PanelReleaseArtifact::sign($id,'1.0.0',['code'=>hash('sha256',$id)],[['name'=>'dataphyre','version'=>'1.0.0']],['builder'=>'release-test','source_digest'=>hash('sha256','source-'.$id)],$at,'primary',$key);
}

test('release execution only commits active after idempotent prepare activate verify and redacts adapter payloads',static function(Context $t):void {
	$key=str_repeat('R',48);$root=$t->tempDirectory('release-execution-success');$policy=dp_panel_release_execution_policy();$request=dp_panel_release_execution_request();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$calls=[];$secret='adapter-result-private';
	$adapter=new PanelCallbackReleaseDeploymentAdapter('test_deployer',static function(string $phase,array $context)use(&$calls,$secret):array{$calls[]=['phase'=>$phase,'operation_key'=>$context['operation_key'],'attempt'=>$context['attempt']];return['ok'=>true,'code'=>$phase.'_complete','secret'=>$secret];});
	$t->throws(static fn()=>$adapter->execute('destroy',[]),InvalidArgumentException::class);$invalidAdapter=new PanelCallbackReleaseDeploymentAdapter('invalid_deployer',static fn():array=>['ok'=>'yes']);$t->throws(static fn()=>$invalidAdapter->execute('prepare',[]),UnexpectedValueException::class);
	$plane=new PanelReleaseControlPlane($root.'/control',['primary'=>$key],$policy,$clock);$plane->register(dp_panel_release_execution_artifact('panel_v1',$key),$request)->ring('canary',10,1000,[['metric'=>'latency','operator'=>'lte','threshold'=>100]],$request);
	$engine=new PanelReleaseExecutionEngine($root.'/engine',$plane,$policy,$adapter,['primary'=>$key],'primary',$clock,120,100,128);
	$t->same($plane,$engine->controlPlane());$t->same($policy,$engine->policy());$t->same($adapter,$engine->adapter());$t->instanceOf(\Dataphyre\Panel\PanelAtomicSnapshotStore::class,$engine->store());$t->same($policy,$plane->policy());
	$result=$engine->execute('panel_v1','canary',['latency'=>80],$request,'deploy-one','worker-a');$t->same('active',$result['status']);$t->same(['prepare','activate','verify'],array_column($calls,'phase'));$t->same('completed',$result['steps']['prepare']['status']);$t->same('pending',$result['steps']['rollback']['status']);$t->isFalse($result['leased']);$t->isFalse($result['lease_credentials_exposed']);
	$deployment=$plane->deployment($result['deployment_id']);$t->same('active',$deployment['status']);$t->same(['approved','executing','active'],array_column($deployment['history'],'to'));
	$replay=$engine->execute('panel_v1','canary',['latency'=>80],$request,'deploy-one','worker-b');$t->same('active',$replay['status']);$t->isTrue($replay['replayed']);$t->same(3,count($calls));
	$blocked=$engine->execute('panel_v1','canary',['latency'=>180],$request,'deploy-blocked','worker-a');$t->same('blocked',$blocked['status']);$t->same(3,count($calls));$t->throws(static fn()=>$engine->execute('panel_v1','canary',['latency'=>70],$request,'deploy-one','worker-a'),LogicException::class);
	$legacy=$plane->deploy('panel_v1','canary',['latency'=>70],$request,'legacy-deploy');$t->same('active',$legacy['status']);$t->isTrue(count($plane->deployments())>=3);
	$encoded=json_encode([$engine,$plane,$result],JSON_THROW_ON_ERROR);$t->notContains($secret,$encoded);$contents='';foreach(glob($root.'/{control,engine}/*.json',GLOB_BRACE)?:[]as$file){$contents.=(string)file_get_contents($file);}$t->notContains($secret,$contents);$t->isTrue($engine->verifyIntegrity()['ok']);
	$reopenedPlane=new PanelReleaseControlPlane($root.'/control',['primary'=>$key],$policy,$clock);$reopened=new PanelReleaseExecutionEngine($root.'/engine',$reopenedPlane,$policy,$adapter,['primary'=>$key],'primary',$clock,120,100,128);$restartReplay=$reopened->execute('panel_v1','canary',['latency'=>80],$request,'deploy-one','worker-c');$t->isTrue($restartReplay['replayed']);$t->same(3,count($calls));$t->same(2,$reopened->jsonSerialize()['execution_count']);
})->tag('panel','release','execution','idempotency','health-gates','restart','redaction')->isolation('case')->maxMillis(18000);

test('release execution automatically rolls back failed verification and records rollback failure without payload leakage',static function(Context $t):void {
	$key=str_repeat('R',48);$root=$t->tempDirectory('release-execution-rollback');$policy=dp_panel_release_execution_policy();$request=dp_panel_release_execution_request();$clock=static fn():string=>'2026-07-16T12:00:00.000000Z';$rollbackOk=true;$secret='rollback-adapter-private';$calls=[];
	$adapter=new PanelCallbackReleaseDeploymentAdapter('rollback_deployer',static function(string $phase,array $context)use(&$rollbackOk,&$calls,$secret):array{$calls[]=$phase;if($phase==='verify'){return['ok'=>false,'code'=>'health_regressed','secret'=>$secret];}if($phase==='rollback'){return['ok'=>$rollbackOk,'code'=>$rollbackOk?'rollback_complete':'rollback_rejected','secret'=>$secret];}return['ok'=>true,'code'=>$phase.'_complete','secret'=>$secret];});
	$plane=new PanelReleaseControlPlane($root.'/control',['primary'=>$key],$policy,$clock);$plane->register(dp_panel_release_execution_artifact('panel_v1',$key),$request)->ring('canary',10,1000,[],$request);$engine=new PanelReleaseExecutionEngine($root.'/engine',$plane,$policy,$adapter,['primary'=>$key],'primary',$clock);
	$rolled=$engine->execute('panel_v1','canary',[],$request,'rollback-success');$t->same('rolled_back',$rolled['status']);$t->same('failed',$rolled['steps']['verify']['status']);$t->same('completed',$rolled['steps']['rollback']['status']);$t->isTrue($rolled['steps']['rollback']['receipt']['ok']);$history=array_column($plane->deployment($rolled['deployment_id'])['history'],'to');$t->same(['approved','executing','failed','rolling_back','rolled_back'],$history);
	$rollbackOk=false;$failed=$engine->execute('panel_v1','canary',[],$request,'rollback-failure');$t->same('rollback_failed',$failed['status']);$t->isFalse($failed['steps']['rollback']['receipt']['ok']);$t->same('rollback_rejected',$failed['steps']['rollback']['receipt']['code']);$t->same(['prepare','activate','verify','rollback','prepare','activate','verify','rollback'],$calls);
	$t->notContains($secret,json_encode([$engine,$plane,$rolled,$failed],JSON_THROW_ON_ERROR));$t->same(1,$engine->jsonSerialize()['statuses']['rolled_back']);$t->same(1,$engine->jsonSerialize()['statuses']['rollback_failed']);
})->tag('panel','release','execution','rollback','failure','redaction')->isolation('case')->maxMillis(18000);

test('release execution recovers rollback commit windows and normalizes adapter exceptions',static function(Context $t):void {
	$key=str_repeat('R',48);$root=$t->tempDirectory('release-execution-rollback-windows');$policy=dp_panel_release_execution_policy();$request=dp_panel_release_execution_request();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$verifyMode='reject';$rollbackMode='interrupt';
	$adapter=new PanelCallbackReleaseDeploymentAdapter('rollback_window_deployer',static function(string $phase)use(&$verifyMode,&$rollbackMode):array{if($phase==='verify'){if($verifyMode==='throw'){throw new RuntimeException('verification transport failed');}return['ok'=>false,'code'=>'health_regressed'];}if($phase==='rollback'){if($rollbackMode==='interrupt'){throw new PanelReleaseExecutionInterrupted('lost after rollback claim');}if($rollbackMode==='throw'){throw new RuntimeException('rollback transport failed');}return['ok'=>true,'code'=>'rollback_complete'];}return['ok'=>true,'code'=>$phase.'_complete'];});
	$plane=new PanelReleaseControlPlane($root.'/control',['primary'=>$key],$policy,$clock);$plane->register(dp_panel_release_execution_artifact('panel_v1',$key),$request)->ring('canary',10,1000,[],$request);$engine=new PanelReleaseExecutionEngine($root.'/engine',$plane,$policy,$adapter,['primary'=>$key],'primary',$clock,120);
	$t->throws(static fn()=>$engine->execute('panel_v1','canary',[],$request,'rollback-window','worker-a'),PanelReleaseExecutionInterrupted::class);$running=$engine->executions()[0];$t->same('rollback',$running['mode']);$t->same('running',$running['steps']['rollback']['status']);
	$now='2026-07-16T12:03:00.000000Z';$lease=$t->nonPublic($engine)->invoke('claim',$running['id'],'repair-worker',0);$receipt=['ok'=>true,'code'=>'rollback_complete','result_digest'=>hash('sha256','rollback complete'),'result_redacted'=>true];$t->nonPublic($engine)->invoke('completeStep',$running['id'],'rollback',$receipt,$lease);
	$now='2026-07-16T12:06:00.000000Z';$rollbackMode='success';$recovered=$engine->resume($running['id'],$request,'resume-worker',0);$t->same('rolled_back',$recovered['status']);
	$rollbackMode='throw';$failed=$engine->execute('panel_v1','canary',[],$request,'rollback-throws','worker-a');$t->same('rollback_failed',$failed['status']);$t->same('adapter_exception',$failed['steps']['rollback']['receipt']['code']);
	$verifyMode='throw';$rollbackMode='success';$forwardFailure=$engine->execute('panel_v1','canary',[],$request,'verify-throws','worker-a');$t->same('rolled_back',$forwardFailure['status']);$t->same('adapter_exception',$forwardFailure['steps']['verify']['receipt']['code']);
})->tag('panel','release','execution','rollback','crash-window','exceptions')->isolation('case')->maxMillis(18000);

test('release execution recovers a crash after an adapter side effect with the identical fenced operation key and rejects tampering',static function(Context $t):void {
	$key=str_repeat('R',48);$root=$t->tempDirectory('release-execution-recovery');$policy=dp_panel_release_execution_policy();$request=dp_panel_release_execution_request();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$prepareCalls=[];$interrupt=true;
	$adapter=new PanelCallbackReleaseDeploymentAdapter('recoverable_deployer',static function(string $phase,array $context)use(&$prepareCalls,&$interrupt):array{if($phase==='prepare'){$prepareCalls[]=['operation_key'=>$context['operation_key'],'attempt'=>$context['attempt'],'fence'=>$context['fence']];if($interrupt){$interrupt=false;throw new PanelReleaseExecutionInterrupted('simulated process loss');}}return['ok'=>true,'code'=>$phase.'_complete'];});
	$plane=new PanelReleaseControlPlane($root.'/control',['primary'=>$key],$policy,$clock);$plane->register(dp_panel_release_execution_artifact('panel_v1',$key),$request)->ring('canary',10,1000,[],$request);$engine=new PanelReleaseExecutionEngine($root.'/engine',$plane,$policy,$adapter,['primary'=>$key],'primary',$clock,120);
	$t->throws(static fn()=>$engine->execute('panel_v1','canary',[],$request,'crash-one','worker-a'),PanelReleaseExecutionInterrupted::class);$running=$engine->executions('Tenant:A')[0];$t->same('running',$running['status']);$t->same('running',$running['steps']['prepare']['status']);$t->isTrue($running['leased']);$t->throws(static fn()=>$engine->resume($running['id'],$request,'worker-b'),LogicException::class);
	$t->throws(static fn()=>$engine->execute('panel_v1','canary',[],$request,'crash-one','worker-b'),LogicException::class);
	$now='2026-07-16T12:03:00.000000Z';$reopenedPlane=new PanelReleaseControlPlane($root.'/control',['primary'=>$key],$policy,$clock);$reopened=new PanelReleaseExecutionEngine($root.'/engine',$reopenedPlane,$policy,$adapter,['primary'=>$key],'primary',$clock,120);$recovered=$reopened->recoverStale(dp_panel_release_execution_request('Recovery:B'),'worker-b',0,10);$t->same([],$recovered['errors']);$t->same(1,count($recovered['resumed']));$t->same('active',$recovered['resumed'][0]['status']);$t->same(2,count($prepareCalls));$t->same($prepareCalls[0]['operation_key'],$prepareCalls[1]['operation_key']);$t->same(1,$prepareCalls[0]['attempt']);$t->same(2,$prepareCalls[1]['attempt']);$t->isTrue($prepareCalls[1]['fence']>$prepareCalls[0]['fence']);
	$files=glob($root.'/engine/*.json')?:[];sort($files,SORT_STRING);$latest=$files[array_key_last($files)];$snapshot=json_decode((string)file_get_contents($latest),true,512,JSON_THROW_ON_ERROR);$snapshot['payload']['revision']++;file_put_contents($latest,json_encode($snapshot,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>new PanelReleaseExecutionEngine($root.'/engine',$reopenedPlane,$policy,$adapter,['primary'=>$key],'primary',$clock,120),UnexpectedValueException::class);
})->tag('panel','release','execution','crash-recovery','fencing','tamper')->isolation('case')->maxMillis(18000);
