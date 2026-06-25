<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

/**
 * Emits normalized HTTP responses to the PHP runtime.
 *
 * The emitter owns the final side effects of response handling: optional
 * Flightdeck debugbar injection, status-code mutation, header emission, and body
 * output. Callers pass any response-like value and receive no return value.
 */
final class ResponseEmitter {

	/**
	 * Normalizes and emits a response-like value.
	 *
	 * @param mixed $response Response instance, scalar, array, or other value accepted by Response::normalize().
	 */
	public static function emit(mixed $response): void {
		self::emitResponse(Response::normalize($response));
	}

	/**
	 * Sends the response status, headers, and body.
	 *
	 * @param Response $response Normalized response object.
	 */
	private static function emitResponse(Response $response): void {
		if(!$response->isStreamed()){
			$response=self::withFlightdeckDebugbar($response);
		}
		http_response_code($response->status);
		foreach($response->headers as $name=>$value){
			if(is_array($value)){
				foreach($value as $item){
					header($name.': '.$item, false);
				}
				continue;
			}
			header($name.': '.$value, true);
		}
		if($response->isStreamed()){
			while(!feof($response->stream)){
				$chunk=fread($response->stream, 8192);
				if($chunk===false){
					break;
				}
				echo $chunk;
			}
			fclose($response->stream);
			return;
		}
		echo $response->body;
	}

	/**
	 * Injects Flightdeck debugbar markup when the runtime has loaded it.
	 *
	 * Content-Length is removed after injection because the response body length
	 * changes. Injection failures preserve the original response.
	 *
	 * @param Response $response Response being emitted.
	 * @return Response Original or debugbar-injected response.
	 */
	private static function withFlightdeckDebugbar(Response $response): Response {
		if($response->body==='' || class_exists('\dataphyre_flightdeck_debugbar', false)!==true){
			return $response;
		}
		try{
			$body=\dataphyre_flightdeck_debugbar::inject($response->body);
		}catch(\Throwable){
			return $response;
		}
		if($body===$response->body){
			return $response;
		}
		$headers=$response->headers;
		foreach(array_keys($headers) as $name){
			if(strtolower((string)$name)==='content-length'){
				unset($headers[$name]);
			}
		}
		return new Response($body, $response->status, $headers);
	}
}
