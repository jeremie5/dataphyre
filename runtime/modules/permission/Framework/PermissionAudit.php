<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;

/**
 * Audits permission catalogs, roles, and assignments.
 *
 * PermissionAudit compares stored roles and assignments against a generated
 * permission catalog, reports broad grants, unknown permission references,
 * empty roles, unknown role assignments, and catalog permissions that no stored
 * role currently grants.
 */
final class PermissionAudit {

	/**
	 * Runs a permission audit against the configured repository.
	 *
	 * When a Panel source is provided, its catalog becomes the known permission
	 * set. Repository role definitions and assignments are loaded directly so the
	 * audit reflects the active persistence layer.
	 *
	 * @param PanelInstance|PanelManager|null $panel Optional Panel source for catalog generation.
	 * @param array<string, mixed> $options Audit behavior and severity options.
	 * @return array{ok: bool, counts: array<string, int>, findings: array<int, array<string, mixed>>, catalog_count: int, role_count: int, assignment_count: int}
	 */
	public static function run(PanelInstance|PanelManager|null $panel=null, array $options=[]): array {
		$options=array_replace([
			'warn_broad_grants'=>true,
			'warn_unknown_permissions'=>true,
			'warn_empty_roles'=>true,
			'warn_uncovered_catalog'=>true,
			'severity_for_broad_grants'=>'warning',
			'severity_for_unknown_permissions'=>'warning',
		], $options);
		$catalog=$panel!==null ? PermissionCatalog::panel($panel, $options) : [];
		$known=array_fill_keys(array_column($catalog, 'permission'), true);
		$roles=Permission::repository()->roleDefinitions();
		$assignments=Permission::repository()->assignments();
		$findings=[];
		foreach($roles as $role=>$rules){
			$rules=PermissionRule::many($rules);
			if($rules===[] && ($options['warn_empty_roles'] ?? true)===true){
				$findings[]=self::finding('empty_role', 'warning', "Role '{$role}' has no permission rules.", ['role'=>$role]);
			}
			$findings=array_merge($findings, self::ruleFindings($rules, $known, ['role'=>$role], $options));
		}
		foreach($assignments as $assignment){
			if(!is_array($assignment)){
				continue;
			}
			$value=PermissionRule::normalize((string)($assignment['value'] ?? ''));
			if($value===''){
				$findings[]=self::finding('empty_assignment_value', 'warning', 'An assignment has an empty permission or role value.', ['assignment'=>$assignment]);
				continue;
			}
			if(($assignment['kind'] ?? '')==='permission'){
				$rule=($assignment['negative'] ?? false) ? '-'.$value : $value;
				$findings=array_merge($findings, self::ruleFindings([$rule], $known, ['assignment'=>$assignment], $options));
			}
			elseif(($assignment['kind'] ?? '')==='role' && !isset($roles[$value])){
				$findings[]=self::finding('unknown_role_assignment', 'warning', "Assignment references unknown role '{$value}'.", ['assignment'=>$assignment]);
			}
		}
		if($catalog!==[] && ($options['warn_uncovered_catalog'] ?? true)===true){
			$covered=self::coveredPermissions($roles, array_keys($known));
			foreach(array_keys($known) as $permission){
				if(!isset($covered[$permission])){
					$findings[]=self::finding('uncovered_permission', 'info', "No stored role grants '{$permission}'.", ['permission'=>$permission]);
				}
			}
		}
		return [
			'ok'=>!self::hasSeverity($findings, ['error', 'warning']),
			'counts'=>self::counts($findings),
			'findings'=>$findings,
			'catalog_count'=>count($catalog),
			'role_count'=>count($roles),
			'assignment_count'=>count($assignments),
		];
	}

