<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Reusable request, navigation, worker, and Reactor correlation bridge. */
final class PanelTelemetryBridge implements \JsonSerializable {
	public const METADATA_KEY='dataphyre_telemetry';
	public function __construct(private readonly PanelTelemetryHub $hub){}
	public function hub():PanelTelemetryHub{return$this->hub;}
	/** Instruments a Panel request without recording query, form, user, or upload values. */
	public function request(PanelRequest $request,callable $handler):mixed{$parent=$this->hub->extract(is_array($request->headers())?$request->headers():[]);$attributes=['method'=>$request->method(),'resource'=>$request->resourceName(),'operation'=>$request->operation(),'relation'=>$request->relationName(),'action'=>$request->actionName(),'partial'=>$request->isPanelModalRequest()?'modal':($request->isPanelFragmentRequest()?'fragment':null),'tenant_present'=>$request->tenantKey()!==null,'record_fingerprint'=>$request->recordKey()===null?null:substr(hash('sha256',$request->recordKey()),0,16)];return$this->hub->trace('panel.request.'.$request->operation(),static fn(PanelTelemetrySpan $span):mixed=>$handler($request,$span->context()),$attributes,$parent,'server');}
	/** Instruments a named route while preserving the request's inbound trace parent. */
	public function route(string $route,PanelRequest $request,callable $handler):mixed{$parent=$this->hub->extract(is_array($request->headers())?$request->headers():[]);return$this->hub->trace('panel.route.'.Resource::normalizeName($route),static fn(PanelTelemetrySpan $span):mixed=>$handler($request,$span->context()),['method'=>$request->method(),'operation'=>$request->operation()],$parent,'server');}
	public function navigationIssued(PanelNavigationIntent $intent,?PanelTelemetryContext $context=null):void{$this->hub->event('panel.navigation.issued',['panel'=>$intent->panel(),'surface'=>$intent->surface(),'operation'=>$intent->operation(),'outcome'=>$intent->outcome(),'chain_depth'=>count($intent->chain()),'parent_present'=>$intent->parent()!==null],$context);}
	public function navigationVerified(PanelNavigationIntentVerification $verification,?PanelTelemetryContext $context=null):void{$intent=$verification->intent();$this->hub->event('panel.navigation.verified',['valid'=>$verification->valid(),'code'=>$verification->code(),'migrated'=>$verification->migrated(),'surface'=>$intent?->surface(),'operation'=>$intent?->operation(),'chain_depth'=>$intent===null?0:count($intent->chain())],$context,$verification->valid()?'info':'warning');}
	/** @param array<string,mixed> $options @return array<string,mixed> */ public function operationOptions(array $options=[],?PanelTelemetryContext $context=null):array{$context=$context??$this->hub->context('panel.operation.submit');$metadata=is_array($options['metadata']??null)?$options['metadata']:[];$metadata[self::METADATA_KEY]=['schema_version'=>1,'headers'=>$this->hub->inject($context)];$options['metadata']=$metadata;return$options;}
	public function operationContext(PanelOperationRecord $record):?PanelTelemetryContext{$metadata=$record->metadata()[self::METADATA_KEY]??null;$headers=is_array($metadata)&&is_array($metadata['headers']??null)?$metadata['headers']:[];return$headers===[]?null:$this->hub->extract($headers);}
	/** Wraps a registered operation handler so submitter and worker remain on one trace. */
	public function operationHandler(callable $handler):\Closure{return function(mixed $payload,PanelOperationExecution $execution,PanelOperationRecord $record)use($handler):mixed{$parent=$this->operationContext($record);$attributes=['operation_id_fingerprint'=>substr(hash('sha256',$record->id()),0,16),'operation_type'=>$record->type(),'queue'=>$record->queue(),'attempt'=>$record->attempt(),'worker_present'=>$record->worker()!==null];return$this->hub->trace('panel.operation.'.$record->type(),static fn(PanelTelemetrySpan $span):mixed=>$handler($payload,$execution,$record,$span->context()),$attributes,$parent,'consumer');};}
	/** Correlates a Reactor transaction without retaining payload, state, or raw identifiers. */
	public function reactor(string $component,?string $transactionId,callable $handler,array $attributes=[]):mixed{$attributes=array_replace($attributes,['component'=>Resource::normalizeName($component),'transaction_fingerprint'=>$transactionId===null?null:substr(hash('sha256',$transactionId),0,16)]);return$this->hub->trace('panel.reactor.'.Resource::normalizeName($component),static fn(PanelTelemetrySpan $span):mixed=>$handler($span->context()),$attributes,null,'server');}
	public function jsonSerialize():array{return['type'=>'panel_telemetry_bridge','schema_version'=>1,'correlation'=>['requests'=>true,'routes'=>true,'signed_navigation'=>true,'operations'=>true,'workers'=>true,'reactor'=>true],'metadata_key'=>self::METADATA_KEY,'hub'=>$this->hub->manifest()];}
}
