<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable, correlation-friendly JSON error boundary for public Panel HTTP APIs. */
final class PanelErrorEnvelope {
	/**
	 * @param array<string,mixed> $context Development-only diagnostic context.
	 * @param array<string,string> $headers Additional response headers.
	 */
	public static function response(string $code,int $httpStatus,string $message,?\Throwable $exception=null,bool $development=false,array $context=[],?string $correlationCandidate=null,array $headers=[]):PanelPageResult{
		$httpStatus=$httpStatus>=400&&$httpStatus<=599?$httpStatus:500;
		$code=self::normalizeCode($code);
		$correlationId=self::correlationId($correlationCandidate);
		$message=(string)PanelSensitiveDataSanitizer::sanitize(trim($message)!==''?$message:'The request could not be completed.',['max_string_bytes'=>500]);
		$error=['code'=>$code,'message'=>$message,'correlation_id'=>$correlationId];
		if($development){
			$error['detail']=PanelSensitiveDataSanitizer::sanitize([
				'exception'=>$exception!==null?['class'=>$exception::class,'message'=>$exception->getMessage()]:null,
				'context'=>$context,
			],['max_depth'=>8,'max_items'=>50,'max_string_bytes'=>1000]);
		}
		return PanelPageResult::json([
			'ok'=>false,
			'status'=>$code,
			'error'=>$error,
			'errors'=>[$message],
			'correlation_id'=>$correlationId,
		],$httpStatus,array_replace($headers,['X-Correlation-ID'=>$correlationId,'Cache-Control'=>'no-store']));
	}

	/** Accepts a caller correlation id only when it is compact and log-safe. */
	public static function correlationId(?string $candidate=null):string{
		$candidate=trim((string)$candidate);
		if($candidate!==''&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$candidate)===1){return $candidate;}
		try{return bin2hex(random_bytes(16));}
		catch(\Throwable){return substr(hash('sha256',uniqid('panel_error_',true)),0,32);}
	}

	private static function normalizeCode(string $code):string{
		$code=strtolower(trim(preg_replace('/[^a-z0-9]+/i','_',$code)??'','_'));
		return $code!==''?$code:'internal_error';
	}
}
