<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Versioned immutable instruction for returning from a Panel page or modal.
 *
 * Intent payloads carry only bounded navigation claims. Application data and
 * signing secrets are never accepted as metadata or serialized into tokens.
 */
final class PanelNavigationIntent implements \JsonSerializable {
	public const VERSION=1;
	public const MAX_CHAIN_DEPTH=8;

	/**
	 * @param list<array{id:string,surface:string,operation:string,outcome:string,target:string}> $chain
	 */
	private function __construct(
		private readonly string $audience,
		private readonly string $panel,
		private readonly string $surface,
		private readonly string $tenantBinding,
		private readonly string $principalBinding,
		private readonly string $operation,
		private readonly string $outcome,
		private readonly string $returnTarget,
		private readonly string $nonce,
		private readonly int $issuedAt,
		private readonly int $notBefore,
		private readonly int $expiresAt,
		private readonly ?string $parent,
		private readonly array $chain
	){}

	/** @param array<string,mixed> $claims */
	public static function make(string $returnTarget, array $claims=[]): self {
		$target=PanelNavigationTarget::normalize($returnTarget);
		if($target===null){ throw new \InvalidArgumentException('Navigation intents require a safe internal return target.'); }
		$issuedAt=(int)($claims['issued_at'] ?? $claims['iat'] ?? time());
		$notBefore=(int)($claims['not_before'] ?? $claims['nbf'] ?? $issuedAt);
		$expiresAt=(int)($claims['expires_at'] ?? $claims['exp'] ?? ($issuedAt+900));
		if($issuedAt<0 || $notBefore<0 || $expiresAt<0 || $notBefore>$expiresAt || $expiresAt<=$issuedAt){
			throw new \InvalidArgumentException('Navigation intent time bounds are invalid.');
		}
		$nonce=(string)($claims['nonce'] ?? self::generateNonce());
		if(preg_match('/^[A-Za-z0-9_-]{22,128}$/D', $nonce)!==1){
			throw new \InvalidArgumentException('Navigation intent nonces must be base64url-safe and contain at least 22 characters.');
		}
		$parent=self::optionalDigest($claims['parent'] ?? null);
		$chain=self::normalizeChain(is_array($claims['chain'] ?? null) ? $claims['chain'] : []);
		return new self(
			self::boundedName((string)($claims['audience'] ?? $claims['aud'] ?? 'dataphyre.panel.navigation'), 'audience', 96),
			self::boundedName((string)($claims['panel'] ?? 'default'), 'panel', 96),
			self::boundedName((string)($claims['surface'] ?? 'default'), 'surface', 128),
			self::binding((string)($claims['tenant_binding'] ?? $claims['tenant'] ?? 'guest')),
			self::binding((string)($claims['principal_binding'] ?? $claims['subject'] ?? $claims['sub'] ?? 'guest')),
			self::boundedName((string)($claims['operation'] ?? 'return'), 'operation', 64),
			self::boundedName((string)($claims['outcome'] ?? 'complete'), 'outcome', 64),
			$target,
			$nonce,
			$issuedAt,
			$notBefore,
			$expiresAt,
			$parent,
			$chain
		);
	}

	/** @param array<string,mixed> $payload */
	public static function fromPayload(array $payload): self {
		$allowed=['v','aud','panel','surface','ten','sub','op','out','ret','nonce','iat','nbf','exp','parent','chain'];
		foreach(array_keys($payload) as $key){
			if(!is_string($key) || !in_array($key, $allowed, true)){ throw new \InvalidArgumentException('Navigation intent payload contains unsupported claims.'); }
		}
		if(($payload['v'] ?? null)!==self::VERSION){ throw new \InvalidArgumentException('Navigation intent version is unsupported.'); }
		foreach(['aud','panel','surface','ten','sub','op','out','ret','nonce','iat','nbf','exp'] as $required){
			if(!array_key_exists($required, $payload)){ throw new \InvalidArgumentException('Navigation intent payload is incomplete.'); }
		}
		return self::make((string)$payload['ret'], [
			'audience'=>(string)$payload['aud'],
			'panel'=>(string)$payload['panel'],
			'surface'=>(string)$payload['surface'],
			'tenant_binding'=>(string)$payload['ten'],
			'principal_binding'=>(string)$payload['sub'],
			'operation'=>(string)$payload['op'],
			'outcome'=>(string)$payload['out'],
			'nonce'=>(string)$payload['nonce'],
			'issued_at'=>(int)$payload['iat'],
			'not_before'=>(int)$payload['nbf'],
			'expires_at'=>(int)$payload['exp'],
			'parent'=>$payload['parent'] ?? null,
			'chain'=>is_array($payload['chain'] ?? null) ? $payload['chain'] : [],
		]);
	}

