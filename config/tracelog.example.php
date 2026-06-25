<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
return [
	'dataphyre'=>[
		'tracelog'=>[
			'enable_tracelog'=>false,
			'save_to_file'=>false,
			'file_lifespan'=>6,
			'password'=>getenv('DATAPHYRE_TRACELOG_PASSWORD') ?: null,
		],
	],
];
