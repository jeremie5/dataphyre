<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	use Dataphyre\Permission\Exceptions\AuthorizationException;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module,string $constant,array $defaults=[]): void {
			if(!defined($constant)){ define($constant,$defaults); }
		}
	}
	final class DpPermissionKernelTables {
		/** @var list<array<int,mixed>> */
		public static array $definitions=[];
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(mixed ...$arguments): void { DpPermissionKernelTables::$definitions[]=$arguments; }
	}
	if(!defined('DP_PERMISSION_CFG')){
		define('DP_PERMISSION_CFG',[
			'roles'=>['viewer'=>['dashboard.view','orders.view'],'editor'=>['role.viewer','orders.edit']],
			'aliases'=>['orders.read'=>'orders.view'],
			'default_roles'=>[],
			'super_permissions'=>['*'],
			'conditions'=>[],
			'subject'=>['permission_keys'=>['permissions'],'role_keys'=>['roles']],
			'storage'=>[
				'assignments_table'=>'dataphyre.permission_assignments',
				'roles_table'=>'dataphyre.permission_roles',
				'role_permissions_table'=>'dataphyre.permission_role_permissions',
				'auto_hydrate'=>false,
			],
			'cache'=>['enabled'=>true,'max_subjects'=>16],
			'trace'=>['enabled'=>false,'max_entries'=>32,'include_context'=>true],
		]);
	}
	require_once __DIR__.'/permission_coverage_helpers.php';
}

