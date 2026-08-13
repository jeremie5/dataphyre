<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAgentAuditReceipt;
use Dataphyre\Panel\PanelAgentException;
use Dataphyre\Panel\PanelAgentExecutionResult;
use Dataphyre\Panel\PanelAgentGuard;
use Dataphyre\Panel\PanelAgentIntentSigner;
use Dataphyre\Panel\PanelAgentPlan;
use Dataphyre\Panel\PanelAgentPolicyDecision;
use Dataphyre\Panel\PanelAgentPolicyEngine;
use Dataphyre\Panel\PanelAgentPolicyResolver;
use Dataphyre\Panel\PanelAgentRequestContext;
use Dataphyre\Panel\PanelAgentRuntime;
use Dataphyre\Panel\PanelAgentTool;
use Dataphyre\Panel\PanelAgentToolCatalog;
use Dataphyre\Panel\PanelAgentToolExecutionRequest;
use Dataphyre\Panel\PanelAgentToolExecutionResult;
use Dataphyre\Panel\PanelAgentToolExecutor;
use Dataphyre\Panel\PanelAgentWorkflowStorageException;
use Dataphyre\Panel\PanelAtomicAgentWorkflowStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_atomic_agent_context(string $principal='operator:1',string $tenant='tenant-a',string $session='session-a'): PanelAgentRequestContext {
	return new PanelAgentRequestContext('operations',$tenant,$principal,$session,'request-atomic-1');
}

/** @return array{plan:string,request:string,nonce:string} */
function dp_panel_atomic_agent_material(string $suffix='a'): array {
	return ['plan'=>hash('sha256','atomic-plan-'.$suffix),'request'=>hash('sha256','atomic-request-'.$suffix),'nonce'=>substr(hash('sha256','atomic-nonce-'.$suffix),0,32)];
}

function dp_panel_atomic_agent_receipt(PanelAtomicAgentWorkflowStore $store,PanelAgentRequestContext $actor,string $plan,string $event,string $code,int $at,array $details=[]): PanelAgentAuditReceipt {
	return PanelAgentAuditReceipt::create(count($store->audit())+1,$event,$actor,$plan,$code,$details,$store->lastAuditHash(),$at);
}

function dp_panel_atomic_agent_error(Context $t,callable $callback,string $code): PanelAgentException {
	try{ $callback(); }catch(PanelAgentException $exception){ $t->same($code,$exception->errorCode()); return $exception; }
	throw new RuntimeException("Expected PanelAgentException {$code}.");
}

function dp_panel_atomic_agent_snapshot(string $directory): string {
	$files=glob($directory.DIRECTORY_SEPARATOR.'agent-*.json') ?: []; sort($files,SORT_STRING);
	if($files===[]){ throw new RuntimeException('Expected an agent workflow snapshot.'); }
	return $files[array_key_last($files)];
}

/** @param callable(array<string,mixed>&):void $mutation */
function dp_panel_atomic_agent_rewrite(string $file,callable $mutation,bool $rehash=true): void {
	$data=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR); $mutation($data);
	if($rehash){ unset($data['hash']); $data['hash']=hash('sha256',PanelAgentGuard::canonicalJson($data)); }
	file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

