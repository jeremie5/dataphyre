<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Permission\PermissionAudit;
use Dataphyre\Permission\PermissionManifest;
use Dataphyre\Permission\PermissionRule;

if(PHP_SAPI!=='cli'){
	http_response_code(404);
	echo "Permission checker is only available from CLI.\n";
	exit(2);
}

try{
	$options=dp_permission_check_options($argv ?? []);
	if(isset($options['help'])){
		dp_permission_check_usage();
		exit(0);
	}
	dp_permission_check_bootstrap();
	$report=dp_permission_check_report($options);
	dp_permission_check_print($report, $options);
	if(isset($options['json'])){
		dp_permission_check_write_json((string)$options['json'], $report);
	}
	exit(dp_permission_check_exit_code($report, $options));
}
catch(Throwable $exception){
	fwrite(STDERR, '[ERROR] '.$exception->getMessage().PHP_EOL);
	exit(2);
}

/**
 * Parses permission audit CLI flags into a normalized option map.
 *
 * The runner accepts either a full manifest or separate role/catalog/assignment
 * JSON files. Optional flags control JSON output, manifest diffs, quiet output,
 * and which audit severities should fail the process.
 *
 * @param array<int, string> $argv Raw CLI argument vector including script name.
 * @return array{manifest: ?string, roles: ?string, known: ?string, assignments: ?string, against: ?string, json: ?string, fail_on_warning: bool, fail_on_info: bool, fail_on_diff: bool, quiet: bool, help?: bool} Parsed options.
 *
 * @throws InvalidArgumentException When a required path is missing, options conflict, or an unknown flag is present.
 */
function dp_permission_check_options(array $argv): array {
	$options=[
		'manifest'=>null,
		'roles'=>null,
		'known'=>null,
		'assignments'=>null,
		'against'=>null,
		'json'=>null,
		'fail_on_warning'=>false,
		'fail_on_info'=>false,
		'fail_on_diff'=>false,
		'quiet'=>false,
	];
	$arguments=array_values(array_slice($argv, 1));
	for($i=0; $i<count($arguments); $i++){
		$argument=(string)$arguments[$i];
		if($argument==='--help' || $argument==='-h'){
			$options['help']=true;
			continue;
		}
		foreach(['manifest', 'roles', 'known', 'assignments', 'against', 'json'] as $key){
			$prefix='--'.$key.'=';
			if(str_starts_with($argument, $prefix)){
				$options[$key]=substr($argument, strlen($prefix));
				continue 2;
			}
			if($argument==='--'.$key){
				if(!isset($arguments[$i + 1])){
					throw new InvalidArgumentException('--'.$key.' requires a path.');
				}
				$options[$key]=(string)$arguments[++$i];
				continue 2;
			}
		}
		if($argument==='--fail-on-warning'){
			$options['fail_on_warning']=true;
			continue;
		}
		if($argument==='--fail-on-info'){
			$options['fail_on_info']=true;
			continue;
		}
		if($argument==='--fail-on-diff'){
			$options['fail_on_diff']=true;
			continue;
		}
		if($argument==='--quiet'){
			$options['quiet']=true;
			continue;
		}
		throw new InvalidArgumentException('Unknown option: '.$argument);
	}
	if(isset($options['help'])){
		return $options;
	}
	if(($options['manifest'] ?? null)===null && ($options['roles'] ?? null)===null){
		throw new InvalidArgumentException('Provide --manifest=<path> or --roles=<path>.');
	}
	if(($options['against'] ?? null)!==null && ($options['manifest'] ?? null)===null){
		throw new InvalidArgumentException('--against requires --manifest so manifests can be diffed.');
	}
	return $options;
}

/**
 * Prints the permission audit CLI usage line.
 *
 * @return void
 */
function dp_permission_check_usage(): void {
	echo "Usage: php runtime/modules/permission/kernel/permission_check.php (--manifest=<path>|--roles=<path>) [--known=<path>] [--assignments=<path>] [--against=<old-manifest>] [--json=<path>] [--fail-on-warning] [--fail-on-info] [--fail-on-diff] [--quiet]\n";
}

/**
 * Loads the permission framework classes required by the standalone runner.
 *
 * The checker runs outside normal Dataphyre boot, so it includes the framework
 * files directly instead of relying on the application autoloader.
 *
 * @return void
 */