namespace dataphyre {
	if(!class_exists(core::class,false)){
		final class core {
			/** @var array<string,callable> */
			public static array $dialbacks=[];
			public static function load_framework_modules(array $modules): void {}
			public static function register_dialback(string $name,callable $callback): void { self::$dialbacks[$name]=$callback; }
			public static function dialback(string $name,mixed ...$arguments): mixed { return null; }
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/permission/kernel/permission.main.php';

	test('permission kernel deep coverage forwards the complete authorization compatibility surface',static function(Context $t): void {
		\Dataphyre\Permission\dp_permission_sql_reset($t);
		$subject=['id'=>9,'permissions'=>['orders.view','profile.view'],'roles'=>[]];
		$t->isTrue(\dataphyre\permission::check('orders.view',$subject));
		\dataphyre\permission::trace(true);
		\dataphyre\permission::check('orders.view',$subject,['tenant'=>'one']);
		$t->isTrue(is_array(\dataphyre\permission::traces()));
		$t->isTrue(is_array(\dataphyre\permission::trace_stats()));
		$t->isTrue(is_array(\dataphyre\permission::trace_summary()));
		\dataphyre\permission::flush_trace();
		$t->same([],\dataphyre\permission::traces());
		$t->isTrue(\dataphyre\permission::ensure('orders.view',$subject));

		\dataphyre\permission::define_condition('owner',static fn(mixed $candidate,array $context): bool=>($candidate['id'] ?? null)===($context['owner_id'] ?? null));
		$t->contains('owner',\dataphyre\permission::conditions());
		$t->isTrue(\dataphyre\permission::check_when('orders.view','owner',$subject,['owner_id'=>9]));
		$t->isTrue(\dataphyre\permission::ensure_when('orders.view','owner',$subject,['owner_id'=>9]));
		$t->isTrue(\dataphyre\permission::explain_when('orders.view','owner',$subject,['owner_id'=>9])['allowed']);
		$t->isTrue(\dataphyre\permission::can($subject,'orders.view'));
		$t->isTrue(\dataphyre\permission::any(['missing','orders.view'],$subject));
		$t->isTrue(\dataphyre\permission::decisions(['orders.view'],$subject)['orders.view']['allowed']);
		$t->same(['orders.view'=>true,'missing'=>false],\dataphyre\permission::allows_many(['orders.view','missing'],$subject));
		$t->same(['orders.view'],\dataphyre\permission::filter_allowed(['orders.view','missing'],$subject));
		$t->isTrue(\dataphyre\permission::ensure_any(['missing','orders.view'],$subject));
		$t->isTrue(\dataphyre\permission::explain('orders.view',$subject)['allowed']);

		\dataphyre\permission::define_role('kernel-role',['kernel.view']);
		$t->isTrue(\dataphyre\permission::check('kernel.view',['id'=>10,'roles'=>['kernel-role']]));
		$t->isTrue(\dataphyre\permission::store_role('stored',['stored.view'],['label'=>'Stored']));
		$stored=['id'=>77];
		$t->isTrue(\dataphyre\permission::assign_permission($stored,'stored.view',['scope'=>'tenant']));
		$t->isTrue(\dataphyre\permission::deny_permission($stored,'stored.delete',['scope'=>'tenant']));
		$t->isTrue(\dataphyre\permission::assign_role($stored,'stored',['scope'=>'tenant']));
		$t->isTrue(\dataphyre\permission::revoke($stored,'permission','stored.view',['scope'=>'tenant']));

		$t->same([],\dataphyre\permission::panel_catalog('invalid'));
		$t->same([],\dataphyre\permission::role_matrix('invalid'));
		$t->same([],\dataphyre\permission::role_presets('invalid'));
		$t->same([],\dataphyre\permission::seed_role_presets('invalid'));
		$manifest=\dataphyre\permission::manifest('invalid');
		$t->isTrue(is_array($manifest));
		$t->type('array',$t->decodeJson(\dataphyre\permission::manifest_json('invalid')));
		$t->isTrue(is_array(\dataphyre\permission::diff_manifests(['permissions'=>[]],['permissions'=>['x']])));
		$t->isTrue(is_array(\dataphyre\permission::import_manifest_roles(['roles'=>[]])));
		$t->same('panel.orders.view',\dataphyre\permission::name('orders','view'));
		$t->same('panel.orders.view',\dataphyre\permission::from_shield('view_orders'));
		$t->same(2,count(\dataphyre\permission::from_shield_many(['view_orders','update_orders'])));
		$t->isTrue(is_array(\dataphyre\permission::audit('invalid')));
		$t->isTrue(is_array(\dataphyre\permission::audit_roles(['viewer'=>['orders.view']],['orders.view'])));

		$subjects=['alice'=>['id'=>1,'permissions'=>['orders.view']]];
		$matrix=\dataphyre\permission::test_matrix($subjects,['alice'=>['allow'=>'orders.view','deny'=>'orders.delete']]);
		$t->isTrue($matrix['ok']);
		$t->isTrue(\dataphyre\permission::assert_matrix($subjects,['alice'=>['allow'=>'orders.view']])['ok']);
		$t->isTrue(\dataphyre\permission::assert_allows($subject,'orders.view'));
		$t->isTrue(\dataphyre\permission::assert_denies($subject,'missing'));
		$t->isTrue(\dataphyre\permission::simulate($subject,['grant'=>'orders.edit'],['orders.edit'])['after']['orders.edit']);
		$snapshot=\dataphyre\permission::snapshot($subject,['orders.view','missing']);
		$t->same(2,count($snapshot['decisions']));
		$t->isTrue(\dataphyre\permission::diff_snapshots($snapshot,$snapshot)['ok']);
		$t->same(['orders.*'],\dataphyre\permission::optimize_rules(['orders.*','orders.view']));
		$t->isTrue(is_array(\dataphyre\permission::analyze_rules(['orders.*','orders.view'])));
		$t->isTrue(isset(\dataphyre\permission::analyze_role_rules(['viewer'=>['orders.view']])['viewer']));

		$t->isTrue(\dataphyre\permission::checkWhen('orders.view',[],$subject));
		$t->isTrue(\dataphyre\permission::set(['direct.view'])->allows('direct.view'));
		$t->throws(static fn()=>\dataphyre\permission::doesNotExist(),\BadMethodCallException::class);
		\dataphyre\permission::flush();
		$t->same(3,count(DpPermissionKernelTables::$definitions));
	});
}
