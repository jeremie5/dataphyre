<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelPackageApplyResult;
use Dataphyre\Panel\PanelPackageInstallPlan;
use Dataphyre\Panel\PanelPackageRollbackPlan;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel package rollback plan derives every install action and metadata branch',static function(Context $t): void {
	$plan=PanelPackageRollbackPlan::make([
		'package'=>['id'=>'deep-package'],'ready'=>true,
		'steps'=>[
			'invalid',
			['action'=>'create','target'=>'new.php','bytes'=>10],
			['action'=>'replace','target'=>'existing.php','bytes'=>20],
			['action'=>'skip','target'=>'kept.php'],
			['action'=>'conflict','target'=>'blocked.php'],
			['action'=>'custom','target'=>'custom.php'],
		],
	],['source'=>'constructor']);
	$t->same($plan,$plan->meta(['batch'=>'nightly']));
	$t->same($plan,$plan->meta(' operator ','Ada'));
	$t->same($plan,$plan->meta(' ',123));
	$manifest=$plan->manifest(['source'=>'call']);
	$t->same('panel_package_rollback_plan',$manifest['type']);
	$t->same('deep-package',$manifest['package']);
	$t->isFalse($manifest['ready']);
	$t->isTrue($manifest['blocked']);
	$t->isTrue($manifest['install_ready']);
	$t->same(['steps'=>5,'snapshots'=>1,'restores'=>1,'deletes'=>1,'leaves'=>2,'blocked'=>1],$manifest['summary']);
	$t->same('delete',$manifest['steps'][0]['action']);
	$t->same('restore',$manifest['steps'][1]['action']);
	$t->isTrue($manifest['steps'][1]['requires_snapshot']);
	$t->notEmpty($manifest['steps'][1]['snapshot_key']);
	$t->same('leave',$manifest['steps'][2]['action']);
	$t->same('blocked',$manifest['steps'][3]['action']);
	$t->same('leave',$manifest['steps'][4]['action']);
	$t->same('call',$manifest['meta']['source']);
	$t->same('Ada',$manifest['meta']['operator']);
	$t->same($plan->toArray(),$plan->jsonSerialize());
})->tag('panel','package','rollback-plan','coverage')->group('framework-coverage');

test('panel package rollback plan derives concrete restore delete leave and blocked apply results',static function(Context $t): void {
	$plan=PanelPackageRollbackPlan::make([
		'type'=>'panel_package_apply_result','ok'=>false,'package'=>['id'=>'applied-package'],
		'backups'=>[
			'invalid',
			['target'=>''],
			['target'=>'restore.php','backup'=>'/snapshots/restore.php'],
		],
		'written'=>[
			'invalid',
			['target'=>'restore.php','action'=>'replace'],
			['target'=>'created.php'],
		],
		'skipped'=>['invalid',['target'=>'kept.php']],
		'blocked'=>['invalid',['target'=>'blocked.php']],
	],['source'=>'array']);
	$manifest=$plan->manifest(['run'=>'one']);
	$t->same('apply_result',$manifest['source']);
	$t->same('applied-package',$manifest['package']);
	$t->isFalse($manifest['ready']);
	$t->isTrue($manifest['blocked']);
	$t->isFalse($manifest['install_ready']);
	$t->same(['steps'=>4,'snapshots'=>1,'restores'=>1,'deletes'=>1,'leaves'=>1,'blocked'=>1],$manifest['summary']);
	$t->same('restore',$manifest['steps'][0]['action']);
	$t->same('/snapshots/restore.php',$manifest['steps'][0]['backup']);
	$t->same('delete',$manifest['steps'][1]['action']);
	$t->same('write',$manifest['steps'][1]['install_action']);
	$t->same('leave',$manifest['steps'][2]['action']);
	$t->same('blocked',$manifest['steps'][3]['action']);
	$t->same('blocked',$manifest['steps'][3]['install_action']);
	$t->contains('blocked work',$manifest['steps'][3]['reason']);
	$t->same('one',$manifest['meta']['run']);
})->tag('panel','package','rollback-plan','coverage')->group('framework-coverage');

test('panel package rollback plan accepts apply result and install plan value objects',static function(Context $t): void {
	$result=PanelPackageApplyResult::make([
		'ok'=>true,'package'=>['id'=>'result-package'],'written'=>[['target'=>'new.php']],
	]);
	$fromResult=PanelPackageRollbackPlan::fromApplyResult($result)->manifest();
	$t->isTrue($fromResult['install_ready']);
	$t->same('result-package',$fromResult['package']);
	$t->same('delete',$fromResult['steps'][0]['action']);

	$template=PanelPackageTemplate::make('install-package')->plugin(false)->provider(false)->theme(false)->docs(false)->tests(false)->with('marketplace',false);
	$template->file('src/Example.php','<?php return true;');
	$install=PanelPackageInstallPlan::make($template,'/tmp/panel-package-target',['overwrite'=>true]);
	$rollback=new PanelPackageRollbackPlan($install,['source'=>'install-object']);
	$t->same('panel_package_rollback_plan',$rollback->manifest()['type']);
	$t->same('install-object',$rollback->manifest()['meta']['source']);
})->tag('panel','package','rollback-plan','coverage')->group('framework-coverage');
