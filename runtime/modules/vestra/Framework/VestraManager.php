<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Vestra;

/**
 * Framework facade for the Dataphyre Vestra kernel.
 *
 * `VestraManager` presents camelCase APIs over the `dataphyre\vestra` kernel helpers.
 * Object URL helpers are deterministic wrappers, while use-count updates, ingestion,
 * and propagation can touch SQL metadata, local cache files, HTML payloads,
 * cURL transport, and remote Vestra state through the kernel.
 */
final class VestraManager {

	private static ?self $instance=null;

	/**
	 * Returns the process-local Vestra manager instance.
	 *
	 * @return self Shared framework facade for Vestra operations.
	 */
	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	/**
	 * Clears the process-local Vestra manager instance.
	 *
	 * This does not clear Vestra cache, SQL metadata, or remote Vestra state; it only
	 * resets the facade singleton used by framework code.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Reports whether the Vestra kernel has any configured endpoint.
	 *
	 *
	 * @return bool True when Vestra API or object URL configuration is present.
	 */
	public function configured(): bool {
		return \dataphyre\vestra::configured();
	}

	/**
	 * Returns the configured Vestra API base URL.
	 *
	 *
	 * @return string Raw configured base URL without additional fallback lookup.
	 */
	public function baseUrl(): string {
		return trim((string)(DP_VESTRA_CFG['base_url'] ?? ''));
	}

	/**
	 * Returns the configured public Vestra Fabric base.
	 *
	 *
	 * @return string Raw configured Fabric URL base without additional fallback lookup.
	 */
	public function objectUrl(): string {
		return trim((string)(DP_VESTRA_CFG['object_url'] ?? ''));
	}

	/**
	 * Builds a current public Vestra Fabric URL for a reference.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param array<string,mixed> $parameters URL and tenant-context parameters.
	 * @return string|false Public object URL, or false when context or signing fails.
	 */
	public function objectUrlFor(array $reference, array $parameters=[]): bool|string {
		return \dataphyre\vestra::object_url($reference, $parameters);
	}

	/**
	 * Builds a current public Vestra Fabric URL for an asset reference.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param string $extension Asset extension to expose in the URL.
	 * @param array<string,mixed> $parameters URL and tenant-context parameters.
	 * @return string|false Public asset URL, or false when context or signing fails.
	 */
	public function assetUrl(array $reference, string $extension='', array $parameters=[]): bool|string {
		return \dataphyre\vestra::asset_url($reference, $extension, $parameters);
	}

	/**
	 * Updates Vestra object use count through the kernel.
	 *
	 * This mutates Dataphyre application accounting and can trigger remote purge
	 * when an object reference count reaches zero.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param int $amount Signed delta to apply to the use count.
	 * @return bool|int New positive count, 0 after purge, or false on failure.
	 */
	public function updateUseCount(array $reference, int $amount): bool|int {
		return \dataphyre\vestra::update_use_count($reference, $amount);
	}

	/**
	 * Rewrites resource references in HTML through Vestra ingestion.
	 *
	 * Delegates resource scanning and propagation to the kernel, then wraps the
	 * returned array in an `IngestionResult` value object.
	 *
	 * @param string $html HTML or CSS-containing markup to rewrite.
	 * @param ?int $resourceLimit Maximum number of new resources to propagate.
	 * @param array<string,array<string,mixed>> $knownChanges Existing URL-to-reference map.
	 * @return IngestionResult Rewritten HTML and new resource mapping.
	 */
	public function ingest(string $html, ?int $resourceLimit=null, array $knownChanges=[]): IngestionResult {
		return IngestionResult::fromArray(
			\dataphyre\vestra::ingest_resources($html, $resourceLimit, $knownChanges)
		);
	}

	/**
	 * Pushes a file path or remote URL into Vestra object storage.
	 *
	 * Local files may be copied into the Vestra cache and de-duplicated by the kernel.
	 * Remote URLs are passed to the Vestra API as origins.
	 *
	 * @param string $file Local file path or remote URL.
	 * @param bool $encryption True when the Vestra should store the resource encrypted.
	 * @return array<string,mixed>|false Vestra Fabric reference, or false on failure.
	 */
	public function propagate(string $file, bool $encryption=false): bool|array {
		return \dataphyre\vestra::propagate($file, $encryption);
	}
}
