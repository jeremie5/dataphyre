<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
return [
	'sanction_on_useragent_change'=>true,
	'sessions_table_name'=>'dataphyre.sessions',
	'sessions_cookie_name'=>'DPID',
	'must_no_session_redirect'=>'/',
	'require_session_redirect'=>'/login',
	'requires_app_redirect'=>'/requires_app',
	'robot_redirect'=>'/robot',
	'botlist'=>[
		'bot',
		'crawler',
		'headless',
		'spider',
	],
];
