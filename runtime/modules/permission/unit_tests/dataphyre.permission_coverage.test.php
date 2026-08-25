<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Permission\Exceptions\AuthorizationException;
use Dataphyre\Permission\Permission;
use Dataphyre\Permission\PermissionCondition;
use Dataphyre\Permission\PermissionEngine;
use Dataphyre\Permission\PermissionAudit;
use Dataphyre\Permission\PermissionManifest;
use Dataphyre\Permission\PermissionNamer;
use Dataphyre\Permission\PermissionOptimizer;
use Dataphyre\Permission\PermissionRepository;
use Dataphyre\Permission\PermissionRule;
use Dataphyre\Permission\PermissionSet;
use Dataphyre\Permission\PermissionSimulator;
use Dataphyre\Permission\PermissionSnapshot;
use Dataphyre\Permission\PermissionSubject;
use Dataphyre\Permission\PermissionTrace;
use Dataphyre\Permission\SubjectResolver;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'permission'=>true, 'panel'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_PERMISSION_CFG')){
	define('DP_PERMISSION_CFG', [
		'roles'=>[
			'viewer'=>['dashboard.view', 'orders.view'],
			'editor'=>['role.viewer', 'orders.edit'],
			'admin'=>['role.editor', 'orders.*', '-orders.delete'],
		],
		'aliases'=>['orders.read'=>'orders.view'],
		'default_roles'=>['viewer'],
		'super_permissions'=>['*'],
		'cache'=>['enabled'=>true, 'max_subjects'=>16],
		'storage'=>['auto_hydrate'=>false],
		'subject'=>[
			'permission_keys'=>['permissions'],
			'role_keys'=>['roles'],
		],
	]);
}
require_once __DIR__.'/permission_coverage_helpers.php';
$dp_permission_cov_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_permission_cov_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_permission_cov_modules_root);
\dataphyre\autoloader::register_framework_modules(['permission']);

