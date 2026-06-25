<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * HTTP-facing entry points for Reactor dispatch.
 *
 * ReactorEndpoint is the thin boundary used by controllers, route files, and
 * standalone endpoints. It delegates request execution to Reactor, shapes batch
 * envelopes, emits JSON headers when possible, and optionally echoes the body
 * for traditional PHP entry scripts.
 */
final class ReactorEndpoint {

	/**
	 * Dispatches a single Reactor request.
	 *
	 * Null requests are captured by Reactor::dispatch through the normal request
	 * capture path. Array requests are accepted for tests, internal calls, and
	 * batch adapters that already decoded transport payloads.
	 *
	 * @param ReactorRequest|array|null $request Explicit request object, decoded request data, or null to capture input.
	 * @return ReactorResponse Dispatch result from the Reactor runtime.
	 */
	public static function handle(ReactorRequest|array|null $request=null): ReactorResponse {
		return Reactor::dispatch($request);
	}

	/**
	 * Dispatches a batch of Reactor requests and serializes each response.
	 *
	 * When no batch is supplied, requests are captured from the current HTTP
	 * payload. Each item is dispatched independently and converted to its JSON
	 * shape before the batch trace is recorded.
	 *
	 * @param ?array<int, ReactorRequest|array> $requests Explicit batch items, or null to capture the incoming batch.
	 * @return array<int, array<string, mixed>> Serialized Reactor response payloads.
	 */
	public static function handleBatch(?array $requests=null): array {
		$requests=$requests ?? ReactorRequest::captureBatch();
		$responses=[];
		foreach($requests as $request){
			$response=Reactor::dispatch($request);
			$responses[]=$response->jsonSerialize();
		}
		ReactorTrace::record('batch.dispatched', ['requests'=>count($responses)]);
		return $responses;
	}

	/**
	 * Dispatches and emits a single JSON Reactor response.
	 *
	 * The method sets HTTP status and Reactor headers only when headers have not
	 * already been sent. It always returns the encoded JSON body, even when
	 * $sendBody is false, which makes it useful for tests and embedded routers.
	 *
	 * @param ReactorRequest|array|null $request Explicit request object, decoded request data, or null to capture input.
	 * @param bool $sendBody Whether to echo the JSON body as a side effect.
	 * @return string Encoded JSON response body.
	 */
	public static function emit(ReactorRequest|array|null $request=null, bool $sendBody=true): string {
		$response=self::handle($request);
		if(!headers_sent()){
			http_response_code($response->status());
			header('Content-Type: application/json; charset=UTF-8');
			header('X-Dataphyre-Reactor: 1');
		}
		$json=json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$json=$json!==false ? $json : '{"status":500,"ok":false,"message":"Unable to encode Reactor response."}';
		if($sendBody){
			echo $json;
		}
		return $json;
	}

	/**
	 * Dispatches and emits a JSON batch response envelope.
	 *
	 * The emitted status is 200 only when every item is below 400; otherwise it
	 * reports the highest failing status clamped to the valid HTTP error range.
	 * Individual response payloads remain available in the batch array.
	 *
	 * @param ?array<int, ReactorRequest|array> $requests Explicit batch items, or null to capture the incoming batch.
	 * @param bool $sendBody Whether to echo the JSON body as a side effect.
	 * @return string Encoded JSON envelope with status, ok, batch, and message keys.
	 */
	public static function emitBatch(?array $requests=null, bool $sendBody=true): string {
		$responses=self::handleBatch($requests);
		$status=200;
		$ok=true;
		foreach($responses as $response){
			$itemStatus=(int)($response['status'] ?? 500);
			if($itemStatus>=400){
				$ok=false;
				$status=max($status, $itemStatus);
			}
		}
		if(!headers_sent()){
			http_response_code($ok ? 200 : min(599, max(400, $status)));
			header('Content-Type: application/json; charset=UTF-8');
			header('X-Dataphyre-Reactor: 1');
			header('X-Dataphyre-Reactor-Batch: 1');
		}
		$payload=[
			'status'=>$ok ? 200 : min(599, max(400, $status)),
			'ok'=>$ok,
			'batch'=>$responses,
			'message'=>$ok ? '' : 'One or more Reactor requests failed.',
		];
		$json=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$json=$json!==false ? $json : '{"status":500,"ok":false,"batch":[],"message":"Unable to encode Reactor batch response."}';
		if($sendBody){
			echo $json;
		}
		return $json;
	}

	/**
	 * Emits the Reactor manifest as JSON.
	 *
	 * The manifest describes registered actions and runtime capabilities without
	 * executing a Reactor action. It is safe for discovery endpoints and operator
	 * tooling that need to inspect the current Reactor surface.
	 *
	 * @param bool $sendBody Whether to echo the JSON body as a side effect.
	 * @return string Encoded Reactor manifest JSON.
	 */
	public static function emitManifest(bool $sendBody=true): string {
		$manifest=Reactor::manifest();
		if(!headers_sent()){
			header('Content-Type: application/json; charset=UTF-8');
			header('X-Dataphyre-Reactor-Manifest: 1');
		}
		$json=json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$json=$json!==false ? $json : '{"module":"reactor","error":"Unable to encode Reactor manifest."}';
		if($sendBody){
			echo $json;
		}
		return $json;
	}
}
