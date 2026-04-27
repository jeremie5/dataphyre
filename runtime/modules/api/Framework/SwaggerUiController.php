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

final class SwaggerUiController {

	public static function show(Request $request, array $route): Response {
		$options=is_array($route['api_docs'] ?? null) ? $route['api_docs'] : [];
		$title=trim((string)($options['title'] ?? 'Dataphyre API Documentation'));
		$spec_url=(string)($options['spec_path'] ?? '/_framework/api/openapi.json');
		$css=(string)($options['swagger_ui_css'] ?? 'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css');
		$bundle_js=(string)($options['swagger_ui_bundle_js'] ?? 'https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js');
		$preset_js=(string)($options['swagger_ui_preset_js'] ?? 'https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js');

		$html='<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>'.htmlspecialchars($title, ENT_QUOTES).'</title>
	<link rel="stylesheet" href="'.htmlspecialchars($css, ENT_QUOTES).'">
	<style>
		html, body { margin: 0; padding: 0; background: #f6f7fb; }
		.topbar { display: none; }
		.swagger-ui .info { margin: 24px 0; }
		.shell { padding: 24px; }
		.header { font: 600 14px/1.4 ui-sans-serif, system-ui, sans-serif; color: #506070; margin: 0 0 12px; }
		#swagger-ui { background: #fff; border-radius: 16px; box-shadow: 0 18px 60px rgba(15, 23, 42, 0.08); }
	</style>
</head>
<body>
	<div class="shell">
		<p class="header">'.htmlspecialchars($title, ENT_QUOTES).'</p>
		<div id="swagger-ui"></div>
	</div>
	<script src="'.htmlspecialchars($bundle_js, ENT_QUOTES).'"></script>
	<script src="'.htmlspecialchars($preset_js, ENT_QUOTES).'"></script>
	<script>
		window.ui=SwaggerUIBundle({
			url: '.json_encode($spec_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).',
			dom_id: "#swagger-ui",
			deepLinking: true,
			layout: "BaseLayout",
			presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
			docExpansion: "list",
			defaultModelsExpandDepth: 1
		});
	</script>
</body>
</html>';

		return Response::html($html);
	}
}
