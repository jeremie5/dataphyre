<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Api;

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

final class OpenApiController {

	public static function show(Request $request, array $route): Response {
		try{
			$options=is_array($route['api_docs'] ?? null) ? $route['api_docs'] : [];
			$document=Api::openApiDocument($options['application'] ?? null, $options);
			$body=json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			return Response::make(
				$body===false ? '{}' : $body,
				200,
				['Content-Type'=>'application/vnd.oai.openapi+json; charset=utf-8']
			);
		}catch(\Throwable $exception){
			return Response::json([
				'ok'=>false,
				'error'=>'Failed generating the OpenAPI document.',
				'message'=>$exception->getMessage(),
			], 500);
		}
	}
}
