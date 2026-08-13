<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_ASSET_ENDPOINT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_ASSET_ENDPOINT_LOADED',true);

require_once __DIR__.'/asset_response.php';

/** Serves immutable Debugbar CSS and JavaScript assets. */
final class dataphyre_flightdeck_debugbar_assets_endpoint {

	/**
	 * Dispatches one Debugbar asset request.
	 *
	 * @param ?string $debugbar_file Optional deterministic Debugbar dependency path.
	 * @param ?bool $production_disabled Optional deterministic production policy decision.
	 * @param ?array<string,mixed> $route_bindings Optional deterministic route bindings.
	 * @param ?array<string,mixed> $query Optional deterministic query values.
	 * @param ?array<string,mixed> $server Optional deterministic server values.
	 */
	public static function dispatch(?string $debugbar_file=null, ?bool $production_disabled=null, ?array $route_bindings=null, ?array $query=null, ?array $server=null): void {
		$debugbar_file ??= __DIR__.'/debugbar.php';
		if(is_file($debugbar_file)!==true){
			dataphyre_flightdeck_asset_response::emit(dataphyre_flightdeck_asset_response::missing());
			return;
		}
		require_once($debugbar_file);
		$production_disabled ??= class_exists('dataphyre_flightdeck_auth',false)
			&& dataphyre_flightdeck_auth::production_disabled()===true;
		if($production_disabled===true){
			dataphyre_flightdeck_asset_response::emit(dataphyre_flightdeck_asset_response::missing());
			return;
		}
		$route_bindings ??= class_exists('dataphyre\\routing',false) ? (\dataphyre\routing::$bindings ?? []) : [];
		$query ??= $_GET;
		$server ??= $_SERVER;
		$asset=dataphyre_flightdeck_asset_response::request_asset($route_bindings,$query,$server);
		$content=class_exists('dataphyre_flightdeck_debugbar',false)
			? dataphyre_flightdeck_debugbar::asset_content($asset)
			: null;
		dataphyre_flightdeck_asset_response::emit(
			dataphyre_flightdeck_asset_response::build($asset,$content,__FILE__,$server),
		);
	}
}

if(defined('DATAPHYRE_FLIGHTDECK_ASSET_ENDPOINT_NO_DISPATCH')!==true){
	dataphyre_flightdeck_debugbar_assets_endpoint::dispatch();
}
