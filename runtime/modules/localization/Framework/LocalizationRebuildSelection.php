<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocalizationRebuildSelection implements \JsonSerializable {

	public function __construct(
		private readonly array $types=[],
		private readonly array $languages=[],
		private readonly array $themes=[],
		private readonly array $paths=[]
	){}

	public static function all(): self {
		return new self();
	}

	public static function global(array $languages=[]): self {
		return new self(['global'], $languages);
	}

	public static function theme(array $languages=[], array $themes=[]): self {
		return new self(['theme'], $languages, $themes);
	}

	public static function local(array $languages=[], array $themes=[], array $paths=[]): self {
		return new self(['local'], $languages, $themes, $paths);
	}

	public function types(): array {
		return $this->types;
	}

	public function languages(): array {
		return $this->languages;
	}

	public function themes(): array {
		return $this->themes;
	}

	public function paths(): array {
		return $this->paths;
	}

	public function jsonSerialize(): array {
		return [
			'types'=>$this->types,
			'languages'=>$this->languages,
			'themes'=>$this->themes,
			'paths'=>$this->paths,
		];
	}
}
