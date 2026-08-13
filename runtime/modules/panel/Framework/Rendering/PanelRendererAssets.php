<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

require_once dirname(__DIR__).'/Assets/PanelAssetCapabilityManifest.php';
require_once dirname(__DIR__).'/Studio/Rendering/PanelStudioEditorAssets.php';
require_once __DIR__.'/Assets/PanelRendererAssetsCoreCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsRuntimeKernelScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsCommandRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsStateTableRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsNavigationRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsAssetHandoffRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsAjaxRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsFieldRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsEditorRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsEditorPackageScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsEditorAssetScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsValidationUploadRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsAccessibilityRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsWidgetRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsDataSurfaceRuntimeScripts.php';
require_once __DIR__.'/Assets/PanelRendererAssetsComponentCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsLayoutCoreCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsPresentationCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsWidgetRuntimeCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsDataSurfaceCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsNavigationCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsMobileCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsMobileNavigationCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsThemeCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsFeatureCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsVisualSystemCss.php';
require_once __DIR__.'/Assets/PanelRendererAssetsBrickV2Css.php';

/**
 * Built-in Panel CSS and JavaScript asset registry.
 *
 * The trait exposes a tiny public asset contract for panel front controllers:
 * normalize requested names, generate configured asset URLs, compute stable
 * content hashes for cache-busting, and return bundled CSS/JS payloads with
 * content types.
 */
trait PanelRendererAssets {
	use PanelRendererAssetsCoreCss;
	use PanelRendererAssetsScripts;
	use PanelRendererAssetsRuntimeKernelScripts;
	use PanelRendererAssetsCommandRuntimeScripts;
	use PanelRendererAssetsStateTableRuntimeScripts;
	use PanelRendererAssetsNavigationRuntimeScripts;
	use PanelRendererAssetsAssetHandoffRuntimeScripts;
	use PanelRendererAssetsAjaxRuntimeScripts;
	use PanelRendererAssetsFieldRuntimeScripts;
	use PanelRendererAssetsEditorRuntimeScripts;
	use PanelRendererAssetsEditorPackageScripts;
	use PanelRendererAssetsEditorAssetScripts;
	use PanelRendererAssetsValidationUploadRuntimeScripts;
	use PanelRendererAssetsAccessibilityRuntimeScripts;
	use PanelRendererAssetsWidgetRuntimeScripts;
	use PanelRendererAssetsDataSurfaceRuntimeScripts;
	use PanelRendererAssetsComponentCss;
	use PanelRendererAssetsLayoutCoreCss;
	use PanelRendererAssetsPresentationCss;
	use PanelRendererAssetsWidgetRuntimeCss;
	use PanelRendererAssetsDataSurfaceCss;
	use PanelRendererAssetsNavigationCss;
	use PanelRendererAssetsMobileCss;
	use PanelRendererAssetsMobileNavigationCss;
	use PanelRendererAssetsThemeCss;
	use PanelRendererAssetsFeatureCss;
	use PanelRendererAssetsVisualSystemCss;
	use PanelRendererAssetsBrickV2Css;

	/**
	 * Resolves a public URL for a known panel asset.
	 *
	 * Unknown names return an empty string so callers can avoid exposing arbitrary
	 * path input through the asset route.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Configured asset URL, or an empty string for unknown assets.
	 */
	public static function assetUrl(string $asset): string {
		$asset=self::assetName($asset);
		if($asset===''){ return ''; }
		$url=trim(PanelConfig::assetUrl($asset));
		if($url==='' || preg_match('/[\x00-\x1F\x7F]/', $url)===1 || str_starts_with($url, '//') || str_starts_with($url, '\\')){ return ''; }
		if(preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/', $url, $match)===1 && !in_array(strtolower($match[1]), ['http', 'https'], true)){
			return '';
		}
		return $url;
	}

	/**
	 * Computes the cache-busting version for a known panel asset.
	 *
	 * Versions are derived from bundled content rather than filesystem mtime so
	 * deployments with generated or embedded assets remain deterministic.
	 *
	 * @param string $asset Requested asset filename.
	 * @return string Sixteen-character SHA-256 prefix, or "missing" for unknown assets.
	 */
	public static function assetVersion(string $asset, mixed $capabilities=null): string {
		$content=self::assetContent($asset, $capabilities);
		if($content===null){
			return 'missing';
		}
		return substr(hash('sha256', $content['content']), 0, 16);
	}

	/**
	 * Returns a standards-compatible SHA-384 subresource-integrity value.
	 *
	 * @param string $asset Built-in asset name.
	 * @param mixed $capabilities Optional capability declarations for panel.css or panel.js.
	 */
	public static function assetIntegrity(string $asset, mixed $capabilities=null): string {
		$content=self::assetContent($asset, $capabilities);
		return $content===null ? '' : 'sha384-'.base64_encode(hash('sha384', $content['content'], true));
	}

	/** Builds the reusable, dependency-closed capability graph. */
	public static function assetCapabilityManifest(mixed $capabilities=[], string $mode='capability'): PanelAssetCapabilityManifest {
		return PanelAssetCapabilityManifest::make($capabilities, $mode);
	}

