<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Secret-free result returned by navigation intent verification. */
final class PanelNavigationIntentVerification implements \JsonSerializable {
	private const MESSAGES=[
		'ok'=>'Navigation intent verified.',
		'malformed'=>'Navigation intent is malformed.',
		'oversized'=>'Navigation intent exceeds its size limit.',
		'unsupported'=>'Navigation intent format is unsupported.',
		'missing_key'=>'Navigation intent signing key is unavailable.',
		'invalid_signature'=>'Navigation intent signature is invalid.',
		'invalid_claims'=>'Navigation intent claims are invalid.',
		'expired'=>'Navigation intent has expired.',
		'not_yet_valid'=>'Navigation intent is not active yet.',
		'issued_in_future'=>'Navigation intent issue time is invalid.',
		'context_mismatch'=>'Navigation intent does not belong to this context.',
		'target_mismatch'=>'Navigation intent return target does not match.',
		'replay'=>'Navigation intent has already been consumed.',
		'missing'=>'Navigation intent is required.',
		'unsigned_migration'=>'Unsigned same-panel navigation accepted by migration policy.',
		'disabled'=>'Navigation intents are not configured.',
	];

	private function __construct(
		private readonly bool $valid,
		private readonly string $code,
		private readonly ?PanelNavigationIntent $intent=null,
		private readonly ?string $keyId=null,
		private readonly bool $migrated=false
	){}

	public static function accepted(PanelNavigationIntent $intent, string $keyId): self { return new self(true, 'ok', $intent, $keyId); }
	public static function rejected(string $code, ?string $keyId=null): self { return new self(false, isset(self::MESSAGES[$code]) ? $code : 'invalid_claims', null, $keyId); }
	public static function migration(string $target): self {
		$intent=PanelNavigationIntent::make($target, ['issued_at'=>0,'not_before'=>0,'expires_at'=>PHP_INT_MAX,'nonce'=>str_repeat('A',22)]);
		return new self(true, 'unsigned_migration', $intent, null, true);
	}
	public static function disabled(): self { return new self(true, 'disabled'); }

	public function valid(): bool { return $this->valid; }
	public function code(): string { return $this->code; }
	public function message(): string { return self::MESSAGES[$this->code] ?? self::MESSAGES['invalid_claims']; }
	public function intent(): ?PanelNavigationIntent { return $this->intent; }
	public function keyId(): ?string { return $this->keyId; }
	public function migrated(): bool { return $this->migrated; }

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_navigation_intent_verification',
			'valid'=>$this->valid,
			'code'=>$this->code,
			'message'=>$this->message(),
			'key_id'=>$this->keyId,
			'migrated'=>$this->migrated,
			'intent'=>$this->intent,
		];
	}
}
