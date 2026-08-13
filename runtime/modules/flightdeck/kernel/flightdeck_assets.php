<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_ASSET_ENDPOINT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_ASSET_ENDPOINT_LOADED',true);

require_once __DIR__.'/asset_response.php';

/** Serves immutable console and module-surface assets. */
final class dataphyre_flightdeck_assets_endpoint {

	/**
	 * Dispatches one Flightdeck asset request.
	 *
	 * @param ?string $view_file Optional deterministic view dependency path.
	 * @param ?string $flightdeck_file Optional deterministic console dependency path.
	 * @param ?string $auth_file Optional deterministic auth dependency path.
	 * @param ?bool $production_disabled Optional deterministic production policy decision.
	 * @param ?array<string,mixed> $route_bindings Optional deterministic route bindings.
	 * @param ?array<string,mixed> $query Optional deterministic query values.
	 * @param ?array<string,mixed> $server Optional deterministic server values.
	 */
	public static function dispatch(?string $view_file=null, ?string $flightdeck_file=null, ?string $auth_file=null, ?bool $production_disabled=null, ?array $route_bindings=null, ?array $query=null, ?array $server=null): void {
		$view_file ??= __DIR__.'/view.php';
		$flightdeck_file ??= __DIR__.'/flightdeck.php';
		$auth_file ??= __DIR__.'/auth.php';
		if(is_file($auth_file)){
			require_once($auth_file);
		}
		if(is_file($view_file)!==true){
			dataphyre_flightdeck_asset_response::emit(dataphyre_flightdeck_asset_response::missing());
			return;
		}
		$production_disabled ??= class_exists('dataphyre_flightdeck_auth',false)
			&& dataphyre_flightdeck_auth::production_disabled()===true;
		if($production_disabled===true){
			dataphyre_flightdeck_asset_response::emit(dataphyre_flightdeck_asset_response::missing());
			return;
		}
		if(defined('DATAPHYRE_FLIGHTDECK_NO_DISPATCH')!==true){
			define('DATAPHYRE_FLIGHTDECK_NO_DISPATCH',true);
		}
		if(defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')!==true){
			define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST',true);
		}
		if(is_file($flightdeck_file)){
			require_once($flightdeck_file);
		}
		require_once($view_file);

		$route_bindings ??= class_exists('dataphyre\\routing',false) ? (\dataphyre\routing::$bindings ?? []) : [];
		$query ??= $_GET;
		$server ??= $_SERVER;
		$asset=dataphyre_flightdeck_asset_response::request_asset($route_bindings,$query,$server);
		$content=class_exists('dataphyre_flightdeck',false) ? dataphyre_flightdeck::asset_content($asset) : null;
		if(!is_array($content)){
			$content=class_exists('dataphyre_flightdeck_view',false) ? dataphyre_flightdeck_view::asset_content($asset) : null;
		}
		if(!is_array($content)){
			$content=self::surface_asset_content($asset);
		}
		dataphyre_flightdeck_asset_response::emit(
			dataphyre_flightdeck_asset_response::build($asset,$content,__FILE__,$server),
		);
	}

	/**
	 * @param ?string $surface_root Optional deterministic surface dependency root.
	 * @param ?list<class-string> $surface_classes Optional deterministic surface resolver order.
	 * @return ?array{content_type:string,body:string}
	 */
	private static function surface_asset_content(string $asset, ?string $surface_root=null, ?array $surface_classes=null): ?array {
		$surface_assets=[
			'panel-surface.css'=>'panel.php',
			'reactor-surface.css'=>'reactor.php',
			'dpanel-surface.css'=>'dpanel.php',
			'datadoc-surface.css'=>'datadoc.php',
			'tracelog-surface.css'=>'tracelog.php',
			'tracelog-plotter.js'=>'tracelog.php',
		];
		$surface_file=$surface_assets[basename($asset)] ?? '';
		if($surface_file===''){
			return null;
		}
		$surface_root ??= __DIR__.'/surfaces';
		$surface_path=rtrim($surface_root,'/\\').DIRECTORY_SEPARATOR.$surface_file;
		if(is_file($surface_path)!==true){
			return null;
		}
		require_once($surface_path);
		$surface_classes ??= [
			'dataphyre_flightdeck_panel_surface',
			'dataphyre_flightdeck_reactor_surface',
			'dataphyre_flightdeck_dpanel_surface',
			'dataphyre_flightdeck_datadoc_surface',
			'dataphyre_flightdeck_tracelog_surface',
		];
		foreach($surface_classes as $surface_class){
			if(class_exists($surface_class,false) && method_exists($surface_class,'asset_content')){
				$content=$surface_class::asset_content($asset);
				if(is_array($content)){
					return $content;
				}
			}
		}
		return null;
	}
}

if(defined('DATAPHYRE_FLIGHTDECK_ASSET_ENDPOINT_NO_DISPATCH')!==true){
	dataphyre_flightdeck_assets_endpoint::dispatch();
}
