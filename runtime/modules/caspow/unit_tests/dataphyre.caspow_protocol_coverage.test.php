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

if(!defined('REQUEST_IP_ADDRESS')){
	define('REQUEST_IP_ADDRESS', '127.0.0.1');
}

\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
function caspow_protocol_flag(string $name): bool {
	return (bool)(\Dataphyre\Test\TestState::channelIfActive('caspow.protocol')?->get($name, false) ?? false);
}
function class_exists(string $class, bool $autoload=true): bool {
	if($class===access::class && caspow_protocol_flag('hide_access')){
		return false;
	}
	return \class_exists($class, $autoload);
}
function hexdec(string $value): int|float {
	return $value==='x' ? 16 : \hexdec($value);
}
function hash(string $algorithm, string $data, bool $binary=false): string|false {
	if(caspow_protocol_flag('fail_digest') && str_contains($data, 'digest-failure')){
		return false;
	}
	return \hash($algorithm, $data, $binary);
}
function time(): int {
	$state=\Dataphyre\Test\TestState::channelIfActive('caspow.protocol');
	return $state?->shift('time_values', \time()) ?? \time();
}
PHP);

require_once __DIR__.'/caspow_test_helpers.php';

/** Starts each protocol scenario with an isolated request and challenge store. */
function dp_caspow_reset_request(Context $t, array $server=[]): void {
	$t->state('caspow.protocol');
	$t->global('_SESSION')->replace([]);
	$t->global('_SERVER')->replace(array_replace([
		'REMOTE_ADDR'=>'127.0.0.1',
		'HTTP_USER_AGENT'=>'Dataphyre CASPoW protocol test',
	], $server));
}

/** Returns a challenge whose proof can use counter zero without doing expensive work. */
function dp_caspow_zero_difficulty_proof(Context $t, array $challenge, array $overrides=[]): array {
	$internals=$t->nonPublic(\dataphyre\caspow::class);
	$challenge['difficulty_bits']=0;
	$challenge['signature']=$internals->invoke('sign_challenge', $challenge);
	$session=$t->global('_SESSION')->value([]);
	$challengeId=(string)$challenge['challenge_id'];
	$session['dp_caspow']['active'][$challengeId]['challenge']=$challenge;
	$t->global('_SESSION')->replace($session);
	$proof=[
		'challenge_id'=>$challengeId,
		'scope'=>$challenge['scope'],
		'algorithm'=>$challenge['algorithm'],
		'nonce'=>$challenge['nonce'],
		'signature'=>$challenge['signature'],
		'counter'=>0,
		'digest'=>$internals->invoke('proof_digest', $challenge['nonce'], $challengeId, 0),
	];
	return array_replace($proof, $overrides);
}

/** Replaces one active challenge record without exposing raw PHP globals. */
function dp_caspow_replace_active_record(Context $t, string $challengeId, array $record): void {
	$session=$t->global('_SESSION')->value([]);
	$session['dp_caspow']['active'][$challengeId]=$record;
	$t->global('_SESSION')->replace($session);
}

