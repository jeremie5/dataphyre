<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

return [
	'default_cluster'=>'primary',
	'datacenters'=>[
		'local'=>[
			'dbms_clusters'=>[
				'primary'=>['dbms'=>'sqlite'],
			],
		],
	],
	'tables'=>[
		'example.items'=>[
			'cluster'=>'primary',
			'multipoint_writes'=>false,
			'caching'=>[],
		],
	],
];
