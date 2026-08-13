<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelDataSubscriptionRealtimeBroker;
use Dataphyre\Panel\PanelInMemoryRealtimeBroker;
use Dataphyre\Panel\PanelInMemoryRealtimeIntentReplayPolicy;
use Dataphyre\Panel\PanelRealtimeBroker;
use Dataphyre\Panel\PanelRealtimeCancellationToken;
use Dataphyre\Panel\PanelRealtimeClientAssets;
use Dataphyre\Panel\PanelRealtimeContext;
use Dataphyre\Panel\PanelRealtimeEndpoint;
use Dataphyre\Panel\PanelRealtimeEvent;
use Dataphyre\Panel\PanelRealtimeException;
use Dataphyre\Panel\PanelRealtimeGuard;
use Dataphyre\Panel\PanelRealtimeIntentSigner;
use Dataphyre\Panel\PanelRealtimeReadResult;
use Dataphyre\Panel\PanelRealtimeSseEncoder;
use Dataphyre\Panel\PanelRealtimeSseResponse;
use Dataphyre\Panel\PanelRealtimeStreamOptions;
use Dataphyre\Panel\PanelRealtimeSubscription;
use Dataphyre\Panel\PanelRealtimeSubscriptionIntentReplayPolicy;
use Dataphyre\Panel\PanelRealtimeTelemetry;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\Testing\PanelRealtimeBrokerConformance;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
require_once dirname(__DIR__).'/testing/PanelRealtimeBrokerConformance.php';

final class DpPanelRealtimeClock {
	public function __construct(public int $now=1000){}
	public function __invoke(): int { return $this->now; }
}

final class DpPanelRealtimeThrowingBroker implements PanelRealtimeBroker {
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?\Dataphyre\Panel\PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult { throw new RuntimeException('secret broker internals'); }
	public function jsonSerialize(): array { return ['type'=>'test_throwing_broker','secret_exposed'=>false]; }
}

final class DpPanelRealtimeTypedFailureBroker implements PanelRealtimeBroker {
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?\Dataphyre\Panel\PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult { throw new PanelRealtimeException('broker_capacity',503,'typed broker secret',true); }
	public function jsonSerialize(): array { return ['type'=>'test_typed_failure_broker']; }
}

final class DpPanelRealtimeThrowingReplayPolicy implements PanelRealtimeSubscriptionIntentReplayPolicy {
	public function consume(\Dataphyre\Panel\PanelRealtimeIntentVerification $intent, PanelRealtimeSubscription $subscription, PanelRealtimeContext $context): bool { throw new RuntimeException('secret replay store failure'); }
	public function jsonSerialize(): array { return ['type'=>'test_throwing_replay_policy','secret_exposed'=>false]; }
}

final class DpPanelRealtimeManifestTrapBroker implements PanelRealtimeBroker {
	public int $manifestCalls=0;
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?\Dataphyre\Panel\PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult { return new PanelRealtimeReadResult($afterSequence,[],$afterSequence,$afterSequence,$afterSequence+1); }
	public function jsonSerialize(): array { $this->manifestCalls++; throw new RuntimeException('broker manifest secret'); }
}

final class DpPanelRealtimeAdversarialReplayPolicy implements PanelRealtimeSubscriptionIntentReplayPolicy {
	public int $manifestCalls=0;
	public function consume(\Dataphyre\Panel\PanelRealtimeIntentVerification $intent, PanelRealtimeSubscription $subscription, PanelRealtimeContext $context): bool { throw new PanelRealtimeException('custom_policy_denied',429,'replay adapter password=super-secret',true); }
	public function jsonSerialize(): array { $this->manifestCalls++; throw new RuntimeException('replay manifest secret'); }
}

final class DpPanelRealtimeForeignEventBroker implements PanelRealtimeBroker {
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?\Dataphyre\Panel\PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult {
		$event=new PanelRealtimeEvent(1,$subscription->streamKey(),'foreign','orders.updated','orders.updated','2026-07-14T12:00:00Z',['foreign_secret'=>'must-not-emit']);
		return new PanelRealtimeReadResult($afterSequence,[$event],1,1,1);
	}
	public function jsonSerialize(): array { return ['type'=>'test_foreign_event_broker']; }
}

final class DpPanelRealtimeOverproducingBroker implements PanelRealtimeBroker {
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?\Dataphyre\Panel\PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult {
		$one=new PanelRealtimeEvent(1,$subscription->streamKey(),$subscription->channel(),'orders.updated','orders.updated','2026-07-14T12:00:00Z',['id'=>1]); $two=new PanelRealtimeEvent(2,$subscription->streamKey(),$subscription->channel(),'orders.updated','orders.updated','2026-07-14T12:00:01Z',['id'=>2]);
		return new PanelRealtimeReadResult($afterSequence,[$one,$two],2,2,1);
	}
	public function jsonSerialize(): array { return ['type'=>'test_overproducing_broker']; }
}

final class DpPanelRealtimeCancellingBroker implements PanelRealtimeBroker {
	public function __construct(private readonly DpPanelRealtimeClock $clock,private readonly bool $deadline=false){}
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?\Dataphyre\Panel\PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult { if($this->deadline){$this->clock->now+=5;} throw new PanelRealtimeException('read_cancelled',408,'adapter diagnostic secret'); }
	public function jsonSerialize(): array { return ['type'=>'test_cancelling_broker']; }
}

