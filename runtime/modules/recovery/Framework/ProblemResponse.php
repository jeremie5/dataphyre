<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use Dataphyre\Http\Response;

/** HTTP adapter for RFC problem responses and compatibility-preserving JSON enrichment. */
final class ProblemResponse {
	/** @param array<string,mixed> $extensions @param array<string,string|array<int,string>> $headers */
	public static function make(Problem $problem, array $extensions=[], array $headers=[]): Response {
		$payload=array_replace($problem->jsonSerialize(), $extensions);
		return Response::json(
			$payload,
			$problem->httpStatus(),
			array_replace(self::headers($problem), ['Content-Type'=>'application/problem+json; charset=utf-8'], $headers)
		);
	}

	/**
	 * Adds a nested problem contract without changing existing response fields.
	 *
	 * @param array<string,mixed> $compatibilityPayload
	 * @param array<string,string|array<int,string>> $headers
	 */
	public static function compatibility(Problem $problem, array $compatibilityPayload=[], array $headers=[]): Response {
		$payload=$compatibilityPayload;
		$payload['problem']=$problem->jsonSerialize();
		if(!array_key_exists('ok', $payload)) $payload['ok']=false;
		return Response::json(
			$payload,
			$problem->httpStatus(),
			array_replace(self::headers($problem), $headers)
		);
	}

	public static function enrich(Response $response, Problem $problem): Response {
		if($response->isStreamed()) return $response;
		$payload=json_decode($response->body, true);
		if(!is_array($payload)) return $response;
		$payload['problem']=$problem->jsonSerialize();
		if(!array_key_exists('ok', $payload)) $payload['ok']=false;
		$response=Response::json($payload, $response->status, $response->headers);
		return $response->withHeaders(self::headers($problem), true);
	}

	/** @return array<string,string> */
	public static function headers(Problem $problem): array {
		$headers=[
			'X-Correlation-Id'=>$problem->correlationId(),
			'X-Recovery-Problem'=>$problem->code(),
			'X-Recovery-Data-State'=>$problem->definition()->dataState(),
			'Cache-Control'=>'no-store',
		];
		$helpUrl=$problem->definition()->helpUrl();
		if($helpUrl!==null) $headers['Link']='<'.$helpUrl.'>; rel="help"';
		$retryAfter=$problem->definition()->retryAfterSeconds();
		if($retryAfter!==null && $retryAfter>0) $headers['Retry-After']=(string)$retryAfter;
		return $headers;
	}
}
