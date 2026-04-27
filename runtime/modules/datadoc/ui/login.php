<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
$return_url=class_exists('\dataphyre\datadoc', false)
	? \dataphyre\datadoc::index_url()
	: '/dataphyre/datadoc';

if(class_exists('dataphyre_flightdeck_auth', false)){
	header('Location: '.dataphyre_flightdeck_auth::login_url($return_url));
	exit;
}

header('Location: /dataphyre/login?'.http_build_query(['return'=>$return_url]));
exit;
