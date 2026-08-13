<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded browser/server cryptographic encoding helpers for local-first sync. */
final class PanelLocalFirstCrypto {
	private function __construct(){}

	public static function base64UrlEncode(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}

	public static function base64UrlDecode(string $value,string $label='base64url value',int $maximumBytes=16384):string{
		if($value===''||strlen($value)>($maximumBytes*2)+16||preg_match('/^[A-Za-z0-9_-]+$/D',$value)!==1){throw new \InvalidArgumentException(ucfirst($label).' is invalid.');}
		$padding=(4-(strlen($value)%4))%4;$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);
		if(!is_string($decoded)||strlen($decoded)>$maximumBytes||!hash_equals(self::base64UrlEncode($decoded),$value)){throw new \InvalidArgumentException(ucfirst($label).' is invalid.');}
		return$decoded;
	}

	/** @return array{pem:string,fingerprint:string} */
	public static function p256PublicKey(string $encoded):array{
		$der=self::base64UrlDecode($encoded,'local-first device public key',2048);
		$pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
		$key=openssl_pkey_get_public($pem);if($key===false){throw new \InvalidArgumentException('Local-first device public key is invalid.');}
		$details=openssl_pkey_get_details($key);if(!is_array($details)||($details['type']??null)!==OPENSSL_KEYTYPE_EC||($details['bits']??null)!==256||($details['ec']['curve_name']??null)!=='prime256v1'){throw new \InvalidArgumentException('Local-first device public keys must use ECDSA P-256.');}
		return['pem'=>$pem,'fingerprint'=>hash('sha256',$der)];
	}

	public static function verifyP256(string $message,string $signature,string $publicKey):bool{
		try{$raw=self::base64UrlDecode($signature,'local-first device signature',64);$pem=self::p256PublicKey($publicKey)['pem'];$der=self::p1363ToDer($raw);}catch(\Throwable){return false;}
		return openssl_verify($message,$der,$pem,OPENSSL_ALGO_SHA256)===1;
	}

	/** Converts WebCrypto's fixed-width P1363 ECDSA signature into ASN.1 DER. */
	public static function p1363ToDer(string $signature):string{
		if(strlen($signature)!==64){throw new \InvalidArgumentException('ECDSA P-256 signatures require 64 raw bytes.');}
		$r=self::derInteger(substr($signature,0,32));$s=self::derInteger(substr($signature,32,32));$body="\x02".self::derLength(strlen($r)).$r."\x02".self::derLength(strlen($s)).$s;
		return"\x30".self::derLength(strlen($body)).$body;
	}

	/** Converts an OpenSSL ASN.1 DER ECDSA signature into WebCrypto P1363 bytes. */
	public static function derToP1363(string $signature):string{
		$offset=0;if(self::readByte($signature,$offset)!==0x30){throw new \InvalidArgumentException('ECDSA signature is not a DER sequence.');}$sequenceLength=self::readLength($signature,$offset);if($sequenceLength!==strlen($signature)-$offset){throw new \InvalidArgumentException('ECDSA signature has an invalid DER sequence length.');}
		if(self::readByte($signature,$offset)!==0x02){throw new \InvalidArgumentException('ECDSA signature is missing r.');}$r=self::readBytes($signature,$offset,self::readLength($signature,$offset));
		if(self::readByte($signature,$offset)!==0x02){throw new \InvalidArgumentException('ECDSA signature is missing s.');}$s=self::readBytes($signature,$offset,self::readLength($signature,$offset));if($offset!==strlen($signature)){throw new \InvalidArgumentException('ECDSA signature contains trailing data.');}
		return self::fixedInteger($r).self::fixedInteger($s);
	}

	private static function derInteger(string $value):string{$value=ltrim($value,"\x00");if($value===''){$value="\x00";}if((ord($value[0])&0x80)!==0){$value="\x00".$value;}return$value;}
	private static function fixedInteger(string $value):string{if($value===''||(ord($value[0])&0x80)!==0){throw new \InvalidArgumentException('ECDSA signature integer is invalid.');}while(strlen($value)>1&&$value[0]==="\x00"){$value=substr($value,1);}if(strlen($value)>32){throw new \InvalidArgumentException('ECDSA signature integer exceeds P-256.');}return str_pad($value,32,"\x00",STR_PAD_LEFT);}
	private static function derLength(int $length):string{if($length<0||$length>65535){throw new \InvalidArgumentException('DER length is invalid.');}if($length<128){return chr($length);}$bytes='';while($length>0){$bytes=chr($length&0xff).$bytes;$length>>=8;}return chr(0x80|strlen($bytes)).$bytes;}
	private static function readLength(string $value,int &$offset):int{$first=self::readByte($value,$offset);if($first<128){return$first;}$count=$first&0x7f;if($count<1||$count>2||$offset+$count>strlen($value)){throw new \InvalidArgumentException('DER length is invalid.');}$length=0;for($index=0;$index<$count;$index++){$length=($length<<8)|self::readByte($value,$offset);}if($length<128){throw new \InvalidArgumentException('DER length is not minimally encoded.');}return$length;}
	private static function readByte(string $value,int &$offset):int{if($offset>=strlen($value)){throw new \InvalidArgumentException('DER value is truncated.');}return ord($value[$offset++]);}
	private static function readBytes(string $value,int &$offset,int $length):string{if($length<1||$offset+$length>strlen($value)){throw new \InvalidArgumentException('DER value is truncated.');}$bytes=substr($value,$offset,$length);$offset+=$length;return$bytes;}
}
