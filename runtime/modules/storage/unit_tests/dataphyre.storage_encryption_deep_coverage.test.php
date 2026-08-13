<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Storage\Support\Encryption;
use Dataphyre\Storage\Support\Stream;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$dp_storage_encryption_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/storage/Framework/Support';
require_once $dp_storage_encryption_root.'/Stream.php';
require_once $dp_storage_encryption_root.'/Encryption.php';

test('storage encryption resolves enablement direct base64 file override and missing keys', static function(Context $t): void {
	$t->isFalse(Encryption::enabled([]));
	$t->isTrue(Encryption::enabled(['encryption'=>['enabled'=>true]]));
	$t->isFalse(Encryption::enabled(['encryption'=>['enabled'=>true]], ['encrypt'=>false]));
	$t->isTrue(Encryption::enabled([], ['encrypt'=>true]));
	$t->throws(static fn()=>Encryption::key([]), RuntimeException::class);
	$t->same(hash('sha256', 'plain', true), Encryption::key(['encryption'=>['key'=>'plain']]));
	$t->same(hash('sha256', 'override', true), Encryption::key(['encryption'=>['key'=>'plain']], ['encryption_key'=>'override']));
	$t->same(hash('sha256', 'decoded', true), Encryption::key(['encryption'=>['key'=>'base64:'.base64_encode('decoded')]]));
	$t->same(hash('sha256', 'base64:***', true), Encryption::key(['encryption'=>['key'=>'base64:***']]));

	$keyFile=$t->workspace('storage-encryption-key')->file('key.txt', " file-secret \n");
	$t->same(hash('sha256', 'file-secret', true), Encryption::key(['encryption'=>['key_file'=>$keyFile]]));
})->tag('storage', 'coverage')->group('framework-coverage');

test('storage encryption round trips empty and populated streams and rejects invalid inputs', static function(Context $t): void {
	$key=Encryption::key(['encryption'=>['key'=>'secret']]);
	$t->throws(static fn()=>Encryption::encryptStream('invalid', $key), InvalidArgumentException::class);
	$t->throws(static fn()=>Encryption::decryptStream('invalid', $key), InvalidArgumentException::class);
	$failureSource=Stream::fromString('failure');
	try{
		Encryption::useEncryptor(static function(string $plain, string $key, string $iv, string &$tag): bool {
			return false;
		});
		$t->throws(static fn()=>Encryption::encryptStream($failureSource, $key), RuntimeException::class);
	}
	finally{
		Encryption::useEncryptor(null);
		@fclose($failureSource);
	}

	$empty=Stream::fromString('');
	$encryptedEmpty=Encryption::encryptStream($empty, $key);
	$decryptedEmpty=Encryption::decryptStream($encryptedEmpty, $key);
	$t->same('', Stream::contents($decryptedEmpty));
	@fclose($empty);
	@fclose($encryptedEmpty);
	@fclose($decryptedEmpty);

	$plain=Stream::fromString(str_repeat('payload-', 32));
	$encrypted=Encryption::encryptStream($plain, $key);
	$encryptedBytes=Stream::contents($encrypted);
	$t->isTrue(is_string($encryptedBytes));
	$decrypted=Encryption::decryptStream($encrypted, $key);
	$t->same(str_repeat('payload-', 32), Stream::contents($decrypted));
	@fclose($plain);
	@fclose($encrypted);
	@fclose($decrypted);

	$invalidMagic=Stream::fromString('not-encrypted');
	$t->throws(static fn()=>Encryption::decryptStream($invalidMagic, $key), RuntimeException::class);
	@fclose($invalidMagic);
	$truncated=Stream::fromString("DPSTOR1\n\x00\x01");
	$t->throws(static fn()=>Encryption::decryptStream($truncated, $key), RuntimeException::class);
	@fclose($truncated);

	$corrupt=(string)$encryptedBytes;
	$last=strlen($corrupt)-1;
	$corrupt[$last]=chr(ord($corrupt[$last]) ^ 1);
	$corruptStream=Stream::fromString($corrupt);
	$t->throws(static fn()=>Encryption::decryptStream($corruptStream, $key), RuntimeException::class);
	@fclose($corruptStream);
})->tag('storage', 'coverage')->group('framework-coverage');
