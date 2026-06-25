<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable snapshot of the Panel package repository lock manifest.
 *
 * The lock captures package metadata and a checksum so package trust, installation planning,
 * diagnostics, and serialized reports can reason about a stable repository state.
 */
final class PanelPackageLock implements \JsonSerializable {

	/**
	 * @var array<string, mixed>
	 */
	private array $manifest;

	/**
	 * Stores a lock manifest produced by the package repository.
	 *
	 * @param array<string, mixed> $manifest Lock manifest containing checksum and package entries.
	 */
	public function __construct(array $manifest) {
		$this->manifest=$manifest;
	}

	/**
	 * Builds a lock snapshot from a repository and optional manifest metadata.
	 *
	 * @param PanelPackageRepository $repository Package repository to snapshot.
	 * @param array<string, mixed> $meta Additional metadata folded into the repository lock manifest.
	 * @return self Lock snapshot for the repository state.
	 */
	public static function fromRepository(PanelPackageRepository $repository, array $meta=[]): self {
		return new self($repository->lockManifest($meta));
	}

	/**
	 * Returns the repository lock checksum.
	 *
	 * @return string Checksum string, or an empty string when the manifest does not contain one.
	 */
	public function checksum(): string {
		return (string)($this->manifest['checksum'] ?? '');
	}

	/**
	 * Returns package entries captured in the lock.
	 *
	 * @return array<int|string, mixed> Package metadata from the manifest.
	 */
	public function packages(): array {
		return (array)($this->manifest['packages'] ?? []);
	}

	/**
	 * Returns the full lock manifest for diagnostics or persistence.
	 *
	 * @return array<string, mixed> Lock manifest payload.
	 */
	public function toArray(): array {
		return $this->manifest;
	}

	/**
	 * Serializes the lock manifest for persistence and integrity checks.
	 *
	 * @return array<string, mixed> Lock manifest payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
