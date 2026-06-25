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

/**
 * Serves generated OpenAPI documents through the HTTP layer.
 *
 * The controller reads route-level api_docs options, asks the API entry point for the
 * selected application document, and returns vendor OpenAPI JSON for tooling and
 * browser consumers.
 */
final class OpenApiController {

	/**
	 * Generates and returns the OpenAPI response for a documentation route.
	 *
	 * Generation failures are converted to a JSON 500 response so docs endpoints
	 * expose stable error data instead of leaking framework exceptions.
	 *
	 * @param Request $request Current HTTP request; retained for controller signature consistency.
	 * @param array<string, mixed> $route Matched route metadata containing optional api_docs options.
	 * @return Response OpenAPI JSON response or structured generation failure.
	 */
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
