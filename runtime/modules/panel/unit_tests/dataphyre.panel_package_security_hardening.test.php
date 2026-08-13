<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageApplyResult;
use Dataphyre\Panel\PanelPackageInstallPlan;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPackageRollbackPlan;
use Dataphyre\Panel\PanelPackageRollbackResult;
use Dataphyre\Panel\PanelPackageSignatureVerifier;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Panel\PanelPackageVerificationResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!class_exists(\dataphyre\core::class, false)){
	require_once dirname(__DIR__, 2).'/core/kernel/core_functions.php';
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

/** @return array{0:PanelPackageManifest,1:PanelPackageTemplate,2:PanelPackageSignatureVerifier} */
function dp_panel_security_signed_fixture(string $id): array {
	$keypair=sodium_crypto_sign_keypair();
	$public=sodium_crypto_sign_publickey($keypair);
	$secret=sodium_crypto_sign_secretkey($keypair);
	$manifest=PanelPackageManifest::make($id, 'Security fixture');
	$template=PanelPackageTemplate::make($manifest)
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('src/Feature.php', 'trusted bytes');
	$verifier=PanelPackageSignatureVerifier::make([
		'host-release'=>['algorithm'=>'ed25519','public_key'=>base64_encode($public)],
	]);
	$payload=$verifier->payload($template);
	$manifest->signature([
		'algorithm'=>'ed25519',
		'key_id'=>'host-release',
		'digest'=>hash('sha256', $payload),
		'signature'=>base64_encode(sodium_crypto_sign_detached($payload, $secret)),
	]);
	return [$manifest, $template, $verifier];
}

/** @return array{0:string,1:string,2:PanelPackageApplyResult} */
function dp_panel_security_applied_fixture(Context $t, string $name): array {
	$workspace=$t->workspace($name);
	$root=$workspace->directory('target');
	$backups=$workspace->directory('backups');
	file_put_contents($root.DIRECTORY_SEPARATOR.'value.txt', 'before');
	$template=PanelPackageTemplate::make($name)
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('value.txt', 'installed');
	$result=PanelPackageInstallPlan::make($template)->overwritePolicy('replace')->apply($root, ['backup_root'=>$backups]);
	return [$root, $backups, $result];
}

test('OpenSSL package verification enforces the declared RSA or ECDSA key family', static function(Context $t): void {
	$privateKey="-----BEGIN RSA "."PRIVATE KEY-----\n".<<<'PEM'
MIIEowIBAAKCAQEAp05omtGDKliUd9I4v9tqXb3GifVwLgTkIz/gymWp7kd2DMPq
opURt0hSIBHgYPIe6DoJun1z1VADFGmbxMqteoJl/VSErQg0jbhq0OW0z+QVu50w
2c5zmMTJQ0xJRkI67ZwEYhfjDgNNudSMYw8mlIfqQAzzrGxXS8LiP3QZwH8NdOSq
Cg9rhpbVaYjSZvCCcG+cCgw7eIiK4Fz/ABz9hRdCMY97YokRtTfmH4vvLHC+BfrY
T4sEKEzut79XtCSwiymNCK+cFnEt8V1R+Y9Rjw2al+G18KOh4KLYUjvJ9mwxTl1c
ZnGW02nKjgC84oWko1+bCdEhYiGM+z/RsvrxGQIDAQABAoIBAFDAd5zCIxz9RCvR
O7LepKg6QOm1nT+Y/MRGwKjwCOUJeOEQbt+qM7LTJVB1UGd6dZCA8tEgXBhJVjM0
BgsmCDVpWvC7Ko6Zt0PwDx5kwLDW1eaIKFv4WbMSyFHDMFrI/MhS1YrDHMRWs91N
ybTGS0jFkTr5BWPjpv7aQXl/AC74XBB5qFVMS1sO10bVHxchDPGB/q+uI7/I4Zdy
f0T0Rd5jSJmzwQNV3aI/Tm7HlzZY5CTDSueVkdCwh3k2cLvdLbGDWiUZ3n7tUQjy
khuiwit0R1OoQqhiR9fx+YuVWfiZR1szYE56dVKcHg1vVUMIFzTcRjIU0UxeML3G
v2OjgUECgYEA1i5QhMcIgHuGhYzZqcRc+cHyH29VDPrc+Rak/5ZRNSLo2Rh4AGHb
mCz+xKfbiZch4BbAIDQ5l1Ag451pu3eBKfwCPT6DXfMhRnzSBq+2MwuFmjhETeNm
/PH6pUiBXgY4WeM/njz1aTWByrmniE9hnTNujfvzHBHbsZHtdQaRuk8CgYEAx/kZ
D0HS/NpsOvfn9N6oemrGpYJltIdovfDrvS8b2VM3SepgKql5zPyonYiadvOTSCJy
ZvVfuO7xcr6sSZqqLoRlwrUpwNnL01VwAwxNKMIFtoAetwjsfRipWkr/6Y8wWHua
m1CA8ENAkIVhma/Hfa9+GssXW2oLnEDcSnYAjBcCgYBX1U535Rd7eSzFf+mTUU+/
rOWaNpHubMJJ9BteJUrQO6y5uusbXQYs9ebUxvGlDzF5MFtB2aj0gIu8TEWb93ok
uZBBhW1iDd7LhUysKUrSzBrSD9kTB/qoKKPdPEqxQGPDmQnx3pXVu3eqp1Ao+kTR
rtHbsEMWc8xgmbODlloUyQKBgQCW1PRp5aRWxAlOkR6MPEWn0FH1FN3RxTDj04x8
LcQ7r+DMB9RxWVNdolUsPZUEk8RLbHAN6JZCzzee7OLWwaoLXCHFMxBDPgPXa2IJ
aoXocDAO76Q7Oqfl02wphthwOmik1NZQv/ABSTixyWlMmqFF09CyNO1xLhOD0AhY
wZi4EQKBgF6EwAzVsbHci/4mxYFjTl1F6GRQo+hX5hy0mYmyCY5vfNfZ7YbZH+Q7
DiIzmgCcTpr8xIBok3ou8W9E8MKo6jPSjr2VdMgeztNv9DZQv7KwDmQM5bJHpjwZ
ZdCCUm08q06LBjnFphO+pg1GhskO2r/QY0a7YY1X9z6I6iWcT5G6
-----END RSA PRIVATE KEY-----
PEM;
	$publicKey=<<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAp05omtGDKliUd9I4v9tq
Xb3GifVwLgTkIz/gymWp7kd2DMPqopURt0hSIBHgYPIe6DoJun1z1VADFGmbxMqt
eoJl/VSErQg0jbhq0OW0z+QVu50w2c5zmMTJQ0xJRkI67ZwEYhfjDgNNudSMYw8m
lIfqQAzzrGxXS8LiP3QZwH8NdOSqCg9rhpbVaYjSZvCCcG+cCgw7eIiK4Fz/ABz9
hRdCMY97YokRtTfmH4vvLHC+BfrYT4sEKEzut79XtCSwiymNCK+cFnEt8V1R+Y9R
jw2al+G18KOh4KLYUjvJ9mwxTl1cZnGW02nKjgC84oWko1+bCdEhYiGM+z/Rsvrx
GQIDAQAB
-----END PUBLIC KEY-----
PEM;
	$manifest=PanelPackageManifest::make('algorithm-confusion');
	$template=PanelPackageTemplate::make($manifest)
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('value.txt', 'signed');
	$verifier=PanelPackageSignatureVerifier::make([
		'confused'=>['algorithm'=>'ecdsa-sha256', 'public_key'=>$publicKey],
	]);
	$payload=$verifier->payload($template);
	$signature='';
	$t->isTrue(openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256));
	$manifest->signature([
		'algorithm'=>'ecdsa-sha256','key_id'=>'confused','digest'=>hash('sha256', $payload),'signature'=>base64_encode($signature),
	]);
	$result=$verifier->verify($template);
	$t->isFalse($result->ok());
	$t->contains('Package cryptographic signature is invalid.', $result->errors());
})->tag('panel', 'package', 'signature', 'openssl', 'security')->maxMillis(1500);

