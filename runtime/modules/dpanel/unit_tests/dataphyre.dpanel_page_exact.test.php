<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_DPANEL_PAGE_NO_DISPATCH')){
	define('DATAPHYRE_DPANEL_PAGE_NO_DISPATCH', true);
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
require_once dirname(__DIR__).'/kernel/panel.php';

suite('Dpanel browser report')
	->contract('dpanel.browser-report', 1)
	->layer('integration')
	->risk('medium')
	->watches('module:dpanel')
	->through('scan-boundary', 'finding-normalization', 'trace-evidence', 'escaping')
	->isolation('case')
	->tag('dpanel', 'exact-coverage', 'page')
	->group('framework-coverage');

test('page bootstrap leaves discovery quiet and scans each unique module root on demand', static function(Context $t): void {
	$t->same('', dataphyre_dpanel_page::bootstrap());
	$diagnose=$t->spy();
	$verbose=$t->spy()->willReturn([['type'=>'warning','module'=>'sql','message'=>'Check storage','level'=>'warning']]);
	$html=dataphyre_dpanel_page::bootstrap(true, [
		'post'=>['dataphyre_full'=>'1'],
		'module_paths'=>['/framework/modules','/framework/modules','/application/modules'],
		'diagnose'=>$diagnose,
		'verbose'=>$verbose,
		'app'=>'catalog',
	]);
	$diagnose->assertCalledTimes($t, 2);
	$verbose->assertCalledTimes($t, 1);
	$t->contains('Check storage', $html);
	$t->contains('Diagnose Catalog', $html);
});

test('page render distinguishes idle clean regular exception and nested trace evidence', static function(Context $t): void {
	$idle=dataphyre_dpanel_page::render([], 'application', false);
	$t->contains('Choose a scan below', $idle);
	$t->contains('No diagnostic findings were reported.', dataphyre_dpanel_page::render([], 'application', true));
	$exception=new RuntimeException('Exploded <unsafe>');
	$html=dataphyre_dpanel_page::render([
		['type'=>'info','file'=>'/srv/module.php','message'=>"Line one\nLine two"],
		['type'=>'warning','module'=>'cache','warning_string'=>'Cache warning','level'=>'warning'],
		['type'=>'fatal','reason'=>'Fatal reason','level'=>'fatal'],
		['type'=>'notice'],
		['type'=>'php_exception','exception'=>$exception],
		['type'=>'tracelog','tracelog'=>[
			['type'=>'info','file'=>'/srv/a.php','line'=>10,'class'=>'A','function'=>'one','message'=>'started'],
			['type'=>'fatal','file'=>'/srv/b.php','line'=>20,'class'=>'B','function'=>'two','message'=>'failed'],
			'ignored',
		]],
	], 'shop <one>', true);
	$t->contains('Line one<br />', $html);
	$t->contains('No message provided', $html);
	$t->contains('Exploded &lt;unsafe&gt;', $html);
	$t->contains('3 trace entries (1 info, 1 fatal)', $html);
	$t->contains('level-fatal', $html);
	$t->contains('Shop &lt;one&gt;', $html);
});

test('page private normalization keeps unknown levels and absent root maps deterministic', static function(Context $t): void {
	$internals=$t->nonPublic(dataphyre_dpanel_page::class);
	$t->same('info', $internals->invoke('level', 'unknown'));
	$t->same(3, $internals->invoke('severity', 'fatal'));
	$t->same([], $internals->invoke('modulePaths', []));
	$t->same(['/srv/common/modules','/srv/project/modules'], $internals->invoke('modulePaths', [
		'common_dataphyre'=>'/srv/common/',
		'dataphyre'=>'/srv/project',
	]));
	$t->contains('body{', $internals->invoke('styles'));
});
