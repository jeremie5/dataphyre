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
 * Serves Swagger UI for Dataphyre OpenAPI documents.
 *
 * The controller renders a minimal Swagger shell configured from route
 * api_docs metadata and serves local shell assets with long-lived immutable
 * caching, ETags, and nosniff headers.
 */
final class SwaggerUiController {

	/**
	 * Renders the Swagger UI HTML shell.
	 *
	 * Route api_docs options may override the document title, OpenAPI spec path,
	 * local asset path, and CDN URLs for Swagger UI CSS and JavaScript bundles.
	 * Values are HTML-escaped before insertion, but configured external asset URLs
	 * are still trusted script/style sources chosen by the application.
	 *
	 * @param Request $request Current HTTP request; accepted for route signature compatibility.
	 * @param array{api_docs?:array<string,mixed>} $route Route metadata containing optional Swagger/OpenAPI UI configuration.
	 * @return Response HTML response containing the Swagger UI mount point and initializer.
	 */
	public static function show(Request $request, array $route): Response {
		$options=is_array($route['api_docs'] ?? null) ? $route['api_docs'] : [];
		$title=trim((string)($options['title'] ?? 'Dataphyre API Documentation'));
		$specUrl=(string)($options['spec_path'] ?? '/_framework/api/openapi.json');
		$assetPath=(string)($options['asset_path'] ?? '/_framework/api/assets');
		$css=(string)($options['swagger_ui_css'] ?? 'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css');
		$bundleJs=(string)($options['swagger_ui_bundle_js'] ?? 'https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js');
		$presetJs=(string)($options['swagger_ui_preset_js'] ?? 'https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js');
		$shellCss=self::asset_url($assetPath, 'swagger-shell.css');
		$initializerJs=self::asset_url($assetPath, 'swagger-init.js');

		$html='<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>'.htmlspecialchars($title, ENT_QUOTES).'</title>
	<link rel="stylesheet" href="'.htmlspecialchars($css, ENT_QUOTES).'">
	<link rel="stylesheet" href="'.htmlspecialchars($shellCss, ENT_QUOTES).'">
</head>
<body>
	<div class="shell">
		<p class="header">'.htmlspecialchars($title, ENT_QUOTES).'</p>
		<div id="swagger-ui"></div>
	</div>
	<script src="'.htmlspecialchars($bundleJs, ENT_QUOTES).'"></script>
	<script src="'.htmlspecialchars($presetJs, ENT_QUOTES).'"></script>
	<script src="'.htmlspecialchars($initializerJs, ENT_QUOTES).'" data-dataphyre-openapi-spec="'.htmlspecialchars($specUrl, ENT_QUOTES).'" defer></script>
</body>
</html>';

		return Response::html($html);
	}

	/**
	 * Serves a local Swagger shell asset.
	 *
	 * Unknown assets return 404 with no-store. Known assets use deterministic
	 * ETags, a fixed Last-Modified value, immutable cache headers, HEAD support,
	 * and nosniff protection.
	 *
	 * @param Request $request Asset request containing route parameters and conditional headers.
	 * @param array{parameters?:array{asset?:string}} $route Route metadata containing the asset parameter fallback.
	 * @return Response Asset, 304 conditional response, or 404 text response.
	 */
	public static function asset(Request $request, array $route): Response {
		$routeParameters=$request->routeParameters();
		$assetParameter=$route['parameters']['asset'] ?? ($routeParameters['asset'] ?? '');
		$asset=self::assetName((string)$assetParameter);
		$content=self::assetContent($asset);
		if($content===null){
			return Response::make('Not found', 404, [
				'Content-Type'=>'text/plain; charset=utf-8',
				'Cache-Control'=>'no-store',
				'X-Content-Type-Options'=>'nosniff',
			]);
		}
		$etag='"'.sha1($asset.'|'.$content).'"';
		$headers=[
			'Content-Type'=>str_ends_with($asset, '.css') ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8',
			'Cache-Control'=>'public, max-age=31536000, immutable',
			'ETag'=>$etag,
			'Last-Modified'=>gmdate('D, d M Y H:i:s', 1767225600).' GMT',
			'Vary'=>'Accept-Encoding',
			'X-Content-Type-Options'=>'nosniff',
		];
		$ifNoneMatch=trim((string)$request->header('if_none_match', ''));
		$ifModifiedSince=strtotime((string)$request->header('if_modified_since', '')) ?: 0;
		if(($ifNoneMatch!=='' && $ifNoneMatch===$etag) || ($ifNoneMatch==='' && $ifModifiedSince>=1767225600)){
			return Response::make('', 304, $headers);
		}
		if($request->method()==='HEAD'){
			return Response::make('', 200, $headers+['Content-Length'=>(string)strlen($content)]);
		}
		return Response::make($content, 200, $headers);
	}

	/**
	 * Builds a versioned URL for a local Swagger shell asset.
	 *
	 * @param string $assetPath Public route prefix for API assets.
	 * @param string $asset Asset filename.
	 * @return string Asset URL with a short content hash.
	 */
	private static function assetUrl(string $assetPath, string $asset): string {
		$asset=self::assetName($asset);
		return '/'.trim($assetPath, '/').'/'.$asset.'?v='.substr(sha1((string)self::assetContent($asset)), 0, 16);
	}

	/**
	 * Normalizes an asset request to its basename.
	 *
	 * @param string $asset Raw asset route segment.
	 * @return string Basename used for local asset lookup.
	 */
	private static function assetName(string $asset): string {
		return basename(str_replace('\\', '/', $asset));
	}

	/**
	 * Returns built-in Swagger shell asset content.
	 *
	 * @param string $asset Normalized asset filename.
	 * @return ?string Asset body, or null when unknown.
	 */
	private static function assetContent(string $asset): ?string {
		return match($asset){
			'swagger-shell.css'=>self::shellCss(),
			'swagger-init.js'=>self::initializerJs(),
			default=>null,
		};
	}

	/**
	 * Returns the CSS wrapper around the embedded Swagger UI.
	 *
	 * @return string CSS asset body.
	 */
	private static function shellCss(): string {
		return <<<'CSS'
html, body { margin: 0; padding: 0; background: #f6f7fb; }
.topbar { display: none; }
.swagger-ui .info { margin: 24px 0; }
.shell { padding: 24px; }
.header { font: 600 14px/1.4 ui-sans-serif, system-ui, sans-serif; color: #506070; margin: 0 0 12px; }
#swagger-ui { background: #fff; border-radius: 16px; box-shadow: 0 18px 60px rgba(15, 23, 42, 0.08); }
CSS;
	}

	/**
	 * Returns the Swagger UI initialization script.
	 *
	 * @return string JavaScript asset body.
	 */
	private static function initializerJs(): string {
		return <<<'JS'
(function(){
	var script=document.currentScript;
	var spec=script ? script.getAttribute("data-dataphyre-openapi-spec") : "";
	window.ui=SwaggerUIBundle({
		url: spec || "/_framework/api/openapi.json",
		dom_id: "#swagger-ui",
		deepLinking: true,
		layout: "BaseLayout",
		presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
		docExpansion: "list",
		defaultModelsExpandDepth: 1
	});
})();
JS;
	}
}
