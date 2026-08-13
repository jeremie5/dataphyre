<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** AES-256-GCM payload codec with context-bound authenticated encryption. */
final class PanelEncryptedCommandPayloadCodec implements PanelCommandPayloadCodec,\JsonSerializable {
	private readonly string $key;private ?\Closure $nonceFactory;
	public function __construct(string $key,?callable $nonceFactory=null){if(strlen($key)<32){throw new \InvalidArgumentException('Command payload encryption keys require at least 32 bytes.');}$this->key=hash('sha256',$key,true);$this->nonceFactory=$nonceFactory!==null?\Closure::fromCallable($nonceFactory):null;if(!function_exists('openssl_encrypt')){throw new \RuntimeException('OpenSSL is required for command payload encryption.');}}
	public function seal(array $payload,string $context):array {PanelOperationsGuard::identifier($context,'command payload context',192);$json=PanelOperationsGuard::json($payload);$nonce=$this->nonceFactory!==null?($this->nonceFactory)():random_bytes(12);if(!is_string($nonce)||strlen($nonce)!==12){throw new \UnexpectedValueException('Command payload nonce factory must return exactly 12 bytes.');}$tag='';$cipher=openssl_encrypt($json,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$nonce,$tag,$context,16);if(!is_string($cipher)||strlen($tag)!==16){throw new \RuntimeException('Command payload encryption failed.');}return['version'=>1,'algorithm'=>'aes-256-gcm','nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher),'context_hash'=>hash('sha256',$context)];}
	public function open(array $sealed,string $context):array {$keys=array_keys($sealed);sort($keys,SORT_STRING);$required=['algorithm','ciphertext','context_hash','nonce','tag','version'];sort($required,SORT_STRING);PanelOperationsGuard::identifier($context,'command payload context',192);if($keys!==$required||$sealed['version']!==1||$sealed['algorithm']!=='aes-256-gcm'||!is_string($sealed['nonce'])||!is_string($sealed['tag'])||!is_string($sealed['ciphertext'])||!is_string($sealed['context_hash'])||!hash_equals($sealed['context_hash'],hash('sha256',$context))){throw new \UnexpectedValueException('Sealed command payload is invalid.');}$nonce=base64_decode($sealed['nonce'],true);$tag=base64_decode($sealed['tag'],true);$cipher=base64_decode($sealed['ciphertext'],true);if(!is_string($nonce)||strlen($nonce)!==12||!is_string($tag)||strlen($tag)!==16||!is_string($cipher)){throw new \UnexpectedValueException('Sealed command payload encoding is invalid.');}$json=openssl_decrypt($cipher,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$nonce,$tag,$context);if(!is_string($json)){throw new \UnexpectedValueException('Sealed command payload authentication failed.');}$value=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new \UnexpectedValueException('Opened command payload is invalid.');}return$value;}
	public function jsonSerialize():array{return['type'=>'panel_encrypted_command_payload_codec','version'=>1,'algorithm'=>'aes-256-gcm','authenticated_context'=>true,'key_exposed'=>false];}
}