suite('Panel atomic durable agent workflow store')
	->contract('panel.agent-workflow-store.atomic',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('atomic-snapshot','optimistic-revision','lease-fence','idempotency','audit-integrity','gc')
	->isolation('case')
	->tag('panel','agents','durability','security')
	->group('framework-coverage');

test('durable agent workflow storage failures expose stable secret-free retry metadata',static function(Context $t): void {
	$t->throws(
		static fn()=>new PanelAgentWorkflowStorageException('Bad Code','invalid'),
		InvalidArgumentException::class,
	);
	$terminal=new PanelAgentWorkflowStorageException('storage_corrupt','Durable workflow state failed integrity validation.');
	$t->same('storage_corrupt',$terminal->errorCode());
	$t->isFalse($terminal->retryable());
	$t->same('Durable workflow state failed integrity validation.',$terminal->getMessage());
	$transient=new PanelAgentWorkflowStorageException('storage_unavailable','Durable workflow storage is unavailable.',true);
	$t->same('storage_unavailable',$transient->errorCode());
	$t->isTrue($transient->retryable());
})->tag('panel','agents','durability','storage','fail-closed')->maxMillis(3000);

test('agent audit receipts hydrate only exact canonical redacted hash-verified payloads',static function(Context $t): void {
	$actor=dp_panel_atomic_agent_context(); $plan=hash('sha256','receipt-plan');
	$receipt=PanelAgentAuditReceipt::create(1,'plan_validated',$actor,$plan,'planned',['safe'=>'yes','api_token'=>'remove-me'],'',1784016000);
	$payload=$receipt->jsonSerialize(); $t->same(1,$payload['version']); $t->same('[REDACTED]',$payload['details']['api_token']);
	$restored=PanelAgentAuditReceipt::fromArray($payload); $t->same($receipt->hash(),$restored->hash()); $t->isTrue($restored->verify(''));
	$t->same($actor->subjectFingerprint(),$restored->actorFingerprint()); $t->same('planned',$restored->code()); $t->same('[REDACTED]',$restored->details()['api_token']); $t->same('',$restored->previousHash());

	$unknown=$payload; $unknown['unknown']=true; $t->throws(static fn()=>PanelAgentAuditReceipt::fromArray($unknown),InvalidArgumentException::class);
	foreach([
		array_diff_key($payload,['version'=>true]),array_replace($payload,['version'=>2]),array_replace($payload,['type'=>'other']),array_replace($payload,['sequence'=>'1']),
		array_replace($payload,['occurred_at'=>-1]),array_replace($payload,['details'=>'bad']),array_replace($payload,['event'=>'Bad Event']),
		array_replace($payload,['scope_fingerprint'=>'bad']),array_replace($payload,['hash'=>strtoupper($payload['hash'])]),array_replace($payload,['previous_hash'=>hash('sha256','bad')]),
	] as $invalid){ $t->throws(static fn()=>PanelAgentAuditReceipt::fromArray($invalid),Throwable::class); }
	$tampered=$payload; $tampered['details']['safe']='changed'; $t->throws(static fn()=>PanelAgentAuditReceipt::fromArray($tampered),InvalidArgumentException::class);
	$oversized=$payload; $oversized['details']=['value'=>str_repeat('x',70000)]; $t->throws(static fn()=>PanelAgentAuditReceipt::fromArray($oversized),LengthException::class);
	$secret=$payload; $secret['details']=['access_token'=>'raw-secret'];
	$canonical=$secret; unset($canonical['type'],$canonical['hash']); $secret['hash']=hash('sha256',PanelAgentGuard::canonicalJson($canonical));
	$t->throws(static fn()=>PanelAgentAuditReceipt::fromArray($secret),InvalidArgumentException::class);

	$second=PanelAgentAuditReceipt::create(2,'plan_approved',$actor,$plan,'approved',[],$receipt->hash(),1784016001); $t->isTrue(PanelAgentAuditReceipt::fromArray($second->jsonSerialize())->verify($receipt->hash()));
	$broken=$second->jsonSerialize(); $broken['previous_hash']=''; $t->throws(static fn()=>PanelAgentAuditReceipt::fromArray($broken),InvalidArgumentException::class);
})->tag('panel','agents','audit','hydration')->maxMillis(5000);

test('two durable store instances share atomic revisions leases results cancellation and secret-safe manifests',static function(Context $t): void {
	$directory=$t->tempDirectory('panel-agent-atomic-shared'); $now=1784016000; $factoryCalls=0;
	$factory=static function() use (&$factoryCalls): string { $factoryCalls++; return 'agent_reservation_shared_'.$factoryCalls; };
	$first=new PanelAtomicAgentWorkflowStore($directory,static fn():int=>$now,60,32,3600,8,1048576,$factory);
	$second=new PanelAtomicAgentWorkflowStore($directory,static fn():int=>$now,60,32,3600,8,1048576,$factory);
	$initial=$first->manifest(); $t->same('verified',$initial['integrity']); $t->same(0,$initial['revision']); $t->same(0,$factoryCalls); $t->notContains($directory,json_encode($initial,JSON_THROW_ON_ERROR));
	$actor=dp_panel_atomic_agent_context(); $material=dp_panel_atomic_agent_material('shared');
	$planned=dp_panel_atomic_agent_receipt($first,$actor,$material['plan'],'plan_validated','planned',$now,['api_key'=>'hide']);
	$t->same(1,$first->append($planned,0)); $t->same(1,$second->revision()); $t->same($planned->hash(),$second->lastAuditHash()); $t->same(1,count($second->audit()));
	$reservation=$first->reserve($material['plan'],$actor->scopeFingerprint(),'shared-secret-idempotency',$material['request'],[$material['nonce']],1);
	$t->isTrue($reservation->acquiredNew()); $t->same(2,$reservation->revision()); $t->same(2,$second->revision()); $t->same(1,$factoryCalls);
	$inProgress=dp_panel_atomic_agent_error($t,static fn()=>$second->lookup($material['plan'],$actor->scopeFingerprint(),'shared-secret-idempotency',$material['request']),'execution_in_progress'); $t->same(409,$inProgress->httpStatus());
	$renewed=$second->renew((string)$reservation->id(),$reservation->revision(),90); $t->same(3,$renewed->revision()); $t->same($now+90,$renewed->expiresAt());
	dp_panel_atomic_agent_error($t,static fn()=>$first->renew((string)$reservation->id(),$reservation->revision(),60),'revision_conflict');
	$rawResult=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[['ordinal'=>1,'tool'=>'orders.update','ok'=>true,'code'=>'completed','output'=>['api_token'=>'hide','order'=>'ord-1'],'error'=>null,'retryable'=>false]],$renewed->revision(),null,['access_token'=>'hide','safe'=>'yes']);
	$completed=$second->complete((string)$renewed->id(),$rawResult,$actor,'execution_completed','executed',['idempotency_key'=>'hide','summary'=>'ok'],$now,$renewed->revision());
	$t->same(4,$completed->storeRevision()); $t->same('[REDACTED]',$completed->steps()[0]['output']['api_token']); $t->same('[REDACTED]',$completed->metadata()['access_token']); $t->same('[REDACTED]',$completed->receipt()?->details()['idempotency_key']);
	$found=$first->lookup($material['plan'],$actor->scopeFingerprint(),'shared-secret-idempotency',$material['request']); $t->same('executed',$found?->code()); $t->same($completed->receipt()?->hash(),$found?->receipt()?->hash());
	dp_panel_atomic_agent_error($t,static fn()=>$first->lookup(hash('sha256','lookup-wrong-plan'),$actor->scopeFingerprint(),'shared-secret-idempotency',$material['request']),'idempotency_conflict');
	dp_panel_atomic_agent_error($t,static fn()=>$first->lookup($material['plan'],$actor->scopeFingerprint(),'shared-secret-idempotency',hash('sha256','lookup-wrong-request')),'idempotency_conflict');
	$replay=$first->reserve($material['plan'],$actor->scopeFingerprint(),'shared-secret-idempotency',$material['request'],[$material['nonce']],4); $t->isFalse($replay->acquiredNew()); $t->same('executed',$replay->result()?->code()); $t->same(4,$replay->revision());
	dp_panel_atomic_agent_error($t,static fn()=>$first->append($planned,3),'revision_conflict');

	$cancelPlan=hash('sha256','cancel-plan'); $cancel=dp_panel_atomic_agent_receipt($first,$actor,$cancelPlan,'plan_cancelled','cancelled',$now,['reason'=>'requested']);
	$t->same(5,$second->cancel($cancelPlan,$cancel,4)); $t->isTrue($first->cancelled($cancelPlan)); $t->same(5,$first->cancel($cancelPlan,$cancel,5));
	$manifest=$second->jsonSerialize(); $t->same(true,$manifest['capabilities']['cross_process_locking']); $t->same(false,$manifest['raw_intent_nonces_stored']); $t->same(3,$manifest['counts']['audit_receipts']);
	$raw=''; foreach(glob($directory.DIRECTORY_SEPARATOR.'agent-*.json') ?: [] as $file){ $raw.=(string)file_get_contents($file); }
	foreach(['shared-secret-idempotency',$material['nonce'],'operator:1','tenant-a','session-a','hide'] as $secret){ $t->notContains($secret,$raw); }
})->tag('panel','agents','durable','cross-process')->maxMillis(10000);

