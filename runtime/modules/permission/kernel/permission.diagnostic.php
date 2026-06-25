<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\permission;

\dp_define_module_config('permission', 'DP_PERMISSION_CFG');

\dataphyre\permission\diagnostic::tests();

/**
 * Validates Permission module prerequisites and configuration shape.
 *
 * The diagnostic runs during module load and reports missing PHP features,
 * malformed role maps, invalid aliases, non-callable conditions, resolver
 * mistakes, and unsafe storage table names to dpanel when panel collection is
 * available.
 */
final class diagnostic {

	/**
	 * Collects Permission health findings for the current runtime.
	 *
	 * Configuration is inspected defensively because diagnostic entrypoints may
	 * execute in embedded tools before the full Permission runtime has been
	 * bootstrapped.
	 *
	 * @return void Findings are appended to dpanel verbose output when the panel class is loaded.
	 */
	public static function tests(): void {
		$verbose=[];
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'permission', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		foreach(['json', 'pcre'] as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'permission', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		foreach(['roles', 'aliases', 'conditions', 'subject', 'cache', 'panel', 'storage'] as $section){
			if(isset($config[$section]) && !is_array($config[$section])){
				$verbose[]=['module'=>'permission', 'error'=>"Configuration section '{$section}' must be an array.", 'time'=>time()];
			}
		}
		$roles=is_array($config['roles'] ?? null) ? $config['roles'] : [];
		foreach($roles as $role=>$permissions){
			if(!is_string($role) || trim($role)===''){
				$verbose[]=['module'=>'permission', 'error'=>'A configured role has an invalid name.', 'time'=>time()];
			}
			if(!is_array($permissions) && !is_string($permissions)){
				$verbose[]=['module'=>'permission', 'error'=>"Role '{$role}' permissions must be a string or array.", 'time'=>time()];
			}
		}
		$aliases=is_array($config['aliases'] ?? null) ? $config['aliases'] : [];
		foreach($aliases as $alias=>$target){
			if(!is_string($alias) || trim($alias)==='' || !is_string($target) || trim($target)===''){
				$verbose[]=['module'=>'permission', 'error'=>'Permission aliases must map non-empty strings to non-empty strings.', 'time'=>time()];
			}
		}
		$conditions=is_array($config['conditions'] ?? null) ? $config['conditions'] : [];
		foreach($conditions as $name=>$condition){
			if(!is_string($name) || trim($name)==='' || !is_callable($condition)){
				$verbose[]=['module'=>'permission', 'error'=>'Permission conditions must map non-empty names to callables.', 'time'=>time()];
			}
		}
		$subject=is_array($config['subject'] ?? null) ? $config['subject'] : [];
		foreach(['id_resolver', 'user_resolver', 'permission_resolver', 'role_resolver'] as $resolver){
			if(isset($subject[$resolver]) && $subject[$resolver]!==null && !is_callable($subject[$resolver])){
				$verbose[]=['module'=>'permission', 'error'=>"Subject resolver '{$resolver}' must be callable.", 'time'=>time()];
			}
		}
		$storage=is_array($config['storage'] ?? null) ? $config['storage'] : [];
		foreach(['assignments_table', 'roles_table', 'role_permissions_table'] as $table_key){
			$table=trim((string)($storage[$table_key] ?? ''));
			if($table!=='' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table)!==1){
				$verbose[]=['module'=>'permission', 'error'=>"Storage table '{$table_key}' has an invalid name.", 'time'=>time()];
			}
		}
		if(class_exists('\dataphyre\dpanel', false)){
			\dataphyre\dpanel::add_verbose($verbose);
		}
	}
}
