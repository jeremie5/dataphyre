<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$authFile=(string)($argv[1] ?? '');
if($authFile==='' || !is_file($authFile)){
	throw new InvalidArgumentException('Flightdeck auth file is required.');
}

define('IS_PRODUCTION',true);
$GLOBALS['dataphyre_flightdeck_config']=['enabled'=>true,'password'=>'secret']; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Production fixture must publish the native Flightdeck configuration boundary."
$_COOKIE=['dataphyre_flightdeck'=>'invalid']; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Production fixture must model the native signed-cookie boundary."
require $authFile;
include $authFile;

echo json_encode([
	'production_disabled'=>dataphyre_flightdeck_auth::production_disabled(),
	'auth_required'=>dataphyre_flightdeck_auth::auth_required(),
	'enabled'=>dataphyre_flightdeck_auth::enabled(),
	'authenticated'=>dataphyre_flightdeck_auth::authenticated(),
	'login_error'=>dataphyre_flightdeck_auth::login_error(),
],JSON_THROW_ON_ERROR)."\n";