	/**
	 * Builds the deterministic browser-asset manifest consumed by Panel shells.
	 *
	 * The manifest keeps legacy panel.css/panel.js handles so existing URL builders
	 * and CDNs continue to work. Capability mode adds a canonical query token and
	 * content version; full mode serves the historical monolith byte-for-byte.
	 *
	 * @param mixed $capabilities String, list, or boolean map of declared capabilities.
	 * @param string $mode `capability` (default) or explicit `full` fallback.
	 * @param array<string,mixed> $options Nonce, integrity, attributes, and host capability URLs.
	 * @return array<string,mixed>
	 */
	public static function assetManifest(mixed $capabilities=[], string $mode='capability', array $options=[]): array {
		$graph=PanelAssetCapabilityManifest::make($capabilities, $mode);
		$integrityEnabled=self::assetOptionBool($options['integrity'] ?? PanelConfig::config('asset_integrity', false));
		$nonce=self::safeAssetAttributeValue($options['nonce'] ?? PanelConfig::config('asset_nonce', ''));
		$configuredAttributes=$options['attributes'] ?? PanelConfig::config('asset_attributes', []);
		$configuredAttributes=is_array($configuredAttributes) ? $configuredAttributes : [];
		$styles=[];
		$scripts=[];
		$missing=[];
		$scoped=$graph->isFull() ? null : $graph->bundleCapabilities();

		if($graph->isPhysical()){
			foreach(self::physicalStyleChunkNames($graph) as $chunk){
				$styles[]=self::physicalAssetDescriptor('style', $chunk, $graph, $integrityEnabled, $nonce, $configuredAttributes);
			}
		}
		else {
			$styles[]=self::assetDescriptor('panel.css', 'style', $scoped, $graph, $integrityEnabled, $nonce, $configuredAttributes);
		}
		if($graph->has('platform')){
			$styles[]=self::assetDescriptor('panel-platform.css', 'style', null, $graph, $integrityEnabled, $nonce, $configuredAttributes);
		}

		$capabilityUrls=$options['capability_urls'] ?? PanelConfig::config('asset_capability_urls', []);
		$capabilityUrls=is_array($capabilityUrls) ? $capabilityUrls : [];
		if($graph->has('reactor')){
			$reactor=self::reactorAssetDescriptor($capabilityUrls['reactor'] ?? null, $nonce, $configuredAttributes);
			if($reactor===null){ $missing[]='reactor'; }
			else { $scripts[]=$reactor; }
		}

		if($graph->isPhysical()){
			foreach(self::physicalRuntimeChunkNames($graph) as $chunk){
				$scripts[]=self::physicalAssetDescriptor('runtime', $chunk, $graph, $integrityEnabled, $nonce, $configuredAttributes);
			}
		}
		else {
			$scripts[]=self::assetDescriptor('panel.js', 'script', $scoped, $graph, $integrityEnabled, $nonce, $configuredAttributes);
		}
		if($graph->has('editor')){
			$scripts[]=self::assetDescriptor('panel-editor.js', 'script', null, $graph, $integrityEnabled, $nonce, $configuredAttributes);
		}
		if($graph->has('editor-assets')){
			$scripts[]=self::assetDescriptor('panel-editor-assets.js', 'script', null, $graph, $integrityEnabled, $nonce, $configuredAttributes);
		}
		if($graph->has('extensions')){
			$scripts[]=self::assetDescriptor('panel-extensions.js', 'script', null, $graph, $integrityEnabled, $nonce, $configuredAttributes);
		}
		if($graph->has('quality-client')){
			$scripts[]=self::assetDescriptor('panel-quality.js', 'script', null, $graph, $integrityEnabled, $nonce, $configuredAttributes);
		}

		$builtIn=[
			'shell','collection-layout','navigation','modal','record','table','data-surface','board','form','upload','editor','editor-assets','studio-editor','chart',
			'widget-runtime','auth','reactor','collaboration','media','extensions','platform','quality-client',
		];
		foreach($graph->capabilities() as $capability){
			if(in_array($capability, $builtIn, true) || !array_key_exists($capability, $capabilityUrls)){ continue; }
			$descriptor=self::hostCapabilityAssetDescriptor($capability, $capabilityUrls[$capability], $nonce, $configuredAttributes);
			if($descriptor===null){ $missing[]=$capability; continue; }
			if($descriptor['type']==='style'){ $styles[]=$descriptor; }
			else { $scripts[]=$descriptor; }
		}

		$base=$graph->toArray();
		$manifest=[
			'schema_version'=>1,
			'id'=>$base['id'],
			'mode'=>$graph->mode(),
			'requested'=>$graph->requested(),
			'capabilities'=>$graph->capabilities(),
			'bundle_capabilities'=>$graph->bundleCapabilities(),
			'token'=>$graph->token(),
			'chunks'=>[
				'styles'=>$graph->styleChunks(),
				'runtime'=>$graph->runtimeChunks(),
			],
			'delivery'=>[
				'strategy'=>$graph->isPhysical() ? 'physical' : 'aggregate',
				'physical'=>$graph->isPhysical(),
				'style_chunks'=>$graph->isPhysical() ? self::physicalStyleChunkNames($graph) : [],
				'runtime_chunks'=>$graph->isPhysical() ? self::physicalRuntimeChunkNames($graph) : [],
				'built_in_requests'=>$graph->isPhysical()
					? count(self::physicalStyleChunkNames($graph))+count(self::physicalRuntimeChunkNames($graph))
					: 2,
				'runtime_namespace'=>$graph->isPhysical() ? 'window.DataphyrePanel.runtimeChunks' : null,
				'legacy_fallback'=>['styles'=>'panel.css','scripts'=>'panel.js'],
			],
			'styles'=>$styles,
			'scripts'=>$scripts,
			'missing_capabilities'=>array_values(array_unique($missing)),
		];
		$identity=$manifest;
		foreach(['styles','scripts'] as $collection){
			$identity[$collection]=array_map(static function(array $asset): array {
				unset($asset['url'], $asset['attributes']);
				return $asset;
			}, $identity[$collection]);
		}
		$manifest['content_id']=substr(hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)), 0, 20);
		return $manifest;
	}

	/**
	 * Returns the HTTP response body and content type for a known panel asset.
	 *
	 * Only the built-in Panel asset bundles, including the separately cacheable
	 * editor-package and editor-asset runtimes, are served
	 * by this registry. The returned content is generated in-process from trait
	 * methods loaded above.
	 *
	 * @param string $asset Requested asset filename.
	 * @return ?array{content_type: string, content: string} Asset response payload, or null for unknown assets.
	 */
	public static function assetContent(string $asset, mixed $capabilities=null): ?array {
		$asset=self::assetName($asset);
		$graph=$capabilities===null ? null : PanelAssetCapabilityManifest::make($capabilities);
		if($asset===''){ return null; }
		$physical=self::physicalAssetParts($asset);
		if($physical!==null){
			$graph??=PanelAssetCapabilityManifest::make('*', 'physical');
			$content=$physical['type']==='style'
				? self::physicalStyleChunk($physical['chunk'], $graph)
				: self::physicalRuntimeChunk($physical['chunk']);
			if($content===null){ return null; }
			return [
				'content_type'=>$physical['type']==='style' ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8',
				'content'=>$content,
			];
		}
		if($asset==='panel-assets.json'){
			$graph??=PanelAssetCapabilityManifest::make('*', 'physical');
			return [
				'content_type'=>'application/json; charset=UTF-8',
				'content'=>json_encode(
					self::assetManifest($graph->capabilities(), 'physical'),
					JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR,
				),
			];
		}
		return match($asset){
			'panel.css'=>[
				'content_type'=>'text/css; charset=UTF-8',
				'content'=>$graph===null ? self::panelStylesheet() : self::panelStylesheetForCapabilities($graph),
			],
			'panel.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>$graph===null ? self::panelScriptBundle() : self::panelScriptBundleForCapabilities($graph),
			],
			'panel-head.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>self::panelHeadScript(),
			],
			'panel-editor.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>self::editorPackageScript(),
			],
			'panel-editor-assets.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>self::editorAssetScript(),
			],
			'panel-extensions.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>PanelExtensionAssets::javascript(),
			],
			'panel-platform.css'=>[
				'content_type'=>'text/css; charset=UTF-8',
				'content'=>PanelPlatformAssets::stylesheet(),
			],
			'panel-quality.js'=>[
				'content_type'=>'application/javascript; charset=UTF-8',
				'content'=>PanelQualityClientAssets::javascript(),
			],
			default=>null,
		};
	}

	/**
	 * Renders allow-listed link or script tags from an asset manifest.
	 *
	 * @param array<string,mixed> $manifest Value returned by assetManifest().
	 * @param string $type `style` or `script`.
	 */
	public static function assetTags(array $manifest, string $type): string {
		$type=$type==='style' ? 'style' : 'script';
		$collection=$type==='style' ? 'styles' : 'scripts';
		$assets=is_array($manifest[$collection] ?? null) ? $manifest[$collection] : [];
		$html='';
		foreach($assets as $asset){
			if(!is_array($asset) || ($asset['type'] ?? null)!==$type){ continue; }
			$url=self::safeAssetUrl((string)($asset['url'] ?? ''));
			if($url===''){ continue; }
			$attributes=self::sanitizeAssetAttributes(
				$type,
				is_array($asset['attributes'] ?? null) ? $asset['attributes'] : [],
			);
			$attributeHtml='';
			foreach($attributes as $name=>$value){
				$attributeHtml.=' '.$name.'="'.self::e($value).'"';
			}
			if($type==='style'){
				$html.='<link rel="stylesheet" href="'.self::e($url).'"'.$attributeHtml.'>';
			}
			else {
				$html.='<script src="'.self::e($url).'" defer'.$attributeHtml.'></script>';
			}
		}
		return $html;
	}

	/** @param array<string,mixed> $configuredAttributes @return array<string,mixed> */
	private static function assetDescriptor(
		string $asset,
		string $type,
		mixed $capabilities,
		PanelAssetCapabilityManifest $graph,
		bool $integrityEnabled,
		string $nonce,
		array $configuredAttributes,
	): array {
		$payload=self::assetContent($asset, $capabilities);
		$content=(string)($payload['content'] ?? '');
		$version=substr(hash('sha256', $content), 0, 16);
		$url=self::assetUrl($asset);
		if($capabilities!==null && $url!==''){
			$url=self::appendAssetQuery($url, [
				'dp_panel_caps'=>$graph->token(),
				'dp_panel_v'=>$version,
			]);
		}
		$attributes=self::configuredAssetAttributes($type, $asset, $configuredAttributes);
		$integrity=$content!=='' ? 'sha384-'.base64_encode(hash('sha384', $content, true)) : '';
		if($integrityEnabled && $integrity!==''){
			$attributes['integrity']=$integrity;
			$attributes['crossorigin']=$attributes['crossorigin'] ?? 'anonymous';
		}
		if($nonce!==''){
			$attributes['nonce']=$nonce;
		}
		$chunks=match($asset){
			'panel.css'=>$graph->styleChunks(),
			'panel.js'=>$graph->runtimeChunks(),
			'panel-editor.js'=>['editor-package'],
			'panel-editor-assets.js'=>['editor-assets'],
			'panel-extensions.js'=>['extensions'],
			'panel-platform.css'=>['platform'],
			'panel-quality.js'=>['quality-client'],
			default=>[],
		};
		return [
			'name'=>$asset,
			'type'=>$type,
			'url'=>$url,
			'content_type'=>(string)($payload['content_type'] ?? ''),
			'bytes'=>strlen($content),
			'sha256'=>hash('sha256', $content),
			'version'=>$version,
			'integrity'=>$integrity,
			'chunks'=>$chunks,
			'attributes'=>$attributes,
		];
	}

	/** @param array<string,mixed> $configuredAttributes @return array<string,mixed> */
	private static function physicalAssetDescriptor(
		string $kind,
		string $chunk,
		PanelAssetCapabilityManifest $graph,
		bool $integrityEnabled,
		string $nonce,
		array $configuredAttributes,
	): array {
		$type=$kind==='style' ? 'style' : 'script';
		$asset=self::physicalAssetName($kind, $chunk);
		$payload=self::assetContent($asset, $kind==='style' ? $graph->bundleCapabilities() : null);
		$content=(string)($payload['content'] ?? '');
		$version=substr(hash('sha256', $content), 0, 16);
		$url=self::assetUrl($asset);
		if($url!==''){
			$query=['dp_panel_v'=>$version];
			if($kind==='style'){
				$query=['dp_panel_caps'=>$graph->token()]+$query;
			}
			$url=self::appendAssetQuery($url, $query);
		}
		$attributes=self::configuredAssetAttributes($type, $asset, $configuredAttributes);
		$integrity=$content!=='' ? 'sha384-'.base64_encode(hash('sha384', $content, true)) : '';
		if($integrityEnabled && $integrity!==''){
			$attributes['integrity']=$integrity;
			$attributes['crossorigin']=$attributes['crossorigin'] ?? 'anonymous';
		}
		if($nonce!==''){
			$attributes['nonce']=$nonce;
		}
		return [
			'name'=>$asset,
			'type'=>$type,
			'url'=>$url,
			'content_type'=>(string)($payload['content_type'] ?? ''),
			'bytes'=>strlen($content),
			'sha256'=>hash('sha256', $content),
			'version'=>$version,
			'integrity'=>$integrity,
			'chunks'=>[$chunk],
			'dependencies'=>$kind==='runtime' ? self::physicalRuntimeDependencies($chunk) : [],
			'physical'=>true,
			'attributes'=>$attributes,
		];
	}

	/** @param mixed $definition @param array<string,mixed> $configuredAttributes */
	private static function reactorAssetDescriptor(mixed $definition, string $nonce, array $configuredAttributes): ?array {
		if($definition!==null){
			return self::hostCapabilityAssetDescriptor('reactor', $definition, $nonce, $configuredAttributes);
		}
		if(!class_exists('\\Dataphyre\\Reactor\\ReactorClientAssets')){
			return null;
		}
		$url=\Dataphyre\Reactor\ReactorClientAssets::assetUrl('reactor.js');
		return self::externalAssetDescriptor('reactor', 'reactor.js', 'script', $url, [], $nonce, $configuredAttributes);
	}

	/** @param mixed $definition @param array<string,mixed> $configuredAttributes */
	private static function hostCapabilityAssetDescriptor(string $capability, mixed $definition, string $nonce, array $configuredAttributes): ?array {
		if(is_string($definition)){
			return self::externalAssetDescriptor($capability, $capability.'.js', 'script', $definition, [], $nonce, $configuredAttributes);
		}
		if(!is_array($definition)){
			return null;
		}
		$url=(string)($definition['url'] ?? $definition['href'] ?? $definition['src'] ?? '');
		$type=Resource::normalizeName((string)($definition['type'] ?? 'script'));
		$type=in_array($type, ['style', 'stylesheet', 'css'], true) ? 'style' : 'script';
		$name=basename(str_replace('\\', '/', trim((string)($definition['name'] ?? $capability.($type==='style' ? '.css' : '.js')))));
		$attributes=is_array($definition['attributes'] ?? null) ? $definition['attributes'] : [];
		return self::externalAssetDescriptor($capability, $name, $type, $url, $attributes, $nonce, $configuredAttributes);
	}

	/** @param array<string,mixed> $attributes @param array<string,mixed> $configuredAttributes */
	private static function externalAssetDescriptor(string $capability, string $name, string $type, string $url, array $attributes, string $nonce, array $configuredAttributes): ?array {
		$url=self::safeAssetUrl($url);
		if($url===''){ return null; }
		$attributes=array_replace(self::configuredAssetAttributes($type, $name, $configuredAttributes), $attributes);
		if($nonce!==''){ $attributes['nonce']=$nonce; }
		$attributes=self::sanitizeAssetAttributes($type, $attributes);
		return [
			'name'=>$name,
			'capability'=>$capability,
			'type'=>$type,
			'url'=>$url,
			'external'=>true,
			'bytes'=>null,
			'sha256'=>null,
			'version'=>null,
			'integrity'=>$attributes['integrity'] ?? null,
			'chunks'=>[$capability],
			'attributes'=>$attributes,
		];
	}

	/** @param array<string,mixed> $configured @return array<string,string> */
	private static function configuredAssetAttributes(string $type, string $asset, array $configured): array {
		$attributes=[];
		$allowed=$type==='style'
			? ['media','integrity','crossorigin','referrerpolicy','fetchpriority','nonce','title']
			: ['integrity','crossorigin','referrerpolicy','fetchpriority','nonce'];
		foreach($configured as $name=>$value){
			if(is_string($name) && in_array(strtolower($name), $allowed, true)){
				$attributes[strtolower($name)]=$value;
			}
		}
		foreach(['all', $type, $asset] as $scope){
			if(is_array($configured[$scope] ?? null)){
				$attributes=array_replace($attributes, $configured[$scope]);
			}
		}
		return self::sanitizeAssetAttributes($type, $attributes);
	}

	/** @param array<string,mixed> $attributes @return array<string,string> */
	private static function sanitizeAssetAttributes(string $type, array $attributes): array {
		$allowed=$type==='style'
			? ['media','integrity','crossorigin','referrerpolicy','fetchpriority','nonce','title']
			: ['integrity','crossorigin','referrerpolicy','fetchpriority','nonce'];
		$clean=[];
		foreach($attributes as $name=>$value){
			$name=strtolower(trim((string)$name));
			$value=self::safeAssetAttributeValue($value);
			if(in_array($name, $allowed, true) && $value!==''){
				$clean[$name]=$value;
			}
		}
		ksort($clean, SORT_STRING);
		return $clean;
	}

	private static function safeAssetAttributeValue(mixed $value): string {
		if(!is_scalar($value)){ return ''; }
		$value=trim((string)$value);
		return $value!=='' && strlen($value)<=1024 && preg_match('/[\x00-\x1F\x7F]/', $value)!==1 ? $value : '';
	}

	private static function safeAssetUrl(string $url): string {
		$url=trim($url);
		if($url==='' || preg_match('/[\x00-\x1F\x7F]/', $url)===1 || str_starts_with($url, '//') || str_starts_with($url, '\\')){
			return '';
		}
		if(preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/', $url, $match)===1 && !in_array(strtolower($match[1]), ['http','https'], true)){
			return '';
		}
		return $url;
	}

	/** @param array<string,string> $query */
	private static function appendAssetQuery(string $url, array $query): string {
		$fragment='';
		if(str_contains($url, '#')){
			[$url, $fragment]=explode('#', $url, 2);
			$fragment='#'.$fragment;
		}
		$separator=str_contains($url, '?') ? '&' : '?';
		return $url.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986).$fragment;
	}

	private static function assetOptionBool(mixed $value): bool {
		if(is_bool($value)){ return $value; }
		if(is_int($value) || is_float($value)){ return (float)$value!==0.0; }
		return in_array(strtolower(trim((string)$value)), ['1','true','yes','on','enabled'], true);
	}

	/**
	 * Normalizes and validates a requested asset name.
	 *
	 * @param string $asset User or route supplied asset path.
	 * @return string Canonical asset filename, or an empty string when unsupported.
	 */
	private static function assetName(string $asset): string {
		$asset=strtolower(basename(str_replace('\\', '/', trim($asset))));
		if(in_array($asset, ['panel.css', 'panel.js', 'panel-head.js', 'panel-assets.json', 'panel-editor.js', 'panel-editor-assets.js', 'panel-extensions.js', 'panel-platform.css', 'panel-quality.js'], true)){
			return $asset;
		}
		return self::physicalAssetParts($asset)!==null ? $asset : '';
	}

	private static function physicalAssetName(string $type, string $chunk): string {
		$chunk=strtolower(trim($chunk));
		return $type==='style' ? 'panel-style-'.$chunk.'.css' : 'panel-runtime-'.$chunk.'.js';
	}

	/** @return ?array{type:'style'|'runtime',chunk:string} */
	private static function physicalAssetParts(string $asset): ?array {
		if(preg_match('/\Apanel-(style|runtime)-([a-z0-9][a-z0-9-]{0,63})\.(css|js)\z/', $asset, $match)!==1){
			return null;
		}
		$type=$match[1];
		if(($type==='style' && $match[3]!=='css') || ($type==='runtime' && $match[3]!=='js')){
			return null;
		}
		$chunk=$match[2];
		$known=$type==='style' ? self::physicalStyleChunkOrder() : self::physicalRuntimeChunkOrder();
		return in_array($chunk, $known, true) ? ['type'=>$type, 'chunk'=>$chunk] : null;
	}

	/**
	 * Concatenates all panel CSS modules into the public stylesheet bundle.
	 *
	 * @return string Complete panel.css content.
	 */
	private static function panelStylesheet(): string {
		return self::panelStylesheetForCapabilities(PanelAssetCapabilityManifest::make('*'));
	}

	/** Builds a stylesheet aggregate while retaining legacy cascade order. */
	private static function panelStylesheetForCapabilities(PanelAssetCapabilityManifest $graph): string {
		$sources=self::panelStylesheetChunkSources($graph);
		$stylesheet='';
		foreach(array_slice(self::stylesheetOwnerOrder(), 1, -1) as $chunk){
			if(($sources[$chunk] ?? '')!==''){
				$stylesheet.='/* dp-owner:'.$chunk.' */'.$sources[$chunk];
			}
		}
		return self::compactStylesheet('@layer dp-tokens,dp-panel,dp-accessibility;'.self::cssLayer('dp-tokens', $sources['tokens'])
			.self::cssLayer('dp-panel', $stylesheet)
			.self::cssLayer('dp-accessibility', $sources['accessibility']));
	}

	/** @return array<string,string> Physical stylesheet source fragments in cascade order. */
	private static function panelStylesheetChunkSources(PanelAssetCapabilityManifest $graph): array {
		$foundation=self::css();
		// showCss is the historical mixed surface foundation: alongside record
		// chrome it owns table views, summaries, and board primitives. Keep the
		// source intact for byte-compatible full bundles, but close over every
		// surface that consumes one of those primitives in scoped aggregates.
		if($graph->has('record') || $graph->has('table') || $graph->has('board')){ $foundation.=self::showCss(); }
		if($graph->has('record')){ $foundation.=self::infolistCss().self::recordPulseCss(); }
		if($graph->has('table')){ $foundation.=self::tablePulseCss(); }
		if($graph->has('board')){ $foundation.=self::boardPulseCss(); }
		if($graph->has('form')){ $foundation.=self::formPulseCss(); }

		$components=self::alertsCss();
		if($graph->has('record')){
			$components.=self::insightsCss().self::linksCss().self::contactsCss().self::locationsCss()
				.self::approvalsCss().self::tagsCss();
		}
		if($graph->has('board')){ $components.=self::boardCss(); }
		if($graph->has('record')){ $components.=self::tasksCss(); }
		if($graph->has('form')){ $components.=self::taskFormCss(); }
		if($graph->has('record')){
			$components.=self::activityCss().self::changesCss().self::itemsCss().self::totalsCss()
				.self::paymentsCss().self::shipmentsCss().self::attachmentsCss().self::messagesCss().self::notesCss();
		}
		if($graph->has('modal')){ $components.=self::modalCss(); }
		if($graph->has('form') || $graph->has('reactor')){ $components.=self::reactivityCss(); }
		$components.=self::themeSelectorCss();
		if($graph->has('navigation')){ $components.=self::sidebarCss().self::sidebarSearchCss(); }
		$components.=self::actionGroupCss();
		if($graph->has('form')){
			$components.=self::tabsCss().self::stepsCss().self::repeaterCss()
				.self::fieldComponentCoverageCss().self::fieldComponentCss();
		}
		if($graph->has('editor')){ $components.=self::editorBrowserAdapterCss(); }
		if($graph->has('editor-assets')){ $components.=self::editorAssetBrowserCss(); }
		if($graph->has('studio-editor')){ $components.=PanelStudioEditorAssets::css(); }
		$components.=self::themeOverrideCss().self::surfaceGuidanceCss();
		if($graph->has('widget-runtime')){ $components.=self::widgetRuntimeCss(); }
		if($graph->has('data-surface')){ $components.=self::dataSurfaceCss(); }
		if($graph->has('chart')){ $components.=self::chartCss(); }
		$components.=self::actionPolishCss();
		if($graph->has('table')){ $components.=self::tableShellCss(); }

		$layout='';
		if($graph->has('table')){ $layout.=self::columnDescriptionCss(); }
		$layout.=self::commandPaletteCss().self::appFrameCss();
		// advancedGridCss is shared by form fields, show fields, boards, and
		// table/grid workspaces despite its legacy placement beside table CSS.
		if($graph->has('form') || $graph->has('record') || $graph->has('table') || $graph->has('board')){ $layout.=self::advancedGridCss(); }
		$layout.=self::nextLevelUiCss().self::shellLayoutCss();
		if($graph->has('table')){
			$layout.=self::commandbarCss().self::commandbarModeCss().self::tableGroupCss().self::dataWorkspaceCss()
				.self::selectionCss().self::rowActionsCss().self::relationManagerCss().self::tableKeyboardCss();
		}
		$responsive=self::mobileReactCss().($graph->has('navigation') ? self::mobileNavigationCss() : '');
		$system=self::chromeAttachmentCss();
		if($graph->has('table')){ $system.=self::tableActionHeaderCss(); }
		if($graph->has('navigation')){ $system.=self::sidebarRailBreakpointCss(); }
		if($graph->has('auth')){ $system.=self::authCss(); }
		if($graph->has('table')){ $system.=self::tableMetaCompactCss().self::compactCommandbarPrimaryCss(); }
		$system.=self::labeledActionIconCleanupCss().self::panelProductStabilizerCss();
		$collectionLayout=$graph->has('collection-layout') || $graph->has('form') || $graph->has('record')
			|| $graph->has('table') || $graph->has('board') || $graph->has('widget-runtime');
		return [
			'tokens'=>self::visualSystemTokensCss(),
			'foundation'=>$foundation,
			'components'=>$components,
			'layout'=>$layout,
			'presentation'=>self::presentationCss(),
			'navigation'=>$graph->has('navigation') ? self::navigationExperienceCss() : '',
			'responsive'=>$responsive,
			'themes'=>self::brutalistThemeCss().self::glassThemeCss(),
			'system'=>$system,
			'visual-system'=>self::visualSystemCss()
				.(($graph->has('form') || $graph->has('record')) ? self::adaptiveFieldGridCss() : '')
				.($graph->has('record') ? self::recordActionOverflowCss() : '')
				.($graph->has('form') ? self::choiceNormalizationCss() : ''),
			'brick-v2'=>$collectionLayout ? self::brickV2Css().($graph->has('collection-layout') ? self::brickV3Css() : '') : '',
			'accessibility'=>self::reducedMotionOverrideCss(),
		];
	}

	/** @return list<string> */
	private static function physicalStyleChunkOrder(): array {
		return ['tokens','foundation','layout','experience','themes','accessibility'];
	}

	/** @return list<string> */
	private static function stylesheetOwnerOrder(): array {
		return ['tokens','foundation','components','layout','presentation','navigation','responsive','themes','system','visual-system','brick-v2','accessibility'];
	}

	/** @return list<string> */
	private static function physicalStyleChunkNames(PanelAssetCapabilityManifest $graph): array {
		return self::physicalStyleChunkOrder();
	}

	private static function physicalStyleChunk(string $chunk, PanelAssetCapabilityManifest $graph): ?string {
		$sources=self::panelStylesheetChunkSources($graph);
		$owners=match($chunk){
			'tokens'=>['tokens'],
			'foundation'=>['foundation','components'],
			'layout'=>['layout','presentation'],
			'experience'=>['navigation','responsive'],
			'themes'=>['themes','system','visual-system','brick-v2'],
			'accessibility'=>['accessibility'],
			default=>null,
		};
		if($owners===null){ return null; }
		$layer=$chunk==='tokens' ? 'dp-tokens' : ($chunk==='accessibility' ? 'dp-accessibility' : 'dp-panel');
		$content='';
		if($layer==='dp-panel'){
			foreach($owners as $owner){
				if(($sources[$owner] ?? '')!==''){
					$content.='/* dp-owner:'.$owner.' */'.$sources[$owner];
				}
			}
		}
		else {
			$content=$sources[$owners[0]] ?? '';
		}
		return self::compactStylesheet('@layer dp-tokens,dp-panel,dp-accessibility;'.self::cssLayer($layer, $content));
	}

	/**
	 * Removes formatting-only whitespace from the generated stylesheet.
	 *
	 * Component sources remain readable while the framework-owned public asset
	 * stays inside its release budget. Every source line ends at a CSS token
	 * boundary, so joining trimmed lines cannot merge identifiers or values.
	 */
	private static function compactStylesheet(string $css): string {
		$lines=preg_split('/\R/u', $css) ?: [$css];
		return implode('', array_map(static fn(string $line): string=>trim($line), $lines));
	}

	/**
	 * Wraps an owned stylesheet fragment in its declared cascade layer.
	 *
	 * Layer names are internal constants supplied by panelStylesheet(). Keeping
	 * the wrapper here makes ownership visible in the emitted asset without
	 * teaching individual component sources about bundle assembly.
	 */
	private static function cssLayer(string $name, string $css): string {
		return '@layer '.$name."{\n".$css."\n}\n";
	}

	/**
	 * Concatenates all panel runtime scripts into the public JavaScript bundle.
	 *
	 * @return string Complete panel.js content.
	 */
	private static function panelScriptBundle(): string {
		return self::panelScriptBundleForCapabilities(PanelAssetCapabilityManifest::make('*'));
	}

	/** Builds one lexical runtime aggregate from the selected source chunks. */
	private static function panelScriptBundleForCapabilities(PanelAssetCapabilityManifest $graph): string {
		$runtime=implode("\n", array_values(self::panelScriptChunkSources($graph)));
		$runtime=self::compactRuntimeScript($runtime);
		return "/* dp-panel-modal-submit-fallback-v2 */\n(function(window,document){\n".$runtime."\n})(window,document);";
	}

	/** @return array<string,string> Runtime source fragments in executable order. */
	private static function panelScriptChunkSources(PanelAssetCapabilityManifest $graph): array {
		$shell=self::script();
		if(!$graph->has('modal')){
			// Modal owns this historical helper, but command, state, Ajax, and
			// accessibility controllers also use it for safe string-built chrome.
			$shell.="\nfunction dpPanelEscape(value){return String(value||\"\").replace(/[&<>]/g,function(c){return {\"&\":\"&amp;\",\"<\":\"&lt;\",\">\":\"&gt;\"}[c];});}";
		}
		$studioRuntime=str_replace('document.'.'addEventListener("DOMContentLoaded",start,{once:true});', 'dpPanelListen(document,"DOMContentLoaded",start,{once:true});', PanelStudioEditorAssets::javascript());
		$studioRuntime=str_replace('window.'.'addEventListener("pagehide",stop,{once:true});', 'dpPanelListen(window,"pagehide",stop,{once:true});', $studioRuntime);
		$chunks=[
			'kernel'=>self::runtimeKernelScript(),
			'shell'=>$shell,
			'command'=>"dpPanelBeginController(\"command\");\n".self::commandRuntimeScript(),
			'state-table'=>"dpPanelBeginController(\"state_table\");\n".self::stateTableRuntimeScript(),
			'navigation'=>"dpPanelBeginController(\"navigation\");\n".self::navigationRuntimeScript(),
			'transport'=>"dpPanelBeginController(\"ajax\");\n".self::assetHandoffRuntimeScript()."\n".self::ajaxRuntimeScript(),
		];
		if($graph->has('form')){ $chunks['form']="dpPanelBeginController(\"fields\");\n".self::fieldRuntimeScript(); }
		if($graph->has('editor')){ $chunks['editor']="dpPanelBeginController(\"editor\");\n".self::editorRuntimeScript(); }
		if($graph->has('studio-editor')){ $chunks['studio-editor']="dpPanelBeginController(\"studio_editor\");\n".$studioRuntime; }
		$chunks['validation-upload']="dpPanelBeginController(\"validation_upload\");\n".self::validationUploadRuntimeScript();
		$chunks['accessibility']="dpPanelBeginController(\"accessibility\");\n".self::accessibilityRuntimeScript();
		$chunks['theme']="dpPanelBeginController(\"theme\");\n".self::themeModeRuntimeScript();
		if($graph->has('data-surface')){ $chunks['data-surface']="dpPanelBeginController(\"data_surface\");\n".self::dataSurfaceRuntimeScript(); }
		if($graph->has('widget-runtime')){ $chunks['widget-runtime']="dpPanelBeginController(\"widget_runtime\");\n".self::widgetRuntimeScript(); }
		if($graph->has('modal')){ $chunks['modal']="dpPanelBeginController(\"modal\");\n".self::modalScript(); }
		if($graph->has('board')){ $chunks['board']="dpPanelBeginController(\"board\");\n".self::boardScript(); }
		return $chunks;
	}

	private static function compactRuntimeScript(string $runtime): string {
		// Keep the extensive runtime API documentation in source without shipping
		// it on every panel.js response. Only JSDoc blocks are removed so ordinary
		// comments and string values such as MIME wildcards remain untouched.
		$runtime=preg_replace('~/\*\*.*?\*/~s', '', $runtime) ?? $runtime;
		// Source traits stay readable, while emitted classic JavaScript does not pay
		// for indentation or repeated blank lines. Panel runtime sources do not use
		// multiline string literals, so this preserves executable token boundaries
		// and line-comment termination without introducing a minifier dependency.
		$runtime=preg_replace('/^[\t ]+|[\t ]+$/m', '', $runtime) ?? $runtime;
		$runtime=preg_replace('/(?:\r?\n){2,}/', "\n", $runtime) ?? $runtime;
		return $runtime;
	}

	/** @return list<string> */
	private static function physicalRuntimeChunkOrder(): array {
		return ['kernel','interaction','transport','form','editor','studio-editor','quality','data-surface','widget-runtime','modal','board'];
	}

	/** @return list<string> */
	private static function physicalRuntimeChunkNames(PanelAssetCapabilityManifest $graph): array {
		$selected=['kernel'=>true,'interaction'=>true,'transport'=>true];
		if($graph->has('form')){ $selected['form']=true; }
		if($graph->has('editor')){ $selected['editor']=true; }
		if($graph->has('studio-editor')){ $selected['studio-editor']=true; }
		$selected['quality']=true;
		if($graph->has('data-surface')){ $selected['data-surface']=true; }
		if($graph->has('widget-runtime')){ $selected['widget-runtime']=true; }
		if($graph->has('modal')){ $selected['modal']=true; }
		if($graph->has('board')){ $selected['board']=true; }
		return array_values(array_filter(
			self::physicalRuntimeChunkOrder(),
			static fn(string $chunk): bool=>isset($selected[$chunk]),
		));
	}

	/** @return list<string> */
	private static function physicalRuntimeDependencies(string $chunk): array {
		return match($chunk){
			'kernel'=>[],
			'interaction','transport'=>['kernel'],
			'form'=>['kernel','transport'],
			'editor','studio-editor'=>['kernel','transport','form'],
			'quality'=>['kernel','interaction','transport'],
			'data-surface'=>['kernel','interaction','transport'],
			'widget-runtime'=>['kernel','transport'],
			'modal'=>['kernel','transport','form','quality'],
			'board'=>['kernel','interaction'],
			default=>[],
		};
	}

	private static function physicalRuntimeChunk(string $chunk): ?string {
		if(!in_array($chunk, self::physicalRuntimeChunkOrder(), true)){ return null; }
		$full=PanelAssetCapabilityManifest::make('*');
		$sources=self::panelScriptChunkSources($full);
		$members=match($chunk){
			'kernel'=>['kernel','shell'],
			'interaction'=>['command','state-table','navigation'],
			'transport'=>['transport'],
			'form'=>['form'],
			'editor'=>['editor'],
			'studio-editor'=>['studio-editor'],
			'quality'=>['validation-upload','accessibility','theme'],
			'data-surface'=>['data-surface'],
			'widget-runtime'=>['widget-runtime'],
			'modal'=>['modal'],
			'board'=>['board'],
		};
		$dependencies=self::physicalRuntimeDependencies($chunk);
		$dependencyJson=json_encode($dependencies, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		$chunkJson=json_encode($chunk, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		$source='';
		foreach($members as $member){
			if(!isset($sources[$member])){ return null; }
			$source.=($source==='' ? '' : "\n").'/* dp-panel-runtime-owner:'.$member." */\n".$sources[$member];
		}
		if($chunk==='kernel'){
			$source.="\nfunction dpPanelEscape(value){return String(value||\"\").replace(/[&<>]/g,function(c){return {\"&\":\"&amp;\",\"<\":\"&lt;\",\">\":\"&gt;\"}[c];});}";
		}
		$exports=self::physicalRuntimeExports($source);
		$exportCode=$exports===[] ? '' : "\nObject.assign(scope,{".implode(',', array_map(
			static fn(string $name): string=>$name.':'.$name,
			$exports,
		)).'});';
		$registry=$chunk==='kernel' ? <<<'JS'
var registry=panel.runtimeChunks=panel.runtimeChunks||{};
panel.requireRuntimeChunks=function(dependencies){
	var missing=(dependencies||[]).filter(function(name){return !registry[name]||registry[name].status!=="ready";});
	if(missing.length){throw new Error("Dataphyre Panel runtime chunk dependency missing: "+missing.join(", "));}
	return true;
};
panel.registerRuntimeChunk=function(name,dependencies,exports){
	registry[name]={name:name,dependencies:(dependencies||[]).slice(),exports:(exports||[]).slice(),status:"ready"};
	return registry[name];
};
JS
			: 'panel.requireRuntimeChunks('.$dependencyJson.');';
		$exportJson=json_encode($exports, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		$body='(function(global){'."\n"
			.'var panel=global.DataphyrePanel=global.DataphyrePanel||{};'."\n"
			.'var scope=panel.runtimeScope=panel.runtimeScope||{};'."\n"
			.$registry."\n"
			.'(function(scope){with(scope){'."\n".$source.$exportCode."\n".'}})(scope);'."\n"
			.'panel.registerRuntimeChunk('.$chunkJson.','.$dependencyJson.','.$exportJson.');'."\n"
			.'})(window);';
		return "/* dp-panel-runtime-chunk:".$chunk." */\n".self::compactRuntimeScript($body)
			."\n//# sourceURL=dataphyre-panel/".$chunk.".js";
	}

	/** @return list<string> */
	private static function physicalRuntimeExports(string $source): array {
		preg_match_all('/^(?:function|var|let|const)\s+(dpPanel[A-Za-z0-9_$]*)\b/m', $source, $matches);
		$exports=array_values(array_unique($matches[1] ?? []));
		sort($exports, SORT_STRING);
		return $exports;
	}

	/**
	 * Returns layout CSS for inline commandbar bottom controls.
	 *
	 * @return string CSS fragment appended to the panel stylesheet bundle.
	 */
	private static function commandbarModeCss(): string {
		return <<<'CSS'
.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-bottom{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-view,.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-utility{flex:0 1 auto;min-width:0}.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-utility{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap}@media(max-width:1180px){.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-bottom{display:grid;grid-template-columns:1fr}.dp-panel-commandbar[data-dp-panel-commandbar-bottom-mode="inline"] .dp-panel-commandbar-utility{justify-content:flex-start}}
CSS;
	}

	/**
	 * Returns the early theme-mode bootstrap script.
	 *
	 * @return string JavaScript that applies the stored theme mode before full panel scripts load.
	 */
	private static function panelHeadScript(): string {
		return <<<'JS'
(function(){
	var script=document.currentScript;
	var fallback=script ? (script.getAttribute("data-dp-panel-theme-mode") || "light") : "light";
	var mode=fallback;
	try {
		mode=localStorage.getItem("dataphyre_panel_theme_mode") || fallback;
	} catch(error) {}
	if(["light", "dark", "system"].indexOf(mode)===-1){
		mode=fallback;
	}
	document.documentElement.dataset.dpThemeMode=mode;
})();
JS;
	}

	/**
	 * Returns compact table metadata control CSS.
	 *
	 * @return string CSS fragment for dense table meta controls and mobile wrapping.
	 */
	private static function tableMetaCompactCss(): string {
		return <<<'CSS'
.dp-panel-table-meta{min-width:0}.dp-panel.dp-panel .dp-panel-table-meta-controls{display:inline-flex;align-items:center;justify-content:flex-end;gap:8px;flex:0 1 auto;min-width:0;max-width:100%;margin-left:auto;overflow:visible}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-view,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-utility,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-actions,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-groups{display:inline-flex;grid-template-columns:none;align-items:center;justify-content:flex-end;gap:8px;flex:0 1 auto;min-width:0;max-width:100%;width:auto;flex-wrap:nowrap}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-view:empty,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-utility:empty,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-actions:empty,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-groups:empty{display:none}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker{display:inline-flex;align-items:center;flex:0 0 auto;width:auto;min-width:0;max-width:max-content}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page label{display:inline-flex;align-items:center;gap:5px;width:auto;min-width:0;height:28px;min-height:28px;margin:0;color:var(--dp-text_muted);font-size:11px;font-weight:850;white-space:nowrap}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page label>span{display:inline;width:auto;min-width:0;min-height:0;height:auto;border:0;border-radius:0;background:transparent;padding:0;color:var(--dp-text_muted);font-size:11px;font-weight:850;line-height:1}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page select{width:auto;min-width:48px;max-width:64px;height:28px;min-height:28px;max-height:28px;block-size:28px;min-block-size:28px;border-radius:8px;padding:1px 20px 1px 8px;color:var(--dp-text_muted);font-size:11px;font-weight:850;line-height:1;box-shadow:none}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary{display:inline-flex;align-items:center;justify-content:center;gap:5px;width:auto;min-width:0;max-width:max-content;height:28px;min-height:28px;max-height:28px;block-size:28px;min-block-size:28px;border-radius:8px;padding:1px 8px;color:var(--dp-text_muted);font-size:11px;font-weight:850;line-height:1;white-space:nowrap;box-shadow:none}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary small{display:inline-flex;align-items:center;justify-content:center;min-width:0;height:16px;min-height:16px;border-radius:999px;padding:1px 5px;font-size:10px;line-height:1}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker form{right:0;left:auto;max-width:min(320px,calc(100vw - 32px))}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-button{height:28px;min-height:28px;max-height:28px;border-radius:8px;padding:1px 8px;font-size:11px;box-shadow:none}@media(max-width:900px){.dp-panel.dp-panel .dp-panel-table-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.dp-panel.dp-panel .dp-panel-table-meta-controls{justify-content:flex-start;flex:1 1 100%;margin-left:0}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary{width:auto;max-width:max-content}}@media(max-width:560px){.dp-panel.dp-panel .dp-panel-table-meta-controls{display:inline-flex;grid-template-columns:none;width:auto;flex-wrap:wrap}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-view,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-utility,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-actions,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-commandbar-groups{display:inline-flex;grid-template-columns:none;width:auto;flex-wrap:wrap}.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-per-page,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker,.dp-panel.dp-panel .dp-panel-table-meta-controls .dp-panel-column-picker summary{width:auto;max-width:max-content}}
CSS;
	}

	/**
	 * Returns CSS that compacts primary commandbar actions.
	 *
	 * @return string CSS fragment for index and board commandbar action layout.
	 */
	private static function compactCommandbarPrimaryCss(): string {
		return <<<'CSS'
@media(min-width:1181px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-top,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-top{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-search,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-search{min-width:0}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary{align-self:stretch}}
@media(min-width:861px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary{display:flex;align-items:center;justify-content:flex-start;gap:9px;flex-wrap:wrap;width:auto;max-width:100%}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary>.dp-panel-inline-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary>.dp-panel-inline-action,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary>.dp-panel-button,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary>.dp-panel-button,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary>.dp-panel-action-group,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary>.dp-panel-action-group{display:inline-flex;flex:0 0 auto;width:auto;min-width:0;max-width:100%}.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary .dp-panel-action,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary .dp-panel-action,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-primary .dp-panel-button,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-primary .dp-panel-button,.dp-panel[data-dp-panel-kind="index"] .dp-panel-commandbar-create,.dp-panel[data-dp-panel-kind="board"] .dp-panel-commandbar-create{width:auto;min-width:124px;max-width:max-content;justify-content:center}}
CSS;
	}

	/**
	 * Returns CSS that hides redundant icons on already-labeled actions.
	 *
	 * @return string CSS fragment for create and row-link action cleanup.
	 */
	private static function labeledActionIconCleanupCss(): string {
		return <<<'CSS'
.dp-panel-commandbar-create .dp-panel-action-icon,.dp-panel-row-link .dp-panel-action-icon{display:none}.dp-panel-commandbar-create,.dp-panel-row-link{gap:0}
CSS;
	}

	/**
	 * Returns Panel stabilization CSS.
	 *
	 * This late bundle preserves layout, commandbar, footer, and responsive
	 * behavior for dense application panel screens.
	 *
	 * @return string CSS fragment appended after shared panel styles.
	 */
	private static function panelProductStabilizerCss(): string {
		return <<<'CSS'
.dp-panel,body:has(.dp-panel){font-family:var(--dp-font_family,Inter,Arial,sans-serif)}
body:has(.dp-panel){color:var(--dp-text,#18202a);background:var(--dp-body_bg,var(--dp-app-bg,#f4f7fb))}
body[data-dp-theme-mode="dark"]:has(.dp-panel){background:var(--dp-body_bg,#020617);color:var(--dp-text,#f8fafc);color-scheme:dark}
.dp-panel{max-width:none;min-height:100dvh;font-family:var(--dp-font_family,Inter,Arial,sans-serif);color:var(--dp-text,#18202a)}
.dp-panel.dp-panel-nav-sidebar{grid-template-columns:320px minmax(0,1fr);column-gap:24px;padding:0}
.dp-panel-nav-sidebar .dp-panel-sidebar{border-radius:0;border:0;border-right:1px solid var(--dp-border_soft,#e7ecf2);background:var(--dp-surface,#fff);box-shadow:none;padding:18px 16px}
.dp-panel-nav-sidebar .dp-panel-sidebar-top{position:relative;top:auto;margin:0;padding:0 0 16px;background:transparent;border-radius:0;box-shadow:none;backdrop-filter:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand{border:0;border-radius:8px;background:transparent;box-shadow:none;padding:0;min-height:44px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand:hover{background:transparent}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand>span{width:44px;height:44px;border-radius:8px}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand strong{font-size:14px;font-weight:800}
.dp-panel-nav-sidebar .dp-panel-sidebar-brand small{font-size:12px;font-weight:600}
.dp-panel-nav-sidebar .dp-panel-sidebar-search,.dp-panel-nav-sidebar .dp-panel-sidebar-context,.dp-panel-nav-sidebar .dp-panel-sidebar-pin{display:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-nav{gap:10px}
.dp-panel-nav-sidebar .dp-panel-sidebar-group{display:grid;gap:4px;margin:8px 0 0;padding:12px 0 0;border:0;border-top:1px solid var(--dp-border_soft,#e7ecf2);border-radius:0;background:transparent;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2{margin:0;padding:0 8px;color:var(--dp-text_muted,#667085);font-size:11px;font-weight:750;letter-spacing:.04em;text-transform:uppercase}
.dp-panel-nav-sidebar .dp-panel-sidebar-group h2 button{min-height:28px;padding:0}
.dp-panel-nav-sidebar .dp-panel-sidebar-link{min-height:38px;border:0;border-radius:8px;background:transparent;box-shadow:none;color:var(--dp-text,#18202a);padding:5px 8px;transform:none;grid-template-columns:34px minmax(0,1fr) auto}
.dp-panel-nav-sidebar .dp-panel-sidebar-link:hover{background:var(--dp-surface_muted,#f8fafc);border:0;box-shadow:none;transform:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active{background:var(--dp-primary-600,#2563eb);color:#fff;border:0;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active strong,.dp-panel-nav-sidebar .dp-panel-sidebar-link.active small{color:#fff}
.dp-panel-nav-sidebar .dp-panel-sidebar-icon{width:30px;height:30px;border-radius:8px;background:var(--dp-neutral_bg,#eef2f7);color:var(--dp-neutral_text,#344054);font-size:10px;font-weight:750;box-shadow:none}
.dp-panel-nav-sidebar .dp-panel-sidebar-link.active .dp-panel-sidebar-icon{background:rgba(255,255,255,.16);color:#fff}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy strong{font-size:13px;font-weight:750}
.dp-panel-nav-sidebar .dp-panel-sidebar-copy small{font-size:12px;font-weight:500}
.dp-panel-nav-sidebar .dp-panel-sidebar-badge{background:transparent;color:inherit;font-size:11px;font-weight:700;padding:0}
.dp-panel .dp-panel-main-region{--dp-panel-main-pad-right:28px;display:flex;flex-direction:column;align-content:stretch;min-width:0;min-height:100dvh;gap:18px;padding:24px var(--dp-panel-main-pad-right) 0 0}
.dp-panel .dp-panel-mobile-nav-backdrop,.dp-panel .dp-panel-mobile-nav-toggle{display:none}
.dp-panel .dp-panel-main-region>header,.dp-panel .dp-panel-header{border:0;border-bottom:1px solid var(--dp-border_soft,#e7ecf2);border-radius:0;box-shadow:none;background:var(--dp-surface,#fff);margin:0;padding:22px 24px}
.dp-panel .dp-panel-commandbar{border:0;border-radius:0;background:transparent;box-shadow:none;padding:0}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{--dp-panel-table-header-control-height:32px;display:grid;grid-template-columns:minmax(220px,1fr) auto auto;align-items:center;align-self:center;gap:8px;min-width:0;width:100%;height:auto;min-height:var(--dp-panel-table-header-control-height);max-height:none}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls>*{align-self:center}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-panel,.dp-panel[data-dp-panel-kind="board"] .dp-panel-table-header-controls .dp-panel-filter-panel{display:inline-flex;align-items:center;align-self:center;width:auto;min-width:0;max-width:max-content;border:0;background:transparent;box-shadow:none}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-trigger,.dp-panel[data-dp-panel-kind="board"] .dp-panel-table-header-controls .dp-panel-filter-trigger{display:inline-flex;align-items:center;justify-content:center;gap:7px;width:auto;min-width:0;max-width:max-content;margin:0}
.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-table-header-primary{display:flex;justify-content:flex-end;min-width:0}
.dp-panel.dp-panel .dp-panel-table-shell,.dp-panel.dp-panel .dp-panel-page-table{border:1px solid var(--dp-border,#d9e0ea);border-radius:10px;background:var(--dp-surface,#fff);box-shadow:none}
.dp-panel.dp-panel .dp-panel-table-scroll,.dp-panel.dp-panel .dp-panel-table{border:0;border-color:var(--dp-border_soft,#e7ecf2);box-shadow:none;background:var(--dp-surface,#fff)}
.dp-panel.dp-panel .dp-panel-table th{background:var(--dp-surface_muted,#f8fafc);color:var(--dp-text_muted,#667085);font-size:11px;font-weight:750;letter-spacing:.04em}
.dp-panel.dp-panel .dp-panel-empty-state{border:0;background:transparent;box-shadow:none}
.dp-panel .dp-panel-modal-root[data-dp-panel-modal-style="slide_over"]{backdrop-filter:blur(2px);background:rgba(15,23,42,.18)}
body[data-dp-theme-mode="dark"] .dp-panel .dp-panel-modal-root[data-dp-panel-modal-style="slide_over"]{background:rgba(2,6,23,.34)}
.dp-panel .dp-panel-footer{box-sizing:border-box;display:block;position:relative;bottom:auto;z-index:auto;margin:0 calc(-1 * var(--dp-panel-main-pad-right,0px)) 0 0;margin-top:auto;align-self:end;padding:0;border:0;border-radius:0;background:transparent;box-shadow:none;width:calc(100% + var(--dp-panel-main-pad-right,0px));max-width:none;overflow:hidden}
.dp-panel .dp-panel-footer-slim{box-sizing:border-box;display:flex;align-items:center;gap:14px;flex-wrap:wrap;width:100%;max-width:none;border-top:1px solid var(--dp-border_soft,#e7ecf2);background:var(--dp-surface,#fff);color:var(--dp-text,#18202a);padding:14px 20px;font-size:13px}
.dp-panel .dp-panel-footer-slim p{margin:0;font-weight:650}
.dp-panel .dp-panel-footer-slim nav{display:inline-flex;align-items:center;gap:12px;flex-wrap:wrap}
.dp-panel .dp-panel-footer-slim a{color:var(--dp-primary-700,#175cd3);text-decoration:none;font-weight:650}
.dp-panel .dp-panel-footer-identity{color:var(--dp-text_muted,#667085)}
.dp-panel .dp-panel-footer-language{display:inline-flex;align-items:center;gap:8px;margin-left:auto}
.dp-panel .dp-panel-footer-language label{display:inline-flex;align-items:center;gap:6px}
.dp-panel .dp-panel-footer-language select,.dp-panel .dp-panel-footer-language button{min-height:32px;border:1px solid var(--dp-border,#d9e0ea);border-radius:8px;background:var(--dp-control_bg,#fff);color:var(--dp-text,#18202a);padding:5px 9px;font:inherit}
.dp-panel .dp-panel-footer-theme-toggle{min-height:34px}
@media(min-width:1181px){.dp-panel .dp-panel-footer{transform:translateX(-2px)}}
.dp-panel[data-dp-panel-sidebar-animation]:not([data-dp-panel-sidebar-animation="none"]) .dp-panel-sidebar-link,.dp-panel[data-dp-panel-sidebar-animation]:not([data-dp-panel-sidebar-animation="none"]) .dp-panel-sidebar-icon{transition:background var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),color var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),border-color var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),box-shadow var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)}
@media(max-width:1180px){.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"]{display:block;width:100%;max-width:100%;padding:0}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region{--dp-panel-main-pad-inline:16px;--dp-panel-main-pad-right:var(--dp-panel-main-pad-inline);padding:16px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>.dp-panel-footer{margin-inline:calc(-1 * var(--dp-panel-main-pad-inline,0px));width:calc(100% + (2 * var(--dp-panel-main-pad-inline,0px)))}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-toggle{display:inline-grid;place-items:center;position:relative;width:42px;height:42px;margin:0 0 8px;border:1px solid var(--dp-border,#d9e0ea);border-radius:10px;background:var(--dp-surface,#fff);color:var(--dp-text,#18202a);box-shadow:none}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-toggle span{display:block;width:17px;height:2px;border-radius:999px;background:currentColor}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-mobile-nav-backdrop{display:none;position:fixed;inset:0;z-index:79;border:0;background:rgba(15,23,42,.36)}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open .dp-panel-mobile-nav-backdrop{display:block}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar{position:fixed;inset:0 auto 0 0;z-index:90;width:min(326px,88vw);max-width:min(326px,88vw);height:100dvh;max-height:100dvh;margin:0;overflow:auto;overscroll-behavior:contain;transform:translateX(-104%);transition:transform .18s ease;border:0;border-right:1px solid var(--dp-border_soft,#e7ecf2);border-radius:0;background:var(--dp-surface,#fff);box-shadow:0 24px 70px rgba(15,23,42,.18);padding:16px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"].dp-panel-mobile-nav-open .dp-panel-sidebar{transform:translateX(0)}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-top{position:relative;top:auto;display:block;margin:0;padding:0 0 14px;border:0;border-radius:0;background:transparent;box-shadow:none}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand{display:grid;grid-template-columns:44px minmax(0,1fr);gap:10px;align-items:center;width:100%;min-height:44px;padding:0}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-brand small{display:none}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-nav{display:grid;grid-template-columns:1fr;gap:8px;width:100%;overflow:visible;padding:0}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group{display:grid;grid-template-columns:1fr;gap:4px;flex:0 1 auto;margin:8px 0 0;padding:12px 0 0;border:0;border-top:1px solid var(--dp-border_soft,#e7ecf2);border-radius:0;background:transparent;box-shadow:none}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group h2{display:block;grid-column:1/-1;width:100%;margin:0 0 4px;padding:0 8px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-group h2 button{display:flex;align-items:center;justify-content:space-between;min-height:28px;width:100%}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-link{width:100%;min-width:0;max-width:none;min-height:40px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-item{display:grid;grid-template-columns:minmax(0,1fr);gap:0}.dp-panel-footer-language{margin-left:0}}
.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;column-gap:10px;row-gap:8px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-mobile-nav-toggle{grid-column:1;grid-row:1;margin:0}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-breadcrumbs{grid-column:2;grid-row:1;min-width:0;margin:0}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-brand,.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row{grid-column:1/-1}
@media(max-width:480px){.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header{row-gap:8px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row{grid-column:1/-1;grid-row:2;align-self:center;min-width:0}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row h1{font-size:clamp(22px,7vw,30px);line-height:1.05}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header>.dp-panel-heading-row p{margin:0 0 2px}}
@media(max-width:1180px){.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="none"] .dp-panel-sidebar{transition:none}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="slide"] .dp-panel-sidebar{transition:transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="slide_fade"] .dp-panel-sidebar{opacity:0;transition:transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="slide_fade"].dp-panel-mobile-nav-open .dp-panel-sidebar{opacity:1}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="fade"] .dp-panel-sidebar{transform:translateX(0);opacity:0;pointer-events:none;transition:opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="fade"].dp-panel-mobile-nav-open .dp-panel-sidebar{opacity:1;pointer-events:auto}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="scale"] .dp-panel-sidebar{transform:translateX(-10px) scale(.985);transform-origin:left center;opacity:0;pointer-events:none;transition:transform var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease),opacity var(--dp-panel-sidebar-animation-duration,.18s) var(--dp-panel-sidebar-animation-easing,ease)}.dp-panel[data-dp-panel-mobile-navigation="drawer"][data-dp-panel-sidebar-animation="scale"].dp-panel-mobile-nav-open .dp-panel-sidebar{transform:translateX(0) scale(1);opacity:1;pointer-events:auto}}
@media(prefers-reduced-motion:reduce){.dp-panel[data-dp-panel-sidebar-animation] .dp-panel-sidebar,.dp-panel[data-dp-panel-sidebar-animation] .dp-panel-sidebar-link,.dp-panel[data-dp-panel-sidebar-animation] .dp-panel-sidebar-icon{transition:none;animation:none}}
@media(max-width:1080px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta{grid-template-columns:1fr auto;align-items:center}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{grid-column:1/-1;grid-row:2;grid-template-columns:minmax(0,1fr) auto;justify-content:start;max-width:760px}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact{grid-column:1/-1;width:100%}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-table-header-primary{grid-column:auto;justify-content:flex-start}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create{width:auto;min-width:120px}}
@media(max-width:900px){.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-meta{grid-template-columns:1fr;align-items:stretch}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{grid-template-columns:minmax(0,1fr) auto;max-width:none}}
@media(max-width:760px){.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region{--dp-panel-main-pad-inline:12px;--dp-panel-main-pad-right:var(--dp-panel-main-pad-inline);padding:12px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-main-region>header{padding:14px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar{width:min(318px,88vw);max-width:min(318px,88vw);padding:12px}.dp-panel-nav-sidebar[data-dp-panel-mobile-navigation="drawer"] .dp-panel-sidebar-copy small{display:none}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls{grid-template-columns:1fr}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact{display:flex;grid-template-columns:none;align-items:stretch;width:100%}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact input[type="search"]{flex:1 1 auto;width:auto;min-width:0;border-radius:10px 0 0 10px}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-search-compact .dp-panel-button{flex:0 0 auto;width:auto;min-width:96px;margin-left:-1px;border-radius:0 10px 10px 0}.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-panel,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-controls .dp-panel-filter-trigger,.dp-panel[data-dp-panel-kind="index"] .dp-panel-table-header-create{width:100%;max-width:none}.dp-panel .dp-panel-footer-slim{display:grid;gap:10px;padding:12px}.dp-panel .dp-panel-footer-language{display:grid;grid-template-columns:1fr auto}.dp-panel .dp-panel-footer-theme-toggle{width:max-content}}
@media(max-width:620px){
.dp-panel-nav-sidebar:not([data-dp-panel-mobile-navigation="drawer"]) .dp-panel-sidebar-nav{scrollbar-width:none}
.dp-panel-nav-sidebar:not([data-dp-panel-mobile-navigation="drawer"]) .dp-panel-sidebar-nav::-webkit-scrollbar{display:none;width:0;height:0}
}
CSS;
	}

	/**
	 * Escapes text for safe HTML output when asset helpers emit markup fragments.
	 *
	 * @param string $value Raw text.
	 * @return string UTF-8 HTML-escaped text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
