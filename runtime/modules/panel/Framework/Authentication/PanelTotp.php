<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** RFC 4226/6238 TOTP implementation supporting SHA-1, SHA-256, and SHA-512. */
final class PanelTotp {
	private const ALPHABET='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	public static function generateSecret(int $bytes=20): string { return self::base32Encode(random_bytes(max(16,min(64,$bytes)))); }
	public static function base32Encode(string $bytes): string {
		if($bytes===''){ throw new \InvalidArgumentException('TOTP secret bytes cannot be blank.'); }
		$buffer=0; $bits=0; $output='';
		foreach(unpack('C*',$bytes) as $byte){ $buffer=($buffer<<8)|$byte; $bits+=8; while($bits>=5){ $bits-=5; $output.=self::ALPHABET[($buffer>>$bits)&31]; $buffer&=(1<<$bits)-1; } }
		if($bits>0){ $output.=self::ALPHABET[($buffer<<(5-$bits))&31]; }
		return $output;
	}
	public static function base32Decode(string $secret): string {
		$secret=strtoupper(preg_replace('/[\s=-]+/','',trim($secret)) ?? '');
		if($secret==='' || preg_match('/^[A-Z2-7]+$/D',$secret)!==1){ throw new \InvalidArgumentException('Invalid Base32 TOTP secret.'); }
		$buffer=0; $bits=0; $output='';
		foreach(str_split($secret) as $character){ $value=strpos(self::ALPHABET,$character); if($value===false){ throw new \InvalidArgumentException('Invalid Base32 TOTP secret.'); } $buffer=($buffer<<5)|$value; $bits+=5; if($bits>=8){ $bits-=8; $output.=chr(($buffer>>$bits)&255); $buffer&=(1<<$bits)-1; } }
		if($bits>0&&$buffer!==0){throw new \InvalidArgumentException('TOTP secret has non-zero Base32 padding bits.');}
		return $output;
	}
	/** @param array<string,mixed> $options */
	public static function at(string $secret, int $timestamp, array $options=[]): string {
		$period=self::period($options); return self::counter($secret,intdiv(max(0,$timestamp),$period),$options);
	}
	/** @param array<string,mixed> $options */
	public static function counter(string $secret, int $counter, array $options=[]): string {
		if($counter<0){ throw new \InvalidArgumentException('TOTP counter cannot be negative.'); }
		$digits=self::digits($options); $algorithm=self::algorithm($options); $key=self::base32Decode($secret);
		$high=intdiv($counter,4294967296); $low=$counter%4294967296; $digest=hash_hmac($algorithm,pack('N2',$high,$low),$key,true);
		$offset=ord($digest[strlen($digest)-1])&15;
		$value=((ord($digest[$offset])&127)<<24)|((ord($digest[$offset+1])&255)<<16)|((ord($digest[$offset+2])&255)<<8)|(ord($digest[$offset+3])&255);
		return str_pad((string)($value%(10**$digits)),$digits,'0',STR_PAD_LEFT);
	}
	/** @param array<string,mixed> $options */
	public static function verify(string $secret, string $code, int $timestamp, array $options=[]): bool { return self::matchingCounter($secret,$code,$timestamp,$options)!==null; }
	/** @param array<string,mixed> $options */
	public static function matchingCounter(string $secret, string $code, int $timestamp, array $options=[]): ?int {
		$digits=self::digits($options); $code=preg_replace('/[\s-]+/','',trim($code)) ?? '';
		if(preg_match('/^\d{'.$digits.'}$/D',$code)!==1){ return null; }
		$period=self::period($options); $skew=max(0,min(10,(int)($options['skew'] ?? 1))); $base=intdiv(max(0,$timestamp),$period); $matched=null;
		for($delta=-$skew;$delta<=$skew;$delta++){ $candidate=$base+$delta; if($candidate<0){ continue; } $valid=hash_equals(self::counter($secret,$candidate,$options),$code); if($valid && $matched===null){ $matched=$candidate; } }
		return $matched;
	}
	/** @param array<string,mixed> $options */
	public static function provisioningUri(string $secret,string $account,string $issuer,array $options=[]): string {
		$account=trim($account); $issuer=trim($issuer); if($account===''||$issuer===''){ throw new \InvalidArgumentException('TOTP account and issuer are required.'); }
		$label=rawurlencode($issuer.':'.$account);
		return 'otpauth://totp/'.$label.'?'.http_build_query(['secret'=>$secret,'issuer'=>$issuer,'algorithm'=>strtoupper(self::algorithm($options)),'digits'=>self::digits($options),'period'=>self::period($options)],'','&',PHP_QUERY_RFC3986);
	}
	/** @param array<string,mixed> $options */ private static function algorithm(array $options): string { $algorithm=strtolower((string)($options['algorithm']??'sha1')); if(!in_array($algorithm,['sha1','sha256','sha512'],true)){ throw new \InvalidArgumentException('Unsupported TOTP algorithm.'); } return $algorithm; }
	/** @param array<string,mixed> $options */ private static function digits(array $options): int { $digits=(int)($options['digits']??6); if($digits<6||$digits>8){ throw new \InvalidArgumentException('TOTP digits must be between 6 and 8.'); } return $digits; }
	/** @param array<string,mixed> $options */ private static function period(array $options): int { $period=(int)($options['period']??30); if($period<15||$period>300){ throw new \InvalidArgumentException('TOTP period must be between 15 and 300 seconds.'); } return $period; }
}