test('permission rules sets aliases wildcards strict denies and child checks are deterministic', static function(Context $t): void {
	$t->same([], PermissionRule::many(null));
	$t->same([], PermissionRule::many(false));
	$t->same(['orders.view','orders.edit'], PermissionRule::many(' Orders::View, orders/edit orders.view '));
	$t->same(
		['orders.view','orders.edit','-orders.delete','role.admin','role.viewer'],
		PermissionRule::many([[
			'allow'=>'orders.view orders.edit',
			'deny'=>'orders.delete',
			'role'=>'admin',
			'groups'=>['viewer'],
		]])
	);
	$t->same('-orders.delete', PermissionRule::normalize('-<Orders/Delete>'));
	$t->same('', PermissionRule::normalize('///'));
	$t->same([
		'permission'=>'orders.*','negative'=>true,'strict'=>false,'wildcard'=>true,'child_exists'=>false,
	], PermissionRule::unwrap('-orders.*'));
	$t->same([
		'permission'=>'orders.%','negative'=>false,'strict'=>false,'wildcard'=>false,'child_exists'=>true,
	], PermissionRule::unwrap('orders.%'));

	$set=PermissionSet::compile(
		['orders.*','-orders.delete','<profile.view>','dashboard.widgets.view'],
		['admin'],
		['orders.read'=>'orders.view'],
		['*']
	);
	$t->isTrue($set->allows('orders.view'));
	$t->isTrue($set->allows('orders.read'));
	$t->isFalse($set->allows('orders.delete'));
	$t->isTrue($set->allows('<profile.view>'));
	$t->isFalse($set->allows('<profile.edit>'));
	$t->isTrue($set->allows('dashboard.%'));
	$t->same(
		['orders.view'=>true,'orders.delete'=>false,'missing'=>false],
		$set->allowsMany(['orders.view','orders.delete','missing'])
	);
	$t->producesStableResult(static fn()=>$set->allowsMany(['orders.view']));
	$t->same(['orders.view'], $set->filterAllowed(['orders.view','orders.delete']));
	$t->same('deny', $set->explain('orders.delete')['reason']);
	$t->same('allow', $set->explain('orders.view')['reason']);
	$t->same('missing', $set->explain('missing')['reason']);
	$t->same(['admin'], $set->roles());
	$t->contains('orders.*', $set->permissions());
	$t->same(3, count($set->decisions(['orders.view','orders.delete','missing'])));
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission engine resolves subjects roles caches explanations tracing and static facade authorization', static function(Context $t): void {
	Permission::flush();
	PermissionTrace::flush();
	PermissionCondition::flush();
	$subject=['id'=>7,'permissions'=>['profile.view','role.editor'],'roles'=>['editor']];
	$t->same(7, SubjectResolver::id($subject));
	$t->same($subject, SubjectResolver::subject($subject));
	$t->same(['profile.view','role.editor'], SubjectResolver::permissions($subject));
	$t->same(['editor'], SubjectResolver::roles($subject));
	$t->same(['editor'], SubjectResolver::roles($subject));
	$object=new class {
		public string $uuid='abc';
		public function getPermissions(): array { return ['object.view']; }
		public function getRoles(): array { return ['viewer']; }
	};
	$t->same('abc', SubjectResolver::id($object));
	$t->same(['object.view'], SubjectResolver::permissions($object));

	$engine=PermissionEngine::fromConfig();
	$stream=fopen('php://memory','r+');
	$t->defer(static fn()=>fclose($stream));
	$t->hasPathValues([
		'context.stream.resource'=>'stream',
		'context.stream.identity'=>get_resource_id($stream),
	], ['context'=>$t->nonPublic($engine)->invoke('cacheIdentityValue', ['stream'=>$stream])]);
	$t->isTrue($engine->allowsAll($subject, ['dashboard.view','orders.edit']));
	$t->isTrue($engine->allowsAny($subject, ['missing','orders.edit']));
	$t->isFalse($engine->allowsAll($subject, ['orders.edit','missing']));
	$t->same(['orders.edit'=>true,'missing'=>false], $engine->allowsMany($subject,['orders.edit','missing']));
	$t->same(['orders.edit'], $engine->filterAllowed($subject,['orders.edit','missing']));
	$t->isTrue($engine->explain($subject,'orders.edit')['allowed']);
	$t->same(['viewer','editor'], $engine->setFor($subject)->roles());
	$t->isTrue($engine->setFor($subject)===$engine->setFor($subject));
	$store66=['id'=>905, 'tenant_id'=>10, 'permissions'=>['fixture.store.view']];
	$store30=['id'=>905, 'tenant_id'=>10, 'permissions'=>['fixture.operations.use']];
	$t->isTrue($engine->allowsAll($store66, 'fixture.store.view', ['tenant_id'=>10, 'brand_id'=>20, 'store_id'=>66]));
	$t->isFalse($engine->allowsAll($store30, 'fixture.store.view', ['tenant_id'=>10, 'brand_id'=>20, 'store_id'=>30]));
	$t->isFalse($engine->allowsAll($store30, 'fixture.store.view', ['tenant_id'=>10, 'brand_id'=>20, 'store_id'=>66]));
	$t->same(['permissions'=>['profile.view','role.editor'],'roles'=>['viewer','editor']], $engine->rulesFor($subject));
	$compiled=$engine->compile(['direct.view'],['admin']);
	$t->isTrue($compiled->allows('orders.create'));
	$t->isFalse($compiled->allows('orders.delete'));
	$t->isTrue($engine->compile(['direct.view'],['admin'])===$compiled);
	$engine->defineRole('custom',['custom.view']);
	$t->isTrue($engine->compile([],['custom'])->allows('custom.view'));
	$engine->defineRole('',[]);
	$engine->flush();

	Permission::useEngine($engine);
	Permission::trace(true);
	$t->isTrue(Permission::check('orders.edit',$subject));
	$t->isTrue(Permission::any(['missing','orders.edit'],$subject));
	$t->isFalse(Permission::denies('orders.edit',$subject));
	$t->isTrue(Permission::ensure('orders.edit',$subject));
	$exception=$t->throws(static fn()=>Permission::ensure('missing',$subject), AuthorizationException::class);
	$t->same(['missing'], $exception->permissions());
	$t->same([], $exception->context());
	$t->instanceOf(PermissionSubject::class, Permission::for($subject));
	$t->isTrue(Permission::for($subject)->can('orders.edit'));
	$t->isTrue(Permission::for($subject)->any(['missing','orders.edit']));
	$t->isTrue(Permission::for($subject)->cannot('missing'));
	$t->isTrue(count(Permission::traces())>0);
	$t->isTrue(Permission::traceStats()['events']>0);
	$t->isTrue(Permission::traceSummary()['entry_count']>0);
	Permission::flushTrace();
	$t->same([], Permission::traces());
	Permission::trace(false);
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission conditions optimizer namer simulator and snapshots cover diagnostic tooling', static function(Context $t): void {
	PermissionCondition::flush();
	PermissionCondition::define('owner', static fn(mixed $subject,array $context): bool=>($subject['id']??null)===($context['owner_id']??null));
	PermissionCondition::define('enabled', static fn(): bool=>true);
	PermissionCondition::define('throws', static fn()=>throw new RuntimeException('condition'));
	PermissionCondition::define('', static fn()=>true);
	$t->isTrue(PermissionCondition::has('OWNER'));
	$t->same(['enabled','owner','throws'], PermissionCondition::names());
	$t->same(['owner','enabled'], PermissionCondition::normalizeMany(' Owner, enabled owner '));
	$subject=['id'=>7];
	$t->isTrue(PermissionCondition::passes(['owner','enabled'],$subject,['owner_id'=>7],'orders.edit'));
	$t->isFalse(PermissionCondition::passes('owner',$subject,['owner_id'=>8],'orders.edit'));
	$t->isFalse(PermissionCondition::passes('missing',$subject,[],'orders.edit'));
	$t->throws(static fn()=>PermissionCondition::passes('throws',$subject,[],'orders.edit'), RuntimeException::class);
	$explain=PermissionCondition::explain(['owner','missing'],$subject,['owner_id'=>7],'orders.edit');
	$t->isTrue($explain['checks'][0]['passed']);
	$t->isFalse($explain['checks'][1]['passed']);

	$analysis=PermissionOptimizer::analyze(['orders.view','orders.edit','orders.*','-orders.delete','orders.view']);
	$t->isTrue($analysis['input_count']>=4);
	$t->isTrue(is_array($analysis['findings']));
	$t->isTrue(is_array($analysis['removed']));
	$optimized=PermissionOptimizer::optimize(['orders.view','orders.edit','orders.*','-orders.delete']);
	$t->contains('orders.*',$optimized);
	$t->contains('-orders.delete',$optimized);
	$roles=PermissionOptimizer::roles(['admin'=>['orders.*'],'viewer'=>['orders.view']]);
	$t->isTrue(isset($roles['admin']));

	$t->same('panel.orders.view', PermissionNamer::panel('orders','view'));
	$t->contains('approve', PermissionNamer::panelAction('orders','approve'));
	$t->contains('items', PermissionNamer::panelRelation('orders','items','view'));
	$shield=PermissionNamer::toShield('panel.orders.view');
	$t->same('panel.orders.view', PermissionNamer::fromShield($shield));
	$t->same(2, count(PermissionNamer::toShieldMany(['a.view','b.edit'])));
	$t->same(2, count(PermissionNamer::fromShieldMany(['View:A','Edit:B'])));

	$t->same(['new.permission','orders.view'], PermissionSimulator::apply(['permissions'=>['orders.view','old.permission']], [
		'grant'=>['new.permission'],'revoke'=>['old.permission'],
	])['permissions']);
	$simulation=PermissionSimulator::run(
		['id'=>1,'permissions'=>['orders.view']],
		['grant'=>['orders.edit']],
		['orders.view','orders.edit','missing']
	);
	$t->isTrue($simulation['after']['orders.edit']);
	$t->isFalse($simulation['after']['missing']);
	$snapshot=PermissionSnapshot::subject(['id'=>1],['orders.view','missing'],[],['include_explain'=>true]);
	$t->same(2, count($snapshot['decisions']));
	$diff=PermissionSnapshot::diff(
		['decisions'=>['a'=>true,'b'=>false]],
		['decisions'=>['a'=>false,'c'=>true]]
	);
	$t->contains('a',$diff['denied']);
	$t->contains('c',$diff['granted']);
	$t->isTrue(isset($diff['unchanged']['b']));
	PermissionCondition::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission repository persists assignments roles and panel mutations through SQL helpers', static function(Context $t): void {
	$storage=\Dataphyre\Permission\dp_permission_sql_reset($t);
	PermissionRepository::flush();
	$repository=PermissionRepository::instance();
	$t->isTrue($repository===PermissionRepository::instance());
	$storedSubject=['id'=>42];
	$t->isTrue($repository->assignPermission($storedSubject,'orders.view',['scope'=>'tenant-a','created_by'=>'tester']));
	$t->isTrue($repository->denyPermission($storedSubject,'orders.delete',['tenant'=>'tenant-a']));
	$t->isTrue($repository->assignRole($storedSubject,'manager',['tenant_id'=>'tenant-a']));
	$t->same(['orders.view','-orders.delete'],$repository->permissionsFor($storedSubject,['scope'=>'tenant-a']));
	$t->same(['manager'],$repository->rolesFor($storedSubject,['scope'=>'tenant-a']));
	$t->same([], $repository->permissionsFor(null));
	$t->isTrue($repository->defineRole('manager',['orders.*','-orders.delete'],['label'=>'Manager','system'=>'yes']));
	$t->same(['orders.*','-orders.delete'],$repository->roleDefinitions()['manager']);
	$t->producesStableResult(static fn()=>$repository->roleDefinitions());
	$roles=$repository->rolesWithPermissions();
	$t->same('orders.*' . "\n" . '-orders.delete',$roles[0]['permissions']);
	$t->same(3,count($repository->assignments()));
	$t->same(3,count($repository->assignments(['scope'=>'tenant-a'])));

	$roleSave=$repository->saveRoleFromPanel([
		'name'=>'supervisor','label'=>'Supervisor','description'=>'May supervise','system'=>'1',
		'permissions'=>"orders.view\norders.edit",
	],['name'=>'manager']);
	$t->isTrue($roleSave['saved']);
	$t->same('Role saved.',$roleSave['message']);
	$t->isFalse($repository->saveRoleFromPanel(['name'=>''])['saved']);

	$invalid=$repository->saveAssignmentFromPanel(['subject_id'=>'','value'=>'']);
	$t->isFalse($invalid['saved']);
	$created=$repository->saveAssignmentFromPanel([
		'subject_type'=>'service','subject_id'=>'svc-1','scope'=>'tenant-a','kind'=>'other',
		'value'=>'jobs.run','negative'=>'yes','created_by'=>'operator',
	]);
	$t->isTrue($created['saved']);
	$assignment=$storage->lastRow('dataphyre.permission_assignments');
	$updated=$repository->saveAssignmentFromPanel([
		'subject_type'=>'service','subject_id'=>'svc-1','scope'=>'global','kind'=>'role','value'=>'worker',
	],$assignment);
	$t->isTrue($updated['saved']);
	$t->isTrue($repository->deleteAssignment($assignment));
	$t->isFalse($repository->deleteAssignment(''));
	$t->isTrue($repository->revoke($storedSubject,'permission','orders.view',['scope'=>'tenant-a']));
	$t->isFalse($repository->revoke(null,'permission','orders.view'));
	$t->isTrue($repository->deleteRole(['name'=>'supervisor']));
	$t->isFalse($repository->deleteRole(''));
	PermissionRepository::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission static facade forwards conditional diagnostic naming and persistence APIs', static function(Context $t): void {
	\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$subject=['id'=>9,'permissions'=>['orders.view','profile.view'],'roles'=>[]];
	Permission::defineCondition('owner',static fn(mixed $candidate,array $context): bool=>($candidate['id'] ?? null)===($context['owner_id'] ?? null));
	$t->contains('owner',Permission::conditions());
	$t->isTrue(Permission::checkWhen('orders.view','owner',$subject,['owner_id'=>9]));
	$t->isFalse(Permission::checkWhen('missing','owner',$subject,['owner_id'=>9]));
	$t->isFalse(Permission::checkWhen('orders.view','owner',$subject,['owner_id'=>10]));
	$t->isTrue(Permission::ensureWhen('orders.view','owner',$subject,['owner_id'=>9]));
	$t->throws(static fn()=>Permission::ensureWhen('orders.view','owner',$subject,['owner_id'=>10]),AuthorizationException::class);
	$t->isTrue(Permission::explainWhen('orders.view','owner',$subject,['owner_id'=>9])['allowed']);

	$t->isTrue(Permission::allows('orders.view',$subject));
	$decisions=Permission::decisions(['orders.view','missing'],$subject);
	$t->isTrue($decisions['orders.view']['allowed']);
	$t->isFalse($decisions['missing']['allowed']);
	$t->same(['orders.view'=>true,'missing'=>false],Permission::allowsMany(['orders.view','missing'],$subject));
	$t->same(['orders.view'],Permission::filterAllowed(['orders.view','missing'],$subject));
	$t->isTrue(Permission::ensureAny(['missing','orders.view'],$subject));
	$t->throws(static fn()=>Permission::ensureAny(['missing','absent'],$subject),AuthorizationException::class);
	$t->isTrue(Permission::explain('orders.view',$subject)['allowed']);
	$t->isTrue(Permission::set(['direct.view'])->allows('direct.view'));
	Permission::defineRole('facade-role',['facade.view']);
	$t->isTrue(Permission::set([],['facade-role'])->allows('facade.view'));
	$t->same(Permission::allowsMany(['orders.view'],$subject),Permission::allows_many(['orders.view'],$subject));
	$t->throws(static fn()=>Permission::does_not_exist(),BadMethodCallException::class);

	$t->same([],Permission::panelCatalog('invalid'));
	$t->same([],Permission::roleMatrix('invalid'));
	$t->same([],Permission::rolePresets('invalid'));
	$t->same([],Permission::seedRolePresets('invalid'));
	$t->isTrue(is_array(Permission::manifest('invalid')));
	$t->type('array',$t->decodeJson(Permission::manifestJson('invalid')));
	$t->isTrue(is_array(Permission::diffManifests(['permissions'=>[]],['permissions'=>['x']])));
	$t->same('panel.orders.view',Permission::name('orders','view'));
	$shield=Permission::toShield('panel.orders.view');
	$t->same('panel.orders.view',Permission::fromShield($shield));
	$t->same(2,count(Permission::toShieldMany(['panel.orders.view','panel.orders.edit'])));
	$t->same(2,count(Permission::fromShieldMany(['view_orders','update_orders'])));
	$t->isTrue(is_array(Permission::audit('invalid')));
	$t->isTrue(is_array(Permission::auditRoles(['viewer'=>['orders.view']],['orders.view'])));

	$t->same(['orders.*'],Permission::optimizeRules(['orders.*','orders.view']));
	$t->isTrue(is_array(Permission::analyzeRules(['orders.*','orders.view'])));
	$t->isTrue(isset(Permission::analyzeRoleRules(['viewer'=>['orders.view']])['viewer']));
	$t->isTrue(Permission::assertAllows($subject,'orders.view'));
	$t->isTrue(Permission::assertDenies($subject,'missing'));
	$t->isTrue(Permission::simulate($subject,['grant'=>'orders.edit'],['orders.edit'])['after']['orders.edit']);
	$snapshot=Permission::snapshot($subject,['orders.view','missing']);
	$t->same(2,count($snapshot['decisions']));
	$t->isTrue(Permission::diffSnapshots($snapshot,$snapshot)['ok']);

	$storedSubject=['id'=>77];
	$t->isTrue(Permission::storeRole('stored',['stored.view'],['label'=>'Stored']));
	$t->isTrue(Permission::assignPermission($storedSubject,'stored.view',['scope'=>'tenant-b']));
	$t->isTrue(Permission::denyPermission($storedSubject,'stored.delete',['scope'=>'tenant-b']));
	$t->isTrue(Permission::assignRole($storedSubject,'stored',['scope'=>'tenant-b']));
	$t->isTrue(Permission::revoke($storedSubject,'permission','stored.view',['scope'=>'tenant-b']));
	$t->isFalse(Permission::revoke(null,'permission','stored.view'));
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission test matrices support grouped inline fallback passing and failing cases', static function(Context $t): void {
	Permission::flush();
	$subjects=[
		'alice'=>['id'=>1,'permissions'=>['orders.view','orders.edit']],
		'bob'=>['id'=>2,'permissions'=>[]],
	];
	$passing=Permission::testMatrix($subjects,[
		'alice'=>['allow'=>'orders.view','can'=>'orders.edit','deny'=>'orders.delete','profile.view'=>false],
		'bob'=>['cannot'=>['missing','orders.edit']],
		'inline'=>[
			'name'=>'carol','subject'=>['id'=>3,'permissions'=>['profile.view']],
			'context'=>['source'=>'inline'],'allows'=>'profile.view','denies'=>'profile.edit',
		],
	],['default_context'=>['tenant'=>'global']]);
	$t->isTrue($passing['ok']);
	$t->same($passing['total'],$passing['passed']);
	$t->same(0,$passing['failed']);
	$t->isTrue(Permission::assertMatrix($subjects,['alice'=>['allow'=>'orders.view']])['ok']);

	$fallback=Permission::testMatrix([
		'fallback'=>[
			'subject'=>['id'=>4,'permissions'=>['fallback.view']],
			'context'=>['tenant'=>'fallback'],
			'expect'=>['allow'=>'fallback.view','deny'=>'fallback.edit'],
		],
	],[]);
	$t->same(2,$fallback['total']);
	$t->isTrue($fallback['ok']);

	$failing=Permission::testMatrix($subjects,[
		'alice'=>[
			'deny'=>['orders.view','orders.edit'],
			'allow'=>['missing.one','missing.two','missing.three','missing.four','missing.five'],
		],
	],['include_explain'=>false]);
	$t->isFalse($failing['ok']);
	$t->same(7,$failing['failed']);
	$t->isFalse(isset($failing['failures'][0]['explain']));
	$t->throws(static fn()=>Permission::assertMatrix($subjects,[
		'alice'=>['deny'=>['orders.view','orders.edit'],'allow'=>['m1','m2','m3','m4','m5']],
	]),RuntimeException::class);
	$t->throws(static fn()=>Permission::assertAllows($subjects['alice'],['orders.view','missing']),RuntimeException::class);
	$t->throws(static fn()=>Permission::assertDenies($subjects['alice'],['missing','orders.view']),RuntimeException::class);
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission audits report broad conflicting unknown empty uncovered and assignment findings', static function(Context $t): void {
	$roles=[
		'admin'=>['panel.*','orders.view','-orders.view','ghost.use'],
		'editor'=>['orders.*'],
		'child'=>['catalog.%'],
		'empty'=>[],
	];
	$known=['orders.view','orders.edit','catalog.item.view','uncovered.view','-orders.view',''];
	$assignments=[
		['kind'=>'role','value'=>'missing-role'],
		['kind'=>'role','value'=>'admin'],
		'not-a-row',
	];
	$audit=PermissionAudit::roles($roles,$known,$assignments,[
		'severity_for_broad_grants'=>'error',
		'severity_for_unknown_permissions'=>'invalid-severity',
	]);
	$t->isFalse($audit['ok']);
	$t->same(4,$audit['role_count']);
	$t->same(3,$audit['assignment_count']);
	$types=array_column($audit['findings'],'type');
	$t->contains('broad_grant',$types);
	$t->contains('conflicting_rule',$types);
	$t->contains('unknown_permission',$types);
	$t->contains('empty_role',$types);
	$t->contains('unknown_role_assignment',$types);
	$t->contains('uncovered_permission',$types);
	$t->isTrue($audit['counts']['error']>0);
	$t->isTrue($audit['counts']['warning']>0);
	$t->isTrue($audit['counts']['info']>0);

	$quiet=PermissionAudit::roles(
		['viewer'=>['orders.view']],
		['orders.view'],
		[],
		['warn_broad_grants'=>false,'warn_unknown_permissions'=>false,'warn_empty_roles'=>false,'warn_uncovered_catalog'=>false]
	);
	$t->isTrue($quiet['ok']);
	$t->same([], $quiet['findings']);

	\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$t->isTrue(Permission::storeRole('broad',['*']));
	$t->isTrue(Permission::assignRole(['id'=>12],'unknown-role'));
	\Dataphyre\Permission\sql_insert('dataphyre.permission_assignments',[
		'id'=>'empty','subject_type'=>'user','subject_id'=>'12','scope'=>'global','kind'=>'permission','value'=>'','negative'=>false,
	]);
	$stored=PermissionAudit::run();
	$t->contains('broad_grant',array_column($stored['findings'],'type'));
	$t->contains('unknown_role_assignment',array_column($stored['findings'],'type'));
	$t->contains('empty_assignment_value',array_column($stored['findings'],'type'));
	$html=PermissionAudit::html();
	$t->contains('Permission Audit',$html);
	$t->contains('<table',$html);

	\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$cleanHtml=PermissionAudit::html();
	$t->contains('No permission audit findings.',$cleanHtml);
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission manifests build normalize diff serialize cache and import role definitions', static function(Context $t): void {
	\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$t->isTrue(Permission::storeRole('zeta',['orders.edit','orders.view','orders.view']));
	$t->isTrue(Permission::storeRole('alpha',['-orders.delete','orders.view']));
	$t->isTrue(Permission::assignPermission(['id'=>22],'orders.view',['scope'=>'tenant-z']));
	$manifest=PermissionManifest::build(null,[
		'include_assignments'=>true,
		'include_generated_at'=>true,
	]);
	$t->same(1,$manifest['version']);
	$t->same('dataphyre.permission',$manifest['module']);
	$t->isTrue(isset($manifest['generated_at']));
	$t->same(['alpha','zeta'],array_keys($manifest['roles']));
	$t->same(1,count($manifest['assignments']));
	$t->isTrue(isset($manifest['audit']));
	$t->isFalse(isset($manifest['catalog']));
	$t->isFalse(isset($manifest['presets']));
	$minimal=PermissionManifest::build(null,[
		'include_roles'=>false,'include_assignments'=>false,'include_audit'=>false,
	]);
	$t->same(['version'=>1,'module'=>'dataphyre.permission'],$minimal);
	$t->contains("\n    \"version\"",PermissionManifest::json(null,['include_roles'=>false,'include_audit'=>false]));
	$compact=PermissionManifest::json(null,['include_roles'=>false,'include_audit'=>false,'pretty'=>false]);
	$t->isFalse(str_contains($compact,"\n  "));
	$t->isTrue(str_ends_with($compact,"\n"));

	$left=[
		'roles'=>['viewer'=>['orders.view'],'removed'=>['old.view']],
		'catalog'=>[
			['permission'=>'orders.view'],['permission'=>'old.view'],['label'=>'ignored'],'bad-row',
		],
	];
	$right=[
		'roles'=>['viewer'=>['orders.edit'],'added'=>['new.view']],
		'catalog'=>[['permission'=>'orders.view'],['permission'=>'new.view'],['permission'=>'new.view']],
	];
	$diff=PermissionManifest::diff($left,$right);
	$t->same(['added'],$diff['roles']['added']);
	$t->same(['removed'],$diff['roles']['removed']);
	$t->same(['viewer'],$diff['roles']['changed']);
	$t->same(['new.view'],$diff['catalog']['added']);
	$t->same(['old.view'],$diff['catalog']['removed']);
	$t->same($diff,PermissionManifest::diff($left,$right));
	$uncacheableLeft=$left+['opaque'=>new stdClass()];
	$t->isTrue(is_array(PermissionManifest::diff($uncacheableLeft,$right)));

	$dry=PermissionManifest::importRoles([
		'roles'=>[
			' imported '=>['permissions'=>'catalog.view catalog.edit'],
			''=>['ignored'],
			'nested'=>[['allow'=>'orders.view']],
		],
	],['dry_run'=>true]);
	$t->same(['imported'=>true,'nested'=>true],$dry);
	$fromPresets=PermissionManifest::importRoles([
		'presets'=>['preset-role'=>['permissions'=>['preset.view']]],
	],['system'=>false]);
	$t->isTrue($fromPresets['preset-role']);
	$t->contains('preset.view',Permission::repository()->roleDefinitions()['preset-role']);
	$t->same([],PermissionManifest::importRoles(['roles'=>'invalid'],['dry_run'=>true]));
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission snapshots normalize catalog legacy decisions rule lists and uncached values', static function(Context $t): void {
	Permission::flush();
	$subject=['id'=>31,'permissions'=>['alpha.view','gamma.view','group.staff'],'roles'=>['viewer']];
	$t->contains('staff',SubjectResolver::roles($subject));
	$snapshot=PermissionSnapshot::subject($subject,[
		'catalog'=>[
			['permission'=>'gamma.view'],
			['name'=>'alpha.view'],
			'beta.view',
			'key.view'=>true,
			null,
		],
	],[],['include_explain'=>true,'include_generated_at'=>true]);
	$t->isTrue(isset($snapshot['generated_at']));
	$t->same(['alpha.view','gamma.view'],$snapshot['allowed']);
	$t->contains('beta.view',$snapshot['denied']);
	$t->contains('key.view',$snapshot['denied']);
	$t->same(array_keys($snapshot['decisions']),array_keys($snapshot['explain']));

	$legacyLeft=[
		'decisions'=>[
			'a'=>['allowed'=>true],
			'b'=>0,
			2=>['allowed'=>true],
		],
		'roles'=>'viewer admin',
		'rules'=>['Orders::View','--broken'],
	];
	$legacyRight=[
		'decisions'=>['a'=>false,'b'=>true,'2'=>true],
		'roles'=>['viewer','editor'],
		'rules'=>[['allow'=>'orders.edit']],
	];
	$legacyDiff=PermissionSnapshot::diff($legacyLeft,$legacyRight);
	$t->contains('b',$legacyDiff['granted']);
	$t->contains('a',$legacyDiff['denied']);
	$t->contains('editor',$legacyDiff['role_changes']['added']);
	$t->contains('admin',$legacyDiff['role_changes']['removed']);
	$t->same($legacyDiff,PermissionSnapshot::diff($legacyLeft,$legacyRight));
	$t->isTrue(PermissionSnapshot::diff(
		['decisions'=>['same'=>true],'rules'=>['--broken']],
		['decisions'=>['same'=>true],'rules'=>[]],
	)['ok']);

	$listDiff=PermissionSnapshot::diff(
		['allowed'=>'old.view same.view','denied'=>'new.view','roles'=>['valid.role'],'rules'=>['valid.view']],
		['allowed'=>'new.view same.view','denied'=>'old.view','roles'=>['Valid::Role'],'rules'=>['.invalid']],
	);
	$t->contains('new.view',$listDiff['granted']);
	$t->contains('old.view',$listDiff['denied']);
	$t->isTrue(isset($listDiff['unchanged']['same.view']));
	$uncacheable=PermissionSnapshot::diff(
		['decisions'=>['x'=>true],'opaque'=>['nested'=>new stdClass()]],
		['decisions'=>['x'=>true]],
	);
	$t->isTrue($uncacheable['ok']);
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission bound subjects tracing sanitization bounds and rule caches cover fluent runtime branches', static function(Context $t): void {
	Permission::flush();
	PermissionCondition::define('always',static fn(): bool=>true);
	$subject=['id'=>55,'permissions'=>['orders.view'],'roles'=>[]];
	$bound=Permission::for($subject);
	$t->isTrue($bound->can('orders.view'));
	$t->isTrue($bound->any(['missing','orders.view']));
	$t->isTrue($bound->cannot('missing'));
	$t->isTrue($bound->decisions(['orders.view'])['orders.view']['allowed']);
	$t->same(['orders.view'=>true,'missing'=>false],$bound->allowsMany(['orders.view','missing']));
	$t->same(['orders.view'],$bound->filterAllowed(['orders.view','missing']));
	$t->isTrue($bound->canWhen('orders.view','always'));
	$t->isTrue($bound->ensureWhen('orders.view','always'));
	$t->isTrue($bound->explainWhen('orders.view','always')['allowed']);
	$t->isTrue($bound->set()->allows('orders.view'));
	$t->isTrue($bound->explain('orders.view')['allowed']);

	PermissionTrace::flush();
	$t->isFalse(PermissionTrace::enabled());
	PermissionTrace::record('ignored',['allowed'=>true]);
	$t->same([],PermissionTrace::entries());
	PermissionTrace::enable();
	$traceObject=new class { public int $id=55; };
	$resource=fopen('php://memory','r');
	for($index=0;$index<260;$index++){
		PermissionTrace::record($index%2===0 ? 'check.all' : 'custom/event',[
			'allowed'=>$index%3===0,
			'cache_hit'=>$index%2===0,
			'duration_ms'=>$index/10,
			'context'=>['secret'=>'omitted'],
			'actor'=>$traceObject,
			'nested'=>['resource'=>$resource],
		]);
	}
	fclose($resource);
	$t->same(256,count(PermissionTrace::entries()));
	$t->isFalse(isset(PermissionTrace::entries()[0]['context']));
	$t->same($traceObject::class,PermissionTrace::entries()[0]['actor']['class']);
	$t->same('resource (stream)',PermissionTrace::entries()[0]['nested']['resource']);
	$stats=PermissionTrace::stats();
	$t->same(260,$stats['events']);
	$t->isTrue($stats['checks']>0);
	$t->isTrue($stats['allowed']>0);
	$t->isTrue($stats['denied']>0);
	$t->isTrue($stats['cache_hits']>0);
	$t->isTrue($stats['cache_misses']>0);
	$t->isTrue($stats['slowest']['duration_ms']>0);
	PermissionTrace::disable();
	$t->isFalse(PermissionTrace::enabled());

	$stringable=new class implements Stringable {
		public function __toString(): string { return 'stringable.view'; }
	};
	$t->same(['stringable.view'],PermissionRule::many($stringable));
	$t->same(['stringable.view'],PermissionRule::many([$stringable]));
	$definition=['allow'=>'cached.view','deny'=>'cached.delete'];
	$t->producesStableResult(static fn()=>PermissionRule::fromDefinition($definition));
	for($index=0;$index<515;$index++){
		PermissionRule::normalize('cache.'.$index);
	}
	for($index=0;$index<130;$index++){
		PermissionRule::fromDefinition(['allow'=>'definition.'.$index]);
	}
	$t->same('cache.final',PermissionRule::normalize('cache.final'));
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission optimizer covers conflicts global wildcard prefix child nested cache and uncacheable rules', static function(Context $t): void {
	$rules='orders.view -orders.view * profile.view orders.% orders orders.edit orders.view';
	$analysis=PermissionOptimizer::analyze($rules);
	$t->isFalse($analysis['ok']);
	$t->contains('conflicting_rule',array_column($analysis['findings'],'type'));
	$t->contains('duplicate_rule',array_column($analysis['findings'],'type'));
	$t->contains('shadowed_rule',array_column($analysis['findings'],'type'));
	$t->contains('*',$analysis['optimized']);
	$t->same($analysis,PermissionOptimizer::analyze($rules));

	$nested=PermissionOptimizer::analyze([
		['allow'=>'catalog.view catalog.edit','deny'=>'catalog.delete'],
		'catalog.*',
	]);
	$t->contains('catalog.*',$nested['optimized']);
	$t->contains('-catalog.delete',$nested['optimized']);
	$t->isTrue(isset(PermissionOptimizer::roles([
		''=>['ignored.view'],
		'zeta'=>['zeta.view'],
		'alpha'=>['alpha.view'],
	])['alpha']));

	$optimizerInternals=$t->nonPublic(PermissionOptimizer::class);
	$t->same('root.*',$optimizerInternals->invoke('shadowedBy','root.child',['root.*','root.child']));
	$t->same('root',$optimizerInternals->invoke('shadowedBy','root.child',['root','root.child']));
	$t->same(null,$optimizerInternals->invoke('shadowedBy','root.child',['other.*','root.child']));
	$t->same(null,$optimizerInternals->invoke('shadowedBy','root.%',['root.*','root.%']));
	$stringable=new class implements Stringable {
		public function __toString(): string { return 'object.view'; }
	};
	$uncacheable=PermissionOptimizer::analyze([[$stringable],'object.*']);
	$t->isTrue(is_array($uncacheable));
	$t->same([],PermissionRule::many([[$stringable]]));
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission simulator covers removals aliases strict rules cache denial deltas and uncacheable trees', static function(Context $t): void {
	$rules=[
		'permissions'=>['keep.view','remove.view','<secret.view>'],
		'roles'=>['viewer','remove-role'],
	];
	$changes=[
		'grants'=>'grant.one','allow'=>'grant.two','allows'=>'grant.three','add'=>'grant.four',
		'add_permissions'=>'grant.five','permissions'=>'grant.six',
		'deny'=>'deny.one','denies'=>'deny.two','deny_permissions'=>'-deny.three',
		'remove'=>'remove.view','removes'=>'secret.view','revoke'=>'absent.one','revokes'=>'absent.two',
		'remove_permissions'=>'absent.three','revoke_permissions'=>'absent.four',
		'role'=>'role.one','roles'=>'role.two','grant_roles'=>'role.three','add_roles'=>'role.four',
		'remove_roles'=>'remove-role','revoke_roles'=>'absent-role',
	];
	$applied=PermissionSimulator::apply($rules,$changes);
	$t->contains('keep.view',$applied['permissions']);
	$t->contains('grant.six',$applied['permissions']);
	$t->contains('-deny.three',$applied['permissions']);
	$t->isFalse(in_array('remove.view',$applied['permissions'],true));
	$t->isFalse(in_array('<secret.view>',$applied['permissions'],true));
	$t->isFalse(in_array('remove-role',$applied['roles'],true));
	$t->contains('role.four',$applied['roles']);
	$t->same($applied,PermissionSimulator::apply($rules,$changes));
	$t->same([],PermissionSimulator::apply(
		['permissions'=>['-remove.denial'],'roles'=>[]],
		['remove'=>'remove.denial'],
	)['permissions']);

	$subject=['id'=>70,'permissions'=>['old.view','same.view'],'roles'=>[]];
	$simulation=PermissionSimulator::run($subject,['remove'=>'old.view','grant'=>'new.view'],['old.view','new.view','same.view']);
	$t->contains('new.view',$simulation['delta']['granted']);
	$t->contains('old.view',$simulation['delta']['denied']);
	$t->isTrue($simulation['delta']['unchanged']['same.view']);

	$stringable=new class implements Stringable {
		public function __toString(): string { return 'object.view'; }
	};
	$uncacheable=PermissionSimulator::apply(
		['permissions'=>[[$stringable]],'roles'=>[]],
		['grant'=>[$stringable]],
	);
	$t->contains('object.view',$uncacheable['permissions']);
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission namer covers operation aliases pluralization prefixes mappings passthrough and batch caches', static function(Context $t): void {
	$aliases=[
		'viewany'=>'view_any','index'=>'view_any','list'=>'view_any','store'=>'create','edit'=>'update',
		'destroy'=>'delete','bulk_delete'=>'delete_any','bulk_force_delete'=>'force_delete_any',
		'force_delete'=>'force_delete','bulk_restore'=>'restore_any','replicate'=>'duplicate',
	];
	foreach($aliases as $input=>$expected){
		$t->same('panel.widgets.'.$expected,PermissionNamer::panel('widget',$input));
	}
	$t->same('panel.widget.view',PermissionNamer::panel('widget','view',['pluralize'=>false]));
	$t->same('panel.permission.items.view',PermissionNamer::panel('permission_item','view'));
	$t->same('view_orders',PermissionNamer::toShield('panel.admin.orders.view',['resource_prefix'=>'admin']));
	$t->same('custom.permission',PermissionNamer::toShield('custom.permission'));
	$t->same('-panel',PermissionNamer::toShield('-panel'));
	$t->same('panel.widgets.publish',PermissionNamer::fromShield('ship_widgets',[
		'shield_operations'=>['ship'=>'publish'],
	]));
	$t->same('ship_widgets',PermissionNamer::toShield('panel.widgets.publish',[
		'shield_operations'=>['ship'=>'publish'],
	]));
	$from=['view_widgets','update_widgets','-delete_widgets'];
	$t->producesStableResult(static fn()=>PermissionNamer::fromShieldMany($from));
	$to=['panel.widgets.view','panel.widgets.update','-panel.widgets.delete'];
	$t->producesStableResult(static fn()=>PermissionNamer::toShieldMany($to));
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission repository covers update fallback malformed rows and storage failure behavior', static function(Context $t): void {
	$storage=\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$repository=PermissionRepository::instance();
	$t->isFalse($repository->defineRole('',[]));
	$t->isTrue($repository->defineRole('repeat',['repeat.view']));
	$t->isTrue($repository->defineRole('repeat',['repeat.edit'],['description'=>'updated']));
	$t->contains('repeat.edit',$repository->roleDefinitions()['repeat']);

	$storage->appendRow('dataphyre.permission_role_permissions','invalid-row');
	$storage->appendRow('dataphyre.permission_role_permissions',['role'=>'','permission'=>'']);
	PermissionRepository::flush();
	$definitions=PermissionRepository::instance()->roleDefinitions();
	$t->contains('repeat.edit',$definitions['repeat']);
	$storage->appendRow('dataphyre.permission_roles','invalid-row');
	$storage->appendRow('dataphyre.permission_roles',['name'=>'']);
	$t->same(1,count(PermissionRepository::instance()->rolesWithPermissions()));

	$storage->fail('select','dataphyre.permission_role_permissions');
	PermissionRepository::flush();
	$t->same([],PermissionRepository::instance()->roleDefinitions());
	$storage->allow('select','dataphyre.permission_role_permissions');
	$storage->fail('select','dataphyre.permission_roles');
	$t->same([],PermissionRepository::instance()->rolesWithPermissions());
	$storage->fail('select','dataphyre.permission_assignments');
	$t->same([],PermissionRepository::instance()->assignments());
	$storage->allow('select');

	$storage->fail('insert','dataphyre.permission_roles');
	$storage->fail('update','dataphyre.permission_roles');
	$t->isFalse(PermissionRepository::instance()->defineRole('failed',['failed.view']));
	$storage->fail('insert','dataphyre.permission_assignments');
	$t->isFalse(PermissionRepository::instance()->assignPermission(['id'=>80],'failed.view'));
	$t->isFalse(PermissionRepository::instance()->assignPermission(['id'=>80],''));
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission sets cover empty rules deny and allow prefixes child queries parents super grants and cache trees', static function(Context $t): void {
	$set=PermissionSet::compile([
		'///','-orders.*','catalog.*','projects','*',
	],[],[],['*']);
	$t->isFalse($set->allows('orders.deep.%'));
	$t->isTrue($set->allows('catalog.%'));
	$t->isFalse($set->allows('missing.%'));
	$t->isTrue($set->allows('projects.view'));
	$t->isTrue(PermissionSet::compile(['projects'])->allows('projects.view'));
	$t->isTrue($set->allows('anything.deep'));
	$t->contains('-orders.*',$set->permissions());

	$prefixSet=PermissionSet::compile(['root.branch.*']);
	$t->isTrue($prefixSet->allows('root.%'));
	$t->isTrue($prefixSet->allows('root.branch.deep.%'));
	$t->isFalse(PermissionSet::compile([],[],[],[])->allows('root.%'));
	$strictSet=PermissionSet::compile(['root']);
	$t->isFalse($strictSet->allows('<root.child>'));

	$stringable=new class implements Stringable {
		public function __toString(): string { return 'projects.view'; }
	};
	$t->same(['projects.view'=>true],$set->allowsMany([$stringable]));
	$t->same(['projects.view'=>true],$set->allowsMany(['projects.view'],['nested'=>['scalar']]));
	$t->same([],PermissionSet::compile([])->allowsMany([[$stringable]],['nested'=>[$stringable]]));

	$engine=PermissionEngine::fromConfig();
	for($index=0;$index<18;$index++){
		$engine->setFor(['id'=>1000+$index,'permissions'=>['cache.view']]);
	}
	$t->isTrue($engine->setFor(['id'=>1017,'permissions'=>['cache.view']])->allows('cache.view'));
	$t->isTrue($engine->setFor('anonymous-subject')->allows('dashboard.view'));
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission remaining audit manifest and matrix normalization branches accept malformed diagnostic input safely', static function(Context $t): void {
	$storage=\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$repository=PermissionRepository::instance();
	$t->nonPublic($repository)->writeProperty('roleCache',['empty'=>[],'global'=>['*']]);
	$storage->appendRow('dataphyre.permission_assignments','malformed-assignment');
	$audit=PermissionAudit::run();
	$t->contains('empty_role',array_column($audit['findings'],'type'));
	$t->isTrue(is_array(PermissionAudit::roles(['global'=>['*']],['known.view'])));
	$auditInternals=$t->nonPublic(PermissionAudit::class);
	$t->same([],$auditInternals->invoke('ruleFindings',[''],[],[],[
		'warn_broad_grants'=>true,'warn_unknown_permissions'=>true,
		'severity_for_broad_grants'=>'warning','severity_for_unknown_permissions'=>'warning',
	]));

	$storage->appendRow('dataphyre.permission_assignments',[
		'id'=>'valid','subject_type'=>'user','subject_id'=>'1','scope'=>'global','kind'=>'permission','value'=>'known.view','negative'=>false,
	]);
	$manifest=PermissionManifest::build(null,['include_assignments'=>true,'include_audit'=>false]);
	$t->same(1,count($manifest['assignments']));
	$manifestInternals=$t->nonPublic(PermissionManifest::class);
	$t->same(1,count($manifestInternals->invoke('normalizeAssignments',[
		'malformed',['subject_type'=>'user','subject_id'=>'2','value'=>'x'],
	])));
	$normalizationDiff=PermissionManifest::diff([
		'roles'=>[
			''=>['ignored.view'],
			'string-role'=>'one.view two.view',
			'nested-role'=>[['allow'=>'nested.view']],
		],
		'nested'=>['opaque'=>new stdClass()],
	],['roles'=>[]]);
	$t->contains('string-role',$normalizationDiff['roles']['removed']);
	$t->contains('nested-role',$normalizationDiff['roles']['removed']);
	$t->same(['dry-role'=>true],Permission::importManifestRoles([
		'roles'=>['dry-role'=>'dry.view'],
	],['dry_run'=>true]));
	$t->isTrue(is_array(Permission::audit(null)));

	$emptyMatrix=Permission::testMatrix(['skip'=>'not-an-array'],[]);
	$t->same(0,$emptyMatrix['total']);
	$t->same(0,Permission::testMatrix(['alice'=>['id'=>1]],['alice'=>'invalid'])['total']);
	$permissionTestInternals=$t->nonPublic(\Dataphyre\Permission\PermissionTest::class);
	$summary=$permissionTestInternals->invoke('failureSummary',['failed'=>2,'failures'=>[
		'bad-row',['subject'=>'alice','expected'=>true,'permission'=>'x','actual'=>false],
	]]);
	$t->contains('alice expected allow x',$summary);
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');

test('permission panel integrations generate catalogs matrices presets manifests audits and facade results', static function(Context $t): void {
	\dataphyre\autoloader::register_framework_modules(['panel']);
	\Dataphyre\Permission\dp_permission_sql_reset($t);
	Permission::flush();
	$panel=\Dataphyre\Panel\PanelInstance::make('coverage');
	$orders=\Dataphyre\Panel\Resource::make('orders')
		->label('Order | Entry')
		->pluralLabel('Orders | Entries')
		->action('approve')
		->relation('items')
		->bulkUpdateUsing(static fn()=>true)
		->duplicateUsing(static fn()=>true)
		->restoreUsing(static fn()=>true)
		->deleteUsing(static fn()=>true)
		->forceDeleteUsing(static fn()=>true)
		->importUsing(static fn()=>true);
	$panel->manager()->register($orders);
	$panel->manager()->register(\Dataphyre\Panel\Resource::make('hidden')->hideFromNavigation());
	$panel->manager()->register(\Dataphyre\Panel\Resource::make('permission_catalog'));
	$managerInternals=$t->nonPublic($panel->manager());
	$resources=$managerInternals->readProperty('resources');
	$resources['malformed']='not-a-resource';
	$managerInternals->writeProperty('resources',$resources);
	$catalog=Permission::panelCatalog($panel);
	$t->isTrue(count($catalog)>0);
	$t->contains('panel.orders.view',array_column($catalog,'permission'));
	$t->contains('panel.orders.action.approve',array_column($catalog,'permission'));
	$t->contains('panel.orders.relation.items.view',array_column($catalog,'permission'));
	$t->contains('panel.permission.catalog.view',array_column($catalog,'permission'));
	$visible=Permission::panelCatalog($panel,['include_hidden'=>false]);
	$t->isFalse(in_array('panel.hidden.view',array_column($visible,'permission'),true));
	$t->isTrue(is_array(Permission::roleMatrix($panel)));
	$presets=Permission::rolePresets($panel);
	$t->isTrue(is_array($presets));
	$t->same(['viewer'],array_keys(Permission::rolePresets($panel,[
		'roles'=>['viewer'],'deny_dangerous_for_manager'=>false,
	])));
	$audit=Permission::audit($panel);
	$t->same(count($catalog),$audit['catalog_count']);
	$t->contains('uncovered_permission',array_column($audit['findings'],'type'));
	$t->isTrue(is_array(Permission::seedRolePresets($panel,['overwrite'=>true])));
	$matrix=Permission::roleMatrix(null);
	$t->isTrue(count($matrix)>0);
	$t->isTrue(count($matrix[0]['allows'])+count($matrix[0]['denies'])+count($matrix[0]['missing'])>0);
	$manifest=Permission::manifest($panel,['include_assignments'=>true]);
	$t->same($catalog,$manifest['catalog']);
	$t->isTrue(isset($manifest['presets']));
	$markdown=\Dataphyre\Permission\PermissionCatalog::markdown($panel);
	$t->contains('Orders \\| Entries',$markdown);
	$t->contains('# Permission Catalog',$markdown);
	$html=\Dataphyre\Permission\PermissionCatalog::html($panel);
	$t->contains('Orders | Entries',$html);
	$t->contains('Permission Catalog',$html);
	$catalogInternals=$t->nonPublic(\Dataphyre\Permission\PermissionCatalog::class);
	$operations=$catalogInternals->invoke('operationsFor',[
		'bulk_updates'=>true,'duplicates'=>true,'restores'=>true,'deletes'=>true,'force_deletes'=>true,'imports'=>true,
		'actions'=>['bad',['name'=>''],['name'=>'custom']],
		'relations'=>['bad',['name'=>''],['name'=>'children']],
	],[
		'include_action_permissions'=>true,'include_relation_permissions'=>true,
	]);
	$t->same('bulk_updates',$operations['bulk_update']['capability']);
	$t->isTrue(isset($operations['action.custom']));
	$t->isTrue(isset($operations['relation.children.update']));
	Permission::flush();
})->tag('permission', 'coverage')->group('framework-coverage');
