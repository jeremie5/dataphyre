<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

final class ResponseEmitter {

	public static function emit(mixed $response): void {
		if($response instanceof Response){
			self::emit_response($response);
			return;
		}
		if(is_array($response) || $response instanceof \JsonSerializable){
			self::emit_response(Response::json($response));
			return;
		}
		if(is_string($response)){
			self::emit_response(Response::make($response));
			return;
		}
		if($response===null){
			self::emit_response(Response::no_content());
			return;
		}
		self::emit_response(Response::make((string)$response));
	}

	private static function emit_response(Response $response): void {
		http_response_code($response->status);
		foreach($response->headers as $name=>$value){
			header($name.': '.$value, true);
		}
		echo $response->body;
	}
}