test('CASPoW verification rejects each malformed or untrusted proof boundary', static function(Context $t): void {
	dp_caspow_reset_request($t);
	$t->isFalse(\dataphyre\caspow::verify_payload(new stdClass()));
	$t->isFalse(\dataphyre\caspow::verify_payload([]));

	dp_caspow_replace_active_record($t, 'invalid-record', ['challenge'=>'invalid']);
	$t->state('caspow.protocol')->put('time_values', [-1]);
	$t->isFalse(\dataphyre\caspow::verify_payload(['challenge_id'=>'invalid-record']));

	$challenge=\dataphyre\caspow::create_challenge('record-already-used');
	$proof=dp_caspow_zero_difficulty_proof($t, $challenge);
	$session=$t->global('_SESSION')->value([]);
	$session['dp_caspow']['active'][$challenge['challenge_id']]['used']=true;
	$t->global('_SESSION')->replace($session);
	$t->isFalse(\dataphyre\caspow::verify_payload($proof));

	$challenge=\dataphyre\caspow::create_challenge('used-store-replay');
	$proof=dp_caspow_zero_difficulty_proof($t, $challenge);
	$session=$t->global('_SESSION')->value([]);
	$session['dp_caspow']['used'][$challenge['challenge_id']]=['expires_at'=>time()+60, 'verified_at'=>time()];
	$t->global('_SESSION')->replace($session);
	$t->isFalse(\dataphyre\caspow::verify_payload($proof));

	$challenge=\dataphyre\caspow::create_challenge('expired');
	$proof=dp_caspow_zero_difficulty_proof($t, $challenge);
	$session=$t->global('_SESSION')->value([]);
	$expiresAt=\time()-1;
	$session['dp_caspow']['active'][$challenge['challenge_id']]['challenge']['expires_at']=$expiresAt;
	$t->global('_SESSION')->replace($session);
	$t->state('caspow.protocol')->put('time_values', [$expiresAt-1, $expiresAt+1]);
	$t->isFalse(\dataphyre\caspow::verify_payload($proof));

	$challenge=\dataphyre\caspow::create_challenge('wrong-binding');
	$proof=dp_caspow_zero_difficulty_proof($t, $challenge);
	$session=$t->global('_SESSION')->value([]);
	$session['dp_caspow']['active'][$challenge['challenge_id']]['binding']='another request';
	$t->global('_SESSION')->replace($session);
	$t->isFalse(\dataphyre\caspow::verify_payload($proof));

	$challenge=\dataphyre\caspow::create_challenge('submitted-signature');
	$t->isFalse(\dataphyre\caspow::verify_payload(dp_caspow_zero_difficulty_proof($t, $challenge, ['signature'=>'tampered'])));

	$challenge=\dataphyre\caspow::create_challenge('stored-signature');
	$proof=dp_caspow_zero_difficulty_proof($t, $challenge);
	$session=$t->global('_SESSION')->value([]);
	$session['dp_caspow']['active'][$challenge['challenge_id']]['challenge']['signature']='tampered';
	$proof['signature']='tampered';
	$t->global('_SESSION')->replace($session);
	$t->isFalse(\dataphyre\caspow::verify_payload($proof));

	foreach([
		'scope'=>['scope'=>'different'],
		'algorithm'=>['algorithm'=>'SHA-512'],
		'nonce'=>['nonce'=>'different'],
		'counter'=>['counter'=>-1],
		'digest'=>['digest'=>str_repeat('0', 64)],
	] as $scope=>$overrides){
		$challenge=\dataphyre\caspow::create_challenge('invalid-'.$scope);
		$t->isFalse(\dataphyre\caspow::verify_payload(dp_caspow_zero_difficulty_proof($t, $challenge, $overrides)), $scope);
	}

	$challenge=\dataphyre\caspow::create_challenge('insufficient-work');
	$proof=dp_caspow_zero_difficulty_proof($t, $challenge, ['digest'=>'']);
	$session=$t->global('_SESSION')->value([]);
	$session['dp_caspow']['active'][$challenge['challenge_id']]['challenge']['difficulty_bits']=256;
	$session['dp_caspow']['active'][$challenge['challenge_id']]['challenge']['signature']=$t->nonPublic(\dataphyre\caspow::class)->invoke(
		'sign_challenge',
		$session['dp_caspow']['active'][$challenge['challenge_id']]['challenge']
	);
	$proof['signature']=$session['dp_caspow']['active'][$challenge['challenge_id']]['challenge']['signature'];
	$t->global('_SESSION')->replace($session);
	$t->isFalse(\dataphyre\caspow::verify_payload($proof));

	$challenge=\dataphyre\caspow::create_challenge('valid-proof');
	$t->isTrue(\dataphyre\caspow::verify_payload(dp_caspow_zero_difficulty_proof($t, $challenge)));
	$session=$t->global('_SESSION')->value([]);
	$t->missingKey($challenge['challenge_id'], $session['dp_caspow']['active']);
	$t->hasKey($challenge['challenge_id'], $session['dp_caspow']['used']);
})->tag('caspow','protocol','verification','coverage')->group('framework-coverage');