test('independent php processes serialize one optimistic reservation winner through the shared filesystem lock',static function(Context $t): void {
	$directory=$t->tempDirectory('panel-agent-atomic-process-race'); $panelRoot=dirname(__DIR__); $actor=dp_panel_atomic_agent_context();
	$code=<<<'PHP'
foreach(['Support/PanelSensitiveDataSanitizer.php','Agents/PanelAgentGuard.php','Agents/PanelAgentException.php','Agents/PanelAgentWorkflowStore.php','Agents/PanelAgentAuditReceipt.php','Agents/PanelAgentExecutionResult.php','Agents/PanelAgentStoreReservation.php','Agents/PanelAtomicAgentWorkflowStore.php'] as $source){require $argv[1].'/Framework/'.$source;}
$store=new \Dataphyre\Panel\PanelAtomicAgentWorkflowStore($argv[2],static fn():int=>40000,30,8,3600,4,1048576,static fn():string=>$argv[3]);
try{$reservation=$store->reserve($argv[4],$argv[5],$argv[6],$argv[7],[$argv[8]],0);echo 'acquired:'.$reservation->revision();}
catch(\Dataphyre\Panel\PanelAgentException $exception){echo 'error:'.$exception->errorCode();}
PHP;
	$workers=[];
	foreach(['one','two'] as $worker){
		$material=dp_panel_atomic_agent_material('process-'.$worker);
		$workers[]=$t->startPhpProcess(
			['-r',$code,$panelRoot,$directory,'agent_reservation_process_'.$worker,$material['plan'],$actor->scopeFingerprint(),'process-key-'.$worker,$material['request'],$material['nonce']],
			timeout_millis:10000,
		);
	}
	$outputs=[];
	foreach($workers as $process){
		$result=$process->wait(); $output=trim($result->stdout()); $error=trim($result->stderr());
		if(!$result->succeeded()){ throw new RuntimeException("Process race worker exited {$result->exitCode()}: {$error} {$output}"); }
		$outputs[]=$output; $t->same('',$error);
	}
	sort($outputs,SORT_STRING); $t->same(['acquired:1','error:revision_conflict'],$outputs);
	$store=new PanelAtomicAgentWorkflowStore($directory,static fn():int=>40000,30,8,3600,4); $t->same(1,$store->revision()); $t->same(1,$store->manifest()['counts']['reservations']);
})->tag('panel','agents','durable','cross-process','race')->maxMillis(15000);