function dp_permission_check_bootstrap(): void {
	$framework=dirname(__DIR__).'/Framework';
	foreach([
		'PermissionRule.php',
		'PermissionRepository.php',
		'PermissionSet.php',
		'SubjectResolver.php',
		'PermissionTrace.php',
		'PermissionEngine.php',
		'PermissionSubject.php',
		'PermissionCondition.php',
		'PermissionTest.php',
		'PermissionSimulator.php',
		'PermissionSnapshot.php',
		'PermissionOptimizer.php',
		'Exceptions/AuthorizationException.php',
		'Permission.php',
		'PermissionAudit.php',
		'PermissionManifest.php',
	] as $file){
		require_once $framework.'/'.$file;
	}
}

/**
 * Builds the complete permission audit report from parsed CLI options.
 *
 * Input may be a manifest or separate files. Roles, known permissions, and
 * assignments are normalized before `PermissionAudit::roles()` runs. When an
 * `against` manifest is provided, the report also includes manifest diff data
 * and a boolean `changed` summary.
 *
 * @param array{manifest?: ?string, roles?: ?string, known?: ?string, assignments?: ?string, against?: ?string} $options Parsed options.
 * @return array{runner: string, generated_at: string, sources: array<string, mixed>, audit: array<string, mixed>, diff: ?array<string, mixed>, changed: bool} Permission audit report.
 */
function dp_permission_check_report(array $options): array {
	$manifest=null;
	$roles=[];
	$known=[];
	$assignments=[];
	if(($options['manifest'] ?? null)!==null){
		$manifest=dp_permission_check_read_json((string)$options['manifest']);
		$roles=dp_permission_check_roles($manifest);
		$known=dp_permission_check_known($manifest);
		$assignments=dp_permission_check_assignments($manifest);
	}
	if(($options['roles'] ?? null)!==null){
		$roles=dp_permission_check_roles(dp_permission_check_read_json((string)$options['roles']));
	}
	if(($options['known'] ?? null)!==null){
		$known=dp_permission_check_known(dp_permission_check_read_json((string)$options['known']));
	}
	if(($options['assignments'] ?? null)!==null){
		$assignments=dp_permission_check_assignments(dp_permission_check_read_json((string)$options['assignments']));
	}
	$audit=PermissionAudit::roles($roles, $known, $assignments);
	$diff=null;
	if(($options['against'] ?? null)!==null && is_array($manifest)){
		$diff=PermissionManifest::diff(dp_permission_check_read_json((string)$options['against']), $manifest);
	}
	return [
		'runner'=>'permission_check.php',
		'generated_at'=>date('c'),
		'sources'=>[
			'manifest'=>$options['manifest'],
			'roles'=>$options['roles'],
			'known'=>$options['known'],
			'assignments'=>$options['assignments'],
			'against'=>$options['against'],
		],
		'audit'=>$audit,
		'diff'=>$diff,
		'changed'=>is_array($diff) ? dp_permission_check_diff_changed($diff) : false,
	];
}

/**
 * Reads and decodes a JSON input file for the permission checker.
 *
 * Relative paths are resolved against the current working directory. Missing,
 * unreadable, or invalid JSON files raise runtime exceptions so CI jobs fail as
 * infrastructure errors.
 *
 * @param string $path CLI-supplied JSON path.
 * @return array<string, mixed> Decoded JSON object or array.
 *
 * @throws RuntimeException When the file is missing, unreadable, or invalid JSON.
 */
function dp_permission_check_read_json(string $path): array {
	$resolved=dp_permission_check_resolve_path($path);
	if($resolved==='' || !is_file($resolved)){
		throw new RuntimeException('JSON file not found: '.$path);
	}
	$content=file_get_contents($resolved);
	if(!is_string($content)){
		throw new RuntimeException('Unable to read '.$path.'.');
	}
	$decoded=json_decode($content, true);
	if(!is_array($decoded) || json_last_error()!==JSON_ERROR_NONE){
		throw new RuntimeException('Invalid JSON in '.$path.': '.json_last_error_msg());
	}
	return $decoded;
}