	public function audience(): string { return $this->audience; }
	public function panel(): string { return $this->panel; }
	public function surface(): string { return $this->surface; }
	public function tenantBinding(): string { return $this->tenantBinding; }
	public function principalBinding(): string { return $this->principalBinding; }
	public function operation(): string { return $this->operation; }
	public function outcome(): string { return $this->outcome; }
	public function returnTarget(): string { return $this->returnTarget; }
	public function nonce(): string { return $this->nonce; }
	public function issuedAt(): int { return $this->issuedAt; }
	public function notBefore(): int { return $this->notBefore; }
	public function expiresAt(): int { return $this->expiresAt; }
	public function parent(): ?string { return $this->parent; }
	/** @return list<array{id:string,surface:string,operation:string,outcome:string,target:string}> */
	public function chain(): array { return $this->chain; }

	/** @return array<string,mixed> */
	public function payload(): array {
		return [
			'v'=>self::VERSION,
			'aud'=>$this->audience,
			'panel'=>$this->panel,
			'surface'=>$this->surface,
			'ten'=>$this->tenantBinding,
			'sub'=>$this->principalBinding,
			'op'=>$this->operation,
			'out'=>$this->outcome,
			'ret'=>$this->returnTarget,
			'nonce'=>$this->nonce,
			'iat'=>$this->issuedAt,
			'nbf'=>$this->notBefore,
			'exp'=>$this->expiresAt,
			'parent'=>$this->parent,
			'chain'=>$this->chain,
		];
	}

	public function jsonSerialize(): array { return $this->payload(); }

	public function chainEntry(): array {
		return [
			'id'=>hash('sha256', $this->nonce),
			'surface'=>$this->surface,
			'operation'=>$this->operation,
			'outcome'=>$this->outcome,
			'target'=>$this->returnTarget,
		];
	}

	private static function generateNonce(): string {
		return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
	}

	private static function binding(string $value): string {
		$value=trim($value);
		if(preg_match('/^[a-f0-9]{64}$/D', $value)===1){ return $value; }
		return hash('sha256', $value!=='' ? $value : 'guest');
	}

	private static function boundedName(string $value, string $label, int $max): string {
		$value=trim($value);
		if($value==='' || strlen($value)>$max || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value)!==1){
			throw new \InvalidArgumentException('Navigation intent '.$label.' is invalid.');
		}
		return $value;
	}

	private static function optionalDigest(mixed $value): ?string {
		if($value===null || $value===''){ return null; }
		$value=trim((string)$value);
		if(preg_match('/^[a-f0-9]{64}$/D', $value)!==1){ throw new \InvalidArgumentException('Navigation intent parent references must be SHA-256 digests.'); }
		return $value;
	}

	/** @param array<mixed> $chain @return list<array{id:string,surface:string,operation:string,outcome:string,target:string}> */
	private static function normalizeChain(array $chain): array {
		if(count($chain)>self::MAX_CHAIN_DEPTH){ throw new \InvalidArgumentException('Navigation intent parent chains are too deep.'); }
		$normalized=[];
		foreach($chain as $entry){
			if(!is_array($entry)){ throw new \InvalidArgumentException('Navigation intent parent chain entries must be arrays.'); }
			$id=trim((string)($entry['id'] ?? ''));
			if(preg_match('/^[a-f0-9]{64}$/D', $id)!==1){ throw new \InvalidArgumentException('Navigation intent chain ids must be SHA-256 digests.'); }
			$target=PanelNavigationTarget::normalize((string)($entry['target'] ?? ''));
			if($target===null){ throw new \InvalidArgumentException('Navigation intent chain targets must be internal.'); }
			$normalized[]=[
				'id'=>$id,
				'surface'=>self::boundedName((string)($entry['surface'] ?? 'default'), 'chain surface', 128),
				'operation'=>self::boundedName((string)($entry['operation'] ?? 'return'), 'chain operation', 64),
				'outcome'=>self::boundedName((string)($entry['outcome'] ?? 'complete'), 'chain outcome', 64),
				'target'=>$target,
			];
		}
		return $normalized;
	}
}
