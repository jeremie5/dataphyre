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

require_once __DIR__.'/access_session_test_helpers.php';

suite('Access kernel behavioral scenarios')
	->contract('access.kernel-scenarios', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:access')
	->through('configuration', 'guard-resolution', 'identifiers', 'fingerprints', 'totp', 'sessions', 'policy')
	->isolation('case')
	->tag('access', 'exact-coverage', 'kernel')
	->group('framework-coverage');

test('configuration normalization names safe tables guards and defaults once for every caller', static function(Context $t): void {
	$defaults=\dataphyre\access_configuration::normalize([]);
	$t->same('dataphyre.sessions', $defaults['sessions_table']);
	$t->same('dataphyre.access_tokens', $defaults['token_table']);
	$t->same(['session'], $defaults['auth_types']);
	$t->same('session', $defaults['default_auth_type']);

	$invalid=\dataphyre\access_configuration::normalize([
		'sessions_table_name'=>' ',
		'identity'=>'invalid',
		'auth_types'=>'invalid',
		'default_auth_type'=>' ',
	]);
	$t->same($defaults, $invalid);

	$normalized=\dataphyre\access_configuration::normalize([
		'sessions_table_name'=>' custom.sessions ',
		'identity'=>['tokens_table'=>' custom.tokens '],
		'enabled_auth_types'=>[' Session ', '', 'JWT', 'jwt'],
		'default_auth_type'=>' API-Key ',
	]);
	$t->same('custom.sessions', $normalized['sessions_table']);
	$t->same('custom.tokens', $normalized['token_table']);
	$t->same(['api-key','session','jwt'], $normalized['auth_types']);
	$t->same('api-key', $normalized['default_auth_type']);
	$t->same('session', \dataphyre\access::default_auth_type());
	$t->same(['session','jwt','custom-auth'], \dataphyre\access::enabled_auth_types());
	$t->isTrue(\dataphyre\access::auth_type_enabled(' JWT '));
	$t->isFalse(\dataphyre\access::auth_type_enabled('missing'));
	$t->same(2, count(DpAccessSessionWorkerScenario::tableDefinitions()));
});

test('request guard resolution follows bearer remembered cookie and fallback precedence with cached context', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\access::class);
	DpAccessSessionWorkerScenario::begin([], [], ['HTTP_AUTHORIZATION'=>'Basic abc']);
	$t->same(null, $internals->invoke('bearer_token'));
	$t->same('session', \dataphyre\access::current_auth_type());
	$t->same('session', \dataphyre\access::current_auth_type());

	DpAccessSessionWorkerScenario::begin([], [], ['REDIRECT_HTTP_AUTHORIZATION'=>' Bearer redirect-token ']);
	$t->same('redirect-token', $internals->invoke('bearer_token'));
	$t->same('jwt', \dataphyre\access::current_auth_type());

	DpAccessSessionWorkerScenario::begin(['dp_access'=>['auth_type'=>'custom-auth']]);
	$t->same('custom-auth', \dataphyre\access::current_auth_type());
	DpAccessSessionWorkerScenario::begin(['dp_access'=>['auth_type'=>'missing']], [DpAccessSessionWorkerScenario::sessionCookieName()=>'cookie']);
	$t->same('session', \dataphyre\access::current_auth_type());

	DpAccessSessionWorkerScenario::begin(DpAccessSessionWorkerScenario::authenticatedSession(42));
	$context=\dataphyre\access::auth_context(' session ');
	$t->same('session', $context['auth_type']);
	$t->same(42, $context['userid']);
	$t->isTrue($context['logged_in']);
	$t->same('__Host-DPID', $context['cookie_name']);
	$t->same(null, \dataphyre\access::get_auth_cookie_name('jwt'));

	DpAccessSessionWorkerScenario::begin(['dp_access'=>['dpid'=>[]]]);
	$t->same(null, \dataphyre\access::auth_context('session')['id']);
	$t->same('session', $internals->invoke('normalize_auth_type', ' '));
	$t->same('session', $internals->invoke('resolve_auth_type', 'missing'));
	$internals->invoke('mark_auth_type', 'custom-auth');
	$t->same('custom-auth', DpAccessSessionWorkerScenario::accessValue('auth_type'));
	$t->same('CUST', $internals->invoke('auth_type_prefix', 'custom-auth'));
	$t->same('CUST', $internals->invoke('auth_type_prefix', 'custom-auth'));
	$t->same('custom-auth', $internals->invoke('auth_type_from_prefix', ' cust '));
	$t->same('session', $internals->invoke('auth_type_from_prefix', 'NONE'));
	$t->same(null, $internals->invoke('delegate_auth_type', 'TEST', 'session', []));
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_TEST_AUTH_TYPE', 'delegated');
	$t->same('delegated', $internals->invoke('delegate_auth_type', 'TEST', 'custom-auth', [1]));
	$t->same('fallback', $internals->invoke('unsupported_auth_type', 'TEST', 'custom-auth', 'fallback'));
});

