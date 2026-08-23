<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\CoreKernelBootstrap;
use Dataphyre\Test\Context;
use Dataphyre\Test\Spy;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/Framework/CoreKernelBootstrap.php';

/** Provides named, inspectable host services for memory-limit scenarios. */
final class CoreBootstrapMemoryScenario {
	public Spy $debugbarEnabled;
	public Spy $applyDebugbar;
	public Spy $iniGet;
	public Spy $iniSet;
	public Spy $memoryUsage;
	public Spy $warn;
	public Spy $fail;

	public function __construct(private Context $test) {
		$this->debugbarEnabled=$test->spy()->willReturn(false);
		$this->applyDebugbar=$test->spy();
		$this->iniGet=$test->spy()->willReturn('128M');
		$this->iniSet=$test->spy()->willReturn(true);
		$this->memoryUsage=$test->spy()->willReturn(1);
		$this->warn=$test->spy();
		$this->fail=$test->spy();
	}

	/** @return array<string,mixed> */
	public function runtime(bool $debugbarAvailable=false): array {
		return [
			'debugbar_available'=>$debugbarAvailable,
			'debugbar_enabled'=>$this->debugbarEnabled,
			'apply_debugbar'=>$this->applyDebugbar,
			'ini_get'=>$this->iniGet,
			'ini_set'=>$this->iniSet,
			'memory_usage'=>$this->memoryUsage,
			'warn'=>$this->warn,
			'fail'=>$this->fail,
		];
	}
}

suite('Composable core kernel bootstrap')
	->contract('core.kernel-bootstrap', 1)
	->layer('unit')
	->risk('critical')
	->watches('module:core')
	->through('symbols', 'verification', 'session', 'memory', 'modules', 'request-lifecycle')
	->isolation('case')
	->tag('core', 'bootstrap', 'exact-coverage')
	->group('framework-coverage');

test('symbol constant version and platform guards report precise bootstrap failures', static function(Context $t): void {
	$fail=$t->spy();
	$t->same([], CoreKernelBootstrap::requireRootpaths(null, $fail));
	$fail->assertCalledWith($t, ['ROOTPATH constant not defined']);
	$t->same(['dataphyre'=>'/framework'], CoreKernelBootstrap::requireRootpaths(['dataphyre'=>'/framework'], $fail));

	$defined=$t->spy()->willReturn(true);
	$define=$t->spy()->willReturn(true);
	CoreKernelBootstrap::ensureConstant('READY', true, $defined, $define, $fail);
	$define->assertCalledTimes($t, 0);
	$defined->willReturn(false);
	CoreKernelBootstrap::ensureConstant('READY', true, $defined, $define, $fail);
	$define->assertCalledWith($t, ['READY', true]);
	$define->willReturn(false);
	CoreKernelBootstrap::ensureConstant('BROKEN', true, $defined, $define, $fail);
	$fail->assertCalledWith($t, ['Unable to assign BROKEN constant']);

	$functions=static fn(string $name): bool=>$name!=='missing_helper';
	$t->isFalse(CoreKernelBootstrap::validateSymbols(['present_helper','missing_helper'], $functions, static fn(): bool=>true, $fail));
	$fail->assertCalledWith($t, ['Dataphyre core helper functions failed to load: missing_helper']);
	$t->isFalse(CoreKernelBootstrap::validateSymbols(['present_helper'], $functions, static fn(): bool=>false, $fail));
	$fail->assertCalledWith($t, ['Dataphyre core class failed to load.']);
	$t->isTrue(CoreKernelBootstrap::validateSymbols(['present_helper'], $functions, static fn(): bool=>true, $fail));

	CoreKernelBootstrap::validateBootstrapVersion(null, '2.0', $fail);
	CoreKernelBootstrap::validateBootstrapVersion('', '2.0', $fail);
	CoreKernelBootstrap::validateBootstrapVersion('1.9', '2.0', $fail);
	CoreKernelBootstrap::validateBootstrapVersion('2.0', '2.0', $fail);
	$fail->assertCalledWith($t, ['Dataphyre Bootstrap version unknown']);
	$fail->assertCalledWith($t, ['Dataphyre Core is incompatible with Dataphyre Bootstrap version 1.9. Please update to 2.0']);

	$warn=$t->spy();
	CoreKernelBootstrap::validatePlatform(8, true, $warn, $fail);
	CoreKernelBootstrap::validatePlatform(4, false, $warn, $fail);
	CoreKernelBootstrap::validatePlatform(4, true, $warn, $fail);
	$warn->assertCalledTimes($t, 2);
	$fail->assertCalledWith($t, ['64-bit PHP build required in production.']);
});

