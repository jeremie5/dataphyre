<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Support;

/**
 * Encrypts and decrypts Dataphyre storage streams with chunked AES-256-GCM.
 *
 * Encrypted objects begin with a Dataphyre magic header followed by repeated
 * length-prefixed IV, authentication tag, and ciphertext chunks. Chunking keeps
 * memory bounded for large objects while preserving authenticated encryption for
 * each plaintext block.
 */
final class Encryption {

	private const MAGIC="DPSTOR1\n";
	private const CIPHER='aes-256-gcm';
	private const CHUNK_SIZE=1048576;

	/**
	 * Determines whether encryption should be applied for one storage operation.
	 *
	 * Per-call options override disk configuration, allowing callers to force or
	 * disable encryption without mutating the disk definition.
	 *
	 * @param array{encryption?:array{enabled?:bool}} $diskConfig Disk configuration containing optional encryption settings.
	 * @param array{encrypt?:bool} $options Operation options that may include encrypt.
	 * @return bool True when the operation should use encrypted stream storage.
	 */
	public static function enabled(array $diskConfig, array $options=[]): bool {
		return (bool)($options['encrypt'] ?? $diskConfig['encryption']['enabled'] ?? false);
	}

	/**
	 * Resolves and normalizes the AES key for storage encryption.
	 *
	 * Keys may be supplied directly, as base64: material, or through an
	 * encryption key file. The returned value is always a 32-byte SHA-256 digest
	 * suitable for aes-256-gcm.
	 *
	 * @param array{encryption?:array{key?:string,key_file?:string}} $diskConfig Disk configuration containing encryption key or key_file.
	 * @param array{encryption_key?:string} $options Operation options that may override encryption_key.
	 * @return string Binary 32-byte encryption key.
	 * @throws \RuntimeException When encryption is requested without key material.
	 */
	public static function key(array $diskConfig, array $options=[]): string {
		$key=(string)($options['encryption_key'] ?? $diskConfig['encryption']['key'] ?? '');
		if($key==='' && isset($diskConfig['encryption']['key_file']) && is_file((string)$diskConfig['encryption']['key_file'])){
			$key=trim((string)file_get_contents((string)$diskConfig['encryption']['key_file']));
		}
		if($key===''){
			throw new \RuntimeException('Storage encryption is enabled but no encryption key is configured.');
		}
		if(str_starts_with($key, 'base64:')){
			$decoded=base64_decode(substr($key, 7), true);
			if($decoded!==false){
				$key=$decoded;
			}
		}
		return hash('sha256', $key, true);
	}

	/**
	 * Encrypts a readable stream into Dataphyre's chunked storage format.
	 *
	 * The source is rewound before reading. The returned temp stream is rewound
	 * and ready for upload or persistence by the storage adapter.
	 *
	 * @param mixed $source Readable stream resource containing plaintext.
	 * @param string $key Binary key returned by key().
	 * @return resource Writable temp stream positioned at the beginning.
	 * @throws \InvalidArgumentException When the source is not a stream resource.
	 * @throws \Exception When IV generation fails.
	 * @throws \RuntimeException When OpenSSL cannot encrypt a chunk.
	 */
	public static function encryptStream(mixed $source, string $key): mixed {
		if(!is_resource($source)){
			throw new \InvalidArgumentException('Encryption source must be a stream resource.');
		}
		$out=fopen('php://temp', 'w+b');
		fwrite($out, self::MAGIC);
		rewind($source);
		while(!feof($source)){
			$plain=fread($source, self::CHUNK_SIZE);
			if($plain==='' || $plain===false){
				break;
			}
			$iv=random_bytes(12);
			$tag='';
			$ciphertext=openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
			if($ciphertext===false){
				throw new \RuntimeException('Unable to encrypt storage chunk.');
			}
			fwrite($out, pack('N', strlen($iv)).$iv.pack('N', strlen($tag)).$tag.pack('N', strlen($ciphertext)).$ciphertext);
		}
		rewind($out);
		return $out;
	}

	/**
	 * Decrypts a Dataphyre encrypted storage stream into plaintext.
	 *
	 * The source is rewound and must start with the Dataphyre storage magic
	 * header. Each chunk authentication tag is verified by OpenSSL before
	 * plaintext is written to the returned temp stream.
	 *
	 * @param mixed $source Readable stream resource containing encrypted storage bytes.
	 * @param string $key Binary key returned by key().
	 * @return resource Writable temp stream positioned at the beginning.
	 * @throws \InvalidArgumentException When the source is not a stream resource.
	 * @throws \RuntimeException When the magic header, chunk framing, or authentication check fails.
	 */
	public static function decryptStream(mixed $source, string $key): mixed {
		if(!is_resource($source)){
			throw new \InvalidArgumentException('Decryption source must be a stream resource.');
		}
		rewind($source);
		if(fread($source, strlen(self::MAGIC))!==self::MAGIC){
			throw new \RuntimeException('Storage object is not a Dataphyre encrypted stream.');
		}
		$out=fopen('php://temp', 'w+b');
		while(!feof($source)){
			$ivLenRaw=fread($source, 4);
			if($ivLenRaw==='' || $ivLenRaw===false){
				break;
			}
			if(strlen($ivLenRaw)!==4){
				throw new \RuntimeException('Encrypted storage stream is truncated.');
			}
			$ivLen=unpack('N', $ivLenRaw)[1];
			$iv=fread($source, $ivLen);
			$tagLen=unpack('N', fread($source, 4))[1];
			$tag=fread($source, $tagLen);
			$cipherLen=unpack('N', fread($source, 4))[1];
			$ciphertext=fread($source, $cipherLen);
			$plain=openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
			if($plain===false){
				throw new \RuntimeException('Unable to decrypt storage chunk.');
			}
			fwrite($out, $plain);
		}
		rewind($out);
		return $out;
	}
}
