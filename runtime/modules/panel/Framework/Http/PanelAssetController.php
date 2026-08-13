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
		$physical=strtolower($asset)==='panel-assets.json'
			|| str_starts_with(strtolower($asset), 'panel-style-')
			|| str_starts_with(strtolower($asset), 'panel-runtime-');
		$capabilityScoped=in_array(strtolower($asset), ['panel.css', 'panel.js'], true)
			|| strtolower($asset)==='panel-assets.json'
			|| preg_match('/\Apanel-style-[a-z0-9][a-z0-9-]{0,63}\.css\z/i', $asset)===1;
		$capabilities=null;
		$capabilityToken=$request!==null ? trim((string)$request->query('dp_panel_caps', '')) : '';
		if($capabilityToken!==''){
			$capabilities=PanelAssetCapabilityManifest::decodeToken($capabilityToken);
			if($capabilities===null || !$capabilityScoped){
				$response=self::httpResponse('Panel asset variant not found.', 404, [
					'Content-Type'=>'text/plain; charset=UTF-8',
					'Cache-Control'=>'no-store',
					'X-Content-Type-Options'=>'nosniff',
				]);
				self::trace($asset, $request, $response, false);
				return $response;
			}
		}
		$payload=strtolower($asset)==='panel-assets.json' && $request!==null && !PanelContext::has('asset_url_builder')
			? PanelContext::run(
				['asset_url_builder'=>self::manifestAssetUrlBuilder($request)],
				static fn(): ?array=>PanelRenderer::assetContent($asset, $capabilities),
			)
			: PanelRenderer::assetContent($asset, $capabilities);
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
			'X-Dataphyre-Panel-Asset-Mode'=>$physical ? 'physical' : ($capabilities===null ? 'full' : 'capability'),
		];
		if($capabilities!==null){
			$headers['X-Dataphyre-Panel-Capabilities']=implode(',', $capabilities);
		}
		$response=self::httpResponse(strtoupper((string)($request?->method() ?? 'GET'))==='HEAD' ? '' : $content, 200, array_replace($headers, [
			'Content-Length'=>(string)strlen($content),
		]));
		if($request!==null && method_exists($response, 'withConditionalHeaders')){
			$response=$response->withConditionalHeaders($request);
		}
		self::trace($asset, $request, $response, true);
		return $response;
	}

	/** Returns a same-route URL builder for the public physical-asset manifest. */
	private static function manifestAssetUrlBuilder(\Dataphyre\Http\Request $request): \Closure {
		$path='/'.ltrim($request->path(), '/');
		$directory=preg_match('#/assets/[^/]+\z#', $path)===1 ? substr($path, 0, (int)strrpos($path, '/')) : '';
		return static function(string $asset) use ($path, $directory): string {
			$asset=basename(str_replace('\\', '/', trim($asset)));
			if($directory!==''){
				return $directory.'/'.rawurlencode($asset);
			}
			return $path.'?dp_panel_asset='.rawurlencode($asset);
		};
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
		$headers=self::responseHeaders($response);
		PanelTrace::record('route.asset', [
			'asset'=>$asset,
			'path'=>$request?->path() ?? '',
			'method'=>$request?->method() ?? '',
			'status'=>self::responseStatus($response),
			'found'=>$found,
			'content_type'=>(string)($headers['Content-Type'] ?? $headers['content-type'] ?? ''),
		]);
	}

	/**
	 * Reads headers from either the HTTP response or the Panel fallback result.
	 *
	 * @param mixed $response Supported response value.
	 * @return array<string,mixed> Response header map.
	 */
	private static function responseHeaders(mixed $response): array {
		if($response instanceof PanelPageResult){
			return $response->headers();
		}
		if(is_object($response) && isset($response->headers) && is_array($response->headers)){
			return $response->headers;
		}
		return [];
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
