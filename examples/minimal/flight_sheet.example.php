<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
return [
	'bootstrap'=>[
		'app'=>'example_app',
		'prevent_keyless_direct_access'=>false,
		'allow_app_override'=>false,
		'is_production'=>false,
		'max_execution_time'=>30,
		'application_roots'=>[
			__DIR__.'/examples/minimal/applications',
		],
		'flightdeck'=>[
			'enabled'=>false,
			'debugbar'=>[
				'enabled'=>false,
			],
		],
	],
	'install'=>[
		'shared'=>[
			'directories'=>[
				'cache',
				'logs',
				'config',
				'plugins',
			],
		],
		'app'=>[
			'directories'=>[
				'cache',
				'logs',
				'config',
			],
		],
	],
];