test('verification run-mode key and configured-memory decisions are deterministic', static function(Context $t): void {
	$exists=$t->spy()->willReturnInOrder(false, true);
	$install=$t->spy()->willReturn(true);
	$clear=$t->spy();
	$t->isTrue(CoreKernelBootstrap::ensureVerified('/application/', 'serve', $exists, $install, $clear));
	$install->assertCalledWith($t, ['serve']);
	$clear->assertCalledWith($t, ['/application/cache/verified']);
	$alreadyExists=$t->spy()->willReturn(true);
	CoreKernelBootstrap::ensureVerified('/application', null, $alreadyExists, $install, $clear);
	$alreadyExists->assertCalledTimes($t, 2);

	$fail=$t->spy();
	$t->same('request', CoreKernelBootstrap::resolveRunMode(null, true, null, $fail));
	$t->same('diagnostic', CoreKernelBootstrap::resolveRunMode(null, false, 'write denied', $fail));
	$t->same('request', CoreKernelBootstrap::resolveRunMode('request', false, null, $fail));
	$t->same('scheduler-task', CoreKernelBootstrap::resolveRunMode('scheduler-task', false, null, $fail));
	$t->same('unit_test', CoreKernelBootstrap::resolveRunMode('unit_test', false, null, $fail));
	$fail->assertCalledWith($t, ['Dataphyre install must be verified or installed from the configured flight sheet. write denied']);
	$fail->assertCalledWith($t, ['Dataphyre install must be verified or installed from the configured flight sheet.']);
	$t->same(
		'Dataphyre install must be verified or installed from the configured flight sheet.',
		CoreKernelBootstrap::verificationFailure(' ')
	);

	CoreKernelBootstrap::validatePrivateKey('', $fail);
	CoreKernelBootstrap::validatePrivateKey('ready-key', $fail);
	$fail->assertCalledWith($t, ['Failed initializing DPVK']);
	$t->same('256M', CoreKernelBootstrap::configuredMemoryLimit('256M', ['max_execution_memory'=>'64M']));
	$t->same('64M', CoreKernelBootstrap::configuredMemoryLimit(false, ['max_execution_memory'=>'64M']));
	$t->same('16M', CoreKernelBootstrap::configuredMemoryLimit('', []));
});