test('session cookie policy isolates hosts and enumerates every legacy expiry variant', static function(Context $t): void {
	DpAccessSessionWorkerScenario::begin([], [], ['HTTP_HOST'=>'accounts.example.test:443']);
	$internals=$t->nonPublic(\dataphyre\access::class);
	$options=$internals->invoke('session_cookie_options', 1_700_000_000);
	$t->same([
		'expires'=>1_700_000_000,
		'path'=>'/',
		'secure'=>true,
		'httponly'=>true,
		'samesite'=>'Lax',
	], $options);
	$t->isFalse(array_key_exists('domain', $options));

	$variants=$internals->invoke('session_cookie_expiry_variants', 1_700_000_000);
	$t->same(['__Host-DPID', '__Secure-DPID', '__Secure-DPID'], array_column($variants, 'name'));
	$t->isFalse(array_key_exists('domain', $variants[0]['options']));
	$t->isFalse(array_key_exists('domain', $variants[1]['options']));
	$t->same('accounts.example.test', $variants[2]['options']['domain'] ?? null);
	$t->same('/', $variants[2]['options']['path'] ?? null);
	$t->same(true, $variants[2]['options']['secure'] ?? null);
});

test('signed identifiers preserve guard ownership and escalate malformed or forged values', static function(Context $t): void {
	DpAccessSessionWorkerScenario::begin();
	$session_id=\dataphyre\access::create_id('session');
	$jwt_id=\dataphyre\access::create_id('jwt');
	$custom_id=\dataphyre\access::create_id('custom-auth');
	$t->matches('/^DPID_[A-Za-z0-9_-]{43}_[a-f0-9]{8}$/', $session_id);
	$t->startsWith('DJTI_', $jwt_id);
	$t->startsWith('CUST_', $custom_id);
	$t->isTrue(\dataphyre\access::validate_id($session_id));
	$t->isFalse(\dataphyre\access::validate_id($session_id, 'jwt'));

	DpAccessSessionWorkerScenario::module('firewall');
	$forged=substr($session_id, 0, -1).($session_id[-1]==='0' ? '1' : '0');
	$t->isFalse(\dataphyre\access::validate_id($forged, 'session'));
	$t->isFalse(\dataphyre\access::validate_id('malformed'));
	$t->same(['forged_dpid','forged_dpid'], DpAccessSessionWorkerScenario::firewallReasons());
	$t->isTrue(DpAccessSessionWorkerScenario::accessValue('minimum_security_alert'));

	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_VALIDATE_ID', true);
	$t->isTrue(\dataphyre\access::validate_id('dialback'));
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_VALIDATE_ID_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::validate_id($jwt_id));
});

