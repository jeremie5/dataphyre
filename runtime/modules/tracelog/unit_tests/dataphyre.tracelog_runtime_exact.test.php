<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/tracelog_runtime_test_helpers.php';

suite('Tracelog deterministic runtime contract')
	->contract('tracelog.runtime', 1)
	->layer('integration')
	->risk('high')
	->watches('module:tracelog')
	->through('bootstrap', 'deferred-events', 'formatting', 'errors', 'handoff', 'buffer', 'shutdown')
	->isolation('case')
	->tag('tracelog', 'runtime', 'exact-coverage')
	->group('framework-coverage');

test('module bootstrap registers configuration schema request identity shutdown and optional diagnostics', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$trace=$t->spy()->willReturn(null);
	$config=$t->spy()->willReturn(null);
	$table=$t->spy()->willReturn(null);
	$heisen=$t->spy()->willReturn(null);
	$shutdown=$t->spy()->willReturn(null);
	$disabled=\dataphyre\tracelog_bootstrap(true, [
		'trace'=>$trace,
		'define_config'=>$config,
		'define_table'=>$table,
		'heisenconstant'=>$heisen,
		'register_shutdown'=>$shutdown,
		'config'=>['enable_tracelog'=>false],
		'enabled'=>false,
	]);
	$t->same(['enabled'=>false,'plotting'=>false,'diagnostic'=>false], $disabled);
	$trace->assertCalledTimes($t, 1);
	$config->assertCalledTimes($t, 1);
	$table->assertCalledTimes($t, 1);
	$heisen->assertCalledWith($t, ['TRID','dataphyre\\tracelog_request_id']);
	$shutdown->assertCalledWith($t, [[\dataphyre\tracelog::class,'shutdown']]);

	$scenario->reset();
	$diagnostic=$t->spy()->willReturn(null);
	$enabled=\dataphyre\tracelog_bootstrap(true, [
		'trace'=>$trace,
		'define_config'=>$config,
		'define_table'=>$table,
		'heisenconstant'=>$heisen,
		'register_shutdown'=>$shutdown,
		'enabled'=>true,
		'plotting'=>true,
		'run_mode'=>'diagnostic',
		'diagnostic'=>$diagnostic,
		'tracelog_runtime'=>$scenario->runtime(),
	]);
	$t->same(['enabled'=>true,'plotting'=>true,'diagnostic'=>true], $enabled);
	$t->isTrue(\dataphyre\tracelog::$constructed);
	$t->count(1, $scenario->installedHandlers());
	$diagnostic->assertCalledTimes($t, 1);
	$t->type('string', \dataphyre\tracelog_request_id());
	$t->same(['enabled'=>false,'plotting'=>false,'diagnostic'=>false], \dataphyre\tracelog_bootstrap(false));
	$t->throws(static fn()=>\dataphyre\tracelog_bootstrap(true, ['register_shutdown'=>'invalid']), LogicException::class);
});

test('disabled suppressed deferred and retroactive events make their acceptance policy explicit', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$t->isFalse($scenario->trace('disabled'));
	$scenario->activate(true);
	$t->isTrue($scenario->trace('deferred', arguments:'scalar'));
	$t->count(1, $scenario->retroactive());

	$initial=(int)(\dataphyre\tracelog::runtimeState()['initial_memory'] ?? 0);
	$scenario->configure(['retroactive'=>[
		'<br>raw bootstrap trace',
		['/srv/early.php','8','Early','boot','early event','warning',[],1000.1,$initial+120,null],
	]])->activate(false);
	\dataphyre\tracelog::set_plotting(true);
	\dataphyre\tracelog::process_retroactive();
	$t->contains('raw bootstrap trace', $scenario->traceBuffer());
	$t->contains('early event', $scenario->traceBuffer());
	$t->isTrue(is_file($scenario->plottingPath()));
	$t->same([], $scenario->retroactive());

	$scenario->configure(['retroactive'=>['discarded'], 'suppressed'=>true]);
	\dataphyre\tracelog::process_retroactive();
	$t->same([], $scenario->retroactive());
	$t->isFalse($scenario->trace('suppressed'));

	$scenario->reset(['retroactive'=>['ignored while disabled']]);
	\dataphyre\tracelog::process_retroactive();
	$t->same('', $scenario->traceBuffer());
	$t->same([], $scenario->retroactive());
	$t->count(1, $scenario->deferWithMalformedRuntimeBuffer('normalized'));
	$t->count(1, $scenario->deferThroughProcessBuffer('legacy-compatible'));
	$t->isTrue($scenario->isSuppressedByRuntimePolicy('dynamic suppression'));
	$t->isTrue($scenario->isSuppressedByProcessPolicy('framework suppression'));
});