test('session plans and configuration distinguish disabled active insecure diagnostic and request failures', static function(Context $t): void {
	$t->same([
		'enabled'=>false,
		'lifespan'=>'60',
		'name'=>'UNITSESSID',
		'secure'=>false,
	], CoreKernelBootstrap::sessionPlan(['core'=>['php_session'=>[
		'enabled'=>false,
		'lifespan'=>10,
		'cookie'=>['name'=>'UNITSESSID', 'secure'=>false],
	]]]));
	$t->same('120', CoreKernelBootstrap::sessionPlan(['core'=>['php_session'=>['cookie'=>['lifespan'=>120]]]])['lifespan']);
	$t->same('180', CoreKernelBootstrap::sessionPlan(['php_session_lifespan'=>180])['lifespan']);
	$t->same('900', CoreKernelBootstrap::sessionPlan([])['lifespan']);
	$t->same('application-bootstrap-only', CoreKernelBootstrap::sessionBootstrapMode('request','web',false,true));
	$t->same('application-release-preflight', CoreKernelBootstrap::sessionBootstrapMode('request','realtime',true,false));
	$t->same('managed-runtime-registration', CoreKernelBootstrap::sessionBootstrapMode('request','realtime',false,false));
	$t->same('managed-runtime-registration', CoreKernelBootstrap::sessionBootstrapMode('request','scheduler',false,false));
	$t->same('request', CoreKernelBootstrap::sessionBootstrapMode('request','web',false,false));
	$t->same('request', CoreKernelBootstrap::sessionBootstrapMode('request',null,false,false));

	$ini=$t->spy()->willReturn(true);
	$start=$t->spy()->willReturn(true);
	$fail=$t->spy();
	$warn=$t->spy();
	$unavailable=$t->spy();
	CoreKernelBootstrap::configureSession('unit_test', [], static fn(): int=>PHP_SESSION_NONE, $ini, $start, $fail, $warn, $unavailable);
	CoreKernelBootstrap::configureSession('managed-runtime-registration', [], static fn(): int=>PHP_SESSION_NONE, $ini, $start, $fail, $warn, $unavailable);
	CoreKernelBootstrap::configureSession('request', [], static fn(): int=>PHP_SESSION_ACTIVE, $ini, $start, $fail, $warn, $unavailable);
	CoreKernelBootstrap::configureSession('request', ['core'=>['php_session'=>['enabled'=>false]]], static fn(): int=>PHP_SESSION_NONE, $ini, $start, $fail, $warn, $unavailable);
	$ini->assertCalledTimes($t, 0);

	$status=$t->spy()->willReturnInOrder(PHP_SESSION_NONE, PHP_SESSION_NONE);
	CoreKernelBootstrap::configureSession('request', [], $status, $ini, $start, $fail, $warn, $unavailable);
	$ini->assertCalledTimes($t, 7);
	$start->assertCalledTimes($t, 1);

	$insecureSettings=[];
	CoreKernelBootstrap::configureSession(
		'request',
		['core'=>['php_session'=>['cookie'=>['secure'=>false]]]],
		$t->spy()->willReturnInOrder(PHP_SESSION_NONE, PHP_SESSION_NONE),
		static function(string $name, string $value) use (&$insecureSettings): bool {
			$insecureSettings[$name]=$value;
			return true;
		},
		$t->spy()->willReturn(true),
		$t->spy(),
		$t->spy(),
		$t->spy(),
	);
	$t->same('1',$insecureSettings['session.cookie_httponly'] ?? null);
	$t->same('Strict',$insecureSettings['session.cookie_samesite'] ?? null);
	$t->same('1',$insecureSettings['session.use_only_cookies'] ?? null);
	$t->same(false,array_key_exists('session.cookie_secure',$insecureSettings));

	$failedIni=$t->spy(static fn(string $name): bool=>$name!=='session.name');
	$failedStart=$t->spy()->willReturn(false);
	$status=$t->spy()->willReturnInOrder(PHP_SESSION_NONE, PHP_SESSION_NONE);
	CoreKernelBootstrap::configureSession('request', ['core'=>['php_session'=>['cookie'=>['secure'=>false]]]], $status, $failedIni, $failedStart, $fail, $warn, $unavailable);
	$fail->assertCalledWith($t, ['Failed to ini_set() session parameters']);
	$unavailable->assertCalledWith($t, ['DataphyreCore: Failed starting php session', 'safemode']);

	CoreKernelBootstrap::configureSession('diagnostic', [], static fn(): int=>PHP_SESSION_NONE, $t->spy()->willReturn(false), $start, $fail, $warn, $unavailable);
	$warn->assertCalledWith($t, ['DataphyreCore: Unable to apply PHP session ini parameters in diagnostic mode; continuing without session bootstrap changes.']);
});

