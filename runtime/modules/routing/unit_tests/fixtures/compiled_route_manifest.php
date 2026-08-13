<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
return [
	'routes'=>[[
		'exact_path'=>'/compiled-file',
		'methods'=>['GET'],
		'defaults'=>['source'=>'manifest'],
		'handler'=>static function(array $parameters): void {
			\Dataphyre\RoutingCoverage\CompiledRouteScenario::manifestParameters($parameters);
		},
	]],
];
