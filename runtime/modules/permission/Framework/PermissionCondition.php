<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Registry and evaluator for named permission conditions.
 *
 * Conditions are normalized by the shared permission-rule naming rules and
 * stored as callables. Evaluation is fail-closed: missing callbacks, undefined
 * names, or callbacks that do not return a truthy boolean success value deny
 * the condition chain. Configured conditions are loaded lazily from
 * `DP_PERMISSION_CFG['conditions']` and can be reset with `flush()` for tests
 * or runtime reloads.
 */
final class PermissionCondition {

	/** @var array<string, callable> Condition callbacks keyed by normalized condition name. */
	private static array $conditions=[];
	
	/** @var bool Whether the configured condition map has been loaded in this process. */
	private static bool $configured=false;

	/**
	 * Registers or replaces a named permission condition callback.
	 *
	 * Blank names are ignored after normalization. The callback receives the
	 * evaluated subject, caller context, permission string, and normalized
	 * condition name.
	 *
	 * @param string $name Condition name or alias to normalize.
	 * @param callable $condition Callback shaped as `(mixed $subject, array $context, string $permission, string $condition): bool`.
	 * @return void Registration mutates the in-process condition registry.
	 */
	public static function define(string $name, callable $condition): void {
		$name=PermissionRule::normalize($name);
		if($name!==''){
			self::$conditions[$name]=$condition;
		}
	}

	/**
	 * Checks whether a named condition exists after lazy configuration loading.
	 *
	 * @param string $name Condition name or alias to normalize.
	 * @return bool `true` when a callback is registered for the normalized condition name.
	 */
	public static function has(string $name): bool {
		self::loadConfigured();
		return isset(self::$conditions[PermissionRule::normalize($name)]);
	}

	/**
	 * Lists registered condition names in deterministic natural order.
	 *
	 * @return array<int, string> Normalized condition names currently available for policy checks.
	 */
	public static function names(): array {
		self::loadConfigured();
		$names=array_keys(self::$conditions);
		sort($names, SORT_NATURAL);
		return $names;
	}

	/**
	 * Evaluates one or more named conditions as an AND gate.
	 *
	 * Every normalized condition must exist and return true. Evaluation stops on
	 * the first missing or failed condition, so the method is suitable for guard
	 * checks where unknown policy hooks must deny access.
	 *
	 * @param array<int|string, mixed>|string $conditions Condition name, delimited condition expression, or array accepted by `PermissionRule::many()`.
	 * @param mixed $subject Principal, resource, or domain object being evaluated.
	 * @param array<string, mixed> $context Request or policy context supplied to condition callbacks.
	 * @param string $permission Permission identifier being checked.
	 * @return bool `true` only when every named condition exists and passes.
	 */
	public static function passes(array|string $conditions, mixed $subject=null, array $context=[], string $permission=''): bool {
		self::loadConfigured();
		foreach(self::normalizeMany($conditions) as $condition){
			$callback=self::$conditions[$condition] ?? null;
			if(!is_callable($callback)){
				return false;
			}
			if((bool)$callback($subject, $context, $permission, $condition)!==true){
				return false;
			}
		}
		return true;
	}

	/**
	 * Evaluates conditions and returns per-condition decision details.
	 *
	 * Unlike `passes()`, this method evaluates every normalized condition so
	 * diagnostics can show all missing and failed callbacks. The aggregate
	 * `allowed` flag remains fail-closed and is false when any condition is
	 * missing or fails.
	 *
	 * @param array<int|string, mixed>|string $conditions Condition name, delimited condition expression, or array accepted by `PermissionRule::many()`.
	 * @param mixed $subject Principal, resource, or domain object being evaluated.
	 * @param array<string, mixed> $context Request or policy context supplied to condition callbacks.
	 * @param string $permission Permission identifier being checked.
	 * @return array{allowed:bool, checks:array<int, array{condition:string, exists:bool, passed:bool}>} Decision trace for authorization diagnostics.
	 */
	public static function explain(array|string $conditions, mixed $subject=null, array $context=[], string $permission=''): array {
		self::loadConfigured();
		$checks=[];
		$allowed=true;
		foreach(self::normalizeMany($conditions) as $condition){
			$callback=self::$conditions[$condition] ?? null;
			$exists=is_callable($callback);
			$passed=$exists ? (bool)$callback($subject, $context, $permission, $condition) : false;
			if(!$passed){
				$allowed=false;
			}
			$checks[]=[
				'condition'=>$condition,
				'exists'=>$exists,
				'passed'=>$passed,
			];
		}
		return [
			'allowed'=>$allowed,
			'checks'=>$checks,
		];
	}

	/**
	 * Normalizes a condition expression into individual condition names.
	 *
	 * @param array<int|string, mixed>|string $conditions Condition input accepted by the permission rule parser.
	 * @return array<int, string> Normalized condition names in evaluation order.
	 */
	public static function normalizeMany(array|string $conditions): array {
		return PermissionRule::many($conditions);
	}

	/**
	 * Clears registered and configured conditions for the current process.
	 *
	 * This is primarily a test and reload hook. The next call to `has()`,
	 * `names()`, `passes()`, or `explain()` reloads callable conditions from
	 * configuration.
	 *
	 * @return void Registry state is reset in memory.
	 */
	public static function flush(): void {
		self::$conditions=[];
		self::$configured=false;
	}

	/**
	 * Loads callable conditions from permission configuration once per process.
	 *
	 * Non-callable configured entries are ignored, keeping the evaluator
	 * fail-closed for names that appear in configuration but cannot be executed.
	 *
	 * @return void Configured callables are merged into the condition registry.
	 */
	private static function loadConfigured(): void {
		if(self::$configured){
			return;
		}
		self::$configured=true;
		$config=defined('\DP_PERMISSION_CFG') && is_array(\DP_PERMISSION_CFG) ? \DP_PERMISSION_CFG : [];
		$conditions=is_array($config['conditions'] ?? null) ? $config['conditions'] : [];
		foreach($conditions as $name=>$condition){
			if(is_callable($condition)){
				self::define((string)$name, $condition);
			}
		}
	}
}
