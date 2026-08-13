<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable persistence envelope whose public serialization redacts cryptographic material. */
final class PanelAuthenticationRecord implements \JsonSerializable {
	/** @param array<string,mixed> $data */
	private function __construct(
		private readonly string $collection,
		private readonly string $id,
		private readonly int $revision,
		private readonly array $data,
		private readonly int $createdAt,
		private readonly int $updatedAt
	){}

	/** @param array<string,mixed> $data */
	public static function make(string $collection, string $id, array $data, ?int $now=null): self {
		$now=$now ?? time();
		return self::restore(['collection'=>$collection, 'id'=>$id, 'revision'=>0, 'data'=>$data, 'created_at'=>$now, 'updated_at'=>$now]);
	}

	/** @param array<string,mixed> $payload */
	public static function restore(array $payload): self {
		$collection=self::identifier((string)($payload['collection'] ?? ''), 'collection');
		$id=self::identifier((string)($payload['id'] ?? ''), 'id');
		$data=$payload['data'] ?? null;
		if(!is_array($data) || ($data!==[] && array_is_list($data))){ throw new \InvalidArgumentException('Panel authentication record data must be an object-like array.'); }
		self::validateData($data);
		$created=max(0, (int)($payload['created_at'] ?? time()));
		$updated=max($created, (int)($payload['updated_at'] ?? $created));
		return new self($collection, $id, max(0, (int)($payload['revision'] ?? 0)), $data, $created, $updated);
	}

	public function collection(): string { return $this->collection; }
	public function id(): string { return $this->id; }
	public function revision(): int { return $this->revision; }
	/** @return array<string,mixed> */ public function data(): array { return $this->data; }
	public function value(string $key, mixed $default=null): mixed { return array_key_exists($key, $this->data) ? $this->data[$key] : $default; }
	public function ownerId(): ?string {
		$owner=$this->value('user_id');
		return is_string($owner) && trim($owner)!=='' ? trim($owner) : null;
	}
	public function ownedBy(string|int $userId): bool {
		$owner=$this->ownerId(); $user=trim((string)$userId);
		return $owner!==null && $user!=='' && hash_equals($owner,$user);
	}
	public function createdAt(): int { return $this->createdAt; }
	public function updatedAt(): int { return $this->updatedAt; }

	/** @param array<string,mixed> $changes */
	public function merge(array $changes, ?int $now=null): self {
		return self::restore(array_replace($this->storagePayload(), [
			'data'=>array_replace($this->data, $changes), 'updated_at'=>max($this->createdAt, $now ?? time()),
		]));
	}

	public function withRevision(int $revision): self {
		$payload=$this->storagePayload(); $payload['revision']=max(0, $revision); return self::restore($payload);
	}

	/** Internal durable representation containing only ciphertexts and keyed hashes, never raw credentials. @return array<string,mixed> */
	public function storagePayload(): array {
		return ['collection'=>$this->collection, 'id'=>$this->id, 'revision'=>$this->revision, 'data'=>$this->data, 'created_at'=>$this->createdAt, 'updated_at'=>$this->updatedAt];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['collection'=>$this->collection, 'id'=>$this->id, 'revision'=>$this->revision, 'data'=>self::redact($this->data), 'created_at'=>$this->createdAt, 'updated_at'=>$this->updatedAt];
	}

	private static function identifier(string $value, string $label): string {
		$value=trim($value);
		if($value==='' || strlen($value)>190 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $value)!==1){ throw new \InvalidArgumentException("Panel authentication {$label} is invalid."); }
		return $value;
	}

	/** @param array<mixed> $data */
	private static function validateData(array $data, int $depth=0): void {
		if($depth>16){ throw new \InvalidArgumentException('Panel authentication record exceeds maximum nesting depth.'); }
		if(count($data)>10000){ throw new \LengthException('Panel authentication record contains too many entries.'); }
		foreach($data as $key=>$value){
			$key=(string)$key; $normalized=strtolower($key);
			if($key===''||strlen($key)>190){throw new \InvalidArgumentException('Panel authentication record key is invalid.');}
			if(in_array($normalized, ['secret', 'code', 'token', 'password', 'raw_secret', 'raw_code', 'raw_token', 'recovery_codes', 'fingerprint', 'ip_address', 'user_agent'], true)){
				throw new \InvalidArgumentException("Raw authentication material '{$key}' cannot be persisted.");
			}
			if(is_string($value)&&strlen($value)>65536){throw new \LengthException('Panel authentication record string exceeds 65536 bytes.');}
			if(is_float($value) && !is_finite($value)){ throw new \InvalidArgumentException('Panel authentication record contains a non-finite number.'); }
			if(is_array($value)){ self::validateData($value, $depth+1); continue; }
			if($value!==null && !is_scalar($value)){ throw new \InvalidArgumentException('Panel authentication record contains a non-serializable value.'); }
		}
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private static function redact(array $data): array {
		$out=[];
		foreach($data as $key=>$value){
			$normalized=strtolower((string)$key);
			if(str_ends_with($normalized, '_hash') || str_ends_with($normalized, '_ciphertext') || in_array($normalized, ['recovery_code_hashes', 'nonce', 'tag'], true)){
				$out[$key]='[redacted]';
			}elseif(is_array($value)){
				$out[$key]=self::redact($value);
			}else{ $out[$key]=$value; }
		}
		return $out;
	}
}