final class DpPanelRealtimeLateReturningBroker implements PanelRealtimeBroker {
	public function __construct(private readonly DpPanelRealtimeClock $clock,private readonly ?PanelRealtimeCancellationToken $cancellation=null,private readonly bool $advanceDeadline=false){}
	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?\Dataphyre\Panel\PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult {
		if($this->advanceDeadline){$this->clock->now+=5;} $this->cancellation?->cancel(); $event=new PanelRealtimeEvent(1,$subscription->streamKey(),$subscription->channel(),'orders.updated','orders.updated','2026-07-14T12:00:00Z',['late_secret'=>'must-not-emit']); return new PanelRealtimeReadResult($afterSequence,[$event],1,1,1);
	}
	public function jsonSerialize(): array { return ['type'=>'test_late_returning_broker']; }
}

/** @return array{clock:DpPanelRealtimeClock,context:PanelRealtimeContext,other:PanelRealtimeContext,subscription:PanelRealtimeSubscription,broker:PanelInMemoryRealtimeBroker,signer:PanelRealtimeIntentSigner} */
function dp_panel_realtime_fixture(array $options=[]): array {
	$clock=$options['clock'] ?? new DpPanelRealtimeClock();
	$context=PanelRealtimeContext::fromTrusted('operations',['tenant_id'=>'north','principal_id'=>'operator-7','correlation_id'=>'corr / 7']);
	$other=PanelRealtimeContext::fromTrusted('operations',['tenant_id'=>'south','principal_id'=>'operator-8','correlation_id'=>'other']);
	$subscription=PanelRealtimeSubscription::fromTrusted($context,'orders',$options['topics'] ?? ['orders.created','orders.updated'],$options['filters'] ?? []);
	$broker=new PanelInMemoryRealtimeBroker($options['retained'] ?? 32,$options['streams'] ?? 8,$options['event_bytes'] ?? 196608,$clock);
	$signer=new PanelRealtimeIntentSigner(['previous'=>str_repeat('p',32),'active'=>str_repeat('a',32)],'active',$clock);
	return compact('clock','context','other','subscription','broker','signer');
}

/** @param callable(array<string,mixed>,array<string,mixed>):array{array<string,mixed>,array<string,mixed>} $mutate */
function dp_panel_realtime_resign(string $token, string $secret, callable $mutate): string {
	[$head,$body]=array_slice(explode('.',$token),0,2);
	$header=json_decode((string)PanelRealtimeGuard::decode($head),true,8,JSON_THROW_ON_ERROR);
	$payload=json_decode((string)PanelRealtimeGuard::decode($body),true,32,JSON_THROW_ON_ERROR);
	[$header,$payload]=$mutate($header,$payload);
	$input=PanelRealtimeGuard::encode(PanelRealtimeGuard::canonicalJson($header)).'.'.PanelRealtimeGuard::encode(PanelRealtimeGuard::canonicalJson($payload));
	return $input.'.'.PanelRealtimeGuard::encode(hash_hmac('sha256',$input,$secret,true));
}

/** @return list<string> */
function dp_panel_realtime_ids(string $chunk): array { preg_match_all('/^id: ([^\r\n]+)$/m',$chunk,$matches); return $matches[1] ?? []; }

