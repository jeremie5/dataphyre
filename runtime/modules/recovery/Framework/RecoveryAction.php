<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use JsonSerializable;

/** Public, already-authorized corrective action attached to one problem occurrence. */
final class RecoveryAction implements JsonSerializable {
	/** @param array<string,int|float|string|bool|null> $meta */
	public function __construct(
		private string $id,
		private string $kind,
		private string $label,
		private string $description='',
		private ?string $href=null,
		private string $method='GET',
		private bool $confirmationRequired=false,
		private bool $retrySafe=false,
		private array $meta=[]
	) {}

	public function id(): string {
		return $this->id;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function jsonSerialize(): array {
		return array_filter([
			'id'=>$this->id,
			'kind'=>$this->kind,
			'label'=>$this->label,
			'description'=>$this->description!=='' ? $this->description : null,
			'href'=>$this->href,
			'method'=>$this->method,
			'confirmation_required'=>$this->confirmationRequired,
			'retry_safe'=>$this->retrySafe,
			'meta'=>$this->meta!==[] ? $this->meta : null,
		], static fn(mixed $value): bool => $value!==null);
	}
}
