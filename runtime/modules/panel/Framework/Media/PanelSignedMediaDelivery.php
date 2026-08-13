<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** HMAC-authenticated private media delivery descriptors and token verifier. */
final class PanelSignedMediaDelivery implements \JsonSerializable {

	private PanelMediaDisk $disk;
	private string $secret;
	private string $baseUrl;
	private int $maxTtl;

	public function __construct(PanelMediaDisk $disk, string $secret, string $baseUrl='/panel/media/private', int $maxTtl=604800) {
		if(strlen($secret)<32){
			throw new \InvalidArgumentException('Private media signing secret must be at least 32 bytes.');
		}
		$baseUrl=trim($baseUrl);
		if($baseUrl==='' || preg_match('#^(?:https?://[^\s]+|/[^\s]*)$#i', $baseUrl)!==1){
			throw new \InvalidArgumentException('Private media base URL must be an HTTP(S) or root-relative URL.');
		}
		$this->disk=$disk;
		$this->secret=$secret;
		$this->baseUrl=$baseUrl;
		$this->maxTtl=max(60, $maxTtl);
	}

	/** @param array<string,mixed> $claims @return array<string,mixed> */
	public function issue(string $path, int $ttlSeconds=900, string $disposition='inline', ?string $filename=null, ?string $audience=null, array $claims=[]): array {
		$path=$this->disk->normalizePath($path);
		$descriptor=$this->disk->descriptor($path);
		$ttl=max(1, min($this->maxTtl, $ttlSeconds));
		$disposition=strtolower(trim($disposition))==='attachment' ? 'attachment' : 'inline';
		$filename=$this->filename($filename ?? (string)$descriptor['filename']);
		$issued=time();
		$payload=array_replace($this->jsonSafe($claims), [
			'v'=>1,
			'disk'=>$this->disk->name(),
			'path'=>$path,
			'iat'=>$issued,
			'exp'=>$issued+$ttl,
			'disposition'=>$disposition,
			'filename'=>$filename,
			'audience'=>$audience!==null && trim($audience)!=='' ? trim($audience) : null,
			'checksum'=>$descriptor['checksum'],
		]);
		$encoded=$this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$signature=$this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
		$token=$encoded.'.'.$signature;
		$separator=str_contains($this->baseUrl, '?') ? '&' : '?';
		return [
			'type'=>'panel_private_media_delivery',
			'disk'=>$this->disk->name(),
			'path'=>$path,
			'url'=>$this->baseUrl.$separator.'token='.rawurlencode($token),
			'token'=>$token,
			'expires_at'=>gmdate('c', $payload['exp']),
			'claims'=>$payload,
			'headers'=>[
				'Content-Type'=>$descriptor['mime'],
				'Content-Length'=>(string)$descriptor['size'],
				'Content-Disposition'=>$disposition.'; filename="'.addcslashes($filename, "\"\\").'"',
				'Cache-Control'=>'private, max-age='.$ttl.', no-transform',
				'ETag'=>'"'.$descriptor['checksum'].'"',
				'X-Content-Type-Options'=>'nosniff',
			],
		];
	}

	/** @return array<string,mixed> */
	public function verify(string $token, ?string $expectedPath=null, ?string $audience=null, ?int $at=null): array {
		$parts=explode('.', trim($token));
		if(count($parts)!==2){
			throw new \UnexpectedValueException('Private media token is malformed.');
		}
		[$encoded, $provided]=$parts;
		$expected=$this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
		if(!hash_equals($expected, $provided)){
			throw new \UnexpectedValueException('Private media token signature is invalid.');
		}
		try {
			$payload=json_decode($this->base64UrlDecode($encoded), true, 64, JSON_THROW_ON_ERROR);
		}
		catch(\Throwable $exception){
			throw new \UnexpectedValueException('Private media token payload is invalid.', 0, $exception);
		}
		if(!is_array($payload) || (int)($payload['v'] ?? 0)!==1 || ($payload['disk'] ?? null)!==$this->disk->name()){
			throw new \UnexpectedValueException('Private media token does not belong to this disk.');
		}
		$now=$at ?? time();
		$issuedAt=(int)($payload['iat'] ?? PHP_INT_MAX);
		$expiresAt=(int)($payload['exp'] ?? 0);
		if($issuedAt>$now+60 || $expiresAt<$now || $expiresAt<$issuedAt || $expiresAt-$issuedAt>$this->maxTtl){
			throw new \UnexpectedValueException('Private media token is expired or not yet valid.');
		}
		$path=$this->disk->normalizePath((string)($payload['path'] ?? ''));
		if($expectedPath!==null && !hash_equals($this->disk->normalizePath($expectedPath), $path)){
			throw new \UnexpectedValueException('Private media token path does not match.');
		}
		if($audience!==null && !hash_equals($audience, (string)($payload['audience'] ?? ''))){
			throw new \UnexpectedValueException('Private media token audience does not match.');
		}
		if(!$this->disk->exists($path)){
			throw new \RuntimeException('Private media target no longer exists.');
		}
		$descriptor=$this->disk->descriptor($path);
		if(isset($payload['checksum']) && !hash_equals((string)$payload['checksum'], (string)$descriptor['checksum'])){
			throw new \UnexpectedValueException('Private media changed after the token was issued.');
		}
		return ['valid'=>true, 'claims'=>$payload, 'descriptor'=>$descriptor];
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_signed_media_delivery',
			'disk'=>$this->disk->name(),
			'base_url'=>$this->baseUrl,
			'algorithm'=>'HMAC-SHA256',
			'max_ttl_seconds'=>$this->maxTtl,
			'capabilities'=>[
				'private_delivery'=>true,
				'expiration'=>true,
				'audience_binding'=>true,
				'path_binding'=>true,
				'content_binding'=>true,
				'disposition_headers'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	private function filename(string $filename): string {
		$filename=trim(str_replace(["\0", "\r", "\n", '/', '\\'], '-', $filename));
		$filename=preg_replace('/[^a-zA-Z0-9_. -]+/', '-', $filename) ?? 'download';
		return trim($filename, '. -') ?: 'download';
	}

	private function base64Url(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private function base64UrlDecode(string $value): string {
		$padding=(4-strlen($value)%4)%4;
		$decoded=base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);
		if($decoded===false){
			throw new \UnexpectedValueException('Invalid base64url payload.');
		}
		return $decoded;
	}

	private function jsonSafe(mixed $value): mixed {
		return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}
}
