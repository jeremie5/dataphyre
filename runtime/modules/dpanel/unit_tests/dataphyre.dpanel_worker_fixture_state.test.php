<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/WorkerFixtureState.php';

final class DpWorkerFixtureStatePrivateContract {
	private static string $staticValue='before';
	private string $instanceValue='before';

	private static function staticJoin(string $left,string $right): string {
		return $left.'-'.$right;
	}

	private function instanceJoin(string $left,string $right): string {
		return $left.':'.$right;
	}

	public static function staticValue(): string { return self::$staticValue; }
	public function instanceValue(): string { return $this->instanceValue; }
}

test('dpanel worker fixture state owns SQL behavior and non-public invocation',static function(Context $t): void {
	$state=dataphyre_dpanel_worker_fixture_state::class;
	$state::installDeterministicCoreConfig();
	$state::installDeterministicCoreConfig();
	$t->same(['dataphyre-dpanel-worker-unit-test-key'],DP_CORE_CFG['private_key'] ?? null);
	$state::resetSql();
	$t->same(0,$state::sqlCallCount());

	$t->same('default',$state::dispatchSql('select',['first'],'default'));
	$state::returnFromSql('select',['id'=>7]);
	$t->same(['id'=>7],$state::dispatchSql('SELECT',['second']));
	$state::respondToSql('select',static fn(string $value): string=>strtoupper($value));
	$t->same('THIRD',$state::dispatchSql('select',['third']));
	$state::clearSqlResponse('select');
	$t->same(false,$state::dispatchSql('select',['fourth']));

	$t->count(4,$state::sqlCalls('select'));
	$t->same(['first'],$state::sqlCall('select'));
	$t->same([],$state::sqlCall('select',99));
	$t->same(4,$state::sqlCallCount('select'));
	$t->same(4,$state::sqlCallCount());
	$t->throws(static fn()=>$state::sqlCalls('drop'),InvalidArgumentException::class);

	$t->same(
		'left-right',
		$state::invokeNonPublic(DpWorkerFixtureStatePrivateContract::class,'staticJoin',['left','right'])
	);
	$t->same(
		'left:right',
		$state::invokeNonPublic(new DpWorkerFixtureStatePrivateContract(),'instanceJoin',['left','right'])
	);
	$t->isTrue($state::inventory(DpWorkerFixtureStatePrivateContract::class)->methodShape('staticJoin')['static']);
	$state::replaceNonPublicProperties(DpWorkerFixtureStatePrivateContract::class,['staticValue'=>'after']);
	$t->same('after',DpWorkerFixtureStatePrivateContract::staticValue());
	$contract=new DpWorkerFixtureStatePrivateContract();
	$state::replaceNonPublicProperties($contract,['instanceValue'=>'after']);
	$t->same('after',$contract->instanceValue());
})->tag('dpanel','worker','fixture-state')->group('framework-coverage');