test('the full agent runtime executes and recovers an idempotent result through a freshly constructed durable store adapter',static function(Context $t): void {
	$directory=$t->tempDirectory('panel-agent-atomic-runtime'); $now=50000; $clock=static function()use(&$now):int{return $now;}; $actor=dp_panel_atomic_agent_context();
	$resolver=new class implements PanelAgentPolicyResolver {
		public function decide(PanelAgentRequestContext $context,PanelAgentTool $tool,array $arguments):PanelAgentPolicyDecision{return PanelAgentPolicyDecision::allow('Host allowed durable execution.');}
		public function approve(PanelAgentRequestContext $approver,PanelAgentPlan $plan):PanelAgentPolicyDecision{return PanelAgentPolicyDecision::allow('Host allowed durable approval.');}
		public function fingerprint():string{return hash('sha256','atomic-runtime-policy');}
	};
	$executor=new class implements PanelAgentToolExecutor {
		public int $calls=0;
		public function execute(PanelAgentToolExecutionRequest $request):PanelAgentToolExecutionResult{$this->calls++;return PanelAgentToolExecutionResult::success(['order_id'=>$request->arguments()['order_id'],'access_token'=>'never-persist']);}
	};
	$catalog=new PanelAgentToolCatalog(); $catalog->register(new PanelAgentTool('orders.peek','1.0','Read one bounded order.','orders.peek','low',true,false,0,false,['type'=>'object','required'=>['order_id'],'additionalProperties'=>false,'properties'=>['order_id'=>['type'=>'string','maxLength'=>32]]]),$executor,'core');
	$policy=new PanelAgentPolicyEngine($resolver); $signer=new PanelAgentIntentSigner(['current'=>str_repeat('k',32)],'current',$clock); $store=new PanelAtomicAgentWorkflowStore($directory,$clock,120,32,3600,8,1048576,static fn():string=>'agent_reservation_runtime');
	$runtime=new PanelAgentRuntime($catalog,$policy,$signer,$store,$clock); $envelope=$runtime->prepare(['title'=>'Read one order','steps'=>[['tool'=>'orders.peek','arguments'=>['order_id'=>'ord-7']]]],$actor,$catalog->revision(),0);
	$t->same(1,$envelope->storeRevision()); $result=$runtime->execute($envelope->plan(),$envelope->intent()->token(),$actor,[],'runtime-idempotency',1); $t->isTrue($result->ok()); $t->same(4,$result->storeRevision()); $t->same(1,$executor->calls); $t->same('[REDACTED]',$result->steps()[0]['output']['access_token']);
	$reopened=new PanelAtomicAgentWorkflowStore($directory,$clock,120,32,3600,8); $recovered=(new PanelAgentRuntime($catalog,$policy,$signer,$reopened,$clock))->result($envelope->plan(),$actor,'runtime-idempotency');
	$t->isTrue($recovered?->replayed()===true); $t->same('idempotent_replay',$recovered?->code()); $t->same($result->receipt()?->hash(),$recovered?->receipt()?->hash()); $t->same(1,$executor->calls);
})->tag('panel','agents','durable','runtime','recovery')->maxMillis(10000);

