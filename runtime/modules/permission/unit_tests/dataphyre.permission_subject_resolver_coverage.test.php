<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Permission\SubjectResolver;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

final class DpPermissionResolverSubject {
	public static array $value=[
		'id'=>'resolved-41',
		'custom_id'=>'resolved-41',
		'grants'=>['resolver.view'],
		'groups'=>['resolver-role'],
	];
}
if(!defined('DP_PERMISSION_CFG')){
	define('DP_PERMISSION_CFG',[
		'roles'=>[],
		'default_roles'=>[],
		'conditions'=>[
			'configured'=>static fn(): bool=>true,
			'ignored'=>'not-callable',
		],
		'subject'=>[
			'id_resolver'=>static fn(mixed $subject): mixed=>is_array($subject) ? ($subject['custom_id'] ?? null) : null,
			'user_resolver'=>static fn(): mixed=>DpPermissionResolverSubject::$value,
			'permission_resolver'=>static fn(mixed $subject,array $context): mixed=>$context['permissions'] ?? ($subject['grants'] ?? []),
			'role_resolver'=>static fn(mixed $subject,array $context): mixed=>$context['roles'] ?? ($subject['groups'] ?? []),
		],
	]);
}
framework(['permission']);

test('permission subject resolvers use configured identity user permission and role callbacks',static function(Context $t): void {
	$explicit=['custom_id'=>'explicit-1','grants'=>['explicit.view'],'groups'=>['explicit-role']];
	$t->same('explicit-1',SubjectResolver::id($explicit));
	$t->same('resolved-41',SubjectResolver::id(null));
	$t->same(DpPermissionResolverSubject::$value,SubjectResolver::subject());
	$t->same($explicit,SubjectResolver::subject($explicit));
	$t->same(['explicit.view'],SubjectResolver::permissions($explicit));
	$t->same(['override.view'],SubjectResolver::permissions($explicit,['permissions'=>'override.view']));
	$t->same(['explicit-role'],SubjectResolver::roles($explicit));
	$t->same(['override-role'],SubjectResolver::roles($explicit,['roles'=>'override-role']));
	$t->contains('configured',\Dataphyre\Permission\PermissionCondition::names());
})->tag('permission','coverage')->group('framework-coverage');