test('fingerprint helpers score subnet drift and revoke only materially changed sessions', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\access::class);
	$t->same('203.0.113', $internals->invoke('extract_subnet', '203.0.113.44'));
	$t->same('2001:db8:85a3:0', $internals->invoke('extract_subnet', '2001:db8:85a3:0:0:8a2e:370:7334'));
	$t->same('not-an-ip', $internals->invoke('extract_subnet', 'not-an-ip'));
	$t->same(0, $internals->invoke('fingerprint_drift_score', ['ua'=>'same'], ['ua'=>'same']));
	$t->same(2, $internals->invoke('fingerprint_drift_score', ['ua'=>'old','lang'=>'en'], ['ua'=>'new']));

	DpAccessSessionWorkerScenario::begin(['dp_access'=>['fingerprint'=>['ua'=>'old','lang'=>'en']]]);
	DpAccessSessionWorkerScenario::module('firewall');
	$internals->writeProperty('fingerprint', ['ua'=>'new','lang'=>'fr']);
	$internals->invoke('enforce_fingerprint_drift');
	$t->isTrue(DpAccessSessionWorkerScenario::accessValue('minimum_security_alert'));
	$t->same(['fingerprint_drift'], DpAccessSessionWorkerScenario::firewallReasons());
	$t->same(['ua'=>'new','lang'=>'fr'], DpAccessSessionWorkerScenario::accessValue('fingerprint'));

	DpAccessSessionWorkerScenario::begin(
		['dp_access'=>['fingerprint'=>['ua'=>'old','lang'=>'en']]],
		[DpAccessSessionWorkerScenario::sessionCookieName()=>'stored-id']
	);
	DpAccessSessionWorkerScenario::returnFromSql('update', false);
	$internals->writeProperty('fingerprint', ['ua'=>'new','lang'=>'fr']);
	$t->throws(static fn()=>$internals->invoke('enforce_fingerprint_drift'), RuntimeException::class);
});

test('TOTP helpers expose deterministic encoding vectors validation windows enrollment and entropy failures', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\access::class);
	$t->same('', $internals->invoke('base32_encode', ''));
	$t->same('MY', $internals->invoke('base32_encode', 'f'));
	$t->same('f', $internals->invoke('base32_decode', ' M-Y=== '));
	$t->same(false, $internals->invoke('base32_decode', ''));
	$t->same(false, $internals->invoke('base32_decode', 'INVALID!'));
	$t->same(false, $internals->invoke('normalize_totp_secret', ' '));
	$t->same('hello', $internals->invoke('normalize_totp_secret', '68656c6c6f'));

	$requested=0;
	$secret=\dataphyre\access::create_totp_secret(2, static function(int $bytes) use (&$requested): string {
		$requested=$bytes;
		return str_repeat("\x01", $bytes);
	});
	$t->same(10, $requested);
	$t->same(16, strlen($secret));
	$t->same(false, \dataphyre\access::create_totp_secret(20, static fn(): never=>throw new RuntimeException('entropy unavailable')));

	$rfc_secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
	$t->same('287082', \dataphyre\access::totp_code($rfc_secret, 59, 30, 6));
	$t->same(false, \dataphyre\access::totp_code('invalid!', 59));
	$t->same(10, strlen((string)\dataphyre\access::totp_code($rfc_secret, null, 0, 20)));
	$t->isFalse(\dataphyre\access::verify_totp($rfc_secret, 'letters', 1, 59));
	$t->isTrue(\dataphyre\access::verify_totp($rfc_secret, ' 287 082 ', 1, 59));
	$t->isFalse(\dataphyre\access::verify_totp($rfc_secret, '000000', -5, null));

	$t->same(false, \dataphyre\access::totp_uri('', 'person@example.test'));
	$t->same(false, \dataphyre\access::totp_uri($rfc_secret, ' '));
	$uri=\dataphyre\access::totp_uri($rfc_secret, 'person@example.test', '');
	$t->startsWith('otpauth://totp/person%40example.test?', $uri);
	$t->isFalse(str_contains($uri, 'issuer='));
	$default_uri=\dataphyre\access::totp_uri($rfc_secret, 'person@example.test');
	$t->contains('Dataphyre%20TestKit', $default_uri);
	$t->same(false, \dataphyre\access::get_totp_pairing_image('', 'person@example.test'));
	$t->startsWith('data:image/svg+xml;base64,', \dataphyre\access::get_totp_pairing_image($rfc_secret, 'person@example.test', 'Issuer', 96));

	DpAccessSessionWorkerScenario::begin();
	foreach([
		'CALL_ACCESS_CREATE_TOTP_SECRET'=>'dial-secret',
		'CALL_ACCESS_TOTP_CODE'=>'123456',
		'CALL_ACCESS_VERIFY_TOTP'=>true,
		'CALL_ACCESS_TOTP_URI'=>'otpauth://dialback',
		'CALL_ACCESS_GET_TOTP_PAIRING_IMAGE'=>'data:image/svg+xml;base64,dialback',
	] as $event=>$result){ DpAccessSessionWorkerScenario::dialback($event, $result); }
	$t->same('dial-secret', \dataphyre\access::create_totp_secret());
	$t->same('123456', \dataphyre\access::totp_code('ignored'));
	$t->isTrue(\dataphyre\access::verify_totp('ignored', 'ignored'));
	$t->same('otpauth://dialback', \dataphyre\access::totp_uri('ignored', 'ignored'));
	$t->same('data:image/svg+xml;base64,dialback', \dataphyre\access::get_totp_pairing_image('ignored', 'ignored'));
});