test('expired reservations reject late owners and reclaim only the original scope request and intent set',static function(Context $t): void {
	$directory=$t->tempDirectory('panel-agent-atomic-race'); $now=10000; $ids=0; $factory=static function() use (&$ids):string { return 'agent_reservation_race_'.(++$ids); };
	$clock=static function() use (&$now):int{return $now;}; $owner=new PanelAtomicAgentWorkflowStore($directory,$clock,30,16,3600,8,1048576,$factory); $contender=new PanelAtomicAgentWorkflowStore($directory,$clock,30,16,3600,8,1048576,$factory);
	$actor=dp_panel_atomic_agent_context(); $other=dp_panel_atomic_agent_context('operator:2'); $material=dp_panel_atomic_agent_material('race');
	$lease=$owner->reserve($material['plan'],$actor->scopeFingerprint(),'race-key',$material['request'],[$material['nonce']],0); $t->same(1,$lease->revision());
	$now=10030; $t->same(null,$contender->lookup($material['plan'],$actor->scopeFingerprint(),'race-key',$material['request']));
	dp_panel_atomic_agent_error($t,static fn()=>$owner->renew((string)$lease->id(),$lease->revision(),30),'reservation_expired');
	$late=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[],$lease->revision());
	dp_panel_atomic_agent_error($t,static fn()=>$owner->complete((string)$lease->id(),$late,$actor,'execution_completed','executed',[],$now,$lease->revision()),'reservation_expired');
	$otherNonce=substr(hash('sha256','other-nonce'),0,32);
	dp_panel_atomic_agent_error($t,static fn()=>$contender->reserve($material['plan'],$actor->scopeFingerprint(),'race-key',$material['request'],[$otherNonce],1),'intent_replayed');
	dp_panel_atomic_agent_error($t,static fn()=>$contender->reserve(hash('sha256','changed-plan'),$actor->scopeFingerprint(),'race-key',$material['request'],[$material['nonce']],1),'idempotency_conflict');
	dp_panel_atomic_agent_error($t,static fn()=>$contender->reserve($material['plan'],$actor->scopeFingerprint(),'race-key',hash('sha256','changed-request'),[$material['nonce']],1),'idempotency_conflict');
	$reclaimed=$contender->reserve($material['plan'],$actor->scopeFingerprint(),'race-key',$material['request'],[$material['nonce']],1); $t->same(2,$reclaimed->revision()); $t->isTrue($reclaimed->id()!==$lease->id());
	dp_panel_atomic_agent_error($t,static fn()=>$owner->complete((string)$lease->id(),$late,$actor,'execution_completed','executed',[],$now,$lease->revision()),'reservation_invalid');
	$renewed=$contender->renew((string)$reclaimed->id(),$reclaimed->revision(),60); $t->same(3,$renewed->revision()); $t->same($now+60,$renewed->expiresAt());
	dp_panel_atomic_agent_error($t,static fn()=>$owner->reserve(dp_panel_atomic_agent_material('fresh')['plan'],$actor->scopeFingerprint(),'fresh-key',dp_panel_atomic_agent_material('fresh')['request'],[$material['nonce']],3),'intent_replayed');
	$t->same(null,$owner->lookup($material['plan'],$other->scopeFingerprint(),'race-key',$material['request']));
	$wrongScope=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[],$renewed->revision());
	dp_panel_atomic_agent_error($t,static fn()=>$contender->complete((string)$renewed->id(),$wrongScope,$other,'execution_completed','executed',[],$now,$renewed->revision()),'reservation_scope_mismatch');
	$complete=$contender->complete((string)$renewed->id(),$wrongScope,$actor,'execution_completed','executed',[],$now,$renewed->revision()); $t->same(4,$complete->storeRevision());
	dp_panel_atomic_agent_error($t,static fn()=>$owner->complete((string)$renewed->id(),$wrongScope,$actor,'execution_completed','executed',[],$now,$renewed->revision()),'reservation_invalid');
})->tag('panel','agents','leases','fencing','races')->maxMillis(10000);

test('explicit garbage collection bounds replay state while retaining the audit chain and cancellation tombstones by default',static function(Context $t): void {
	$directory=$t->tempDirectory('panel-agent-atomic-gc'); $now=20000; $ids=0;
	$store=new PanelAtomicAgentWorkflowStore($directory,static function() use (&$now):int{return $now;},30,8,3600,2,1048576,static function() use (&$ids):string{return 'agent_reservation_gc_'.(++$ids);});
	$actor=dp_panel_atomic_agent_context(); $completeMaterial=dp_panel_atomic_agent_material('gc-complete');
	$lease=$store->reserve($completeMaterial['plan'],$actor->scopeFingerprint(),'complete-key',$completeMaterial['request'],[$completeMaterial['nonce']],0);
	$result=PanelAgentExecutionResult::make(true,'executed',$completeMaterial['plan'],[],$lease->revision()); $store->complete((string)$lease->id(),$result,$actor,'execution_completed','executed',[],$now,$lease->revision());
	$pendingMaterial=dp_panel_atomic_agent_material('gc-pending'); $store->reserve($pendingMaterial['plan'],$actor->scopeFingerprint(),'pending-key',$pendingMaterial['request'],[$pendingMaterial['nonce']],2);
	$cancelPlan=hash('sha256','gc-cancel'); $cancel=dp_panel_atomic_agent_receipt($store,$actor,$cancelPlan,'plan_cancelled','cancelled',$now); $store->cancel($cancelPlan,$cancel,3);
	$now+=3631; $first=$store->collectGarbage(1); $t->isTrue($first['changed']); $t->same(1,$first['completed_reservations']); $t->same(0,$first['abandoned_reservations']); $t->same(1,$first['nonce_tombstones']);
	$second=$store->collectGarbage(1); $t->same(1,$second['abandoned_reservations']); $t->isTrue($store->cancelled($cancelPlan)); $t->same(2,$second['audit_receipts_retained']);
	$noop=$store->collectGarbage(10); $t->isFalse($noop['changed']); $t->same($second['revision'],$noop['revision']);
	$pruned=$store->collectGarbage(10,true); $t->same(1,$pruned['cancellations']); $t->isFalse($store->cancelled($cancelPlan)); $t->same(2,$pruned['audit_receipts_retained']);
	$t->same(null,$store->lookup($completeMaterial['plan'],$actor->scopeFingerprint(),'complete-key',$completeMaterial['request']));
	$t->isTrue(count(glob($directory.DIRECTORY_SEPARATOR.'agent-*.json') ?: [])<=2);
	$t->throws(static fn()=>$store->collectGarbage(0),InvalidArgumentException::class); $t->throws(static fn()=>$store->collectGarbage(100001),InvalidArgumentException::class);
})->tag('panel','agents','gc','retention','capacity')->maxMillis(10000);