test('active event formatting names argument kinds severities call modes ordering and plot frames', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t)->activate(false);
	$arguments=['text',['nested'],true,false,7,null,static fn(): null=>null,new stdClass(),1.25];
	$t->isTrue($scenario->trace('ignored by call formatter', 'function_call', $arguments));
	$t->contains('FC:</span>', $scenario->traceBuffer());
	$t->contains('&quot;text&quot;,Array,True,False,Integer(7),Null,Callable,Object,N/A', $scenario->traceBuffer());

	$t->isTrue($scenario->trace('dynamic test', 'function_call_with_test', ['value']));
	$t->count(1, $scenario->generatedTests());
	$t->isTrue($scenario->trace('informational', null));
	$t->isTrue($scenario->trace('warning', 'warning'));
	$t->isTrue($scenario->trace('error', 'error'));
	$t->isTrue($scenario->trace('fatal', 'fatal'));
	$t->contains('Tracelog fatal:', $scenario->fatalLogs()[0]);
	$t->isTrue($scenario->trace('global', 'info', null, null, null, null, '', ''));
	$before=$scenario->traceBuffer();
	$t->isTrue($scenario->trace('retroactive first', 'info', null, 999.5, 100));
	$t->isTrue(strpos($scenario->traceBuffer(), 'retroactive first')<strpos($scenario->traceBuffer(), $before));

	\dataphyre\tracelog::set_plotting(true);
	$t->isTrue($scenario->trace('runtime-provided plot frame'));
	$t->isTrue($scenario->trace('plotted', 'info', null, 1000.2, 120, [[
		'file'=>'/srv/example.php','line'=>42,'function'=>'run','class'=>'Example','args'=>[],'time'=>'0.2',
	]]));
	$t->isTrue(is_file($scenario->plottingPath()));
	$derived=$scenario->plotFrame(['/srv/fallback.php',3,'Fallback','invoke','message','info',[],null]);
	$t->same('fallback.php', basename($derived[0]['file']));
});

test('trace buffer trimming protects both appended tails and prepended heads', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t)->activate(false);
	$oversized=str_repeat('A', 2100000);
	$t->isTrue($scenario->trace($oversized, 'info', null, null, null, null, '', ''));
	$t->contains('buffer was trimmed', $scenario->traceBuffer());
	$t->isTrue(strlen($scenario->traceBuffer())<2101000);
	$scenario->replaceTraceBuffer('tail');
	$t->isTrue($scenario->trace(str_repeat('B', 2100000), 'info', null, 999.0, 1, null, '', ''));
	$t->contains('buffer was trimmed', $scenario->traceBuffer());
	$t->isTrue(str_starts_with($scenario->traceBuffer(), '<br><b>'));
});

test('error handler installation and delivery distinguish dialbacks fatal escalation deferral and direct append', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	new \dataphyre\tracelog();
	$t->count(1, $scenario->installedHandlers());
	$t->isTrue(\dataphyre\tracelog::handleError(E_WARNING, '<unsafe>', '/srv/warn.php', 9));
	$t->same('', $scenario->traceBuffer());

	$scenario->activate(true);
	$t->isTrue(\dataphyre\tracelog::handleError(E_WARNING, '<unsafe>', '/srv/warn.php', 9));
	$t->contains('&lt;unsafe&gt;', (string)$scenario->retroactive()[0]);
	$scenario->activate(false);
	$t->isTrue(\dataphyre\tracelog::handleError(E_USER_ERROR, 'fatal', '/srv/fatal.php', 11));
	$t->count(1, $scenario->unavailableCalls());
	$t->contains('fatal', $scenario->traceBuffer());

	$scenario->configure(['dialback'=>static fn(string $name, mixed ...$arguments): mixed=>$name==='CALL_TRACELOG_ERROR_FOUND' ? 'handled' : null]);
	$t->same('handled', \dataphyre\tracelog::handleError(E_NOTICE, 'dialback', '/srv/file.php', 1));
	$scenario->reset(['dialback'=>static fn(string $name, mixed ...$arguments): mixed=>$name==='CALL_TRACELOG_SET_HANDLER' ? 'skip' : null]);
	new \dataphyre\tracelog();
	$t->same([], $scenario->installedHandlers());
	$scenario->reset(['set_error_handler'=>'invalid']);
	$t->throws(static fn()=>new \dataphyre\tracelog(), LogicException::class);
});

