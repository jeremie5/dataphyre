<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Authenticated encryption for TOTP secrets and other authentication material at rest. */
final class PanelAuthenticationCipher {
	private string $key;
	public function __construct(string $key) {
		if(strlen($key)<16){ throw new \InvalidArgumentException('Panel authentication encryption key must contain at least 16 bytes.'); }
		$this->key=strlen($key)===32 ? $key : hash('sha256', $key, true);
	}
	public static function randomKey(): string { return random_bytes(32); }

	public function encrypt(string $plaintext, string $context): string {
		if($plaintext===''){ throw new \InvalidArgumentException('Panel authentication cipher refuses blank plaintext.'); }
		$key=$this->contextKey($context);
		if(function_exists('sodium_crypto_secretbox')){
			$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$payload=['v'=>1,'a'=>'secretbox','n'=>self::encode($nonce),'c'=>self::encode(sodium_crypto_secretbox($plaintext,$nonce,$key))];
		}else{
			if(!function_exists('openssl_encrypt')){ throw new \RuntimeException('Panel authentication requires Sodium or OpenSSL authenticated encryption.'); }
			$iv=random_bytes(12); $tag='';
			$cipher=openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$context,16);
			if($cipher===false){ throw new \RuntimeException('Unable to encrypt Panel authentication material.'); }
			$payload=['v'=>1,'a'=>'aes-256-gcm','n'=>self::encode($iv),'t'=>self::encode($tag),'c'=>self::encode($cipher)];
		}
		return self::encode(json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	}

	public function decrypt(string $ciphertext, string $context): string {
		try{ $payload=json_decode(self::decode($ciphertext), true, 16, JSON_THROW_ON_ERROR); }
		catch(\Throwable $error){ throw new \UnexpectedValueException('Invalid Panel authentication ciphertext.',0,$error); }
		if(!is_array($payload) || ($payload['v'] ?? null)!==1 || !is_string($payload['a'] ?? null) || !is_string($payload['n'] ?? null) || !is_string($payload['c'] ?? null)){ throw new \UnexpectedValueException('Invalid Panel authentication ciphertext envelope.'); }
		$key=$this->contextKey($context); $nonce=self::decode($payload['n']); $cipher=self::decode($payload['c']);
		try{
			if($payload['a']==='secretbox' && function_exists('sodium_crypto_secretbox_open')){
				$plaintext=sodium_crypto_secretbox_open($cipher,$nonce,$key);
			}elseif($payload['a']==='aes-256-gcm' && function_exists('openssl_decrypt') && is_string($payload['t'] ?? null)){
				$plaintext=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,self::decode($payload['t']),$context);
			}else{ throw new \UnexpectedValueException('Unsupported Panel authentication ciphertext algorithm.'); }
		}catch(\UnexpectedValueException $error){throw $error;}catch(\Throwable $error){throw new \UnexpectedValueException('Panel authentication ciphertext failed authentication.',0,$error);}
		if($plaintext===false){ throw new \UnexpectedValueException('Panel authentication ciphertext failed authentication.'); }
		return $plaintext;
	}

	private function contextKey(string $context): string {
		$context=trim($context); if($context===''){ throw new \InvalidArgumentException('Panel authentication encryption context cannot be blank.'); }
		return hash_hmac('sha256','dataphyre-panel-auth:'.$context,$this->key,true);
	}
	private static function encode(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
	private static function decode(string $value): string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/D',$value)!==1){ throw new \UnexpectedValueException('Invalid base64url value.'); }
		$padding=(4-strlen($value)%4)%4; $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);
		if($decoded===false){ throw new \UnexpectedValueException('Invalid base64url value.'); } return $decoded;
	}
}