test('newest corrupt truncated or structurally forged workflow snapshots fail closed without callback or path leakage',static function(Context $t): void {
	$directory=$t->tempDirectory('panel-agent-atomic-corrupt'); $now=30000; $factoryCalls=0;
	$store=new PanelAtomicAgentWorkflowStore($directory,static fn():int=>$now,30,8,3600,8,1048576,static function()use(&$factoryCalls):string{$factoryCalls++;return 'agent_reservation_corrupt_'.$factoryCalls;});
	$actor=dp_panel_atomic_agent_context(); $material=dp_panel_atomic_agent_material('corrupt'); $store->reserve($material['plan'],$actor->scopeFingerprint(),'corrupt-key',$material['request'],[$material['nonce']],0); $t->same(1,$factoryCalls);
	file_put_contents($directory.DIRECTORY_SEPARATOR.'.agent-crash.tmp','truncated'); $t->same(1,$store->revision());
	$forged=$directory.DIRECTORY_SEPARATOR.'agent-00000000000000000002.json'; file_put_contents($forged,'{"type":');
	$t->throws(static fn()=>$store->revision(),UnexpectedValueException::class); $manifest=$store->manifest(); $t->same('failed_closed',$manifest['integrity']); $t->same(null,$manifest['revision']); $t->same(1,$factoryCalls); $t->notContains($directory,json_encode($manifest,JSON_THROW_ON_ERROR));
	unlink($forged); $file=dp_panel_atomic_agent_snapshot($directory); $original=(string)file_get_contents($file);
	dp_panel_atomic_agent_rewrite($file,static function(array &$snapshot):void{$snapshot['unknown']=true;}); $t->throws(static fn()=>$store->revision(),UnexpectedValueException::class);
	file_put_contents($file,$original); dp_panel_atomic_agent_rewrite($file,static function(array &$snapshot):void{$snapshot['state']['nonces'][hash('sha256','forged')]='missing';}); $t->throws(static fn()=>$store->revision(),Throwable::class);
	file_put_contents($file,$original); dp_panel_atomic_agent_rewrite($file,static function(array &$snapshot):void{$snapshot['state']['revision']=99;}); $t->throws(static fn()=>$store->revision(),UnexpectedValueException::class);
	file_put_contents($file,$original); dp_panel_atomic_agent_rewrite($file,static function(array &$snapshot):void{$snapshot['state']['reservations'][array_key_first($snapshot['state']['reservations'])]['scope']='invalid';}); $t->throws(static fn()=>$store->revision(),Throwable::class);
	file_put_contents($file,$original); dp_panel_atomic_agent_rewrite($file,static function(array &$snapshot):void{$snapshot['state']['unknown']=true;}); $t->throws(static fn()=>$store->revision(),UnexpectedValueException::class);
	file_put_contents($file,$original); dp_panel_atomic_agent_rewrite($file,static function(array &$snapshot):void{$snapshot['state']['reservations'][array_key_first($snapshot['state']['reservations'])]['status']='invalid';}); $t->throws(static fn()=>$store->revision(),UnexpectedValueException::class);
	file_put_contents($file,substr($original,0,20)); $t->throws(static fn()=>$store->audit(),UnexpectedValueException::class);

	$resultDirectory=$t->tempDirectory('panel-agent-atomic-corrupt-result'); $resultStore=new PanelAtomicAgentWorkflowStore($resultDirectory,static fn():int=>31000,30,8,3600,8,1048576,static fn():string=>'agent_reservation_result');
	$resultMaterial=dp_panel_atomic_agent_material('corrupt-result'); $resultLease=$resultStore->reserve($resultMaterial['plan'],$actor->scopeFingerprint(),'result-key',$resultMaterial['request'],[$resultMaterial['nonce']],0);
	$resultStore->complete((string)$resultLease->id(),PanelAgentExecutionResult::make(true,'executed',$resultMaterial['plan'],[],$resultLease->revision()),$actor,'execution_completed','executed',[],31000,$resultLease->revision());
	$resultFile=dp_panel_atomic_agent_snapshot($resultDirectory); $resultOriginal=(string)file_get_contents($resultFile);
	dp_panel_atomic_agent_rewrite($resultFile,static function(array &$snapshot):void{$id=array_key_first($snapshot['state']['reservations']);$snapshot['state']['reservations'][$id]['result']['replayed']=true;}); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($resultFile,$resultOriginal); dp_panel_atomic_agent_rewrite($resultFile,static function(array &$snapshot):void{$id=array_key_first($snapshot['state']['reservations']);$snapshot['state']['reservations'][$id]['result']['code']='Bad Code';}); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($resultFile,$resultOriginal); dp_panel_atomic_agent_rewrite($resultFile,static function(array &$snapshot):void{$id=array_key_first($snapshot['state']['reservations']);$deep='x';for($index=0;$index<16;$index++){$deep=[$deep];}$snapshot['state']['reservations'][$id]['result']['metadata']=['deep'=>$deep];}); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($resultFile,$resultOriginal); dp_panel_atomic_agent_rewrite($resultFile,static function(array &$snapshot):void{$id=array_key_first($snapshot['state']['reservations']);$snapshot['state']['reservations'][$id]['result']['code']='other';}); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($resultFile,$resultOriginal); dp_panel_atomic_agent_rewrite($resultFile,static function(array &$snapshot):void{$snapshot['state']['audit'][0]['code']='tampered';}); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($resultFile,$resultOriginal); dp_panel_atomic_agent_rewrite($resultFile,static function(array &$snapshot):void{$snapshot['state']['audit'][]=[];$snapshot['state']['audit'][]=[];}); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($resultFile,$resultOriginal); dp_panel_atomic_agent_rewrite($resultFile,static function(array &$snapshot):void{$id=array_key_first($snapshot['state']['reservations']);$snapshot['state']['reservations'][$id]['result']['store_revision']=$snapshot['state']['reservations'][$id]['lease_revision'];}); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($resultFile,$resultOriginal); $resultFiles=glob($resultDirectory.DIRECTORY_SEPARATOR.'agent-*.json') ?: []; sort($resultFiles,SORT_STRING); $predecessor=$resultFiles[0]; $predecessorOriginal=(string)file_get_contents($predecessor); file_put_contents($predecessor,$predecessorOriginal."\n"); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
	file_put_contents($predecessor,$predecessorOriginal); unlink($predecessor); $t->throws(static fn()=>$resultStore->revision(),UnexpectedValueException::class);
})->tag('panel','agents','corruption','fail-closed')->maxMillis(10000);

