<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_ROUTE_GUARD_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_ROUTE_GUARD_LOADED', true);

$flightdeck_auth_file=__DIR__.'/auth.php';
if(is_file($flightdeck_auth_file)){
	require_once($flightdeck_auth_file);
}

/**
 * Guards direct Flightdeck kernel routes before module UI code executes.
 *
 * The guard is intentionally dependency-light: it loads the local auth helper,
 * refuses incomplete or disabled installations with plain-text responses, and
 * redirects unauthenticated operators to the Flightdeck login flow.
 */
final class dataphyre_flightdeck_route_guard {

	/**
	 * Authorizes access to a Flightdeck surface.
	 *
	 * Failure paths send an HTTP response and terminate the process, matching the
	 * direct route entrypoint model used by legacy Flightdeck assets.
	 *
	 * @param string $surface Logical surface name retained for route-specific policy expansion.
	 * @return bool True only when Flightdeck is enabled and the operator is authenticated.
	 */
	public static function authorize(string $surface='module'): bool {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			http_response_code(503);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Flightdeck installation is incomplete.';
			exit;
		}
		if(dataphyre_flightdeck_auth::production_disabled()===true){
			http_response_code(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Not found';
			exit;
		}
		if(dataphyre_flightdeck_auth::enabled()!==true){
			http_response_code(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Flightdeck is disabled.';
			exit;
		}
		if(dataphyre_flightdeck_auth::authenticated()===true){
			return true;
		}
		dataphyre_flightdeck_auth::redirect_to_login();
	}
}
