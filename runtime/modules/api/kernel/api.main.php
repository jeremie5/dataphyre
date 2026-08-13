<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Module initialization');

/**
 * Loads shared and application API configuration files from a root map.
 *
 * @param array<string,mixed> $rootPath Dataphyre root paths keyed by common_dataphyre and dataphyre.
 */
function api_bootstrap_config(array $rootPath): void {
	foreach(['common_dataphyre', 'dataphyre'] as $rootKey){
		$root=$rootPath[$rootKey] ?? null;
		if(!is_string($root) || trim($root)===''){
			continue;
		}
		$filepath=rtrim($root, '/\\').'/config/api.php';
		if(file_exists($filepath)){
			require_once($filepath);
		}
	}
}

api_bootstrap_config(defined('ROOTPATH') && is_array(ROOTPATH) ? ROOTPATH : []);

/**
 * Kernel marker for the API module after configuration bootstrap.
 *
 * The API module currently exposes its behavior through configuration, route
 * registration, and companion kernel files. This marker class gives runtime
 * checks and module discovery a stable symbol without adding mutable state.
 */
class api {
}
