<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Mvc {
	function session_status(): int {
		return (int)\Dataphyre\Test\TestState::channel('mvc.session-native')->get('status',\session_status());
	}

	function headers_sent(): bool {
		return (bool)\Dataphyre\Test\TestState::channel('mvc.session-native')->get('headers_sent',\headers_sent());
	}

	function session_start(): bool {
		$state=\Dataphyre\Test\TestState::channel('mvc.session-native');
		$state->increment('start_calls');
		$state->put('status',PHP_SESSION_ACTIVE);
		return true;
	}
}

namespace {
	use Dataphyre\Mvc\Session;
	use Dataphyre\Test\Context;
	use Dataphyre\Test\GlobalState;
	use Dataphyre\Test\NonPublicAccess;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['mvc']);

	final class DpMvcSessionScenario {
		public function __construct(
			public TestState $native,
			public GlobalState $session,
			public NonPublicAccess $internals,
		) {}
	}

	function dp_mvc_session_reset(Context $t,?bool $native=false): DpMvcSessionScenario {
		$state=$t->state('mvc.session-native',[
			'status'=>PHP_SESSION_NONE,
			'headers_sent'=>false,
			'start_calls'=>0,
		]);
		$session=$t->globalMap('_SESSION')->clear();
		$internals=$t->nonPublic(Session::class);
		$internals->replacePropertyForTest('fallback',[]);
		$internals->replacePropertyForTest('started',false);
		$internals->replacePropertyForTest('nativeSessionOverride',$native);
		return new DpMvcSessionScenario($state,$session,$internals);
	}

	test('mvc session deep coverage exercises fallback values counters arrays and private paths', static function(Context $t): void {
		$scenario=dp_mvc_session_reset($t,false);
		$sessionInternals=$scenario->internals;
		$t->isFalse($sessionInternals->invoke('nativeSessionEnabled'));

		Session::start();
		Session::start();
		$t->same([], Session::get('missing', []));
		Session::put('literal', 'value');
		$t->same('value', Session::get('literal'));
		$t->isTrue(Session::has('literal'));
		$t->isFalse(Session::has('absent'));

		Session::put('profile', 'replace-me');
		Session::put('profile.name', 'Ada');
		Session::put('profile.contact.email', 'ada@example.test');
		$t->same('Ada', Session::get('profile.name'));
		$t->same('fallback', Session::get('profile.missing', 'fallback'));
		$t->isTrue(Session::has('profile.contact.email'));
		$t->isFalse(Session::has('profile.contact.phone'));

		Session::forget('profile.contact.email');
		Session::forget('profile.contact.missing');
		Session::forget('profile.unknown.deep');
		Session::forget('absent');
		Session::forget('literal');
		$t->isFalse(Session::has('literal'));

		Session::put('pull.value', 7);
		$t->same(7, Session::pull('pull.value'));
		$t->same('none', Session::pull('pull.missing', 'none'));

		$calls=0;
		$t->same('computed', Session::remember('memo', static function() use (&$calls): string {
			$calls++;
			return 'computed';
		}));
		$t->same('computed', Session::remember('memo', static function() use (&$calls): string {
			$calls++;
			return 'other';
		}));
		$t->same(1, $calls);

		Session::put('count', '4');
		$t->same(6, Session::increment('count', 2));
		$t->same(4, Session::decrement('count', 2));
		Session::put('bad-count', 'four');
		$t->same(1, Session::increment('bad-count'));

		Session::put('items', 'not-an-array');
		$t->same(['first'], Session::push('items', 'first'));
		$t->same(['first', 'second'], Session::push('items', 'second'));

		$t->same('literal-dot',$sessionInternals->invoke('dataGet',['a.b'=>'literal-dot'],'a.b',null));
		$t->same('nested',$sessionInternals->invoke('dataGet',['a'=>['b'=>'nested']],'a.b',null));
		$t->same('default',$sessionInternals->invoke('dataGet',['a'=>'scalar'],'a.b','default'));
		$t->same('default',$sessionInternals->invoke('dataGet',[],'simple','default'));
		$t->isTrue($sessionInternals->invoke('dataHas',['a.b'=>null],'a.b'));
		$t->isTrue($sessionInternals->invoke('dataHas',['a'=>['b'=>null]],'a.b'));
		$t->isFalse($sessionInternals->invoke('dataHas',['a'=>'scalar'],'a.b'));
		$t->isFalse($sessionInternals->invoke('dataHas',[],'simple'));

		$data=$sessionInternals->capture('dataSet',data:[],key:'literal',value:1)->argument('data');
		$data=$sessionInternals->capture('dataSet',data:$data,key:'literal.child',value:2)->argument('data');
		$t->same(['literal'=>['child'=>2]], $data);

		$forget=['literal'=>1, 'tree'=>['leaf'=>2], 'scalar'=>'value'];
		foreach(['literal', 'missing', 'tree.leaf', 'tree.missing', 'scalar.child', 'unknown.child'] as $key){
			$forget=$sessionInternals->capture('dataForget',data:$forget,key:$key)->argument('data');
		}
		$t->same(['tree'=>[], 'scalar'=>'value'], $forget);
	})->tag('mvc', 'session', 'deep-coverage')->group('framework-coverage');

	test('mvc session deep coverage exercises flash errors tokens aging and native storage', static function(Context $t): void {
		dp_mvc_session_reset($t,false);
		Session::flash('notice', 'one');
		Session::flash('notice', 'two');
		$t->same('two', Session::get('notice'));

		Session::flashInput(['profile'=>['name'=>'Ada']]);
		$t->same(['profile'=>['name'=>'Ada']], Session::old());
		$t->same('Ada', Session::old('profile.name'));
		$t->same('fallback', Session::old('missing', 'fallback'));
		Session::put('_old_input', 'invalid');
		$t->same([], Session::old());
		$t->same('fallback', Session::old('name', 'fallback'));

		Session::put('_errors', 'invalid');
		Session::flashErrors(['email'=>['Invalid email.']], '  ');
		$t->same(['email'=>['Invalid email.']], Session::errors(''));
		$t->same(['Invalid email.'], Session::error('email'));
		$t->isTrue(Session::hasErrors());
		Session::flashErrors(['name'=>'Name is invalid.'], 'profile');
		$t->same([], Session::error('name', 'profile'));
		$t->same([], Session::errors('missing'));
		Session::put('_errors', 'invalid-again');
		$t->same([], Session::errors());
		$t->isFalse(Session::hasErrors());

		Session::put('_token', null);
		$token=Session::token();
		$t->same(64, strlen($token));
		$t->same($token, Session::token());
		Session::put('_token', '');
		$t->same(64, strlen(Session::token()));
		Session::put('_token', 'known-token');
		$t->same('known-token', Session::token());
		$t->same(64, strlen(Session::regenerateToken()));

		Session::put('expired', 'old');
		Session::put('_flash_old', ['expired', 'expired']);
		Session::flash('fresh', 'new');
		Session::ageFlash();
		$t->isFalse(Session::has('expired'));
		$t->same('new', Session::get('fresh'));
		Session::ageFlash();
		$t->isFalse(Session::has('fresh'));
		Session::flush();

		$native=dp_mvc_session_reset($t,true);
		$native->session->replace(['seed'=>'native']);
		Session::start();
		$t->same(1,$native->native->get('start_calls'));
		$t->same('native', Session::get('seed'));
		Session::put('native.value', 9);
		$t->same(9,$native->session->get('native')['value']);
		Session::flush();
		$t->same([],$native->session->map());

		$native->internals->writeProperty('nativeSessionOverride',null);
		$t->isFalse($native->internals->invoke('nativeSessionEnabled'));
		$native->internals->writeProperty('fallback',[]);
		$native->internals->writeProperty('started',false);
	})->tag('mvc', 'session', 'deep-coverage')->group('framework-coverage');
}
