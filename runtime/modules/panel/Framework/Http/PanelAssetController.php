<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Serves compiled Panel framework assets.
 *
 * The controller resolves an asset name from the route or request, reads the
 * payload from PanelRenderer::assetContent(), returns immutable cache headers for
 * known assets, returns a no-store 404 for missing assets, and records every
 * request through PanelTrace.
 */
final class PanelAssetController {

	/**
	 * Handles a Panel asset request.
	 *
	 * The route `asset` value wins when available; otherwise the query parameter
	 * or request basename is used. Asset names are sanitized in response().
	 *
	 * @param \Dataphyre\Http\Request $request HTTP request.
	 * @param array<string, mixed> $route Optional route parameter payload.
	 * @return \Dataphyre\Http\Response|PanelPageResult asset response with immutable-cache headers, or no-store 404 fallback.
	 */
	public static function handle(\Dataphyre\Http\Request $request, array $route=[]): mixed {
		$route=$route!==[] ? $route : $request->route();
		$asset=(string)($route['asset'] ?? $request->query('asset', basename($request->path())));
		return self::response($asset, $request);
	}

	/**
	 * Invokes the controller as a route handler.
	 *
	 * @param \Dataphyre\Http\Request $request HTTP request.
	 * @return \Dataphyre\Http\Response|PanelPageResult asset response produced by handle().
	 */
	public function __invoke(\Dataphyre\Http\Request $request): mixed {
		return self::handle($request);
	}

	/**
	 * Builds a response for a named Panel asset.
	 *
	 * The asset name is reduced to its basename to avoid path traversal. HEAD
	 * requests receive the same headers as GET responses but an empty body.
	 *
	 * @param string $asset Requested asset name.
	 * @param ?\Dataphyre\Http\Request $request Optional request used for method, conditional headers, and trace context.
	 * @return \Dataphyre\Http\Response|PanelPageResult response carrying sanitized asset content, cache validators, nosniff header, or no-store 404 body.
	 */
	public static function response(string $asset, ?\Dataphyre\Http\Request $request=null): mixed {
		$asset=basename(str_replace('\\', '/', $asset));
		$payload=PanelRenderer::assetContent($asset);
		if($payload===null){
			$response=self::httpResponse('Panel asset not found.', 404, [
				'Content-Type'=>'text/plain; charset=UTF-8',
				'Cache-Control'=>'no-store',
				'X-Content-Type-Options'=>'nosniff',
			]);
			self::trace($asset, $request, $response, false);
			return $response;
		}
		$content=(string)$payload['content'];
		$mtime=(int)(filemtime(dirname(__DIR__, 2).'/kernel/assets.php') ?: time());
		$headers=[
			'Content-Type'=>(string)$payload['content_type'],
			'Cache-Control'=>'public, max-age=31536000, immutable',
			'ETag'=>'"'.hash('sha256', $content).'"',
			'Last-Modified'=>gmdate('D, d M Y H:i:s', $mtime).' GMT',
			'Vary'=>'Accept-Encoding',
			'X-Content-Type-Options'=>'nosniff',
		];
		$response=self::httpResponse(strtoupper((string)($request?->method() ?? 'GET'))==='HEAD' ? '' : $content, 200, array_replace($headers, [
			'Content-Length'=>(string)strlen($content),
		]));
		if($request!==null && method_exists($response, 'withConditionalHeaders')){
			$response=$response->withConditionalHeaders($request);
		}
		self::trace($asset, $request, $response, true);
		return $response;
	}

	/**
	 * Creates the best available HTTP response object.
	 *
	 * @param string $content Response body.
	 * @param int $status HTTP status code.
	 * @param array<string, string> $headers Response headers.
	 * @return \Dataphyre\Http\Response|PanelPageResult Framework response when HTTP is loaded, otherwise Panel fallback result.
	 */
	private static function httpResponse(string $content, int $status, array $headers): mixed {
		if(class_exists('\Dataphyre\Http\Response')){
			return new \Dataphyre\Http\Response($content, $status, $headers);
		}
		return new PanelPageResult($content, $status, $headers);
	}

	/**
	 * Records the asset request in PanelTrace.
	 *
	 * @param string $asset Sanitized asset name.
	 * @param ?\Dataphyre\Http\Request $request Request context, when available.
	 * @param mixed $response Response value used to derive status and content type.
	 * @param bool $found Whether the asset was found.
	 * @return void
	 */
	private static function trace(string $asset, ?\Dataphyre\Http\Request $request, mixed $response, bool $found): void {
		PanelTrace::record('route.asset', [
			'asset'=>$asset,
			'path'=>$request?->path() ?? '',
			'method'=>$request?->method() ?? '',
			'status'=>self::responseStatus($response),
			'found'=>$found,
			'content_type'=>is_object($response) ? (string)($response->headers['Content-Type'] ?? '') : '',
		]);
	}

	/**
	 * Extracts the status code from supported response shapes.
	 *
	 * @param mixed $response Response value.
	 * @return int HTTP status code, defaulting to 200.
	 */
	private static function responseStatus(mixed $response): int {
		if(is_object($response) && isset($response->status) && is_numeric($response->status)){
			return (int)$response->status;
		}
		if($response instanceof PanelPageResult){
			return $response->status();
		}
		return 200;
	}
}
