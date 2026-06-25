<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Provides assertion helpers for exercising permission rules in tests and diagnostics.
 *
 * The helper normalizes permission aliases through `PermissionRule`, evaluates decisions through
 * the real `Permission::check()` pipeline, and can produce matrix reports with optional
 * explanations so authorization regressions show both the failed rule and the subject/context
 * that produced it.
 */
final class PermissionTest {

	/**
	 * Asserts that a subject is allowed to perform every supplied permission.
	 *
	 * @param mixed $subject Subject passed to permission policies and guards.
	 * @param mixed $permissions Permission string, rule object, iterable, or nested permission input accepted by `PermissionRule::many()`.
	 * @param array<string, mixed> $context Additional policy context.
	 * @return bool `true` when all permissions are allowed.
	 *
	 * @throws \RuntimeException When one or more permissions are denied.
	 */
	public static function assertAllows(mixed $subject, mixed $permissions, array $context=[]): bool {
		$failed=[];
		foreach(PermissionRule::many($permissions) as $permission){
			if(!Permission::check($permission, $subject, $context)){
				$failed[]=$permission;
			}
		}
		if($failed!==[]){
			throw new \RuntimeException('Expected permission allow failed: '.implode(', ', $failed));
		}
		return true;
	}

	/**
	 * Asserts that a subject is denied every supplied permission.
	 *
	 * @param mixed $subject Subject passed to permission policies and guards.
	 * @param mixed $permissions Permission string, rule object, iterable, or nested permission input accepted by `PermissionRule::many()`.
	 * @param array<string, mixed> $context Additional policy context.
	 * @return bool `true` when all permissions are denied.
	 *
	 * @throws \RuntimeException When one or more permissions are allowed.
	 */
	public static function assertDenies(mixed $subject, mixed $permissions, array $context=[]): bool {
		$failed=[];
		foreach(PermissionRule::many($permissions) as $permission){
			if(Permission::check($permission, $subject, $context)){
				$failed[]=$permission;
			}
		}
		if($failed!==[]){
			throw new \RuntimeException('Expected permission deny failed: '.implode(', ', $failed));
		}
		return true;
	}

	/**
	 * Evaluates a table of subject/permission expectations.
	 *
	 * Subjects can be supplied separately or embedded in expectation rows. Expectations accept
	 * grouped `allow`, `can`, `deny`, and `cannot` lists plus direct `permission => bool` entries.
	 * The returned report is stable for tests and examples.
	 *
	 * @param array<string, mixed> $subjects Subject fixtures keyed by matrix subject name.
	 * @param array<string, mixed> $expectations Expected decisions keyed by subject name or inline case.
	 * @param array{include_explain?:bool, default_context?:array<string, mixed>} $options Matrix options.
	 * @return array{ok:bool, total:int, passed:int, failed:int, failures:array<int, array<string, mixed>>} Matrix result.
	 */
	public static function matrix(array $subjects, array $expectations, array $options=[]): array {
		$options=array_replace([
			'include_explain'=>true,
			'default_context'=>[],
		], $options);
		$failures=[];
		$passed=0;
		$total=0;
		foreach(self::cases($subjects, $expectations) as $case){
			$subjectName=(string)$case['subject_name'];
			$subject=$case['subject'];
			$context=is_array($case['context'] ?? null) ? array_replace($options['default_context'], $case['context']) : $options['default_context'];
			foreach(self::expectationMap($case['expectations']) as $permission=>$expected){
				$total++;
				$actual=Permission::check($permission, $subject, $context);
				if($actual===$expected){
					$passed++;
					continue;
				}
				$failure=[
					'subject'=>$subjectName,
					'permission'=>$permission,
					'expected'=>$expected,
					'actual'=>$actual,
				];
				if(($options['include_explain'] ?? true)===true){
					$failure['explain']=Permission::explain($permission, $subject, $context);
				}
				$failures[]=$failure;
			}
		}
		return [
			'ok'=>$failures===[],
			'total'=>$total,
			'passed'=>$passed,
			'failed'=>count($failures),
			'failures'=>$failures,
		];
	}