/**
 * Normalizes role definitions from a manifest or standalone roles data.
 *
 * Accepted shapes include `roles`, `presets`, or a direct role map. Each role
 * name and permission is normalized through `PermissionRule`, empty roles are
 * skipped, and permissions/roles are sorted for deterministic reports.
 *
 * @param array<string|int, mixed> $source Manifest, preset list, or role map.
 * @return array<string, array<int, string>> Normalized permissions keyed by role.
 */
function dp_permission_check_roles(array $source): array {
	$role_source=$source;
	if(is_array($source['roles'] ?? null)){
		$role_source=$source['roles'];
	}
	elseif(is_array($source['presets'] ?? null)){
		$role_source=$source['presets'];
	}
	$roles=[];
	foreach($role_source as $role=>$definition){
		if(!is_string($role) && is_array($definition) && isset($definition['name'])){
			$role=(string)$definition['name'];
		}
		$role=PermissionRule::normalize((string)$role);
		if($role===''){
			continue;
		}
		if(is_array($definition) && array_key_exists('permissions', $definition)){
			$roles[$role]=PermissionRule::many($definition['permissions']);
		}
		else{
			$roles[$role]=PermissionRule::many($definition);
		}
		sort($roles[$role], SORT_NATURAL);
	}
	ksort($roles, SORT_NATURAL);
	return $roles;
}

/**
 * Normalizes the known permission catalog from several supported input shapes.
 *
 * Catalog entries may be strings, keyed rows, or arrays containing `permission`
 * or `name`. Values are normalized, deduplicated, and sorted for audit use.
 *
 * @param array<string|int, mixed> $source Manifest, catalog, known list, or permissions list.
 * @return array<int, string> Normalized known permission names.
 */
function dp_permission_check_known(array $source): array {
	if(is_array($source['catalog'] ?? null)){
		$source=$source['catalog'];
	}
	elseif(is_array($source['known'] ?? null)){
		$source=$source['known'];
	}
	elseif(is_array($source['permissions'] ?? null)){
		$source=$source['permissions'];
	}
	$permissions=[];
	foreach($source as $key=>$row){
		$value=null;
		if(is_array($row)){
			$value=$row['permission'] ?? $row['name'] ?? null;
		}
		elseif(is_string($row)){
			$value=$row;
		}
		elseif(is_string($key)){
			$value=$key;
		}
		$permission=PermissionRule::normalize((string)$value);
		if($permission!==''){
			$permissions[]=$permission;
		}
	}
	$permissions=array_values(array_unique($permissions));
	sort($permissions, SORT_NATURAL);
	return $permissions;
}

/**
 * Extracts subject-role assignment rows from a manifest or standalone assignment list.
 *
 * Rows must be arrays and contain either `kind` or `value`; other input items
 * are ignored so catalog or role documents can be passed safely.
 *
 * @param array<string|int, mixed> $source Manifest or assignment list.
 * @return array<int, array<string, mixed>> Assignment rows accepted by the audit engine.
 */
function dp_permission_check_assignments(array $source): array {
	if(is_array($source['assignments'] ?? null)){
		$source=$source['assignments'];
	}
	$assignments=[];
	foreach($source as $row){
		if(!is_array($row)){
			continue;
		}
		if(!array_key_exists('kind', $row) && !array_key_exists('value', $row)){
			continue;
		}
		$assignments[]=$row;
	}
	return $assignments;
}

/**
 * Detects whether a manifest diff contains role or catalog changes.
 *
 * Added, removed, and changed roles are considered; catalog additions/removals
 * are considered through the same section/key scan.
 *
 * @param array<string, mixed> $diff Manifest diff data.
 * @return bool True when any tracked diff section contains changes.
 */
function dp_permission_check_diff_changed(array $diff): bool {
	foreach(['roles', 'catalog'] as $section){
		foreach(['added', 'removed', 'changed'] as $key){
			if(($diff[$section][$key] ?? [])!==[]){
				return true;
			}
		}
	}
	return false;
}

/**
 * Prints a human-readable permission audit summary and findings.
 *
 * Quiet mode suppresses all output. Otherwise the summary includes role,
 * catalog, assignment, severity counts, individual findings, and optional
 * manifest diff totals.
 *
 * @param array<string, mixed> $report Permission audit report.
 * @param array{quiet?: bool} $options Parsed options.
 * @return void
 */
