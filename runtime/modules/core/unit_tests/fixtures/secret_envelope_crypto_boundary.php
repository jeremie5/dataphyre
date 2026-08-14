<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Secrets {
	final class SecretEnvelopeCryptoBoundary {
		public static string $mode='';
	}
	SecretEnvelopeCryptoBoundary::$mode=(string)($argv[1] ?? '');

	function openssl_encrypt(mixed ...$arguments): string|false {
		return SecretEnvelopeCryptoBoundary::$mode==='encrypt-failure'
			? false
			: \openssl_encrypt(...$arguments);
	}

	function openssl_decrypt(mixed ...$arguments): string|false {
		return SecretEnvelopeCryptoBoundary::$mode==='malformed-decrypted-payload'
			? '{"unexpected":true}'
			: \openssl_decrypt(...$arguments);
	}

	function hash_hkdf(mixed ...$arguments): string {
		return SecretEnvelopeCryptoBoundary::$mode==='derivation-failure'
			? ''
			: \hash_hkdf(...$arguments);
	}
}

namespace {
	use Dataphyre\Secrets\SecretEnvelope;
	use Dataphyre\Secrets\SecretException;
	use Dataphyre\Secrets\SecretKeyRing;

	foreach(array_slice($argv, 2) as $source){
		require_once $source;
	}
	$rejected=false;
	try{
		$ring=SecretKeyRing::fromSecrets(str_repeat('crypto-boundary-', 3));
		$vault=new SecretEnvelope($ring);
		switch((string)($argv[1] ?? '')){
			case 'encrypt-failure':
				$vault->sealString('value', 'serve.crypto-boundary');
				break;
			case 'malformed-decrypted-payload':
				$id=$ring->primaryId();
				$payload=rtrim(strtr(base64_encode(str_repeat('x', 29)), '+/', '-_'), '=');
				$vault->openString('dpsecret:v1:'.$id.':'.$payload, 'serve.crypto-boundary');
				break;
			case 'derivation-failure':
				$vault->fingerprintString('value', 'serve.crypto-boundary');
				break;
			case 'unavailable-crypto':
				break;
			default:
				throw new RuntimeException('Unknown crypto boundary mode.');
		}
	}catch(SecretException){
		$rejected=true;
	}
	echo json_encode(['rejected'=>$rejected], JSON_THROW_ON_ERROR);
}