test('realtime scope subscription guards and public manifests remain redacted',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['filters'=>['status'=>'paid']]);
	$t->same('operations',$f['context']->panel()); $t->same('north',$f['context']->tenant()); $t->same('operator-7',$f['context']->principal());
	$t->same('corr7',$f['context']->correlationId()); $t->same('fallback',$f['context']->get('missing','fallback'));
	$t->same(['status'=>'paid'],$f['subscription']->filters()); $t->same(['orders.created','orders.updated'],$f['subscription']->topics()); $t->same('orders',$f['subscription']->channel());
	$security=PanelSecurityContext::make('operator-9',['tenant_id'=>'west','attributes'=>['correlation_id'=>'security-1']]); $t->same('west',PanelRealtimeContext::fromTrusted('operations',$security)->tenant());
	$public=json_encode([$f['context'],$f['subscription']],JSON_THROW_ON_ERROR);
	$t->notContains('north',$public); $t->notContains('operator-7',$public); $t->notContains('paid',$public); $t->contains('tenant_bound',$public);
	$t->throws(static fn()=>PanelRealtimeContext::fromTrusted('panel',['tenant_id'=>'north']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeContext::fromTrusted('bad panel',['tenant_id'=>'north','principal_id'=>'one']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeSubscription::fromTrusted($f['context'],'orders',[]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeSubscription::fromTrusted($f['context'],'orders',['*','orders.created']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeSubscription::fromTrusted($f['context'],'orders',['bad topic']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeSubscription::fromTrusted($f['context'],'orders',['*'],['bad'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeGuard::identifier('bad value'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeGuard::text("bad\n",'value'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelRealtimeGuard::assertJson(INF),InvalidArgumentException::class);
	PanelRealtimeGuard::assertJson(1.5); $t->isTrue(true);
	$t->throws(static fn()=>new PanelRealtimeEvent(1,$f['subscription']->streamKey(),'Orders','orders.updated','orders.updated','2026-07-14T12:00:00Z',[]),InvalidArgumentException::class);
	$t->same(null,PanelRealtimeGuard::decode('***')); $t->same('safe',PanelRealtimeGuard::decode(PanelRealtimeGuard::encode('safe')));
})->tag('panel','realtime','scope','security')->maxMillis(1000);

test('realtime client asset is isolated versioned fetch streaming code without evaluation',static function(Context $t): void {
	$javascript=PanelRealtimeClientAssets::javascript(); $content=PanelRealtimeClientAssets::content(); $versioned=PanelRealtimeClientAssets::content(true); $manifest=PanelRealtimeClientAssets::manifest();
	$t->greaterThan(20000,strlen($javascript)); $t->matches('/^[a-f0-9]{16}$/',PanelRealtimeClientAssets::version());
	$t->same('application/javascript; charset=utf-8',$content['content_type']); $t->same($javascript,$content['body']); $t->same('no-cache',$content['cache_control']); $t->contains('immutable',$versioned['cache_control']);
	$t->contains('fetch_streamed_sse',json_encode($manifest,JSON_THROW_ON_ERROR)); $t->contains('AbortController',$javascript); $t->contains('Last-Event-ID',$javascript);
	$t->notContains('new EventSource',$javascript); $t->notContains('eval(',$javascript); $t->notContains('.innerHTML',$javascript); $t->isFalse($manifest['shared_asset_registered']);
})->tag('panel','realtime','client','asset')->maxMillis(1000);

test('realtime intents rotate keys bind every scope and never serialize credentials',static function(Context $t): void {
	$f=dp_panel_realtime_fixture();
	$subscribe=$f['signer']->issueSubscription($f['subscription'],60); $resume=$f['signer']->issueResume($f['subscription'],42,60);
	$t->same('subscribe',$subscribe->purpose()); $t->same(1000,$subscribe->issuedAt()); $t->same(1060,$subscribe->expiresAt());
	$t->same('subscribe',$f['signer']->verify($subscribe->token(),$f['subscription'],$f['context'],'subscribe')->purpose());
	$verified=$f['signer']->verify($resume->token(),$f['subscription'],$f['context'],'resume'); $t->same(42,$verified->cursor()); $t->same(1000,$verified->issuedAt()); $t->same(1060,$verified->expiresAt());
	$t->notContains($subscribe->token(),json_encode([$subscribe,$verified,$f['signer']],JSON_THROW_ON_ERROR));
	$oldSigner=new PanelRealtimeIntentSigner(['old'=>str_repeat('o',32)],'old',$f['clock']);
	$old=$oldSigner->issueSubscription($f['subscription'],60);
	$rotated=new PanelRealtimeIntentSigner(['new'=>str_repeat('n',32),'old'=>str_repeat('o',32)],'new',$f['clock']);
	$t->same('old',$rotated->verify($old->token(),$f['subscription'],$f['context'],'subscribe')->keyId());
	$t->same('new',$rotated->issueSubscription($f['subscription'])->keyId());
	$t->throws(static fn()=>$f['signer']->verify($subscribe->token(),$f['subscription'],$f['other'],'subscribe'),PanelRealtimeException::class);
	$different=PanelRealtimeSubscription::fromTrusted($f['context'],'orders',['*']);
	$t->throws(static fn()=>$f['signer']->verify($subscribe->token(),$different,$f['context'],'subscribe'),PanelRealtimeException::class);
	$t->throws(static fn()=>$f['signer']->verify($subscribe->token(),$f['subscription'],$f['context'],'resume'),PanelRealtimeException::class);
	$t->throws(static fn()=>$f['signer']->issueResume($f['subscription'],-1),InvalidArgumentException::class);
	$t->throws(static fn()=>$f['signer']->issueSubscription($f['subscription'],5),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelRealtimeIntentSigner([], 'none'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelRealtimeIntentSigner(['short'=>'x'],'short'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelRealtimeIntentSigner(['ok'=>str_repeat('x',32)],'missing'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelRealtimeIntentSigner(['A'=>str_repeat('a',32),'a'=>str_repeat('b',32)],'a'),InvalidArgumentException::class);
})->tag('panel','realtime','intent','rotation','security')->maxMillis(1000);

test('realtime intent parser rejects tampering confusion extra claims future and expiry',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(); $token=$f['signer']->issueSubscription($f['subscription'],30)->token();
	$t->throws(static fn()=>$f['signer']->verify(substr($token,0,-1).'x',$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class);
	$alg=dp_panel_realtime_resign($token,str_repeat('a',32),static function(array $header,array $payload): array{$header['alg']='none';return [$header,$payload];});
	$t->throws(static fn()=>$f['signer']->verify($alg,$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class);
	$extra=dp_panel_realtime_resign($token,str_repeat('a',32),static function(array $header,array $payload): array{$payload['admin']=true;return [$header,$payload];});
	$t->throws(static fn()=>$f['signer']->verify($extra,$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class);
	$cursor=dp_panel_realtime_resign($token,str_repeat('a',32),static function(array $header,array $payload): array{$payload['cursor']=1;return [$header,$payload];});
	$t->throws(static fn()=>$f['signer']->verify($cursor,$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class);
	$nonce=dp_panel_realtime_resign($token,str_repeat('a',32),static function(array $header,array $payload): array{$payload['nonce']=[];return [$header,$payload];});
	$t->throws(static fn()=>$f['signer']->verify($nonce,$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class);
	$t->throws(static fn()=>$f['signer']->verify('bad.token.parts',$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class);
	$f['clock']->now=1040; $expired=$t->throws(static fn()=>$f['signer']->verify($token,$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class); $t->same('intent_expired',$expired->publicCode());
	$f['clock']->now=1000; $futureClock=new DpPanelRealtimeClock(1100); $futureSigner=new PanelRealtimeIntentSigner(['active'=>str_repeat('a',32)],'active',$futureClock); $future=$futureSigner->issueSubscription($f['subscription'],60);
	$t->throws(static fn()=>$f['signer']->verify($future->token(),$f['subscription'],$f['context'],'subscribe'),PanelRealtimeException::class);
})->tag('panel','realtime','intent','adversarial')->maxMillis(1000);

test('in-memory broker provides bounded ordered replay filtering and explicit resets',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['retained'=>2,'filters'=>['status'=>'paid']]); $broker=$f['broker'];
	$one=$broker->publish($f['context'],'orders','orders.created','orders.created',['id'=>1],['status'=>'paid']);
	$broker->publish($f['context'],'orders','orders.updated','orders.updated',['id'=>2],['status'=>'review']);
	$three=$broker->publish($f['context'],'orders','orders.updated','orders.updated',['id'=>3],['status'=>'paid']);
	$t->same(1,$one->sequence()); $t->same(3,$three->sequence());
	$reset=$broker->read($f['subscription'],0,10); $t->same('retention_gap',$reset->resetReason()); $t->same(3,$reset->head()); $t->same(2,$reset->earliest());
	$page=$broker->read($f['subscription'],1,1); $t->same([],$page->events()); $t->same(2,$page->cursor()); $t->isTrue($page->hasMore());
	$next=$broker->read($f['subscription'],2,10); $t->same([3],array_map(static fn(PanelRealtimeEvent $event): int=>$event->sequence(),$next->events())); $t->greaterThan(0,$next->wireBytes()); $t->contains('panel_realtime_read',json_encode($next,JSON_THROW_ON_ERROR)); $t->same('orders',$next->events()[0]->channel());
	$ahead=$broker->read($f['subscription'],9,10); $t->same('source_reset',$ahead->resetReason());
	$empty=PanelRealtimeSubscription::fromTrusted($f['context'],'missing',['*']); $t->same(0,$broker->read($empty,0,1)->head()); $t->same('source_reset',$broker->read($empty,1,1)->resetReason());
	$t->throws(static fn()=>$broker->read($f['subscription'],-1,1),InvalidArgumentException::class);
	$t->contains('at_least_once',json_encode($broker,JSON_THROW_ON_ERROR)); $t->contains('"exactly_once":false',json_encode($broker,JSON_THROW_ON_ERROR));
	$bounded=dp_panel_realtime_fixture(['streams'=>1,'event_bytes'=>1024]);
	$bounded['broker']->publish($bounded['context'],'one','topic','topic',['ok'=>true]);
	$t->throws(static fn()=>$bounded['broker']->publish($bounded['context'],'two','topic','topic',['ok'=>true]),PanelRealtimeException::class);
	$t->throws(static fn()=>(new PanelInMemoryRealtimeBroker(2,2,1024))->publish($bounded['context'],'one','topic','topic',str_repeat('x',2000)),PanelRealtimeException::class);
	$small=new PanelRealtimeEvent(1,$f['subscription']->streamKey(),'orders','orders.updated','orders.updated','2026-07-14T12:00:00Z',['id'=>1]); $t->throws(static fn()=>new PanelRealtimeReadResult(0,array_fill(0,1001,$small),1,1,1),LengthException::class);
	$largeResult=[]; for($sequence=1;$sequence<=33;$sequence++){ $largeResult[]=new PanelRealtimeEvent($sequence,$f['subscription']->streamKey(),'orders','orders.updated','orders.updated','2026-07-14T12:00:00Z',['a'=>str_repeat('x',65000),'b'=>str_repeat('y',65000)]); } $t->throws(static fn()=>new PanelRealtimeReadResult(0,$largeResult,33,33,1),LengthException::class);
})->tag('panel','realtime','broker','replay')->maxMillis(1000);

test('existing panel data subscriptions bridge into the stable realtime envelope honestly',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['topics'=>['data.insert','data.update']]);
	$source=new PanelArrayDataSource([],['name'=>'orders']);
	$source->upsert(['id'=>1,'tenant_id'=>'north','name'=>'One'],['status'=>'paid']);
	$source->upsert(['id'=>1,'tenant_id'=>'north','name'=>'Updated'],['status'=>'paid']);
	$unprojected=PanelDataSubscriptionRealtimeBroker::fromTrustedTenantSource($source,$f['context'],'orders'); $required=$t->throws(static fn()=>$unprojected->read($f['subscription'],0,10),PanelRealtimeException::class); $t->same('projection_required',$required->publicCode()); $t->contains('"projection_configured":false',json_encode($unprojected,JSON_THROW_ON_ERROR));
	$projector=static function(\Dataphyre\Panel\PanelDataChange $change, PanelRealtimeContext $context): array { $wire=$change->jsonSerialize(); return ['payload'=>['key'=>$wire['key'],'name'=>$wire['after']['name'] ?? null,'principal_authorized'=>$context->principal()==='operator-7'],'metadata'=>[]]; };
	$bridge=PanelDataSubscriptionRealtimeBroker::fromTrustedTenantSource($source,$f['context'],'orders',$projector);
	$result=$bridge->read($f['subscription'],0,10);
	$t->same([1,2],array_map(static fn(PanelRealtimeEvent $event): int=>$event->sequence(),$result->events()));
	$t->same('data.insert',$result->events()[0]->type()); $t->same(2,$result->cursor());
	$firstWire=$result->events()[0]->jsonSerialize(); $t->same(true,$firstWire['payload']['principal_authorized']); $t->notContains('before',json_encode($firstWire,JSON_THROW_ON_ERROR));
	$manifest=json_encode($bridge,JSON_THROW_ON_ERROR); $t->contains('"retention_gap_detection":false',$manifest); $t->contains('host_tenant_scope_required',$manifest); $t->contains('principal_projection_required',$manifest); $t->contains('"projection_configured":true',$manifest); $t->notContains('north',$manifest);
	$throwing=PanelDataSubscriptionRealtimeBroker::fromTrustedTenantSource($source,$f['context'],'orders',static function(): never{throw new RuntimeException('projector secret');}); $projectorFailure=$t->throws(static fn()=>$throwing->read($f['subscription'],0,10),PanelRealtimeException::class); $t->same('projection_unavailable',$projectorFailure->publicCode()); $t->notContains('secret',$projectorFailure->getMessage());
	$invalid=PanelDataSubscriptionRealtimeBroker::fromTrustedTenantSource($source,$f['context'],'orders',static fn(): array=>[]); $t->same('projection_invalid',$t->throws(static fn()=>$invalid->read($f['subscription'],0,10),PanelRealtimeException::class)->publicCode());
	$invalidMetadata=PanelDataSubscriptionRealtimeBroker::fromTrustedTenantSource($source,$f['context'],'orders',static fn(): array=>['payload'=>[],'metadata'=>null]); $t->same('projection_invalid',$t->throws(static fn()=>$invalidMetadata->read($f['subscription'],0,10),PanelRealtimeException::class)->publicCode());
	$suppressed=PanelDataSubscriptionRealtimeBroker::fromTrustedTenantSource($source,$f['context'],'orders',static fn(): ?array=>null); $t->same([],$suppressed->read($f['subscription'],0,10)->events());
	$foreign=PanelRealtimeSubscription::fromTrusted($f['other'],'orders',['*']);
	$t->throws(static fn()=>$bridge->read($foreign,0,10),PanelRealtimeException::class);
})->tag('panel','realtime','data-subscription','bridge')->maxMillis(1000);

test('realtime endpoint fails closed then emits signed resumable SSE without duplicates',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(); $intent=$f['signer']->issueSubscription($f['subscription'],120)->token();
	$f['broker']->publish($f['context'],'orders','orders.created','orders.created',['id'=>1]);
	$f['broker']->publish($f['context'],'orders','orders.updated','orders.updated',['id'=>1]);
	$strict=new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,$f['clock']); $strictFailure=$strict->open($f['subscription'],$intent,null,$f['context']); $t->same(503,$strictFailure->status()); $t->contains('replay_policy_required',(string)$strictFailure->nextChunk()); $t->contains('replay_policy_required',json_encode($strict,JSON_THROW_ON_ERROR));
	$locked=$strict->allowReusableSubscriptionIntents(); $t->isTrue($locked->broker()===$f['broker']); $t->isTrue($locked->signer()===$f['signer']);
	$denied=$locked->open($f['subscription'],$intent,null,$f['context']); $t->same(403,$denied->status()); $t->contains('host_authorization_required',(string)$denied->nextChunk());
	$forbidden=$locked->authorizeHost(static fn(): bool=>false)->open($f['subscription'],$intent,null,$f['context']); $t->same(403,$forbidden->status());
	$unavailable=$locked->authorizeHost(static function(): never{throw new RuntimeException('secret policy');})->open($f['subscription'],$intent,null,$f['context']); $t->same(503,$unavailable->status()); $t->notContains('secret',(string)$unavailable->nextChunk());
	$endpoint=$locked->authorizeHost(static fn(string $operation,PanelRealtimeSubscription $subscription,PanelRealtimeContext $context,int $cursor): bool=>$operation==='subscribe' && $context->principal()==='operator-7');
	$response=$endpoint->open($f['subscription'],$intent,null,$f['context']); $t->same(200,$response->status()); $t->same('text/event-stream; charset=utf-8',$response->headers()['Content-Type']);
	$t->contains('no-store',$response->headers()['Cache-Control']); $t->contains('no-transform',$response->headers()['Cache-Control']); $t->same('no',$response->headers()['X-Accel-Buffering']); $t->same('nosniff',$response->headers()['X-Content-Type-Options']);
	$chunk=(string)$response->nextChunk(); $t->contains('retry: 1500',$chunk); $t->contains('event: orders.created',$chunk); $t->contains('event: orders.updated',$chunk);
	$ids=dp_panel_realtime_ids($chunk); $t->same(2,count($ids)); $t->same(2,$f['signer']->verify($ids[1],$f['subscription'],$f['context'],'resume')->cursor());
	$resumed=$endpoint->open($f['subscription'],$intent,$ids[1],$f['context']); $t->same(200,$resumed->status()); $t->notContains('orders.created',(string)$resumed->nextChunk());
	$f['broker']->publish($f['context'],'orders','orders.updated','orders.updated',['id'=>2]);
	$fresh=(string)$resumed->nextChunk(); $t->contains('"sequence":3',$fresh); $t->notContains('"sequence":1',$fresh);
	$wrong=PanelRealtimeSubscription::fromTrusted($f['context'],'orders',['*']);
	$t->same(401,$endpoint->open($wrong,$intent,$ids[1],$f['context'])->status());
	$t->same(401,$endpoint->open($f['subscription'],'broken',null,$f['context'])->status());
	$public=json_encode([$endpoint,$response],JSON_THROW_ON_ERROR); $t->contains('"exactly_once":false',$public); $t->contains('reusable_explicit_opt_in',$public); $t->contains('"reusable_subscription_intents_explicitly_allowed":true',$public); $t->notContains($intent,$public); $t->notContains(str_repeat('a',32),$public); $t->same(2,$endpoint->telemetry()->counters()['connections_opened']);
	$badClock=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,static fn(): string=>'bad'))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true);
	$t->same(500,$badClock->open($f['subscription'],$intent,null,$f['context'])->status());
})->tag('panel','realtime','endpoint','sse','resume')->maxMillis(1000);

test('subscription intent replay policy atomically protects fresh connects while resume ids stay replayable',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['topics'=>['*']]); $intent=$f['signer']->issueSubscription($f['subscription'],120)->token(); $policy=new PanelInMemoryRealtimeIntentReplayPolicy(10,0,$f['clock']);
	$f['broker']->publish($f['context'],'orders','orders.updated','orders.updated',['id'=>1]);
	$endpoint=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,$f['clock']))->authorizeHost(static fn(): bool=>true)->protectSubscriptionIntents($policy);
	$first=$endpoint->open($f['subscription'],$intent,null,$f['context']); $t->same(200,$first->status()); $ids=dp_panel_realtime_ids((string)$first->nextChunk()); $t->same(1,count($ids));
	$duplicate=$endpoint->open($f['subscription'],$intent,null,$f['context']); $t->same(409,$duplicate->status()); $t->contains('subscription_intent_replayed',(string)$duplicate->nextChunk());
	$resumed=$endpoint->open($f['subscription'],$intent,$ids[0],$f['context']); $t->same(200,$resumed->status());
	$manifest=json_encode($endpoint,JSON_THROW_ON_ERROR); $t->contains('single_use_initial_connect',$manifest); $t->contains('"resume_intents_replayable":true',$manifest); $t->notContains($f['signer']->verify($intent,$f['subscription'],$f['context'],'subscribe')->nonce(),$manifest);
	$unavailable=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,$f['clock']))->authorizeHost(static fn(): bool=>true)->protectSubscriptionIntents(new DpPanelRealtimeThrowingReplayPolicy());
	$failure=$unavailable->open($f['subscription'],$f['signer']->issueSubscription($f['subscription'],120)->token(),null,$f['context']); $t->same(503,$failure->status()); $t->contains('replay_policy_unavailable',(string)$failure->nextChunk());
	$adversarialPolicy=new DpPanelRealtimeAdversarialReplayPolicy(); $adversarial=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,$f['clock']))->authorizeHost(static fn(): bool=>true)->protectSubscriptionIntents($adversarialPolicy);
	$adversarialFailure=$adversarial->open($f['subscription'],$f['signer']->issueSubscription($f['subscription'],120)->token(),null,$f['context']); $adversarialChunk=(string)$adversarialFailure->nextChunk(); $t->same(429,$adversarialFailure->status()); $t->contains('custom_policy_denied',$adversarialChunk); $t->contains('"retryable":true',$adversarialChunk); $t->contains('Panel realtime request was rejected.',$adversarialChunk); $t->notContains('super-secret',$adversarialChunk);
	$manifestBroker=new DpPanelRealtimeManifestTrapBroker(); $manifestPolicy=new DpPanelRealtimeAdversarialReplayPolicy(); $adapterSafe=(new PanelRealtimeEndpoint($manifestBroker,$f['signer'],null,null,$f['clock']))->protectSubscriptionIntents($manifestPolicy); $adapterManifest=json_encode($adapterSafe,JSON_THROW_ON_ERROR);
	$t->same(0,$manifestBroker->manifestCalls); $t->same(0,$manifestPolicy->manifestCalls); $t->contains('"adapter_safe_manifest":true',$adapterManifest); $t->contains('"manifest_delegated":false',$adapterManifest); $t->notContains('manifest secret',$adapterManifest); $t->notContains('DpPanelRealtime',$adapterManifest);
	$constructorPolicy=new PanelInMemoryRealtimeIntentReplayPolicy(10,0,$f['clock']); $constructorIntent=$f['signer']->issueSubscription($f['subscription'],120)->token(); $badConstructor=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,static fn(): string=>'invalid-clock'))->authorizeHost(static fn(): bool=>true)->protectSubscriptionIntents($constructorPolicy);
	$t->same(500,$badConstructor->open($f['subscription'],$constructorIntent,null,$f['context'])->status()); $goodConstructor=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,$f['clock']))->authorizeHost(static fn(): bool=>true)->protectSubscriptionIntents($constructorPolicy); $t->same(200,$goodConstructor->open($f['subscription'],$constructorIntent,null,$f['context'])->status());
	$afterAuthorization=new PanelInMemoryRealtimeIntentReplayPolicy(10,0,$f['clock']); $fresh=$f['signer']->issueSubscription($f['subscription'],120)->token(); $base=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,$f['clock']))->protectSubscriptionIntents($afterAuthorization);
	$t->same(403,$base->authorizeHost(static fn(): bool=>false)->open($f['subscription'],$fresh,null,$f['context'])->status()); $t->same(200,$base->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$fresh,null,$f['context'])->status());
	$guarded=new PanelInMemoryRealtimeIntentReplayPolicy(1,0,$f['clock']); $one=$f['signer']->verify($f['signer']->issueSubscription($f['subscription'],60)->token(),$f['subscription'],$f['context'],'subscribe'); $two=$f['signer']->verify($f['signer']->issueSubscription($f['subscription'],120)->token(),$f['subscription'],$f['context'],'subscribe');
	$t->isTrue($guarded->consume($one,$f['subscription'],$f['context'])); $t->isFalse($guarded->consume($one,$f['subscription'],$f['context'])); $capacity=$t->throws(static fn()=>$guarded->consume($two,$f['subscription'],$f['context']),PanelRealtimeException::class); $t->same('replay_policy_capacity',$capacity->publicCode());
	$f['clock']->now=1061; $t->isTrue($guarded->consume($two,$f['subscription'],$f['context'])); $t->throws(static fn()=>$guarded->consume($one,$f['subscription'],$f['context']),PanelRealtimeException::class);
	$t->throws(static fn()=>new PanelInMemoryRealtimeIntentReplayPolicy(0),InvalidArgumentException::class); $t->notContains($one->nonce(),json_encode($guarded,JSON_THROW_ON_ERROR));
})->tag('panel','realtime','intent','replay','single-use')->maxMillis(1000);