test('durable store rejects unsafe configuration factories nonces results receipts capacity and clocks without committing',static function(Context $t): void {
	$base=$t->tempDirectory('panel-agent-atomic-invalid');
	foreach([
		static fn()=>new PanelAtomicAgentWorkflowStore(' '),static fn()=>new PanelAtomicAgentWorkflowStore("bad\0path"),
		static fn()=>new PanelAtomicAgentWorkflowStore($base,null,29),static fn()=>new PanelAtomicAgentWorkflowStore($base,null,3601),
		static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,0),static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,100001),
		static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,1,3599),static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,1,31536001),
		static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,1,3600,1),static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,1,3600,257),
		static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,1,3600,2,1048575),static fn()=>new PanelAtomicAgentWorkflowStore($base,null,30,1,3600,2,536870913),
	] as $invalid){ $t->throws($invalid,Throwable::class); }
	$file=$base.DIRECTORY_SEPARATOR.'not-directory'; file_put_contents($file,'x'); $t->throws(static fn()=>new PanelAtomicAgentWorkflowStore($file),RuntimeException::class);
	$actor=dp_panel_atomic_agent_context(); $material=dp_panel_atomic_agent_material('invalid');
	$badClock=new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-agent-bad-clock'),static fn():string=>'bad'); $t->same('verified',$badClock->manifest()['integrity']); $t->throws(static fn()=>$badClock->reserve($material['plan'],$actor->scopeFingerprint(),'key',$material['request'],[$material['nonce']],0),UnexpectedValueException::class);
	$badFactory=new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-agent-bad-factory'),static fn():int=>1,30,8,3600,2,1048576,static fn():array=>[]);
	$t->throws(static fn()=>$badFactory->reserve($material['plan'],$actor->scopeFingerprint(),'key',$material['request'],[$material['nonce']],0),UnexpectedValueException::class); $t->same(0,$badFactory->revision());
	$invalidFactory=new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-agent-invalid-factory'),static fn():int=>1,30,8,3600,2,1048576,static fn():string=>'bad id');
	$t->throws(static fn()=>$invalidFactory->reserve($material['plan'],$actor->scopeFingerprint(),'key',$material['request'],[$material['nonce']],0),UnexpectedValueException::class);
	$collisionStore=new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-agent-collision'),static fn():int=>1,30,8,3600,2,1048576,static fn():string=>'agent_reservation_same');
	$collisionStore->reserve($material['plan'],$actor->scopeFingerprint(),'one',$material['request'],[$material['nonce']],0); $fresh=dp_panel_atomic_agent_material('collision-two');
	dp_panel_atomic_agent_error($t,static fn()=>$collisionStore->reserve($fresh['plan'],$actor->scopeFingerprint(),'two',$fresh['request'],[$fresh['nonce']],1),'reservation_id_collision');
	foreach([[],[$material['nonce'],$material['nonce']],['bad'],[1],[str_repeat('a',32),str_repeat('b',32),str_repeat('c',32),str_repeat('d',32)]] as $nonces){ dp_panel_atomic_agent_error($t,static fn()=>$invalidFactory->reserve($material['plan'],$actor->scopeFingerprint(),'key',$material['request'],$nonces,0),'nonce_invalid'); }

	$capacity=new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-agent-capacity'),static fn():int=>100,30,1,3600,2,1048576,static fn():string=>'agent_reservation_capacity');
	$lease=$capacity->reserve($material['plan'],$actor->scopeFingerprint(),'one',$material['request'],[$material['nonce']],0); $next=dp_panel_atomic_agent_material('capacity-next');
	dp_panel_atomic_agent_error($t,static fn()=>$capacity->reserve($next['plan'],$actor->scopeFingerprint(),'two',$next['request'],[$next['nonce']],1),'store_capacity_exceeded');
	$t->throws(static fn()=>$capacity->renew((string)$lease->id(),$lease->revision(),29),InvalidArgumentException::class); $t->throws(static fn()=>$capacity->renew((string)$lease->id(),$lease->revision(),3601),InvalidArgumentException::class);
	$wrongPlan=PanelAgentExecutionResult::make(true,'executed',$next['plan'],[],$lease->revision()); dp_panel_atomic_agent_error($t,static fn()=>$capacity->complete((string)$lease->id(),$wrongPlan,$actor,'execution_completed','executed',[],100,$lease->revision()),'reservation_result_invalid');
	$wrongRevision=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[],0); dp_panel_atomic_agent_error($t,static fn()=>$capacity->complete((string)$lease->id(),$wrongRevision,$actor,'execution_completed','executed',[],100,$lease->revision()),'reservation_result_invalid');
	$unsafe=PanelAgentAuditReceipt::create(1,'plan_validated',$actor,$material['plan'],'planned',['nonce'=>'raw-replay-proof'],'',100); dp_panel_atomic_agent_error($t,static fn()=>$capacity->append($unsafe,1),'audit_details_unsafe');
	$badReceipt=PanelAgentAuditReceipt::create(1,'plan_validated',$actor,$material['plan'],'planned',[],'',100); dp_panel_atomic_agent_error($t,static fn()=>$capacity->cancel($material['plan'],$badReceipt,1),'audit_chain_invalid');

	$byteStore=new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-agent-byte-capacity'),static fn():int=>100,30,8,3600,2,1048576,static fn():string=>'agent_reservation_bytes'); $byteMaterial=dp_panel_atomic_agent_material('bytes');
	$byteLease=$byteStore->reserve($byteMaterial['plan'],$actor->scopeFingerprint(),'byte-key',$byteMaterial['request'],[$byteMaterial['nonce']],0);
	$blobs=array_fill(0,15,str_repeat('x',65536)); $blobs[]=str_repeat('y',64000); $largeResult=PanelAgentExecutionResult::make(true,'executed',$byteMaterial['plan'],[['blobs'=>$blobs]],$byteLease->revision());
	dp_panel_atomic_agent_error($t,static fn()=>$byteStore->complete((string)$byteLease->id(),$largeResult,$actor,'execution_completed','executed',[],100,$byteLease->revision()),'store_capacity_exceeded'); $t->same(1,$byteStore->revision());
})->tag('panel','agents','validation','capacity')->maxMillis(10000);