	/**
	 * Audits supplied role and assignment data.
	 *
	 * This variant is useful for tests, manifests, and import previews because it
	 * accepts role definitions, known permissions, and assignments without reading
	 * from the repository.
	 *
	 * @param array<string, mixed> $roles Role-to-rules map.
	 * @param array<int, string> $knownPermissions Permission catalog tokens.
	 * @param array<int, array<string, mixed>> $assignments Assignment rows to inspect.
	 * @param array<string, mixed> $options Audit behavior and severity options.
	 * @return array{ok: bool, counts: array<string, int>, findings: array<int, array<string, mixed>>, catalog_count: int, role_count: int, assignment_count: int}
	 */
	public static function roles(array $roles, array $knownPermissions=[], array $assignments=[], array $options=[]): array {
		$options=array_replace([
			'warn_broad_grants'=>true,
			'warn_unknown_permissions'=>true,
			'warn_empty_roles'=>true,
			'warn_uncovered_catalog'=>true,
			'severity_for_broad_grants'=>'warning',
			'severity_for_unknown_permissions'=>'warning',
		], $options);
		$known=array_fill_keys(array_values(array_unique(array_map(
			static fn(mixed $permission): string => PermissionRule::unwrap((string)$permission)['permission'] ?? '',
			$knownPermissions
		))), true);
		unset($known['']);
		$findings=[];
		foreach($roles as $role=>$rules){
			$rules=PermissionRule::many($rules);
			if($rules===[] && ($options['warn_empty_roles'] ?? true)===true){
				$findings[]=self::finding('empty_role', 'warning', "Role '{$role}' has no permission rules.", ['role'=>$role]);
			}
			$findings=array_merge($findings, self::ruleFindings($rules, $known, ['role'=>(string)$role], $options));
		}
		foreach($assignments as $assignment){
			if(!is_array($assignment)){
				continue;
			}
			$value=PermissionRule::normalize((string)($assignment['value'] ?? ''));
			if(($assignment['kind'] ?? '')==='role' && !array_key_exists($value, $roles)){
				$findings[]=self::finding('unknown_role_assignment', 'warning', "Assignment references unknown role '{$value}'.", ['assignment'=>$assignment]);
			}
		}
		if($known!==[] && ($options['warn_uncovered_catalog'] ?? true)===true){
			$covered=self::coveredPermissions($roles, array_keys($known));
			foreach(array_keys($known) as $permission){
				if(!isset($covered[$permission])){
					$findings[]=self::finding('uncovered_permission', 'info', "No stored role grants '{$permission}'.", ['permission'=>$permission]);
				}
			}
		}
		return [
			'ok'=>!self::hasSeverity($findings, ['error', 'warning']),
			'counts'=>self::counts($findings),
			'findings'=>$findings,
			'catalog_count'=>count($known),
			'role_count'=>count($roles),
			'assignment_count'=>count($assignments),
		];
	}

	/**
	 * Renders an HTML permission audit summary.
	 *
	 * The output is intended for Panel diagnostics and escapes all finding text
	 * before rendering.
	 *
	 * @param PanelInstance|PanelManager|null $panel Optional Panel source for catalog generation.
	 * @param array<string, mixed> $options Audit behavior and severity options.
	 * @return string HTML section containing counts and findings.
	 */
	public static function html(PanelInstance|PanelManager|null $panel=null, array $options=[]): string {
		$audit=self::run($panel, $options);
		$html='<div class="dp-panel-section"><h2>Permission Audit</h2>';
		$html.='<p class="dp-panel-muted">'.self::e((string)$audit['catalog_count']).' catalog permissions, '.self::e((string)$audit['role_count']).' stored roles, '.self::e((string)$audit['assignment_count']).' assignments.</p>';
		if(($audit['findings'] ?? [])===[]){
			return $html.'<div class="dp-panel-notice dp-panel-notice-success"><span>No permission audit findings.</span></div></div>';
		}
		$html.='<table class="dp-panel-table"><thead><tr><th>Severity</th><th>Type</th><th>Finding</th></tr></thead><tbody>';
		foreach($audit['findings'] as $finding){
			$html.='<tr><td>'.self::e((string)($finding['severity'] ?? '')).'</td><td><code>'.self::e((string)($finding['type'] ?? '')).'</code></td><td>'.self::e((string)($finding['message'] ?? '')).'</td></tr>';
		}
		return $html.'</tbody></table></div>';
	}

