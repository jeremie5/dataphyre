<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(class_exists('dataphyre_flightdeck_route_guard', false)){
	return;
}

$flightdeck_auth_file=__DIR__.'/auth.php';
if(is_file($flightdeck_auth_file)){
	require_once($flightdeck_auth_file);
}

final class dataphyre_flightdeck_route_guard {

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
