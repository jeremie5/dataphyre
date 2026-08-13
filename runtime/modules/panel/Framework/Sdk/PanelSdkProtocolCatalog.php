<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** First-party protocol schemas and opt-in host route bindings for application SDKs. */
final class PanelSdkProtocolCatalog {
	private function __construct(){}

	/**
	 * Builds a complete protocol contract without assuming that Panel owns host routing.
	 *
	 * Supported route keys are data_surface, command, events, and studio_artifact. A route is emitted only when the host
	 * supplies its same-origin path. Event and Studio schemas remain available to generated clients even when their transport
	 * is websocket, SSE, queue, local storage, or another host-owned mechanism.
	 *
	 * @param array<string,string> $routes
	 * @param array<string,mixed> $options
	 */
	public static function firstParty(string $id,string $version,array $routes,array $options=[]):PanelSdkContract {
		$unknown=array_diff(array_keys($routes),['data_surface','command','events','studio_artifact']);if($unknown!==[]){throw new \InvalidArgumentException('Panel SDK first-party route map contains unsupported keys.');}
		$contract=PanelSdkContract::make($id,$version,$options)->withEvent('panel.event',self::eventEnvelope())->withArtifact('panel.studio.artifact',self::studioArtifact());
		$error=self::errorEnvelope();
		if(isset($routes['data_surface'])){$contract=$contract->withOperation(PanelSdkOperation::post('data_surface_window',$routes['data_surface'],self::dataSurfaceWindow(),['body'=>self::dataSurfaceRequest(),'errors'=>['default'=>$error,422=>self::dataSurfaceError()],'scopes'=>['panel.data_surface.read'],'tags'=>['data_surface'],'summary'=>'Read or cross-filter a signed DataSurface window','idempotent'=>true]));}
		if(isset($routes['command'])){$contract=$contract->withOperation(PanelSdkOperation::post('dispatch_command',$routes['command'],self::commandReceipt(),['body'=>self::commandRequest(),'errors'=>['default'=>$error],'scopes'=>['panel.command.dispatch'],'tags'=>['command_fabric'],'summary'=>'Dispatch an idempotent Panel command','idempotent'=>true]));}
		if(isset($routes['events'])){$query=PanelSdkSchema::object(['after'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>256]),'limit'=>PanelSdkSchema::integer(['minimum'=>1,'maximum'=>500])]);$contract=$contract->withOperation(PanelSdkOperation::get('list_events',$routes['events'],PanelSdkSchema::arrayOf(self::eventEnvelope(),['maxItems'=>500]),['query'=>$query,'errors'=>['default'=>$error],'scopes'=>['panel.events.read'],'tags'=>['event_fabric'],'summary'=>'Read a bounded event-fabric window']));}
		if(isset($routes['studio_artifact'])){$contract=$contract->withOperation(PanelSdkOperation::get('get_studio_artifact',$routes['studio_artifact'],self::studioArtifact(),['errors'=>['default'=>$error],'scopes'=>['panel.studio.read'],'tags'=>['studio'],'summary'=>'Read a version-bound Studio artifact']));}
		return$contract;
	}

	public static function errorEnvelope():PanelSdkSchema {
		$error=PanelSdkSchema::object(['code'=>self::identifier(160),'message'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>2000]),'correlation_id'=>self::nullableString(128),'detail'=>PanelSdkSchema::any()],['code','message','correlation_id'],false);
		return PanelSdkSchema::object(['ok'=>PanelSdkSchema::enum([false]),'status'=>self::identifier(160),'error'=>$error,'errors'=>PanelSdkSchema::arrayOf(PanelSdkSchema::string(['maxLength'=>2000]),['maxItems'=>100]),'correlation_id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>128])],['ok','status','error','errors','correlation_id'],false,'Stable Panel public error envelope.');
	}

	public static function dataSurfaceRequest():PanelSdkSchema {
		$interaction=PanelSdkSchema::object(['type'=>PanelSdkSchema::enum(['cross_filter']),'values'=>PanelSdkSchema::arrayOf(PanelSdkSchema::any(),['maxItems'=>100,'uniqueItems'=>true])],['type','values']);
		return PanelSdkSchema::object(['intent'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>16384]),'interaction'=>$interaction],['intent'],false,'Signed DataSurface intent with an optional bounded interaction.');
	}

	public static function dataSurfaceWindow():PanelSdkSchema {
		$record=PanelSdkSchema::object(['key'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>1024]),'position'=>PanelSdkSchema::integer(['minimum'=>0]),'visible'=>PanelSdkSchema::boolean(),'data'=>self::jsonObject()],['key','position','visible','data']);
		$intent=PanelSdkSchema::object(['type'=>PanelSdkSchema::enum(['panel_data_surface_intent']),'version'=>PanelSdkSchema::integer(['minimum'=>1]),'intent'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>16384]),'issued_at'=>PanelSdkSchema::integer(['minimum'=>0]),'expires_at'=>PanelSdkSchema::integer(['minimum'=>0])],['type','version','intent','issued_at','expires_at']);
		return PanelSdkSchema::object([
			'type'=>PanelSdkSchema::enum(['panel_data_surface_window']),'version'=>PanelSdkSchema::integer(['minimum'=>1,'maximum'=>2]),'definition'=>self::identifier(),'resource'=>self::identifier(),'surface'=>PanelSdkSchema::enum(array_map(static fn(PanelDataSurfaceType $type):string=>$type->value,PanelDataSurfaceType::cases())),'projection'=>self::jsonObject(),'records'=>PanelSdkSchema::arrayOf($record,['maxItems'=>PanelDataSurfaceRange::MAX_FETCH]),'window'=>self::jsonObject(),'returned'=>PanelSdkSchema::integer(['minimum'=>0,'maximum'=>PanelDataSurfaceRange::MAX_FETCH]),'visible'=>PanelSdkSchema::integer(['minimum'=>0,'maximum'=>PanelDataSurfaceRange::MAX_FETCH]),'total'=>PanelSdkSchema::nullable(PanelSdkSchema::integer(['minimum'=>0])),'total_known'=>PanelSdkSchema::boolean(),'has_before'=>PanelSdkSchema::boolean(),'has_after'=>PanelSdkSchema::nullable(PanelSdkSchema::boolean()),'previous_intent'=>PanelSdkSchema::nullable($intent),'next_intent'=>PanelSdkSchema::nullable($intent),'canvas'=>self::jsonObject(),
		],['type','version','definition','resource','surface','projection','records','window','returned','visible','total','total_known','has_before','has_after','previous_intent','next_intent'],false,'Bounded DataSurface window.');
	}

	public static function dataSurfaceError():PanelSdkSchema {return PanelSdkSchema::object(['type'=>PanelSdkSchema::enum(['panel_data_surface_error']),'code'=>self::identifier(160),'message'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>2000]),'correlation_id'=>self::nullableString(128)],['type','code','message','correlation_id']);}

	public static function commandRequest():PanelSdkSchema {
		return PanelSdkSchema::object(['command'=>self::identifier(160),'idempotency_key'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>512]),'input'=>self::jsonObject(),'expected_revision'=>PanelSdkSchema::nullable(PanelSdkSchema::integer(['minimum'=>0])),'correlation_id'=>self::nullableString(128),'metadata'=>self::jsonObject()],['command','idempotency_key','input'],false,'Host-resolved command request. Tenant, actor, ability, and authority evidence are intentionally not client selectable.');
	}

	public static function commandReceipt():PanelSdkSchema {
		$digest=PanelSdkSchema::string(['pattern'=>'^[a-f0-9]{64}$','minLength'=>64,'maxLength'=>64]);
		return PanelSdkSchema::object(['type'=>PanelSdkSchema::enum(['panel_command_receipt']),'schema_version'=>PanelSdkSchema::integer(['minimum'=>1]),'api_version'=>PanelSdkSchema::integer(['minimum'=>1]),'version'=>PanelSdkSchema::integer(['minimum'=>1]),'id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>160]),'status'=>PanelSdkSchema::enum(['succeeded','failed','denied']),'command_fingerprint'=>$digest,'idempotency_hash'=>$digest,'result'=>PanelSdkSchema::any(),'error'=>PanelSdkSchema::nullable(PanelSdkSchema::string(['maxLength'=>2048])),'event_ids'=>PanelSdkSchema::arrayOf(PanelSdkSchema::string(['minLength'=>1,'maxLength'=>160]),['maxItems'=>4096]),'metadata'=>self::jsonObject(),'completed_at'=>PanelSdkSchema::string(['format'=>'date-time','maxLength'=>64]),'digest'=>$digest,'key_id'=>self::identifier(160),'signature'=>$digest,'replay'=>PanelSdkSchema::boolean()],['type','schema_version','api_version','version','id','status','command_fingerprint','idempotency_hash','result','error','event_ids','metadata','completed_at','digest','key_id','signature','replay']);
	}

	public static function eventEnvelope():PanelSdkSchema {
		$digest=PanelSdkSchema::string(['pattern'=>'^[a-f0-9]{64}$','minLength'=>64,'maxLength'=>64]);
		return PanelSdkSchema::object(['type'=>PanelSdkSchema::enum(['panel_event_envelope']),'schema_version'=>PanelSdkSchema::integer(['minimum'=>1]),'api_version'=>PanelSdkSchema::integer(['minimum'=>1]),'version'=>PanelSdkSchema::integer(['minimum'=>1]),'id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>160]),'sequence'=>PanelSdkSchema::integer(['minimum'=>1]),'event_type'=>self::identifier(160),'aggregate_type'=>self::identifier(160),'aggregate_id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>160]),'tenant_id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>160]),'actor_id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>160]),'command_fingerprint'=>$digest,'correlation_id'=>self::nullableString(160),'causation_id'=>self::nullableString(160),'payload'=>self::jsonObject(),'metadata'=>self::jsonObject(),'occurred_at'=>PanelSdkSchema::string(['format'=>'date-time','maxLength'=>64]),'previous_hash'=>$digest,'digest'=>$digest,'key_id'=>self::identifier(160),'signature'=>$digest],['type','schema_version','api_version','version','id','sequence','event_type','aggregate_type','aggregate_id','tenant_id','actor_id','command_fingerprint','correlation_id','causation_id','payload','metadata','occurred_at','previous_hash','digest','key_id','signature']);
	}

	public static function studioArtifact():PanelSdkSchema {
		$document=PanelSdkSchema::object(['type'=>PanelSdkSchema::enum(['panel_studio_document']),'version'=>PanelSdkSchema::integer(['minimum'=>1]),'tenant_id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>128]),'id'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>128]),'title'=>PanelSdkSchema::string(['minLength'=>1,'maxLength'=>160]),'meta'=>self::jsonObject()],['type','version','tenant_id','id','title','meta']);
		$definition=PanelSdkSchema::object(['type'=>PanelSdkSchema::enum(['panel_studio_definition']),'version'=>PanelSdkSchema::integer(['minimum'=>1]),'hash'=>PanelSdkSchema::string(['pattern'=>'^[a-f0-9]{64}$','minLength'=>64,'maxLength'=>64]),'root'=>self::jsonObject()],['type','version','hash','root']);
		return PanelSdkSchema::object(['document'=>$document,'definition'=>$definition,'revision'=>PanelSdkSchema::integer(['minimum'=>0]),'contract_fingerprint'=>PanelSdkSchema::string(['pattern'=>'^[a-f0-9]{64}$','minLength'=>64,'maxLength'=>64])],['document','definition','revision'],false,'Portable Studio artifact.');
	}

	private static function identifier(int $max=128):PanelSdkSchema{return PanelSdkSchema::string(['pattern'=>'^[A-Za-z0-9][A-Za-z0-9_.:-]*$','minLength'=>1,'maxLength'=>$max]);}
	private static function nullableString(int $max):PanelSdkSchema{return PanelSdkSchema::nullable(PanelSdkSchema::string(['minLength'=>1,'maxLength'=>$max]));}
	private static function jsonObject():PanelSdkSchema{return PanelSdkSchema::object([],[],true);}
}