test('embedded keys are anonymous explicit fallback only and cannot shadow unknown host key ids', static function(Context $t): void {
	$keypair=sodium_crypto_sign_keypair();
	$public=sodium_crypto_sign_publickey($keypair);
	$secret=sodium_crypto_sign_secretkey($keypair);
	$manifest=PanelPackageManifest::make('embedded-key-policy');
	$template=PanelPackageTemplate::make($manifest)->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false);
	$enabled=PanelPackageSignatureVerifier::make([], ['allow_embedded_keys'=>true]);
	$payload=$enabled->payload($template);
	$manifest->signature([
		'algorithm'=>'ed25519','digest'=>hash('sha256', $payload),
		'signature'=>base64_encode(sodium_crypto_sign_detached($payload, $secret)),
		'public_key'=>base64_encode($public),
	]);
	$t->isTrue($enabled->verify($template)->ok());
	$t->isFalse(PanelPackageSignatureVerifier::make()->verify($template)->ok());
	$manifest->signature('key_id', 'attacker-selected-id');
	$shadowed=$enabled->verify($template);
	$t->isFalse($shadowed->ok());
	$t->contains('Package signing key is not trusted by this verifier.', $shadowed->errors());
})->tag('panel', 'package', 'signature', 'embedded-key', 'security')->maxMillis(1000);

