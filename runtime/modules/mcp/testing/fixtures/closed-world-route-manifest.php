<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

return [
	'version'=>1,
	'metadata'=>['fixture'=>'mcp-closed-world-boundary'],
	'routes'=>[
		'malformed',
		[
			'name'=>'orders.show',
			'methods'=>['GET'],
			'path'=>'/orders/{id}',
			'path_regex'=>'#^/orders/(?P<id>[0-9]+)$#',
			'defaults'=>[],
			'metadata'=>['module'=>'demo'],
			'handler'=>['type'=>'controller','class'=>'OrderController','method'=>'show'],
			'middleware'=>['auth'],
		],
	],
];