	/**
	 * Builds findings for one normalized rule set.
	 *
	 * @param array<int, string> $rules Normalized permission rules.
	 * @param array<string, bool> $known Known permission catalog map.
	 * @param array<string, mixed> $context Context merged into each finding.
	 * @param array<string, mixed> $options Audit behavior and severity options.
	 * @return array<int, array<string, mixed>> Rule findings.
	 */
	private static function ruleFindings(array $rules, array $known, array $context, array $options): array {
		$findings=[];
		$seenPositive=[];
		$seenNegative=[];
		foreach($rules as $rule){
			$parsed=PermissionRule::unwrap($rule);
			$permission=(string)($parsed['permission'] ?? '');
			if($permission===''){
				continue;
			}
			if(($parsed['negative'] ?? false)===true){
				$seenNegative[$permission]=true;
			}
			else{
				$seenPositive[$permission]=true;
			}
			if(isset($seenPositive[$permission], $seenNegative[$permission])){
				$findings[]=self::finding('conflicting_rule', 'warning', "Permission '{$permission}' is both granted and denied.", $context+['permission'=>$permission]);
			}
			if(($options['warn_broad_grants'] ?? true)===true && self::isBroad($permission) && ($parsed['negative'] ?? false)!==true){
				$findings[]=self::finding('broad_grant', (string)$options['severity_for_broad_grants'], "Broad grant '{$permission}' should be intentional.", $context+['permission'=>$permission]);
			}
			if(($options['warn_unknown_permissions'] ?? true)===true && $known!==[] && !self::known($permission, $known)){
				$findings[]=self::finding('unknown_permission', (string)$options['severity_for_unknown_permissions'], "Permission '{$permission}' is not in the generated catalog.", $context+['permission'=>$permission]);
			}
		}
		return $findings;
	}

	/**
	 * Calculates which catalog permissions are granted by stored roles.
	 *
	 * @param array<string, mixed> $roles Role-to-rules map.
	 * @param array<int, string> $permissions Catalog permission tokens.
	 * @return array<string, bool> Covered permission map.
	 */
	private static function coveredPermissions(array $roles, array $permissions): array {
		$covered=[];
		foreach($roles as $rules){
			$set=Permission::set($rules);
			foreach($permissions as $permission){
				if($set->allows($permission)){
					$covered[$permission]=true;
				}
			}
		}
		return $covered;
	}

	/**
	 * Reports whether a rule is known by the generated catalog.
	 *
	 * Wildcard and child-existence suffixes are considered known when at least one
	 * catalog permission exists below the same prefix.
	 *
	 * @param string $permission Permission token to check.
	 * @param array<string, bool> $known Known permission map.
	 * @return bool True when the token is known or intentionally broad.
	 */
	private static function known(string $permission, array $known): bool {
		if(isset($known[$permission])){
			return true;
		}
		if($permission==='*'){
			return true;
		}
		if(str_ends_with($permission, '.*') || str_ends_with($permission, '.%')){
			$base=substr($permission, 0, -2);
			foreach($known as $candidate=>$_){
				if(str_starts_with((string)$candidate, $base.'.')){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Reports whether a permission token is broad enough to warrant review.
	 *
	 * @param string $permission Permission token.
	 * @return bool True for global, panel-wide, or shallow wildcard grants.
	 */
	private static function isBroad(string $permission): bool {
		return $permission==='*'
			|| $permission==='panel.*'
			|| substr_count($permission, '.')<=1 && str_ends_with($permission, '.*');
	}

	/**
	 * Creates a normalized audit finding.
	 *
	 * @param string $type Stable finding type.
	 * @param string $severity Requested severity.
	 * @param string $message Human-readable finding text.
	 * @param array<string, mixed> $context Additional finding context.
	 * @return array{type: string, severity: string, message: string, context: array<string, mixed>}
	 */
	private static function finding(string $type, string $severity, string $message, array $context=[]): array {
		return [
			'type'=>$type,
			'severity'=>in_array($severity, ['info', 'warning', 'error'], true) ? $severity : 'warning',
			'message'=>$message,
			'context'=>$context,
		];
	}

	/**
	 * Counts findings by severity.
	 *
	 * @param array<int, array<string, mixed>> $findings Audit findings.
	 * @return array{error: int, warning: int, info: int}
	 */
	private static function counts(array $findings): array {
		$counts=['error'=>0, 'warning'=>0, 'info'=>0];
		foreach($findings as $finding){
			$severity=(string)($finding['severity'] ?? 'info');
			$counts[$severity]=($counts[$severity] ?? 0)+1;
		}
		return $counts;
	}

	/**
	 * Reports whether any finding has one of the requested severities.
	 *
	 * @param array<int, array<string, mixed>> $findings Audit findings.
	 * @param array<int, string> $severities Severities to match.
	 * @return bool True when a matching severity exists.
	 */
	private static function hasSeverity(array $findings, array $severities): bool {
		foreach($findings as $finding){
			if(in_array((string)($finding['severity'] ?? ''), $severities, true)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Escapes text for Panel audit HTML.
	 *
	 * @param string $value Raw text.
	 * @return string HTML-escaped text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