function dp_permission_check_print(array $report, array $options): void {
	if(!empty($options['quiet'])){
		return;
	}
	$audit=$report['audit'] ?? [];
	$counts=is_array($audit['counts'] ?? null) ? $audit['counts'] : [];
	echo 'Permission audit: '
		.(int)($audit['role_count'] ?? 0).' roles, '
		.(int)($audit['catalog_count'] ?? 0).' known permissions, '
		.(int)($audit['assignment_count'] ?? 0).' assignments; '
		.(int)($counts['error'] ?? 0).' errors, '
		.(int)($counts['warning'] ?? 0).' warnings, '
		.(int)($counts['info'] ?? 0).' info.'.PHP_EOL;
	foreach(($audit['findings'] ?? []) as $finding){
		if(!is_array($finding)){
			continue;
		}
		echo '['.strtoupper((string)($finding['severity'] ?? 'info')).'] '
			.(string)($finding['type'] ?? 'finding').': '
			.(string)($finding['message'] ?? '').PHP_EOL;
	}
	if(is_array($report['diff'] ?? null)){
		$diff=$report['diff'];
		$role_changes=count($diff['roles']['added'] ?? [])+count($diff['roles']['removed'] ?? [])+count($diff['roles']['changed'] ?? []);
		$catalog_changes=count($diff['catalog']['added'] ?? [])+count($diff['catalog']['removed'] ?? []);
		echo 'Manifest diff: '.$role_changes.' role changes, '.$catalog_changes.' catalog changes.'.PHP_EOL;
	}
}

/**
 * Writes the permission audit report as pretty JSON.
 *
 * Parent directories are created on demand. Encoding and write failures raise
 * runtime exceptions so automation can distinguish report failures from audit
 * findings.
 *
 * @param string $path CLI-supplied output path.
 * @param array<string, mixed> $report Permission audit report.
 * @return void
 *
 * @throws RuntimeException When the destination is blank, cannot be created, cannot be encoded, or cannot be written.
 */
function dp_permission_check_write_json(string $path, array $report): void {
	$resolved=dp_permission_check_resolve_path($path);
	if($resolved===''){
		throw new RuntimeException('JSON path is empty.');
	}
	$directory=dirname($resolved);
	if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
		throw new RuntimeException('Unable to create directory '.$directory.'.');
	}
	$json=json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if(!is_string($json)){
		throw new RuntimeException('Unable to encode report JSON.');
	}
	if(file_put_contents($resolved, $json.PHP_EOL)===false){
		throw new RuntimeException('Unable to write '.$resolved.'.');
	}
}

/**
 * Computes the permission checker process exit code.
 *
 * Audit errors always fail. Warnings, info findings, and manifest diffs only
 * fail when their corresponding strict flags are enabled.
 *
 * @param array<string, mixed> $report Permission audit report.
 * @param array{fail_on_warning?: bool, fail_on_info?: bool, fail_on_diff?: bool} $options Parsed options.
 * @return int Zero for success, one for policy failure.
 */
function dp_permission_check_exit_code(array $report, array $options): int {
	$counts=is_array($report['audit']['counts'] ?? null) ? $report['audit']['counts'] : [];
	if(($counts['error'] ?? 0)>0){
		return 1;
	}
	if(!empty($options['fail_on_warning']) && ($counts['warning'] ?? 0)>0){
		return 1;
	}
	if(!empty($options['fail_on_info']) && ($counts['info'] ?? 0)>0){
		return 1;
	}
	if(!empty($options['fail_on_diff']) && ($report['changed'] ?? false)===true){
		return 1;
	}
	return 0;
}

/**
 * Resolves a CLI path against the current working directory.
 *
 * Absolute Unix paths and Windows drive paths pass through unchanged. Blank
 * values remain blank so callers can produce targeted validation errors.
 *
 * @param string $path CLI-supplied path.
 * @return string Absolute or unchanged path suitable for file operations.
 */
function dp_permission_check_resolve_path(string $path): string {
	$path=trim($path);
	if($path===''){
		return '';
	}
	$normalized=str_replace('\\', '/', $path);
	if(preg_match('/^[A-Za-z]:\//', $normalized)===1 || str_starts_with($normalized, '/')){
		return $path;
	}
	$cwd=getcwd();
	return (is_string($cwd) && $cwd!=='') ? $cwd.'/'.$path : $path;
}