test('dpanel worker application state gives explicit ownership to request and global contracts',static function(Context $t): void {
	$t->setGlobalsForTest([
		'_SERVER'=>[],
		'_GET'=>[],
		'_COOKIE'=>[],
		'_SESSION'=>[],
	]);
	$t->global('dp_worker_contract')->unsetValue();
	$t->global('userid')->unsetValue();
	$state=dataphyre_dpanel_worker_application_state::class;

	$state::serverAddress('127.0.0.2');
	$state::remoteAddress('198.51.100.4');
	$state::requestUri('/worker/contract');
	$t->same('127.0.0.2',$state::serverValue('SERVER_ADDR'));
	$t->same('198.51.100.4',$state::serverValue('REMOTE_ADDR'));
	$t->same('/worker/contract',$state::serverValue('REQUEST_URI'));
	$t->isTrue($state::hasServer('REQUEST_URI'));
	$t->same('fallback',$state::serverValue('MISSING','fallback'));
	$state::forgetServer('REQUEST_URI');
	$t->isFalse($state::hasServer('REQUEST_URI'));
	$state::replaceServer(['WHOLE'=>'server']);
	$t->same(['WHOLE'=>'server'],$state::server());
	$t->throws(static fn()=>$state::serverValue(' '),InvalidArgumentException::class);

	$state::replaceQuery(['page'=>2]);
	$t->same(['page'=>2],$state::query());
	$state::replaceCookies(['keep'=>'yes']);
	$state::putCookie('added','ok');
	$t->same(['keep'=>'yes','added'=>'ok'],$state::cookies());
	$state::forgetCookie('added');
	$t->same(['keep'=>'yes'],$state::cookies());

	$state::replaceSession(['keep'=>'yes']);
	$t->same(['keep'=>'yes'],$state::session());
	$t->isTrue($state::sessionHas('keep'));
	$t->same('yes',$state::sessionValue('keep'));
	$t->same('fallback',$state::sessionValue('missing','fallback'));
	$state::putSession('added','ok');
	$t->same('ok',$state::sessionValue('added'));
	$state::forgetSession('added');
	$t->isFalse($state::sessionHas('added'));

	$t->isFalse($state::hasGlobal('dp_worker_contract'));
	$t->same('fallback',$state::globalValue('dp_worker_contract','fallback'));
	$state::replaceGlobal('dp_worker_contract','value');
	$t->isTrue($state::hasGlobal('dp_worker_contract'));
	$t->same('value',$state::globalValue('dp_worker_contract'));
	$state::forgetGlobal('dp_worker_contract');
	$t->isFalse($state::hasGlobal('dp_worker_contract'));
	$state::authenticatedUserId(42);
	$t->same(42,$state::globalValue('userid'));
	$state::forgetAuthenticatedUserId();
	$t->isFalse($state::hasGlobal('userid'));
	$t->throws(static fn()=>$state::replaceGlobal('not valid','value'),InvalidArgumentException::class);
})->tag('dpanel','worker','application-state')->group('framework-coverage');

test('dpanel worker workspace owns standalone JSON fixture files and cleanup',static function(Context $t): void {
	$workspaceClass=dataphyre_dpanel_worker_workspace::class;
	$t->throws(static fn()=>$workspaceClass::active(),RuntimeException::class);
	$base=$t->workspace('dpanel-worker-workspace-base');
	$workspace=$workspaceClass::activate(' fixture files ',$base->root());
	$t->isTrue($workspace===$workspaceClass::activate('ignored',$base->root()));
	$t->isTrue($workspace===$workspaceClass::active());
	$t->contains('dataphyre-dpanel-fixture-files-',$workspace->root());
	$t->same($workspace->root(),$workspace->path('./'));
	$t->same($workspace->root().'/result.json',$workspace->path('nested/../result.json'));
	$t->throws(static fn()=>$workspace->path('../escape'),InvalidArgumentException::class);
	$t->throws(static fn()=>$workspace->path('/absolute'),InvalidArgumentException::class);
	$t->throws(static fn()=>$workspace->path("bad\0path"),InvalidArgumentException::class);

	$t->same($workspace->root().'/fixtures',$workspace->directory('fixtures'));
	$file=$workspace->file('fixtures/data.json','{"ok":true}');
	$t->same('{"ok":true}',file_get_contents($file));
	$t->isTrue($workspace->removeFile('fixtures/data.json'));
	$t->isTrue($workspace->removeFile('fixtures/data.json'));
	$file=$workspace->file('fixtures/data.json','{"ok":true}');
	$workspace->file('blocked','file');
	$t->throws(static fn()=>$workspace->directory('blocked/child'),RuntimeException::class);
	$workspace->directory('collision');
	$t->throws(static fn()=>$workspace->file('collision','no'),RuntimeException::class);

	$workspaceClass::cleanupActive();
	$t->isFalse(is_dir($workspace->root()));
	$t->throws(static fn()=>$workspaceClass::active(),RuntimeException::class);
	$workspace->cleanup();
	$workspaceClass::cleanupActive();

	$invalidBase=$t->tempFile('not-a-directory','dpanel-worker-invalid-base');
	$t->throws(static fn()=>$workspaceClass::activate('invalid',$invalidBase),RuntimeException::class);
	$fallback=$workspaceClass::activate(' !!! ',$base->root());
	$t->contains('dataphyre-dpanel-worker-',$fallback->root());
	$fallback->cleanup();
})->tag('dpanel','worker','workspace')->group('framework-coverage');