test('device classification caches normalized needle sets while policy decisions remain explicit', static function(Context $t): void {
	DpAccessSessionWorkerScenario::begin([], [], ['HTTP_USER_AGENT'=>'Googlebot Mobile']);
	$t->isTrue(\dataphyre\access::is_bot());
	$t->isTrue(\dataphyre\access::is_bot());
	$t->isTrue(\dataphyre\access::is_mobile());
	$t->isTrue(\dataphyre\access::is_mobile());
	$responder=DpAccessSessionWorkerScenario::denialResponder();
	$t->isFalse(\dataphyre\access::access(false, false, false, true, $responder));
	$t->same('/robots-not-allowed', DpAccessSessionWorkerScenario::denials()[0]['redirect']);

	DpAccessSessionWorkerScenario::begin([], [], ['HTTP_USER_AGENT'=>'iPhone Mobile']);
	$t->isFalse(\dataphyre\access::is_bot());
	$t->isFalse(\dataphyre\access::access(false, false, true, false, DpAccessSessionWorkerScenario::denialResponder()));
	$t->same('/open-app', DpAccessSessionWorkerScenario::denials()[0]['redirect']);

	DpAccessSessionWorkerScenario::begin(DpAccessSessionWorkerScenario::authenticatedSession(), [], ['HTTP_USER_AGENT'=>'Desktop']);
	$t->isFalse(\dataphyre\access::access(false, true, false, false, DpAccessSessionWorkerScenario::denialResponder()));
	$t->same('/home', DpAccessSessionWorkerScenario::denials()[0]['redirect']);
	$t->isTrue(\dataphyre\access::access(false, false));
	$t->isTrue(\dataphyre\access::access(true, false));

	DpAccessSessionWorkerScenario::begin([], [], ['HTTP_USER_AGENT'=>'Desktop', 'REQUEST_URI'=>'/private/report']);
	$t->isFalse(\dataphyre\access::is_mobile());
	$t->isFalse(\dataphyre\access::is_mobile());
	$t->isTrue(\dataphyre\access::access(false, true));
	$t->isTrue(\dataphyre\access::access(false, false));
	$t->isFalse(\dataphyre\access::access(true, false, false, false, DpAccessSessionWorkerScenario::denialResponder()));
	$t->same('/login?redir=cHJpdmF0ZS9yZXBvcnQ', DpAccessSessionWorkerScenario::denials()[0]['redirect']);

	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_IS_BOT', true);
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_IS_MOBILE', true);
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_ACCESS', true);
	$t->isTrue(\dataphyre\access::is_bot());
	$t->isTrue(\dataphyre\access::is_mobile());
	$t->isTrue(\dataphyre\access::access());
	$policy=$t->nonPublic(\dataphyre\access::class);
	$t->same(null, $policy->invoke('access_policy_denial', 'denied', 403, '', 'trace')['redirect']);
});

