<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Authenticated exact-key decoder for remote mutation receipts and errors. */
final class PanelHttpDataMutationProtocolResponse {
	public static function decode(PanelHttpDataSourceTransportResponse $response,PanelHttpDataMutationProtocolRequest $request,PanelHttpDataMutationDefinition $definition):PanelDataMutationReceipt|PanelDataMutationBatchResult{
		if(strlen($response->body())>$definition->maxResponseBytes()||$response->body()===''||preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/iD',trim($response->contentType()))!==1){throw PanelHttpDataMutationException::protocolInvalid();}
		try{$body=json_decode($response->body(),true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!is_array($body)||array_is_list($body)){throw new \UnexpectedValueException();}$definition->authenticator()->verify($body);}
		catch(PanelDataMutationException $error){throw$error;}
		catch(\Throwable $error){throw PanelHttpDataMutationException::protocolInvalid($error);}
		try{
			if($response->status()!==200){throw self::error($body,$response->status(),$request);}
			PanelHttpDataSourceValue::exactKeys($body,['type','version','operation','request_id','source','definition_fingerprint','capability','request_fingerprint','result','key_id','signature'],'Remote mutation success response');
			if($body['type']!=='panel_http_data_mutation_response'||$body['version']!==1){throw new \UnexpectedValueException();}self::binding($body,$request);
			$result=$body['result'];if(!is_array($result)||array_is_list($result)){throw new \UnexpectedValueException();}PanelHttpDataSourceValue::exactKeys($result,['atomic','count','receipts'],'Remote mutation response result');
			if(!is_bool($result['atomic'])||$result['atomic']!==$request->atomic()||!is_int($result['count'])||$result['count']!==$request->count()||!is_array($result['receipts'])||!array_is_list($result['receipts'])||count($result['receipts'])!==$request->count()){throw new \UnexpectedValueException();}
			$receipts=[];foreach($result['receipts']as$index=>$payload){if(!is_array($payload)||array_is_list($payload)){throw new \UnexpectedValueException();}$receipt=PanelDataMutationReceipt::fromArray($payload);$mutation=$request->mutations()[$index];if($receipt->source()!==$request->source()||!hash_equals($receipt->mutationFingerprint(),$mutation->fingerprint())||!hash_equals($receipt->idempotencyDigest(),$mutation->idempotencyDigest())||$receipt->operation()!==$mutation->operation()||(string)$receipt->key()!==(string)$mutation->key()){throw new \UnexpectedValueException();}$receipts[]=$receipt;}
			if($request->operation()==='mutate'){return$receipts[0];}
			$batch=new PanelDataMutationBatch($request->mutations(),$request->atomic());return new PanelDataMutationBatchResult($batch,$receipts,$request->source());
		}catch(PanelDataMutationException $error){throw$error;}catch(\Throwable $error){throw PanelHttpDataMutationException::protocolInvalid($error);}
	}
	/** @param array<string,mixed> $body */private static function binding(array $body,PanelHttpDataMutationProtocolRequest $request):void{
		if(($body['operation']??null)!==$request->operation()||($body['request_id']??null)!==$request->requestId()||($body['source']??null)!==$request->source()||($body['definition_fingerprint']??null)!==$request->definitionFingerprint()||($body['request_fingerprint']??null)!==$request->requestFingerprint()){throw new \UnexpectedValueException();}
		$capability=$body['capability']??null;if(!is_array($capability)||array_is_list($capability)){throw new \UnexpectedValueException();}PanelHttpDataSourceValue::exactKeys($capability,['version','fingerprint'],'Remote mutation response capability');if(($capability['version']??null)!==$request->capabilityPin()->version()||!is_string($capability['fingerprint']??null)||!hash_equals($request->capabilityPin()->fingerprint(),$capability['fingerprint'])){throw PanelHttpDataMutationException::capabilityMismatch();}
	}
	/** @param array<string,mixed> $body */private static function error(array $body,int $status,PanelHttpDataMutationProtocolRequest $request):PanelDataMutationException{
		PanelHttpDataSourceValue::exactKeys($body,['type','version','operation','request_id','source','definition_fingerprint','capability','request_fingerprint','error','key_id','signature'],'Remote mutation error response');if(($body['type']??null)!=='panel_http_data_mutation_error'||($body['version']??null)!==1){throw new \UnexpectedValueException();}self::binding($body,$request);
		$error=$body['error'];if(!is_array($error)||array_is_list($error)){throw new \UnexpectedValueException();}PanelHttpDataSourceValue::exactKeys($error,['code','status','retryable'],'Remote mutation error payload');if(!is_string($error['code'])||!is_int($error['status'])||$error['status']!==$status||!is_bool($error['retryable'])){throw new \UnexpectedValueException();}PanelHttpDataSourceValue::identifier($error['code'],'Remote mutation upstream error code',64);
		return match(true){
			$status===401||$status===403=>new PanelDataMutationAccessDenied('mutation_remote_denied','The remote mutation was denied.'),
			$status===409=>new PanelDataMutationConflict('mutation_remote_conflict','The remote mutation conflicts with current state.',$error['retryable']),
			$status===422=>new PanelDataMutationUnsupported(['remote_policy'],'The remote mutation was rejected by its capability or policy contract.'),
			$status===429=>new PanelHttpDataMutationException('mutation_remote_rate_limited','The remote mutation service is rate limited.',429,true,true),
			$status===408||$status===425||$status>=500=>new PanelHttpDataMutationException('mutation_remote_upstream_unavailable','The remote mutation service is unavailable.',503,$error['retryable'],true),
			default=>new PanelDataMutationException('mutation_remote_rejected','The remote mutation was rejected.',422,false),
		};
	}
}