test('CASPoW profiles describe strong accessible constrained and mobile clients', static function(Context $t): void {
	dp_caspow_reset_request($t);
	$internals=$t->nonPublic(\dataphyre\caspow::class);
	$strong=$internals->invoke('select_profile', ['hardware_concurrency'=>8, 'device_memory'=>8]);
	$t->same('strong', $strong['profile']);
	$t->same(384, $strong['chunk_size']);

	$accessible=$internals->invoke('select_profile', ['hardware_concurrency'=>2, 'device_memory'=>2, 'reduced_motion'=>true]);
	$t->same('accessible', $accessible['profile']);
	$t->same(192, $accessible['chunk_size']);

	$t->global('_SERVER')->put('HTTP_SAVE_DATA', 'on');
	$constrained=$internals->invoke('select_profile', ['hardware_concurrency'=>2, 'device_memory'=>2]);
	$t->same('constrained', $constrained['profile']);
	$t->same(1800, $constrained['max_duration_ms']);

	$t->state('caspow.protocol')->put('hide_access', true);
	$t->global('_SERVER')->replace(['HTTP_USER_AGENT'=>'Mobile Safari', 'REMOTE_ADDR'=>'127.0.0.1']);
	$mobile=$internals->invoke('select_profile', ['hardware_concurrency'=>2, 'device_memory'=>2]);
	$t->same('standard', $mobile['profile']);
	$t->lessThanOrEqual(2200, $mobile['max_duration_ms']);
})->tag('caspow','profiles','mobile','coverage')->group('framework-coverage');

test('CASPoW payload primitives normalize counters scopes digests and network bindings', static function(Context $t): void {
	dp_caspow_reset_request($t);
	$internals=$t->nonPublic(\dataphyre\caspow::class);
	$t->same(['ready'=>true], $internals->invoke('decode_payload', ['ready'=>true]));
	$t->same(null, $internals->invoke('decode_payload', 42));
	$t->same(null, $internals->invoke('decode_payload', '%%%'));
	$t->same(null, $internals->invoke('decode_payload', base64_encode('null')));
	$t->same(42, $internals->invoke('normalize_counter', '42'));
	$t->same(null, $internals->invoke('normalize_counter', '12345678901'));
	$t->same(null, $internals->invoke('normalize_counter', new stdClass()));
	$t->same('default', $internals->invoke('normalize_scope', ''));
	$t->length(120, $internals->invoke('normalize_scope', str_repeat('a', 121)));
	$t->same(0, $internals->invoke('leading_zero_bits', '8'));
	$t->same(1, $internals->invoke('leading_zero_bits', '4'));
	$t->same(2, $internals->invoke('leading_zero_bits', '2'));
	$t->same(3, $internals->invoke('leading_zero_bits', '1'));
	$t->same(8, $internals->invoke('leading_zero_bits', '00'));
	$t->same(4, $internals->invoke('leading_zero_bits', 'x'));
	$t->same('', $internals->invoke('ip_subnet', 'invalid'));
	$t->same('2001:db8:abcd:0012', $internals->invoke('ip_subnet', '2001:db8:abcd:0012:ffff::1'));
	$t->same('203.0.113', $internals->invoke('ip_subnet', '203.0.113.42'));
	$t->notEmpty($internals->invoke('binding_signature'));
})->tag('caspow','normalization','network','coverage')->group('framework-coverage');