test('session creation and revocation route dialbacks custom guards SQL success and storage failure', static function(Context $t): void {
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_CREATE_SESSION', true);
	$t->isTrue(\dataphyre\access::create_session(1));
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_CREATE_SESSION_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::create_session(2, true, 'custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::create_session(2, true, 'custom-auth'));

	DpAccessSessionWorkerScenario::begin([], [], ['HTTP_HOST'=>'::::']);
	DpAccessSessionWorkerScenario::returnFromSql('insert', true);
	$t->isTrue(\dataphyre\access::create_session(7, true));
	$t->same(7, DpAccessSessionWorkerScenario::accessValue('userid'));
	$t->isTrue(DpAccessSessionWorkerScenario::sqlCall('insert')[1]['keepalive']);
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::returnFromSql('insert', false);
	$t->isFalse(\dataphyre\access::create_session(8));

	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_DISABLE_SESSION', false);
	$t->isFalse(\dataphyre\access::disable_session());
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_DISABLE_SESSION_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::disable_session('custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::disable_session('custom-auth'));
	$t->isTrue(\dataphyre\access::disable_session());

	$dpid=DpAccessSessionWorkerScenario::validId();
	DpAccessSessionWorkerScenario::begin(DpAccessSessionWorkerScenario::authenticatedSession(9, $dpid), [DpAccessSessionWorkerScenario::sessionCookieName()=>$dpid]);
	DpAccessSessionWorkerScenario::returnFromSql('update', true);
	$t->isTrue(\dataphyre\access::disable_session());
	$t->isFalse(array_key_exists('userid', DpAccessSessionWorkerScenario::accessState()));
	DpAccessSessionWorkerScenario::begin([], [DpAccessSessionWorkerScenario::sessionCookieName()=>$dpid]);
	DpAccessSessionWorkerScenario::returnFromSql('update', false);
	$t->isFalse(\dataphyre\access::disable_session());

	foreach([
		['disable_all_sessions_of_user', 'CALL_ACCESS_DISABLE_ALL_SESSIONS_OF_USER', [10], true],
		['disable_other_sessions_of_user', 'CALL_ACCESS_DISABLE_OTHER_SESSIONS_OF_USER', [10,'current'], true],
	] as [$method,$event,$arguments,$result]){
		DpAccessSessionWorkerScenario::begin();
		DpAccessSessionWorkerScenario::dialback($event, $result);
		$t->isTrue(\dataphyre\access::$method(...$arguments));
	}
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_DISABLE_ALL_SESSIONS_OF_USER_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::disable_all_sessions_of_user(10, 'custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::disable_all_sessions_of_user(10, 'custom-auth'));
	DpAccessSessionWorkerScenario::returnFromSql('update', true);
	$t->isTrue(\dataphyre\access::disable_all_sessions_of_user(10));
	DpAccessSessionWorkerScenario::returnFromSql('update', false);
	$t->isFalse(\dataphyre\access::disable_all_sessions_of_user(10));

	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_DISABLE_OTHER_SESSIONS_OF_USER_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::disable_other_sessions_of_user(10, 'current', 'custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::disable_other_sessions_of_user(10, 'current', 'custom-auth'));
	$t->isFalse(\dataphyre\access::disable_other_sessions_of_user(10, ' '));
	DpAccessSessionWorkerScenario::returnFromSql('update', true);
	$t->isTrue(\dataphyre\access::disable_other_sessions_of_user(10, 'current'));
	DpAccessSessionWorkerScenario::returnFromSql('update', false);
	$t->isFalse(\dataphyre\access::disable_other_sessions_of_user(10, 'current'));
});

test('session identity validation and recovery cover cached moved fresh expired delegated and absent states', static function(Context $t): void {
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_LOGGED_IN', true);
	$t->isTrue(\dataphyre\access::logged_in());
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_LOGGED_IN_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::logged_in('custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::logged_in('custom-auth'));
	$t->isFalse(\dataphyre\access::logged_in());
	DpAccessSessionWorkerScenario::begin(DpAccessSessionWorkerScenario::authenticatedSession(55));
	$t->isTrue(\dataphyre\access::logged_in());
	$t->same(55, \dataphyre\access::userid());
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_USERID', 'early-user');
	$t->same('early-user', \dataphyre\access::userid());
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_USERID_AUTH_TYPE', 'delegated-user');
	$t->same('delegated-user', \dataphyre\access::userid('custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::userid('custom-auth'));
	$t->isFalse(\dataphyre\access::userid());

	DpAccessSessionWorkerScenario::cacheSessionValidation(true);
	$t->isTrue(\dataphyre\access::validate_session());
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_VALIDATE_SESSION', true);
	$t->isTrue(\dataphyre\access::validate_session());
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_VALIDATE_SESSION_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::validate_session(true, 'custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::validate_session(true, 'custom-auth'));

	$dpid=DpAccessSessionWorkerScenario::validId();
	$session=DpAccessSessionWorkerScenario::authenticatedSession(77, $dpid);
	$session['dp_access']['ip_address']='198.51.100.4';
	DpAccessSessionWorkerScenario::begin($session, [DpAccessSessionWorkerScenario::sessionCookieName()=>$dpid]);
	DpAccessSessionWorkerScenario::returnFromSql('update', true);
	DpAccessSessionWorkerScenario::returnFromSql('select', ['date'=>time(), 'keepalive'=>false]);
	$t->isTrue(\dataphyre\access::validate_session(false));
	$t->same(1, count(DpAccessSessionWorkerScenario::sqlCalls('update')));
	$t->same(REQUEST_IP_ADDRESS, DpAccessSessionWorkerScenario::accessValue('ip_address'));

	DpAccessSessionWorkerScenario::begin($session, [DpAccessSessionWorkerScenario::sessionCookieName()=>$dpid]);
	DpAccessSessionWorkerScenario::returnFromSql('select', ['date'=>strtotime('-8 days'), 'keepalive'=>true]);
	$t->isFalse(\dataphyre\access::validate_session(false));

	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_RECOVER_SESSION', true);
	$t->isTrue(\dataphyre\access::recover_session());
	DpAccessSessionWorkerScenario::begin();
	DpAccessSessionWorkerScenario::dialback('CALL_ACCESS_RECOVER_SESSION_AUTH_TYPE', true);
	$t->isTrue(\dataphyre\access::recover_session('custom-auth'));
	DpAccessSessionWorkerScenario::begin();
	$t->isFalse(\dataphyre\access::recover_session('custom-auth'));

	DpAccessSessionWorkerScenario::begin([], [DpAccessSessionWorkerScenario::sessionCookieName()=>$dpid]);
	DpAccessSessionWorkerScenario::returnFromSql('select', ['id'=>$dpid, 'userid'=>88, 'date'=>time(), 'keepalive'=>true]);
	$t->isTrue(\dataphyre\access::recover_session());
	$t->same(88, DpAccessSessionWorkerScenario::accessValue('userid'));
	DpAccessSessionWorkerScenario::begin([], [DpAccessSessionWorkerScenario::sessionCookieName()=>$dpid]);
	DpAccessSessionWorkerScenario::returnFromSql('select', ['id'=>$dpid, 'userid'=>88, 'date'=>strtotime('-8 days'), 'keepalive'=>true]);
	DpAccessSessionWorkerScenario::returnFromSql('update', true);
	$t->isFalse(\dataphyre\access::recover_session());
	$t->isTrue(DpAccessSessionWorkerScenario::accessValue('no_known_recoverable_session'));
	DpAccessSessionWorkerScenario::begin(['dp_access'=>['no_known_recoverable_session'=>true]]);
	$t->isFalse(\dataphyre\access::recover_session());
});
