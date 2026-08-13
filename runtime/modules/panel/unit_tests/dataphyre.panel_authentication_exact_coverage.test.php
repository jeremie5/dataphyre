<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	use Dataphyre\Panel\PanelAuthenticationCipher;
	use Dataphyre\Panel\PanelAuthenticationDecision;
	use Dataphyre\Panel\PanelAuthenticationManager;
	use Dataphyre\Panel\PanelAuthenticationRecord;
	use Dataphyre\Panel\PanelFilesystemAuthenticationStore;
	use Dataphyre\Panel\PanelLocalOneTimeChallengeAdapter;
	use Dataphyre\Panel\PanelMemoryAuthenticationStore;
	use Dataphyre\Panel\PanelOneTimeChallengeDispatch;
	use Dataphyre\Panel\PanelRuntimeEnvironmentCoverageSeam;
	use Dataphyre\Panel\PanelTotp;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	require_once __DIR__.'/panel_runtime_environment_coverage_seam.php';
	framework(['panel']);

	test('authentication cipher keeps its OpenSSL fallback authenticated and reports missing providers', static function(Context $t): void {
		PanelRuntimeEnvironmentCoverageSeam::$enabled=true;
		PanelRuntimeEnvironmentCoverageSeam::$sodiumAvailable=false;
		try {
			$cipher=new PanelAuthenticationCipher('portable-authentication-key-material');

			PanelRuntimeEnvironmentCoverageSeam::$opensslAvailable=false;
			$t->throws(static fn()=> $cipher->encrypt('secret', 'portable-provider'), RuntimeException::class);

			PanelRuntimeEnvironmentCoverageSeam::$opensslAvailable=true;
			PanelRuntimeEnvironmentCoverageSeam::$failEncryption=true;
			$t->throws(static fn()=> $cipher->encrypt('secret', 'failed-encryption'), RuntimeException::class);

			PanelRuntimeEnvironmentCoverageSeam::$failEncryption=false;
			$encrypted=$cipher->encrypt('secret', 'openssl-fallback');
			$t->same('secret', $cipher->decrypt($encrypted, 'openssl-fallback'));

			PanelRuntimeEnvironmentCoverageSeam::$opensslAvailable=false;
			$t->throws(static fn()=> $cipher->decrypt($encrypted, 'openssl-fallback'), UnexpectedValueException::class);

			PanelRuntimeEnvironmentCoverageSeam::$opensslAvailable=true;
			PanelRuntimeEnvironmentCoverageSeam::$throwDuringDecryption=true;
			$t->throws(static fn()=> $cipher->decrypt($encrypted, 'openssl-fallback'), UnexpectedValueException::class);
		} finally {
			PanelRuntimeEnvironmentCoverageSeam::reset();
		}
	})->tag('panel', 'authentication', 'cipher', 'openssl', 'exact-coverage')->maxMillis(1000);

	test('authentication value objects expose complete secret-free public contracts', static function(Context $t): void {
		$t->same(32, strlen(PanelAuthenticationCipher::randomKey()));
		$decision=new PanelAuthenticationDecision(false, 'denied', 'challenge-one', 1, ['factor'=>'totp']);
		$t->isTrue($decision->denied());
		$t->same('panel_authentication_decision', $decision->jsonSerialize()['type']);

		$dispatch=new PanelOneTimeChallengeDispatch('challenge-one', '123456', 'email', 2000, ['recipient'=>'masked']);
		$t->same('panel_one_time_challenge_dispatch', $dispatch->jsonSerialize()['type']);
		$t->isFalse(array_key_exists('code', $dispatch->jsonSerialize()));

		$adapter=new PanelLocalOneTimeChallengeAdapter('adapter-key-material-long-enough');
		$adapter->dispatch('challenge-one', '5551234', 'Verify access', 2000);
		$t->same(1, $adapter->jsonSerialize()['dispatch_count']);

		$record=PanelAuthenticationRecord::make('sessions', 'session-one', ['user_id'=>'operator'], 1000);
		$t->same(['user_id'=>'operator'], $record->data());
	})->tag('panel', 'authentication', 'value-objects', 'exact-coverage')->maxMillis(1000);

	test('authentication stores expose inventory deletion and malformed JSON behavior', static function(Context $t): void {
		$memory=new PanelMemoryAuthenticationStore();
		$record=$memory->create(PanelAuthenticationRecord::make('sessions', 'memory-session', ['user_id'=>'operator'], 1000));
		$t->isFalse($memory->delete('sessions', 'missing'));
		$t->isTrue($memory->delete('sessions', $record->id()));

		$workspace=$t->workspace('panel-authentication-exact-store');
		$filesystem=new PanelFilesystemAuthenticationStore($workspace->root());
		$t->same(str_replace('\\', '/', $workspace->root()), str_replace('\\', '/', $filesystem->directory()));
		$filesystem->create(PanelAuthenticationRecord::make('sessions', 'filesystem-session', ['user_id'=>'operator'], 1000));
		$t->isTrue($filesystem->delete('sessions', 'filesystem-session'));
		$t->isFalse($filesystem->delete('sessions', 'filesystem-session'));

		$workspace->file('authentication.store.json', '{malformed');
		$t->throws(static fn()=> (new PanelFilesystemAuthenticationStore($workspace->root()))->all('sessions'), UnexpectedValueException::class);
	})->tag('panel', 'authentication', 'stores', 'exact-coverage')->maxMillis(2000);

	test('authentication manager covers factories lookup disabling factor challenges and explicit revocation', static function(Context $t): void {
		$workspace=$t->workspace('panel-authentication-manager-factory');
		$filesystem=PanelAuthenticationManager::filesystem($workspace->root(), str_repeat('e', 32), str_repeat('p', 24));
		$t->instanceOf(PanelFilesystemAuthenticationStore::class, $filesystem->store());

		$manager=PanelAuthenticationManager::memory(str_repeat('k', 32), str_repeat('q', 24));
		$t->instanceOf(PanelMemoryAuthenticationStore::class, $manager->store());
		$t->same(null, $manager->challenge('missing-challenge'));
		$t->isFalse($manager->disableTotp('missing-factor', 1000));

		$secret=PanelTotp::base32Encode('12345678901234567890');
		$confirmedAt=1234567890;
		$enrollment=$manager->provisionTotp('operator', 'Primary', [
			'id'=>'factor-one', 'secret'=>$secret, 'now'=>$confirmedAt, 'recovery_codes'=>2,
		]);
		$t->isTrue($manager->confirmTotp('factor-one', PanelTotp::at($secret, $confirmedAt), $confirmedAt)->verified());
		$t->isTrue($manager->disableTotp('factor-one', $confirmedAt+1));

		$second=$manager->provisionTotp('operator', 'Step up', [
			'id'=>'factor-two', 'secret'=>$secret, 'now'=>$confirmedAt+2, 'recovery_codes'=>2,
		]);
		$t->isTrue($manager->confirmTotp('factor-two', PanelTotp::at($secret, $confirmedAt+30), $confirmedAt+30)->verified());

		$totpChallenge=$manager->beginChallenge('operator', 'Approve payout', 'totp', ['id'=>'totp-challenge', 'now'=>$confirmedAt+60]);
		$t->same('totp-challenge', $manager->challenge($totpChallenge->id())?->id());
		$t->isTrue($manager->verifyChallenge($totpChallenge->id(), PanelTotp::at($secret, $confirmedAt+60), $confirmedAt+60)->verified());

		$recoveryChallenge=$manager->beginChallenge('operator', 'Recover access', 'recovery', ['id'=>'recovery-challenge', 'now'=>$confirmedAt+61]);
		$t->isTrue($manager->verifyChallenge($recoveryChallenge->id(), $second->recoveryCodes()[0], $confirmedAt+61)->verified());

		$firstDevice=$manager->trustDevice('operator', 'Laptop', 'laptop-fingerprint', ['id'=>'device-one', 'now'=>2000]);
		$secondDevice=$manager->trustDevice('operator', 'Phone', 'phone-fingerprint', ['id'=>'device-two', 'now'=>2000]);
		$manager->createSession('operator', ['id'=>'bound-session', 'device_id'=>$firstDevice->device()->id(), 'now'=>2001]);
		$t->same(2, $manager->revokeAllDevices('operator', 2002));
		$t->isFalse($manager->devices('operator')[0]->active(2003));
		$t->same('device-two', $secondDevice->device()->id());

		$session=$manager->createSession('operator', ['id'=>'explicit-session', 'now'=>3000]);
		$t->isTrue($manager->revokeSession($session->session()->id(), 3001));
		$t->isFalse($manager->revokeSession('missing-session', 3001));
		$t->same('panel_authentication_manager', $manager->jsonSerialize()['type']);
		$t->notEmpty($enrollment->recoveryCodes());
	})->tag('panel', 'authentication', 'manager', 'exact-coverage')->maxMillis(3000);
}
