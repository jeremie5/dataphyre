<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
$example_applications=is_dir(__DIR__.'/applications/example_app') ? __DIR__.'/applications' : __DIR__.'/examples/minimal/applications';
return [
	'bootstrap'=>[
		'app'=>'example_app',
		'prevent_keyless_direct_access'=>false,
		'allow_app_override'=>false,
		'is_production'=>false,
		'max_execution_time'=>30,
		'application_roots'=>[
			$example_applications,
		],
		'flightdeck'=>[
			'enabled'=>false,
			'debugbar'=>[
				'enabled'=>false,
				'memory_limit'=>null,
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