	/**
	 * Asserts that a permission matrix passes without mismatches.
	 *
	 * @param array<string, mixed> $subjects Subject fixtures keyed by matrix subject name.
	 * @param array<string, mixed> $expectations Expected decisions keyed by subject name or inline case.
	 * @param array{include_explain?:bool, default_context?:array<string, mixed>} $options Matrix options.
	 * @return array{ok:bool, total:int, passed:int, failed:int, failures:array<int, array<string, mixed>>} Passing matrix report.
	 *
	 * @throws \RuntimeException When any matrix expectation fails.
	 */
	public static function assertMatrix(array $subjects, array $expectations, array $options=[]): array {
		$report=self::matrix($subjects, $expectations, $options);
		if(($report['ok'] ?? false)!==true){
			throw new \RuntimeException(self::failureSummary($report));
		}
		return $report;
	}

	/**
	 * Expands the supported matrix input shapes into canonical test cases.
	 *
	 * @param array<string, mixed> $subjects Subject fixtures keyed by name.
	 * @param array<string, mixed> $expectations Expected decisions keyed by name or inline case.
	 * @return array<int, array{subject_name:string, subject:mixed, context:array<string, mixed>, expectations:mixed}> Canonical cases.
	 */
	private static function cases(array $subjects, array $expectations): array {
		$cases=[];
		foreach($expectations as $subjectName=>$rules){
			if(is_array($rules) && array_key_exists('subject', $rules)){
				$name=(string)($rules['name'] ?? $subjectName);
				$cases[]=[
					'subject_name'=>$name,
					'subject'=>$rules['subject'],
					'context'=>is_array($rules['context'] ?? null) ? $rules['context'] : [],
					'expectations'=>$rules,
				];
				continue;
			}
			$name=(string)$subjectName;
			$cases[]=[
				'subject_name'=>$name,
				'subject'=>$subjects[$name] ?? null,
				'context'=>[],
				'expectations'=>$rules,
			];
		}
		if($cases===[] && $subjects!==[]){
			foreach($subjects as $name=>$definition){
				if(!is_array($definition) || !array_key_exists('expect', $definition)){
					continue;
				}
				$cases[]=[
					'subject_name'=>(string)$name,
					'subject'=>$definition['subject'] ?? $definition,
					'context'=>is_array($definition['context'] ?? null) ? $definition['context'] : [],
					'expectations'=>$definition['expect'],
				];
			}
		}
		return $cases;
	}

	/**
	 * Converts grouped and direct expectation syntax into normalized permission decisions.
	 *
	 * @param mixed $expectations Matrix expectation row.
	 * @return array<string, bool> Permission decision map keyed by normalized permission name.
	 */
	private static function expectationMap(mixed $expectations): array {
		if(!is_array($expectations)){
			return [];
		}
		$map=[];
		foreach(['allow'=>true, 'allows'=>true, 'can'=>true, 'deny'=>false, 'denies'=>false, 'cannot'=>false] as $key=>$expected){
			if(!array_key_exists($key, $expectations)){
				continue;
			}
			foreach(PermissionRule::many($expectations[$key]) as $permission){
				$map[$permission]=$expected;
			}
		}
		foreach($expectations as $permission=>$expected){
			if(!is_string($permission) || in_array($permission, ['subject', 'context', 'name', 'allow', 'allows', 'can', 'deny', 'denies', 'cannot'], true)){
				continue;
			}
			if(is_bool($expected)){
				$normalized=PermissionRule::normalize($permission);
				if($normalized!==''){
					$map[$normalized]=$expected;
				}
			}
		}
		return $map;
	}

	/**
	 * Builds a compact exception message from the first matrix failures.
	 *
	 * @param array<string, mixed> $report Matrix report returned by `matrix()`.
	 * @return string Human-readable summary for assertion failures.
	 */
	private static function failureSummary(array $report): string {
		$messages=[];
		foreach(array_slice($report['failures'] ?? [], 0, 5) as $failure){
			if(!is_array($failure)){
				continue;
			}
			$messages[]=(string)($failure['subject'] ?? 'subject').' expected '
				.(((bool)($failure['expected'] ?? false)) ? 'allow ' : 'deny ')
				.(string)($failure['permission'] ?? 'permission')
				.', got '.(((bool)($failure['actual'] ?? false)) ? 'allow' : 'deny');
		}
		$suffix=((int)($report['failed'] ?? 0)>5) ? ' and '.((int)$report['failed']-5).' more' : '';
		return 'Permission matrix failed: '.implode('; ', $messages).$suffix.'.';
	}
}