test('artifact validation rejects unsafe and case-colliding bundles as a complete install', static function(Context $t): void {
	$root=$t->workspace('package-invalid-artifacts')->root();
	$template=PanelPackageTemplate::make('invalid-artifacts')
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('safe.txt', 'safe')->file('../escape.txt', 'escape')->file('Case.txt', 'one')->file('case.txt', 'two');
	$plan=PanelPackageInstallPlan::make($template);
	$manifest=$plan->manifest();
	$t->same(2, $manifest['summary']['invalid']);
	$t->isFalse($manifest['ready']);
	$result=$plan->apply($root);
	$t->isFalse($result->ok());
	$t->same([], $result->written());
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'safe.txt'));

	$verifier=PanelPackageSignatureVerifier::make();
	$collision=$verifier->verify([
		'package'=>['id'=>'collision','signature'=>['private_key'=>'must-not-serialize']],
		'artifacts'=>[['path'=>'Foo.php','contents'=>'one'],['path'=>'foo.php','contents'=>'two']],
	]);
	$t->isFalse($collision->ok());
	$t->contains('Package contains a duplicate artifact path.', $collision->errors());
	$t->contains('Package signature metadata contains forbidden secret material.', $collision->errors());
})->tag('panel', 'package', 'artifact', 'path', 'security')->maxMillis(1000);