test('realtime streams honor heartbeat cancellation and finite connection deadlines',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['topics'=>['*']]); $intent=$f['signer']->issueSubscription($f['subscription'],120)->token();
	$options=new PanelRealtimeStreamOptions(10,4096,20,5,5,750,120); $telemetry=new PanelRealtimeTelemetry();
	$endpoint=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],$options,$telemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true);
	$response=$endpoint->open($f['subscription'],$intent,null,$f['context']); $t->same("retry: 750\n\n",$response->nextChunk());
	$f['clock']->now=1005; $t->same(null,$response->nextChunk()); $t->isTrue($response->closed()); $t->same('deadline',$response->session()?->closeReason());
	$f['clock']->now=1000; $longOptions=new PanelRealtimeStreamOptions(10,4096,20,5,30,750,120);
	$long=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],$longOptions,$telemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true);
	$heartbeat=$long->open($f['subscription'],$intent,null,$f['context']); $heartbeat->nextChunk(); $f['clock']->now=1005; $t->contains(': heartbeat 1005',(string)$heartbeat->nextChunk());
	$f['clock']->now=1000; $token=new PanelRealtimeCancellationToken(null,null,$f['clock']); $cancelled=$long->open($f['subscription'],$intent,null,$f['context'],$token); $token->cancel(); $t->same(null,$cancelled->nextChunk()); $t->isTrue($cancelled->closed());
	$probe=new PanelRealtimeCancellationToken(null,static function(): never{throw new RuntimeException('probe');},$f['clock']); $t->isTrue($probe->isCancellationRequested()); $probeResponse=$long->open($f['subscription'],$intent,null,$f['context'],$probe); $t->same(null,$probeResponse->nextChunk());
	$t->contains('deadline_configured',json_encode(new PanelRealtimeCancellationToken(1001,null,$f['clock']),JSON_THROW_ON_ERROR));
	$t->same(1,$telemetry->counters()['heartbeats_emitted']); $t->same(2,$telemetry->counters()['cancellations']);
})->tag('panel','realtime','heartbeat','cancellation','deadline')->maxMillis(1000);