test('memory conversion and application cover host limits debugbar elevation and every failure policy', static function(Context $t): void {
	$t->same(-1, CoreKernelBootstrap::memoryLimitToBytes(''));
	$t->same(-1, CoreKernelBootstrap::memoryLimitToBytes('-1'));
	$t->same(0, CoreKernelBootstrap::memoryLimitToBytes('invalid'));
	$t->same(2 * 1073741824, CoreKernelBootstrap::memoryLimitToBytes('2G'));
	$t->same(64 * 1048576, CoreKernelBootstrap::memoryLimitToBytes('64m'));
	$t->same(512 * 1024, CoreKernelBootstrap::memoryLimitToBytes('512K'));
	$t->same(128, CoreKernelBootstrap::memoryLimitToBytes('128'));

	$normal=new CoreBootstrapMemoryScenario($t);
	$t->same('64M', CoreKernelBootstrap::configureMemory('64M', $normal->runtime()));
	$normal->iniSet->assertCalledWith($t, ['memory_limit', '64M']);

	$elevated=new CoreBootstrapMemoryScenario($t);
	$elevated->debugbarEnabled->willReturn(true);
	$elevated->iniGet->willReturnInOrder('256M', '128M');
	$t->same('256M', CoreKernelBootstrap::configureMemory('64M', $elevated->runtime(true)));
	$elevated->applyDebugbar->assertCalledTimes($t, 2);

	$unlimited=new CoreBootstrapMemoryScenario($t);
	$unlimited->debugbarEnabled->willReturn(true);
	$unlimited->iniGet->willReturnInOrder('-1', '128M');
	$t->same('-1', CoreKernelBootstrap::configureMemory('64M', $unlimited->runtime(true)));

	$disabled=new CoreBootstrapMemoryScenario($t);
	$disabled->debugbarEnabled->willReturn(false);
	CoreKernelBootstrap::configureMemory('64M', $disabled->runtime(true));
	$disabled->applyDebugbar->assertCalledTimes($t, 0);

	$throwing=new CoreBootstrapMemoryScenario($t);
	$throwing->debugbarEnabled->willThrow(new RuntimeException('debugbar unavailable'));
	CoreKernelBootstrap::configureMemory('64M', $throwing->runtime(true));
	$throwing->debugbarEnabled->assertCalledTimes($t, 2);

	$belowUsage=new CoreBootstrapMemoryScenario($t);
	$belowUsage->memoryUsage->willReturn(128 * 1048576);
	CoreKernelBootstrap::configureMemory('64M', $belowUsage->runtime());
	$belowUsage->warn->assertCalledWith($t, ['DataphyreCore: Skipped lowering PHP memory_limit below current request usage.']);

	$unchangeableSafe=new CoreBootstrapMemoryScenario($t);
	$unchangeableSafe->iniGet->willReturn('-1');
	$unchangeableSafe->iniSet->willReturn(false);
	CoreKernelBootstrap::configureMemory('64M', $unchangeableSafe->runtime());
	$unchangeableSafe->warn->assertCalledWith($t, ['DataphyreCore: Unable to change PHP memory_limit; continuing with existing limit -1.']);

	$unchangeableHigher=new CoreBootstrapMemoryScenario($t);
	$unchangeableHigher->iniGet->willReturn('128M');
	$unchangeableHigher->iniSet->willReturn(false);
	CoreKernelBootstrap::configureMemory('64M', $unchangeableHigher->runtime());
	$unchangeableHigher->warn->assertCalled($t);

	$unchangeableUnsafe=new CoreBootstrapMemoryScenario($t);
	$unchangeableUnsafe->iniGet->willReturn('32M');
	$unchangeableUnsafe->iniSet->willReturn(false);
	CoreKernelBootstrap::configureMemory('64M', $unchangeableUnsafe->runtime());
	$unchangeableUnsafe->fail->assertCalledWith($t, ['Failed to ini_set() memory_limit']);
});