test('CASPoW challenge stores collect expiry and retain only configured bounds', static function(Context $t): void {
	dp_caspow_reset_request($t);
	$internals=$t->nonPublic(\dataphyre\caspow::class);
	$internals->invoke('ensure_store');
	$now=time();
	$session=$t->global('_SESSION')->value([]);
	$session['dp_caspow']['active']=[
		'expired'=>['challenge'=>['expires_at'=>$now-1]],
		'current'=>['challenge'=>['expires_at'=>$now+60]],
	];
	$session['dp_caspow']['used']=[
		'expired'=>['expires_at'=>$now-61, 'verified_at'=>$now-100],
		'current'=>['expires_at'=>$now, 'verified_at'=>$now],
	];
	$t->global('_SESSION')->replace($session);
	$internals->invoke('gc_store');
	$session=$t->global('_SESSION')->value([]);
	$t->same(['current'], array_keys($session['dp_caspow']['active']));
	$t->same(['current'], array_keys($session['dp_caspow']['used']));

	$activeLimit=$internals->invoke('max_active_challenges');
	$usedLimit=$internals->invoke('max_used_challenges');
	$session['dp_caspow']['active']=[];
	for($index=0; $index<$activeLimit+2; $index++){
		$session['dp_caspow']['active']['active-'.$index]=['challenge'=>['issued_at'=>$index, 'expires_at'=>$now+60]];
	}
	$session['dp_caspow']['used']=[];
	for($index=0; $index<$usedLimit+2; $index++){
		$session['dp_caspow']['used']['used-'.$index]=['verified_at'=>$index, 'expires_at'=>$now+60];
	}
	$t->global('_SESSION')->replace($session);
	$internals->invoke('enforce_store_limits');
	$session=$t->global('_SESSION')->value([]);
	$t->count($activeLimit, $session['dp_caspow']['active']);
	$t->count($usedLimit, $session['dp_caspow']['used']);
	$t->missingKey('active-0', $session['dp_caspow']['active']);
	$t->missingKey('used-0', $session['dp_caspow']['used']);
})->tag('caspow','session-store','bounds','coverage')->group('framework-coverage');

test('CASPoW bounded configuration helpers expose every public challenge policy', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\caspow::class);
	$t->greaterThanOrEqual(30, $internals->invoke('ttl_seconds'));
	$t->greaterThanOrEqual(1, $internals->invoke('desktop_base_bits'));
	$t->greaterThanOrEqual(1, $internals->invoke('mobile_base_bits'));
	$t->greaterThanOrEqual(1, $internals->invoke('minimum_desktop_bits'));
	$t->greaterThanOrEqual(1, $internals->invoke('minimum_mobile_bits'));
	$t->greaterThanOrEqual(1, $internals->invoke('maximum_bits'));
	$t->greaterThanOrEqual(32, $internals->invoke('chunk_size'));
	$t->greaterThanOrEqual(250, $internals->invoke('max_duration_ms'));
	$t->greaterThanOrEqual(1, $internals->invoke('max_active_challenges'));
	$t->greaterThanOrEqual(1, $internals->invoke('max_used_challenges'));
	$t->greaterThanOrEqual(4, $internals->invoke('max_iterations_multiplier'));
	$t->same('sha256', $internals->invoke('server_algorithm_name'));
	$t->same('SHA-256', $internals->invoke('client_algorithm_name'));
	$t->same('fallback', $internals->invoke('config_value', 'missing', 'fallback'));
})->tag('caspow','configuration','bounds','coverage')->group('framework-coverage');

test('CASPoW fails closed when the configured digest engine cannot hash a proof', static function(Context $t): void {
	dp_caspow_reset_request($t);
	$t->state('caspow.protocol')->put('fail_digest', true);
	$challenge=\dataphyre\caspow::create_challenge('digest-engine-failure');
	$proof=dp_caspow_zero_difficulty_proof($t, $challenge, ['digest'=>'']);
	$proof['challenge_id']='digest-failure';
	$session=$t->global('_SESSION')->value([]);
	$record=$session['dp_caspow']['active'][$challenge['challenge_id']];
	unset($session['dp_caspow']['active'][$challenge['challenge_id']]);
	$record['challenge']['challenge_id']='digest-failure';
	$record['challenge']['signature']=$t->nonPublic(\dataphyre\caspow::class)->invoke('sign_challenge', $record['challenge']);
	$proof['signature']=$record['challenge']['signature'];
	$session['dp_caspow']['active']['digest-failure']=$record;
	$t->global('_SESSION')->replace($session);
	$t->isFalse(\dataphyre\caspow::verify_payload($proof));
})->tag('caspow','digest','failure','coverage')->group('framework-coverage');