test('realtime stream resets explicitly for retention slow consumers and oversized frames',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['topics'=>['*']]); $intent=$f['signer']->issueSubscription($f['subscription'],120)->token();
	for($i=1;$i<=3;$i++){ $f['broker']->publish($f['context'],'orders','orders.created','orders.created',['id'=>$i]); }
	$slowOptions=new PanelRealtimeStreamOptions(2,4096,2,5,30,1000,120);
	$slow=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],$slowOptions,null,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']);
	$slowChunk=(string)$slow->nextChunk(); $t->contains('event: panel.reset',$slowChunk); $t->contains('slow_consumer',$slowChunk); $t->isTrue($slow->closed());
	$retained=dp_panel_realtime_fixture(['topics'=>['*'],'retained'=>1]); $retainedIntent=$retained['signer']->issueSubscription($retained['subscription'],120)->token();
	$retained['broker']->publish($retained['context'],'orders','one','one',['id'=>1]); $retained['broker']->publish($retained['context'],'orders','two','two',['id'=>2]);
	$gap=(new PanelRealtimeEndpoint($retained['broker'],$retained['signer'],new PanelRealtimeStreamOptions(10,4096,20,5,30),null,$retained['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($retained['subscription'],$retainedIntent,null,$retained['context']);
	$t->contains('retention_gap',(string)$gap->nextChunk());
	$large=dp_panel_realtime_fixture(['topics'=>['*'],'event_bytes'=>10000]); $largeIntent=$large['signer']->issueSubscription($large['subscription'],120)->token();
	$large['broker']->publish($large['context'],'orders','one','one',str_repeat('x',1500));
	$tooLarge=(new PanelRealtimeEndpoint($large['broker'],$large['signer'],new PanelRealtimeStreamOptions(10,1024,20,5,30),null,$large['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($large['subscription'],$largeIntent,null,$large['context']);
	$t->contains('event_too_large',(string)$tooLarge->nextChunk());
})->tag('panel','realtime','backpressure','reset')->maxMillis(1000);

test('realtime broker failures SSE framing and response pulls stay bounded and non-leaking',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['topics'=>['*']]); $intent=$f['signer']->issueSubscription($f['subscription'],120)->token(); $telemetry=new PanelRealtimeTelemetry();
	$response=(new PanelRealtimeEndpoint(new DpPanelRealtimeThrowingBroker(),$f['signer'],null,$telemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']);
	$chunk=(string)$response->nextChunk(); $t->contains('stream_unavailable',$chunk); $t->notContains('secret',$chunk); $t->isTrue($response->closed()); $t->same(1,$telemetry->counters()['broker_failures']);
	$typedTelemetry=new PanelRealtimeTelemetry(); $typed=(new PanelRealtimeEndpoint(new DpPanelRealtimeTypedFailureBroker(),$f['signer'],null,$typedTelemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']); $typedChunk=(string)$typed->nextChunk(); $t->contains('stream_unavailable',$typedChunk); $t->notContains('typed broker secret',$typedChunk); $t->same(1,$typedTelemetry->counters()['broker_failures']);
	$scopeTelemetry=new PanelRealtimeTelemetry(); $foreign=(new PanelRealtimeEndpoint(new DpPanelRealtimeForeignEventBroker(),$f['signer'],null,$scopeTelemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']); $foreignChunk=(string)$foreign->nextChunk(); $t->contains('broker_scope_violation',$foreignChunk); $t->notContains('must-not-emit',$foreignChunk); $t->notContains('event: orders.updated',$foreignChunk); $t->isTrue($foreign->closed()); $t->same(1,$scopeTelemetry->counters()['broker_failures']);
	$countTelemetry=new PanelRealtimeTelemetry(); $countOptions=new PanelRealtimeStreamOptions(1,4096,20,5,30); $over=(new PanelRealtimeEndpoint(new DpPanelRealtimeOverproducingBroker(),$f['signer'],$countOptions,$countTelemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']); $overChunk=(string)$over->nextChunk(); $t->contains('broker_contract_violation',$overChunk); $t->notContains('event: orders.updated',$overChunk); $t->same(1,$countTelemetry->counters()['broker_failures']);
	$cancelTelemetry=new PanelRealtimeTelemetry(); $cancelled=(new PanelRealtimeEndpoint(new DpPanelRealtimeCancellingBroker($f['clock']),$f['signer'],null,$cancelTelemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']); $cancelled->nextChunk(); $t->same('cancelled',$cancelled->session()?->closeReason()); $t->same(1,$cancelTelemetry->counters()['cancellations']); $t->same(0,$cancelTelemetry->counters()['broker_failures']);
	$deadlineTelemetry=new PanelRealtimeTelemetry(); $deadlineOptions=new PanelRealtimeStreamOptions(10,4096,20,5,5); $deadline=(new PanelRealtimeEndpoint(new DpPanelRealtimeCancellingBroker($f['clock'],true),$f['signer'],$deadlineOptions,$deadlineTelemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']); $deadline->nextChunk(); $t->same('deadline',$deadline->session()?->closeReason()); $t->same(1,$deadlineTelemetry->counters()['deadlines']); $t->same(0,$deadlineTelemetry->counters()['broker_failures']);
	$f['clock']->now=1000; $lateDeadlineTelemetry=new PanelRealtimeTelemetry(); $lateDeadline=(new PanelRealtimeEndpoint(new DpPanelRealtimeLateReturningBroker($f['clock'],null,true),$f['signer'],$deadlineOptions,$lateDeadlineTelemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']); $lateDeadlineChunk=(string)$lateDeadline->nextChunk(); $t->same('deadline',$lateDeadline->session()?->closeReason()); $t->notContains('must-not-emit',$lateDeadlineChunk); $t->notContains('event: orders.updated',$lateDeadlineChunk); $t->same(1,$lateDeadlineTelemetry->counters()['deadlines']);
	$f['clock']->now=1000; $lateToken=new PanelRealtimeCancellationToken(null,null,$f['clock']); $lateCancelTelemetry=new PanelRealtimeTelemetry(); $lateCancel=(new PanelRealtimeEndpoint(new DpPanelRealtimeLateReturningBroker($f['clock'],$lateToken),$f['signer'],null,$lateCancelTelemetry,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context'],$lateToken); $lateCancelChunk=(string)$lateCancel->nextChunk(); $t->same('cancelled',$lateCancel->session()?->closeReason()); $t->notContains('must-not-emit',$lateCancelChunk); $t->notContains('event: orders.updated',$lateCancelChunk); $t->same(1,$lateCancelTelemetry->counters()['cancellations']);
	$encoder=new PanelRealtimeSseEncoder(); $t->contains('event: safe.event',$encoder->event('safe.event',['ok'=>true],'safe-id'));
	$t->throws(static fn()=>$encoder->event('Unsafe.Event',['ok'=>true]),InvalidArgumentException::class);
	$t->contains('signed_event_ids',json_encode($encoder,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>$encoder->event('safe',['ok'=>true],"bad\nid"),InvalidArgumentException::class);
	$t->throws(static fn()=>$encoder->retry(1),InvalidArgumentException::class); $t->throws(static fn()=>$encoder->heartbeat(-1),InvalidArgumentException::class);
	$error=PanelRealtimeSseResponse::error(409,$encoder->event('panel.error',['code'=>'conflict'])); $t->isFalse($error->closed()); $t->contains('conflict',(string)$error->nextChunk()); $t->same(null,$error->nextChunk()); $t->isTrue($error->closed());
	$t->same([],iterator_to_array($error->chunks(1))); $t->throws(static fn()=>iterator_to_array($error->chunks(0)),InvalidArgumentException::class);
	$t->notContains('secret',json_encode([$response,$telemetry],JSON_THROW_ON_ERROR));
})->tag('panel','realtime','failure','redaction','sse')->maxMillis(1000);

test('realtime filtered scans emit signed cursor controls and host close remains observable',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['topics'=>['orders.created']]); $intent=$f['signer']->issueSubscription($f['subscription'],120)->token();
	$f['broker']->publish($f['context'],'orders','orders.updated','orders.updated',['id'=>1]);
	$response=(new PanelRealtimeEndpoint($f['broker'],$f['signer'],null,null,$f['clock']))->allowReusableSubscriptionIntents()->authorizeHost(static fn(): bool=>true)->open($f['subscription'],$intent,null,$f['context']);
	$chunk=(string)$response->nextChunk(); $t->contains('event: panel.cursor',$chunk); $t->same(1,$response->session()?->cursor());
	$t->contains('panel_realtime_stream_session',json_encode($response->session(),JSON_THROW_ON_ERROR));
	$response->session()?->close(); $t->same('host_closed',$response->session()?->closeReason()); $t->same(null,$response->nextChunk());
})->tag('panel','realtime','cursor','filter')->maxMillis(1000);

test('realtime writable broker conformance pack proves ordering filtering cursors and isolation',static function(Context $t): void {
	$f=dp_panel_realtime_fixture(['topics'=>['*']]); $report=PanelRealtimeBrokerConformance::verify($f['broker'],$f['context'],$f['other']);
	$t->isTrue($report['passed']); $t->same(8,$report['checks']); $t->same([],$report['violations']); $t->same(0,$report['observations']['foreign_events']);
})->tag('panel','realtime','broker','conformance')->maxMillis(1000);