test('execution module request and diagnostic lifecycle methods expose ordered side effects', static function(Context $t): void {
	$fail=$t->spy();
	CoreKernelBootstrap::configureExecutionTime(30, $t->spy()->willReturn(true), $fail);
	CoreKernelBootstrap::configureExecutionTime(30, $t->spy()->willReturn(false), $fail);
	$fail->assertCalledWith($t, ['Failed to ini_set() max_execution_time']);
	CoreKernelBootstrap::configureTimezone('UTC', $t->spy()->willReturn(true), $fail);
	CoreKernelBootstrap::configureTimezone('Mars/Olympus', $t->spy()->willReturn(false), $fail);
	$fail->assertCalledWith($t, ['Invalid timezone: Mars/Olympus']);

	$t->same([], CoreKernelBootstrap::modulesForRunMode('diagnostic'));
	$t->contains('localization', CoreKernelBootstrap::modulesForRunMode('unit_test'));
	$requestModules=CoreKernelBootstrap::modulesForRunMode('request');
	$t->contains('async', $requestModules);
	$t->contains('fraudar', $requestModules);
	$load=$t->spy();
	CoreKernelBootstrap::loadModules('unit_test', static fn(string $module): mixed=>match($module){
		'tracelog'=>['/modules/tracelog.php'],
		'cache'=>null,
		'sql'=>[],
		'datadoc'=>['/modules/datadoc.php'],
		default=>false,
	}, $load);
	$load->assertCalledWith($t, ['/modules/tracelog.php', false]);
	$load->assertCalledWith($t, ['/modules/datadoc.php', true]);

	$getLoad=$t->spy();
	$checkLock=$t->spy();
	$unavailable=$t->spy();
	CoreKernelBootstrap::prepareRequest('unit_test', $getLoad, $checkLock, static fn(): int=>PHP_SESSION_NONE, static fn(): int=>5, $unavailable);
	$getLoad->assertCalledTimes($t, 0);
	CoreKernelBootstrap::prepareRequest('request', $getLoad, $checkLock, static fn(): int=>PHP_SESSION_ACTIVE, static fn(): int=>5, $unavailable);
	CoreKernelBootstrap::prepareRequest('request', $getLoad, $checkLock, static fn(): int=>PHP_SESSION_NONE, static fn(): int=>4, $unavailable);
	CoreKernelBootstrap::prepareRequest('request', $getLoad, $checkLock, static fn(): int=>PHP_SESSION_NONE, static fn(): int=>5, $unavailable);
	$getLoad->assertCalledTimes($t, 3);
	$checkLock->assertCalledTimes($t, 3);
	$unavailable->assertCalledWith($t, ['Load shedding as visitor had no session and server load level is above 5', 'loadlevel']);

	$disabledLoad=$t->spy();
	$disabledLock=$t->spy();
	$disabledUnavailable=$t->spy();
	CoreKernelBootstrap::prepareRequest('request', $disabledLoad, $disabledLock, static fn(): int=>PHP_SESSION_NONE, static fn(): int=>5, $disabledUnavailable, false);
	$disabledLoad->assertCalledTimes($t, 0);
	$disabledLock->assertCalledTimes($t, 0);
	$disabledUnavailable->assertCalledTimes($t, 0);
	$t->same(true, CoreKernelBootstrap::loadSheddingEnabled([]));
	$t->same(true, CoreKernelBootstrap::loadSheddingEnabled(['core'=>['load_shedding'=>['enabled'=>true]]]));
	$t->same(false, CoreKernelBootstrap::loadSheddingEnabled(['core'=>['load_shedding'=>['enabled'=>false]]]));
	$t->same(true, CoreKernelBootstrap::loadSheddingEnabled(['core'=>['load_shedding'=>'invalid']]));

	$loadDiagnostic=$t->spy();
	$runDiagnostic=$t->spy();
	CoreKernelBootstrap::runDiagnostic('request', $loadDiagnostic, $runDiagnostic);
	CoreKernelBootstrap::runDiagnostic('diagnostic', $loadDiagnostic, $runDiagnostic);
	$loadDiagnostic->assertCalledTimes($t, 1);
	$runDiagnostic->assertCalledTimes($t, 1);
	$headers=$t->spy();
	CoreKernelBootstrap::finishRequest('unit_test', $headers);
	CoreKernelBootstrap::finishRequest('request', $headers);
	$headers->assertCalledTimes($t, 1);
});
