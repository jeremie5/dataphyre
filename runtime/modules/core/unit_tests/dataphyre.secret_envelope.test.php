<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Secrets\SecretEnvelope;
use Dataphyre\Secrets\SecretException;
use Dataphyre\Secrets\SecretKeyRing;
use Dataphyre\Secrets\SecretRedactor;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/Framework/Secrets/SecretException.php';
require_once dirname(__DIR__).'/Framework/Secrets/SecretKeyRing.php';
require_once dirname(__DIR__).'/Framework/Secrets/SecretEnvelope.php';
require_once dirname(__DIR__).'/Framework/Secrets/SecretRedactor.php';

test('secret envelopes round trip strings and canonical JSON with purpose-bound authenticated context', static function(Context $t): void {
	$ring=SecretKeyRing::fromSecrets(str_repeat('primary-', 8));
	$vault=new SecretEnvelope($ring);
	$context=['tenant_id'=>10, 'scope'=>['kind'=>'store', 'id'=>66], 'environment'=>'sandbox'];
	$string=$vault->sealString('sk_test_private', 'serve.payment.stripe', $context);
	$t->same('sk_test_private', $vault->openString($string['ciphertext'], 'serve.payment.stripe', $context));
	$t->same(64, strlen($string['fingerprint']));
	$t->same($ring->primaryId(), $string['key_id']);
	$t->same(false, str_contains($string['ciphertext'], 'sk_test_private'));
	$t->isTrue($vault->matchesString('sk_test_private', $string['fingerprint'], $string['ciphertext'], 'serve.payment.stripe', $context));
	$t->isFalse($vault->matchesString('different', $string['fingerprint'], $string['ciphertext'], 'serve.payment.stripe', $context));

	$first=$vault->sealJson(['z'=>2, 'a'=>['b'=>true, 'a'=>'value']], 'serve.integration.bundle', $context);
	$second=$vault->sealJson(['a'=>['a'=>'value', 'b'=>true], 'z'=>2], 'serve.integration.bundle', ['environment'=>'sandbox', 'scope'=>['id'=>66, 'kind'=>'store'], 'tenant_id'=>10]);
	$t->same($first['fingerprint'], $second['fingerprint']);
	$t->same(['a'=>['a'=>'value', 'b'=>true], 'z'=>2], $vault->openJson($first['ciphertext'], 'serve.integration.bundle', $context));
})->tag('core', 'secrets', 'encryption', 'security')->maxMillis(1000);

test('secret envelopes fail closed for context substitution tampering malformed input and unavailable key versions', static function(Context $t): void {
	$vault=new SecretEnvelope(SecretKeyRing::fromSecrets(str_repeat('context-', 8)));
	$sealed=$vault->sealString('private', 'serve.provider.key', ['tenant_id'=>10, 'store_id'=>66]);
	foreach([
		static fn()=>$vault->openString($sealed['ciphertext'], 'serve.provider.key', ['tenant_id'=>10, 'store_id'=>67]),
		static fn()=>$vault->openString(substr($sealed['ciphertext'], 0, -1).'A', 'serve.provider.key', ['tenant_id'=>10, 'store_id'=>66]),
		static fn()=>$vault->openString('plaintext', 'serve.provider.key', []),
		static fn()=>$vault->openString($sealed['ciphertext'], '../unsafe purpose', []),
	] as $operation){
		$thrown=false;
		try{ $operation(); }catch(SecretException){ $thrown=true; }
		$t->isTrue($thrown);
	}
	$t->isFalse($vault->isEnvelope('plaintext'));
	$t->isTrue($vault->isEnvelope($sealed['ciphertext']));
})->tag('core', 'secrets', 'tamper', 'fail-closed', 'security')->maxMillis(1000);

test('secret key rotation reads old envelopes and marks them for rewrites without exposing key material', static function(Context $t): void {
	$old=str_repeat('old-secret-', 4);
	$new=str_repeat('new-secret-', 4);
	$oldVault=new SecretEnvelope(SecretKeyRing::fromSecrets($old));
	$oldEnvelope=$oldVault->sealString('rotate-me', 'serve.rotation', ['tenant_id'=>4]);
	$rotatedRing=SecretKeyRing::fromSecrets($new, [$old]);
	$rotatedVault=new SecretEnvelope($rotatedRing);
	$t->same('rotate-me', $rotatedVault->openString($oldEnvelope['ciphertext'], 'serve.rotation', ['tenant_id'=>4]));
	$t->isTrue($rotatedVault->needsRotation($oldEnvelope['ciphertext']));
	$newEnvelope=$rotatedVault->sealString('rotate-me', 'serve.rotation', ['tenant_id'=>4]);
	$t->isFalse($rotatedVault->needsRotation($newEnvelope['ciphertext']));
	$t->same(2, count($rotatedRing->keyIds()));
	$t->same(false, str_contains(json_encode($rotatedRing->keyIds()) ?: '', 'old-secret'));
})->tag('core', 'secrets', 'rotation', 'security')->maxMillis(1000);

test('secret redaction recursively protects API keys tokens credentials envelopes and fingerprints', static function(Context $t): void {
	$redacted=SecretRedactor::redact([
		'apiKey'=>'key',
		'nested'=>['client_secret'=>'secret', 'safe'=>'visible', 'authorization_code_ciphertext'=>'cipher'],
		'custom'=>'hide-me',
	], ['custom']);
	$t->same('[REDACTED]', $redacted['apiKey']);
	$t->same('[REDACTED]', $redacted['nested']['client_secret']);
	$t->same('[REDACTED]', $redacted['nested']['authorization_code_ciphertext']);
	$t->same('[REDACTED]', $redacted['custom']);
	$t->same('visible', $redacted['nested']['safe']);
	$t->isTrue(SecretRedactor::sensitiveKey('webhookSecret'));
	$t->isFalse(SecretRedactor::sensitiveKey('provider_name'));
})->tag('core', 'secrets', 'redaction', 'security')->maxMillis(1000);
