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
	'metadata'=>['fixture'=>'mcp-semantic-contract'],
	'routes'=>[[
		'name'=>'semantic.route',
		'exact_path'=>'/semantic',
		'methods'=>['GET'],
		'metadata'=>['surface'=>'semantic'],
	]],
	'named_routes'=>['semantic.route'=>0],
];