test('handoff persistence signs opaque session candidates bounds session payloads and reads recent traces', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$scenario->persist();
	$t->same([], $scenario->session());
	$t->same('', $scenario->sessionPayload(''));
	$t->same('small', $scenario->sessionPayload('small'));
	$t->contains('retained tail shown', $scenario->sessionPayload(str_repeat('x', 200000)));

	$scenario->persistDeferred();
	$scenario->replaceTraceBuffer('<br>complete trace')->persist();
	$session=$scenario->session();
	$t->same('<br>complete trace', $session['tracelog']);
	$t->same('rq-test', $session['flightdeck_last_tracelog_rqid']);
	$t->same(1700000000, $session['flightdeck_last_tracelog_time']);
	$t->isTrue(isset($session['flightdeck_last_tracelog_handoff']));
	$t->count(3, $scenario->handoffCandidates());
	$t->same('<br>complete trace', \dataphyre\tracelog::last_handoff_trace($session['flightdeck_last_tracelog_handoff']));
	$t->same('<br>complete trace', $scenario->readNewestSessionHandoffAfterCandidateLoss());
	$t->same(null, $scenario->handoffFileFromToken('invalid'));
	$t->same(null, $scenario->handoffFileFromToken(str_repeat('g', 40).'.signature'));
	$t->same(null, $scenario->handoffFileFromToken(str_repeat('a', 40).'.wrong'));
	$id=str_repeat('b', 40);
	$t->endsWith('/'.$id.'.dat', (string)$scenario->handoffFileFromToken($scenario->signHandoffId($id)));
	$t->type('string', $scenario->primaryHandoffToken());

	$recent=$scenario->onlyRecentHandoff('recent trace');
	$scenario->configure(['session_id'=>'','cookies'=>[]]);
	$t->same('recent trace', \dataphyre\tracelog::last_handoff_trace());
	$t->isTrue(is_file($recent));
	$scenario->reset()->useCommonDataphyreRoot();
	$t->count(3, $scenario->handoffCandidates());
	$scenario->reset(['roots'=>[],'session_id'=>'','cookies'=>[]]);
	$t->same([], $scenario->handoffCandidates());
	$t->same(null, $scenario->primaryHandoffToken());
	$t->same('', \dataphyre\tracelog::last_handoff_trace());
});

test('state boundaries normalize malformed injected storage and contain PHP process storage', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$t->same('normalized session', $scenario->persistWithMalformedRuntimeSession('normalized session')['tracelog']);
	$t->same('process session', $scenario->persistThroughProcessSession('process session')['tracelog']);
	$t->same('null process session', $scenario->persistThroughNullProcessSession('null process session')['tracelog']);
	$t->same('shutdown session', $scenario->shutdownThroughMissingProcessSession('shutdown session')['tracelog']);

	$scenario->reset()->recordFileWrites()->activate(false);
	\dataphyre\tracelog::set_plotting(true);
	$t->isTrue($scenario->trace('observed writer'));
	$t->count(1, $scenario->recordedFileWrites());
});

test('buffer callback opens signed normal and plotting viewers only for active open traces', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t);
	$t->same('body', $scenario->buffer('body'));
	$scenario->activate(false)->replaceTraceBuffer('trace');
	$t->same('body', $scenario->buffer('body'));
	$normal=$scenario->openViewer(false)->buffer('body');
	$t->contains('/dataphyre/tracelog?handoff=', $normal);
	$t->hasKey('exec_time', $scenario->session());
	$plotting=$scenario->openViewer(true)->buffer('body');
	$t->contains('/dataphyre/tracelog/plotter?handoff=', $plotting);
	\dataphyre\tracelog::set_plotting(true);
	$scenario->reset(['roots'=>[]]);
	\dataphyre\tracelog::set_plotting(true);
	$t->isTrue(\dataphyre\tracelog::$plotting);
});

test('database and shutdown policies cover success failure suppression persistence and caught infrastructure errors', static function(Context $t): void {
	$scenario=DpTracelogRuntimeScenario::open($t)->activate(false)->replaceTraceBuffer('sql trace');
	$insert=$t->spy()->willReturn('row-id');
	\dataphyre\tracelog::save_to_database('rq-success', ['insert'=>$insert,'app'=>'shop']);
	$insert->assertCalledTimes($t, 1);
	$t->throws(static fn()=>\dataphyre\tracelog::save_to_database('rq-invalid', ['insert'=>'invalid']), LogicException::class);
	$failed=$t->spy()->willReturn(false);
	\dataphyre\tracelog::save_to_database('rq-failed', ['insert'=>$failed]);
	$t->contains('Failed creating log in database', $scenario->traceBuffer());

	$scenario->saveToSql(true);
	$shutdownInsert=$t->spy()->willReturn('stored');
	\dataphyre\tracelog::shutdown(['rqid'=>'rq-shutdown','insert'=>$shutdownInsert]);
	$shutdownInsert->assertCalledTimes($t, 1);
	$scenario->configure(['suppressed'=>true]);
	\dataphyre\tracelog::shutdown(['insert'=>$shutdownInsert]);
	$shutdownInsert->assertCalledTimes($t, 1);

	$shutdownLog=$t->spy()->willReturn(null);
	$scenario->reset(['write_file'=>static fn(): never=>throw new RuntimeException('disk unavailable')])
		->activate(false)->replaceTraceBuffer('trace');
	\dataphyre\tracelog::shutdown(['shutdown_log'=>$shutdownLog]);
	$shutdownLog->assertCalledTimes($t, 1);
});
