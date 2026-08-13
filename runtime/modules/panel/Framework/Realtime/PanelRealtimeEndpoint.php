<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Framework-neutral SSE endpoint policy. Hosts supply a trusted context,
 * an exact subscription, connect intent, optional Last-Event-ID, and an
 * explicit authorizer. The default is fail closed.
 */
final class PanelRealtimeEndpoint implements \JsonSerializable {
	private $hostAuthorizer=null;
	private ?PanelRealtimeSubscriptionIntentReplayPolicy $subscriptionIntentReplayPolicy=null;
	private bool $reusableSubscriptionIntentsAllowed=false;
	private ?\Closure $clock;

	public function __construct(
		private readonly PanelRealtimeBroker $broker,
		private readonly PanelRealtimeIntentSigner $signer,
		?PanelRealtimeStreamOptions $options=null,
		?PanelRealtimeTelemetry $telemetry=null,
		?callable $clock=null
	){
		$this->options=$options ?? new PanelRealtimeStreamOptions(); $this->telemetry=$telemetry ?? new PanelRealtimeTelemetry(); $this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}
	private readonly PanelRealtimeStreamOptions $options;
	private readonly PanelRealtimeTelemetry $telemetry;

	/** @param callable(string,PanelRealtimeSubscription,PanelRealtimeContext,int):(bool) $authorizer */
	public function authorizeHost(?callable $authorizer): self { $clone=clone $this; $clone->hostAuthorizer=$authorizer; return $clone; }
	public function protectSubscriptionIntents(?PanelRealtimeSubscriptionIntentReplayPolicy $policy): self { $clone=clone $this; $clone->subscriptionIntentReplayPolicy=$policy; return $clone; }
	public function allowReusableSubscriptionIntents(bool $allowed=true): self { $clone=clone $this; $clone->reusableSubscriptionIntentsAllowed=$allowed; return $clone; }

	public function open(
		PanelRealtimeSubscription $subscription,
		string $subscriptionIntent,
		?string $lastEventId,
		PanelRealtimeContext $context,
		?PanelRealtimeCancellation $cancellation=null
	): PanelRealtimeSseResponse {
		try{
			if($this->subscriptionIntentReplayPolicy===null && !$this->reusableSubscriptionIntentsAllowed){ throw new PanelRealtimeException('replay_policy_required',503,'Panel realtime requires subscription intent replay protection.'); }
			$connectIntent=$this->signer->verify($subscriptionIntent,$subscription,$context,'subscribe');
			$cursor=0; $resuming=false;
			if($lastEventId!==null && trim($lastEventId)!==''){
				$cursor=$this->signer->verify(trim($lastEventId),$subscription,$context,'resume')->cursor(); $resuming=true;
			}
			if($this->hostAuthorizer===null){ throw new PanelRealtimeException('host_authorization_required',403,'Panel realtime host authorization is required.'); }
			try{ $decision=($this->hostAuthorizer)('subscribe',$subscription,$context,$cursor); }
			catch(\Throwable){ throw new PanelRealtimeException('host_authorization_unavailable',503,'Panel realtime host authorization is unavailable.',true); }
			if($decision!==true){ throw new PanelRealtimeException('host_authorization_denied',403,'Panel realtime subscription is not authorized.'); }
			$session=new PanelRealtimeStreamSession($this->broker,$this->signer,$subscription,$cursor,$this->options,$this->telemetry,$cancellation,$this->clock);
			if(!$resuming && $this->subscriptionIntentReplayPolicy!==null){
				try{ $consumed=$this->subscriptionIntentReplayPolicy->consume($connectIntent,$subscription,$context); }
				catch(PanelRealtimeException $exception){ throw $exception; }
				catch(\Throwable){ throw new PanelRealtimeException('replay_policy_unavailable',503,'Panel realtime replay protection is unavailable.',true); }
				if(!$consumed){ throw new PanelRealtimeException('subscription_intent_replayed',409,'Panel realtime subscription intent was already used.'); }
			}
			$this->telemetry->increment('connections_opened');
			return PanelRealtimeSseResponse::stream($session);
		}
		catch(PanelRealtimeException $exception){
			if(str_starts_with($exception->publicCode(),'intent_')){ $this->telemetry->increment('intents_rejected'); }else{ $this->telemetry->increment('connections_denied'); }
			return $this->error($exception->httpStatus(),$exception->publicCode(),$this->publicMessage($exception->publicCode()),$exception->retryable(),$context->correlationId());
		}
		catch(\Throwable){
			$this->telemetry->increment('connections_denied');
			return $this->error(500,'stream_open_failed','Panel realtime stream could not be opened.',true,$context->correlationId());
		}
	}

