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
	 * @param ?callable $terminator Optional deterministic request-termination strategy.
	 * @return bool True only when Flightdeck is enabled and the operator is authenticated.
	 */
	public static function authorize(string $surface='module', ?callable $terminator=null): bool {
		$auth_available=class_exists('dataphyre_flightdeck_auth', false);
		return self::authorize_state(
			$auth_available,
			$auth_available && dataphyre_flightdeck_auth::production_disabled()===true,
			$auth_available && dataphyre_flightdeck_auth::enabled()===true,
			$auth_available && dataphyre_flightdeck_auth::authenticated()===true,
			$terminator,
		);
	}

	/**
	 * Applies one dependency-independent route authorization decision.
	 *
	 * @param bool $auth_available Whether the local authenticator is callable.
	 * @param bool $production_disabled Whether production policy disables Flightdeck.
	 * @param bool $enabled Whether Flightdeck is enabled by configuration.
	 * @param bool $authenticated Whether the current operator is authenticated.
	 * @param ?callable $terminator Optional deterministic request-termination strategy.
	 * @return bool True only for an enabled authenticated request.
	 */
	private static function authorize_state(bool $auth_available, bool $production_disabled, bool $enabled, bool $authenticated, ?callable $terminator=null): bool {
		if($auth_available!==true){
			http_response_code(503);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Flightdeck installation is incomplete.';
			return self::terminate($terminator);
		}
		if($production_disabled===true){
			http_response_code(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Not found';
			return self::terminate($terminator);
		}
		if($enabled!==true){
			http_response_code(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Flightdeck is disabled.';
			return self::terminate($terminator);
		}
		if($authenticated===true){
			return true;
		}
		dataphyre_flightdeck_auth::redirect_to_login($terminator);
		return false;
	}

	/** @return false */
	private static function terminate(?callable $terminator): bool {
		if($terminator===null){exit;}
		$terminator();
		return false;
	}
}