test('install revalidates signed templates stale targets and in-root symlinks before publication', static function(Context $t): void {
	[, $signedTemplate, $verifier]=dp_panel_security_signed_fixture('post-verification-mutation');
	$signedRoot=$t->workspace('post-verification-mutation')->root();
	$staleRoot=$t->workspace('stale-install-target')->root();
	file_put_contents($staleRoot.DIRECTORY_SEPARATOR.'value.txt', 'planned bytes');
	$staleTemplate=PanelPackageTemplate::make('stale-install-target')
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('value.txt', 'package bytes');
	\dataphyre\core::register_dialback('CALL_PANEL_FRAMEWORK_PACKAGE_BEFORE_APPLY', static function(array $payload)use($signedTemplate, $staleRoot): ?array {
		if(($payload['package_id'] ?? '')==='post-verification-mutation'){$signedTemplate->file('src/Feature.php', 'tampered after verification');}
		if(($payload['package_id'] ?? '')==='stale-install-target'){file_put_contents($staleRoot.DIRECTORY_SEPARATOR.'value.txt', 'operator bytes');}
		return null;
	});
	$signed=PanelPackageInstallPlan::make($signedTemplate)->signatureVerifier($verifier)->apply($signedRoot);
	$t->isFalse($signed->ok());
	$t->same([], $signed->written());
	$t->isFalse(is_file($signedRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Feature.php'));

	$stale=PanelPackageInstallPlan::make($staleTemplate)->overwritePolicy('replace')->apply($staleRoot);
	$t->isFalse($stale->ok());
	$t->same('operator bytes', file_get_contents($staleRoot.DIRECTORY_SEPARATOR.'value.txt'));
	$t->same([], $stale->written());

	$linkRoot=$t->workspace('install-symlink-target')->root();
	$actual=$linkRoot.DIRECTORY_SEPARATOR.'actual.txt';
	$link=$linkRoot.DIRECTORY_SEPARATOR.'linked.txt';
	file_put_contents($actual, 'actual');
	if(@symlink($actual, $link)){
		$linkTemplate=PanelPackageTemplate::make('install-symlink-target')
			->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
			->file('linked.txt', 'replacement');
		$linkResult=PanelPackageInstallPlan::make($linkTemplate)->overwritePolicy('replace')->apply($linkRoot);
		$t->isFalse($linkResult->ok());
		$t->same('actual', file_get_contents($actual));
	}
	else{$t->isTrue(true);}
})->tag('panel', 'package', 'install', 'toctou', 'symlink', 'security')->maxMillis(2000);

test('backups are unique verified and serialized rollback cannot expand caller authority', static function(Context $t): void {
	[$root, $backups, $first]=dp_panel_security_applied_fixture($t, 'unique-backup');
	$t->isTrue($first->ok());
	$secondTemplate=PanelPackageTemplate::make('unique-backup')
		->plugin(false)->provider(false)->docs(false)->tests(false)->with('marketplace', false)
		->file('value.txt', 'installed twice');
	$second=PanelPackageInstallPlan::make($secondTemplate)->overwritePolicy('replace')->apply($root, ['backup_root'=>$backups]);
	$t->isTrue($second->ok());
	$firstValue=array_values(array_filter($first->backups(), static fn(array $row): bool => ($row['path'] ?? '')==='value.txt'))[0];
	$secondValue=array_values(array_filter($second->backups(), static fn(array $row): bool => ($row['path'] ?? '')==='value.txt'))[0];
	$t->isFalse($firstValue['backup']===$secondValue['backup']);
	$t->same($firstValue['sha256'], hash_file('sha256', $firstValue['backup']));
	$t->same($secondValue['sha256'], hash_file('sha256', $secondValue['backup']));

	$serialized=$second->toArray();
	$untrusted=PanelPackageRollbackPlan::make($serialized)->apply(['backup_root'=>$backups]);
	$t->isFalse($untrusted->ok());
	$t->contains('explicit trusted target_root', $untrusted->blocked()[0]['reason']);
	$trusted=PanelPackageRollbackPlan::make($serialized)->apply(['target_root'=>$root, 'backup_root'=>$backups]);
	$t->isTrue($trusted->ok());
	$t->same('installed', file_get_contents($root.DIRECTORY_SEPARATOR.'value.txt'));

	[$tamperRoot, $tamperBackups, $tamperApply]=dp_panel_security_applied_fixture($t, 'force-backup-integrity');
	$t->isTrue($tamperApply->ok());
	file_put_contents((string)$tamperApply->backups()[0]['backup'], 'tampered');
	$forced=PanelPackageRollbackPlan::fromApplyResult($tamperApply)->apply(['backup_root'=>$tamperBackups, 'force'=>true]);
	$t->isFalse($forced->ok());
	$t->same('installed', file_get_contents($tamperRoot.DIRECTORY_SEPARATOR.'value.txt'));

	$duplicateTarget=$tamperRoot.DIRECTORY_SEPARATOR.'duplicate.txt';
	file_put_contents($duplicateTarget, 'installed');
	$duplicate=[
		'type'=>'panel_package_apply_result','ok'=>true,'package'=>['id'=>'duplicate'],'target_root'=>$tamperRoot,
		'written'=>[
			['action'=>'create','target'=>$duplicateTarget,'sha256'=>hash('sha256', 'installed')],
			['action'=>'create','target'=>$duplicateTarget,'sha256'=>hash('sha256', 'installed')],
		],
		'backups'=>[],'blocked'=>[],'attempted'=>[],'reverted'=>[],'meta'=>[],
	];
	$duplicateResult=PanelPackageRollbackPlan::make($duplicate)->apply(['target_root'=>$tamperRoot]);
	$t->isFalse($duplicateResult->ok());
	$t->isTrue(is_file($duplicateTarget));
})->tag('panel', 'package', 'backup', 'rollback', 'security')->maxMillis(2500);

test('package audit result objects fail closed and redact credential-bearing metadata', static function(Context $t): void {
	$apply=PanelPackageApplyResult::make([
		'ok'=>true,'package'=>['id'=>'contradiction'],'blocked'=>[['reason'=>'blocked','private_key'=>'secret']],
		'meta'=>['api_token'=>'token-value'],
	]);
	$t->isFalse($apply->ok());
	$t->same('[REDACTED]', $apply->blocked()[0]['private_key']);
	$t->same('[REDACTED]', $apply->toArray()['meta']['api_token']);
	$t->isFalse(PanelPackageApplyResult::make(['ok'=>true,'written'=>'malformed'])->ok());

	$rollback=PanelPackageRollbackResult::make(['ok'=>true,'reverted'=>[['ok'=>true]],'meta'=>['password'=>'secret']]);
	$t->isFalse($rollback->ok());
	$t->same('[REDACTED]', $rollback->toArray()['meta']['password']);
	$t->isFalse(PanelPackageRollbackResult::make(['ok'=>true,'blocked'=>['reason'=>'malformed']])->ok());

	$verification=PanelPackageVerificationResult::make([
		'ok'=>true,'checks'=>[['name'=>'signature_valid','ok'=>false]],'errors'=>[],
		'meta'=>['authorization_token'=>'secret'],
	]);
	$t->isFalse($verification->ok());
	$t->same('[REDACTED]', $verification->toArray()['meta']['authorization_token']);
})->tag('panel', 'package', 'result', 'redaction', 'security')->maxMillis(1000);
