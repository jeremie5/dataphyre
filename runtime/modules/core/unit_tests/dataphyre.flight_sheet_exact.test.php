<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/flight_sheet.php';

/** Owns flight-sheet roots, plans, and private installer boundary probes. */
final class CoreFlightSheetScenario {
	private TempWorkspace $workspace;
	private NonPublicAccess $internals;
	private string $appRoot;
	private string $commonRoot;

	public function __construct(Context $test) {
		$this->workspace=$test->workspace('core-flight-sheet');
		$this->internals=$test->nonPublic(\dataphyre\flight_sheet::class);
		$this->appRoot=$this->workspace->directory('application');
		$this->commonRoot=$this->workspace->directory('common');
	}

	/** @return array<string,mixed> */
	public function runtime(array $overrides=[]): array {
		return [
			'app_root'=>$this->appRoot,
			'common_root'=>$this->commonRoot,
			'sheet_path'=>$this->workspace->path('flight_sheet.php'),
			'clock'=>static fn(): int=>1_700_000_000,
			'random_bytes'=>static fn(int $bytes): string=>str_repeat("\xA5", $bytes),
			...$overrides,
		];
	}

	/** @param array<string,mixed> $plan @return array<string,mixed> */
	public function committedRuntime(array $plan, array $overrides=[]): array {
		$path=$this->workspace->file('plans/'.bin2hex(random_bytes(5)).'.php', '<?php return '.var_export($plan, true).';');
		return $this->runtime(['sheet_path'=>$path, ...$overrides]);
	}

	/** @return array<string,mixed> */
	public function directRuntime(mixed $sheet, array $overrides=[]): array {
		return $this->runtime(['sheet'=>$sheet, ...$overrides]);
	}

	public function appPath(string $path=''): string { return $this->appRoot.($path==='' ? '' : '/'.$path); }
	public function commonPath(string $path=''): string { return $this->commonRoot.($path==='' ? '' : '/'.$path); }
	public function file(string $path, string $contents): string { return $this->workspace->file($path, $contents); }
	public function directory(string $path): string { return $this->workspace->directory($path); }
	public function path(string $path): string { return $this->workspace->path($path); }
	public function invoke(string $method, mixed ...$arguments): mixed { return $this->internals->invoke($method, ...$arguments); }
}