	public function telemetry(): PanelRealtimeTelemetry { return $this->telemetry; }
	public function broker(): PanelRealtimeBroker { return $this->broker; }
	public function signer(): PanelRealtimeIntentSigner { return $this->signer; }
	public function jsonSerialize(): array { $singleUse=$this->subscriptionIntentReplayPolicy!==null; $mode=$singleUse?'single_use_initial_connect':($this->reusableSubscriptionIntentsAllowed?'reusable_explicit_opt_in':'replay_policy_required'); return ['type'=>'panel_realtime_endpoint','version'=>1,'transport'=>'sse','authorization_configured'=>$this->hostAuthorizer!==null,'subscription_intent_mode'=>$mode,'initial_connect_fail_closed'=>!$singleUse&&!$this->reusableSubscriptionIntentsAllowed,'reusable_subscription_intents_explicitly_allowed'=>$this->reusableSubscriptionIntentsAllowed,'subscription_intent_replay_policy'=>$singleUse?['configured'=>true,'manifest_delegated'=>false]:null,'broker'=>['configured'=>true,'manifest_delegated'=>false],'signer'=>$this->signer,'stream'=>$this->options,'telemetry'=>$this->telemetry,'capabilities'=>['tenant_principal_binding'=>true,'signed_connect_intents'=>true,'signed_resume_ids'=>true,'resume_intents_in_sse_id'=>true,'resume_intents_replayable'=>true,'subscription_intent_single_use_available'=>true,'key_rotation'=>true,'bounded_replay'=>true,'backpressure_reset'=>true,'heartbeats'=>true,'cancellation'=>true,'deadline'=>true,'adapter_safe_manifest'=>true,'dependency_identity_accessors'=>true],'delivery'=>'at_least_once_across_reconnect','exactly_once'=>false,'websocket_server'=>false,'tokens_exposed_in_manifest'=>false]; }

	private function publicMessage(string $code): string {
		$messages=['intent_expired'=>'Panel realtime intent has expired.','intent_invalid'=>'Panel realtime intent is invalid.','host_authorization_required'=>'Panel realtime host authorization is required.','host_authorization_unavailable'=>'Panel realtime host authorization is unavailable.','host_authorization_denied'=>'Panel realtime subscription is not authorized.','replay_policy_required'=>'Panel realtime requires subscription intent replay protection.','replay_policy_unavailable'=>'Panel realtime replay protection is unavailable.','replay_policy_capacity'=>'Panel realtime replay protection is at capacity.','subscription_intent_replayed'=>'Panel realtime subscription intent was already used.','subscription_scope_invalid'=>'Panel realtime subscription scope is invalid.','read_cancelled'=>'Panel realtime read was cancelled.','broker_capacity'=>'Panel realtime broker capacity is exhausted.','event_too_large'=>'Panel realtime event exceeds its byte bound.','projection_required'=>'Panel realtime data projection is required.','projection_unavailable'=>'Panel realtime data projection is unavailable.','projection_invalid'=>'Panel realtime data projection returned an invalid envelope.','broker_contract_violation'=>'Panel realtime broker violated its bounded read contract.'];
		return $messages[$code] ?? 'Panel realtime request was rejected.';
	}

	private function error(int $status, string $code, string $message, bool $retryable, string $correlationId): PanelRealtimeSseResponse {
		$encoder=new PanelRealtimeSseEncoder();
		$frame=$encoder->event('panel.error',['schema_version'=>1,'type'=>'panel.error','code'=>$code,'message'=>$message,'retryable'=>$retryable,'correlation_id'=>$correlationId!==''?$correlationId:null]);
		return PanelRealtimeSseResponse::error($status,$frame);
	}
}