suite('Core flight-sheet installer')
	->contract('core.flight-sheet', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:core')
	->through('plan-loading', 'target-merge', 'idempotent-files', 'keys', 'verification', 'failures')
	->isolation('case')
	->tag('core', 'flight-sheet', 'exact-coverage')
	->group('framework-coverage');

test('installer rejects absent roots and empty or malformed plans without side effects', static function(Context $t): void {
	$scenario=new CoreFlightSheetScenario($t);
	$t->isFalse(\dataphyre\flight_sheet::install(runtime:$scenario->runtime(['app_root'=>''])));
	$t->isFalse(\dataphyre\flight_sheet::install(runtime:$scenario->directRuntime('not-an-array')));
	$nonArrayPath=$scenario->file('plans/non-array.php', "<?php return 'not-an-array';");
	$t->isFalse(\dataphyre\flight_sheet::install(runtime:$scenario->runtime(['sheet_path'=>$nonArrayPath])));
	$t->isFalse(\dataphyre\flight_sheet::install(runtime:$scenario->directRuntime([])));
	$t->isFalse(\dataphyre\flight_sheet::install(runtime:$scenario->directRuntime(['install'=>[]])));
	$t->same(null, \dataphyre\flight_sheet::last_error());
});

test('declarative plans install shared and app artifacts once with named application overrides', static function(Context $t): void {
	$scenario=new CoreFlightSheetScenario($t);
	$source=$scenario->file('sources/copied.txt', 'copied');
	$scenario->file('common/config/static/dpvk', 'shared-key');
	$plan=['install'=>[
		'shared'=>[
			'directories'=>['', 'shared/cache'],
			'files'=>[
				'invalid-entry',
				[],
				['path'=>'shared/info.txt', 'contents'=>'shared'],
				['path'=>'shared/ignored.txt', 'type'=>'future-type'],
			],
		],
		'app'=>[
			'directories'=>['cache', 'config'],
			'files'=>[
				['path'=>'cache/verified', 'type'=>'generated_verified'],
				['path'=>'config/dpvk', 'type'=>'generated_dpvk'],
				['path'=>'config/app.txt', 'type'=>'literal', 'contents'=>'application'],
				['path'=>'config/copied.txt', 'type'=>'copy_if_missing', 'source'=>$source],
				['path'=>'config/missing.txt', 'type'=>'copy_if_missing', 'source'=>$scenario->path('sources/missing.txt')],
			],
		],
		'applications'=>['alpha'=>['directories'=>['cache', 'config', 'alpha']]],
	]];
	$runtime=$scenario->committedRuntime($plan);

	$t->isTrue(\dataphyre\flight_sheet::install('alpha', $runtime));
	$t->same('shared', file_get_contents($scenario->commonPath('shared/info.txt')));
	$t->same('shared-key', file_get_contents($scenario->appPath('config/dpvk')));
	$t->same('application', file_get_contents($scenario->appPath('config/app.txt')));
	$t->same('copied', file_get_contents($scenario->appPath('config/copied.txt')));
	$t->isFalse(file_exists($scenario->appPath('config/missing.txt')));
	$t->isTrue(is_dir($scenario->appPath('alpha')));
	$marker=json_decode((string)file_get_contents($scenario->appPath('cache/verified')), true, 512, JSON_THROW_ON_ERROR);
	$t->hasPathValues(['verified_at'=>'2023-11-14T22:13:20+00:00', 'app'=>'alpha'], $marker);

	$t->isTrue(\dataphyre\flight_sheet::install('alpha', $runtime));
	$t->same('application', file_get_contents($scenario->appPath('config/app.txt')));
	\dataphyre\flight_sheet::forget($runtime['sheet_path']);
	\dataphyre\flight_sheet::forget();
});

test('direct plans generate local entropy and default-time verification markers', static function(Context $t): void {
	$scenario=new CoreFlightSheetScenario($t);
	$runtime=$scenario->directRuntime(['install'=>['app'=>['files'=>[
		['path'=>'cache/verified', 'type'=>'generated_verified'],
		['path'=>'config/dpvk', 'type'=>'generated_dpvk'],
	]]]], [
		'common_root'=>$scenario->directory('empty-common'),
		'clock'=>null,
		'random_bytes'=>null,
	]);
	$t->isTrue(\dataphyre\flight_sheet::install(null, $runtime));
	$t->matches('/^[a-f0-9]{128}$/', (string)file_get_contents($scenario->appPath('config/dpvk')));
	$marker=json_decode((string)file_get_contents($scenario->appPath('cache/verified')), true, 512, JSON_THROW_ON_ERROR);
	$t->same(null, $marker['app']);
	$t->matches('/^\d{4}-\d{2}-\d{2}T/', $marker['verified_at']);
});

test('filesystem failures are captured with actionable directory write and copy diagnostics', static function(Context $t): void {
	$scenario=new CoreFlightSheetScenario($t);
	$existing=$scenario->directory('existing-directory');
	$scenario->invoke('create_directory', $existing);
	$blocker=$scenario->file('blocker', 'file');
	$t->throwsLike(
		static fn()=>$scenario->invoke('create_directory', $blocker.'/child'),
		RuntimeException::class,
		'Failed creating directory'
	);
	$directoryTarget=$scenario->directory('cannot-be-file');
	$t->throwsLike(
		static fn()=>$scenario->invoke('write_file_if_missing', $directoryTarget, 'content'),
		RuntimeException::class,
		'Failed writing file'
	);

	$copySource=$scenario->file('copy-source.txt', 'copy');
	$copyTarget=$scenario->directory('copy-target-directory');
	$t->throwsLike(
		static fn()=>$scenario->invoke('apply_target', [
			'files'=>[['path'=>'copy-target-directory', 'type'=>'copy_if_missing', 'source'=>$copySource]],
		], dirname($copyTarget), null, $scenario->runtime()),
		RuntimeException::class,
		'Failed copying file'
	);

	$blockedApp=$scenario->file('blocked-app-root', 'not-a-directory');
	$t->isFalse(\dataphyre\flight_sheet::install(runtime:$scenario->directRuntime([
		'install'=>['app'=>['files'=>[['path'=>'cache/verified', 'type'=>'generated_verified']]]],
	], ['app_root'=>$blockedApp])));
	$t->contains('Failed creating directory', (string)\dataphyre\flight_sheet::last_error());
});

test('root and path resolution honors explicit observations before process constants', static function(Context $t): void {
	$scenario=new CoreFlightSheetScenario($t);
	$runtime=$scenario->runtime([
		'app_root'=>null,
		'common_root'=>null,
		'rootpaths'=>[
			'dataphyre'=>$scenario->appPath(),
			'common_dataphyre'=>$scenario->commonPath(),
		],
		'project_root'=>$scenario->path('project'),
	]);
	unset($runtime['app_root'], $runtime['common_root'], $runtime['sheet_path']);
	$t->same($scenario->appPath(), $scenario->invoke('app_root', $runtime));
	$t->same($scenario->commonPath().'/', $scenario->invoke('install_root', $runtime));
	$t->same($scenario->path('project/flight_sheet.php'), $scenario->invoke('path', $runtime));
	$t->same(
		rtrim(dirname(__DIR__, 4), '/\\').'/',
		$scenario->invoke('install_root', ['rootpaths'=>[]])
	);
	$t->same(
		$scenario->commonPath().'/flight_sheet.php',
		$scenario->invoke('path', ['common_root'=>$scenario->commonPath(), 'project_root'=>''])
	);
	if(!defined('DATAPHYRE_PROJECT_ROOT')){
		define('DATAPHYRE_PROJECT_ROOT', $scenario->path('constant-project'));
	}
	$t->same(
		rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\').'/flight_sheet.php',
		$scenario->invoke('path', ['rootpaths'=>[]])
	);
});
