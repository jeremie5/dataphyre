<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Vestra;

/**
 * Static facade for Dataphyre Vestra object operations.
 *
 * Client keeps application call sites small while VestraManager owns configuration,
 * URL generation, HTML ingestion, usage accounting, and propagation to object
 * storage.
 */
final class Client {

	/**
	 * Returns the shared Vestra manager instance.
	 *
	 * @return VestraManager Manager that owns Vestra configuration and runtime side effects.
	 */
	public static function manager(): VestraManager {
		return VestraManager::instance();
	}

	/**
	 * Reports whether Vestra configuration is available for runtime operations.
	 *
	 * @return bool True when the manager has enough configuration to generate URLs and propagate assets.
	 */
	public static function configured(): bool {
		return self::manager()->configured();
	}

	/**
	 * Returns the public base URL used for Vestra assets.
	 *
	 * @return string Base URL without an object-specific path.
	 */
	public static function baseUrl(): string {
		return self::manager()->baseUrl();
	}

	/**
	 * Returns the configured object URL.
	 *
	 * @return string Object delivery endpoint.
	 */
	public static function objectUrl(): string {
		return self::manager()->objectUrl();
	}

	/**
	 * Builds a current Vestra Fabric URL for a reference.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param array<string, mixed> $parameters Optional URL and tenant-context parameters.
	 * @return string|false Public URL for the object, or false when context or signing fails.
	 */
	public static function objectUrlFor(array $reference, array $parameters=[]): bool|string {
		return self::manager()->objectUrlFor($reference, $parameters);
	}

	/**
	 * Builds a current Vestra Fabric asset URL from a reference.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param string $extension Optional extension appended to the generated URL.
	 * @param array<string, mixed> $parameters Optional URL and tenant-context parameters.
	 * @return string|false Public URL for the asset, or false when context or signing fails.
	 */
	public static function assetUrl(array $reference, string $extension='', array $parameters=[]): bool|string {
		return self::manager()->assetUrl($reference, $extension, $parameters);
	}

	public static function asset_url(array $reference, string $extension='', array $parameters=[]): bool|string {
		return self::assetUrl($reference, $extension, $parameters);
	}

	/**
	 * Updates usage accounting for a Vestra object.
	 *
	 * The manager owns persistence and may update SQL counters, local metadata, or
	 * remote usage records depending on configuration.
	 *
	 * @param array<string,mixed> $reference Reference whose usage count should change.
	 * @param int $amount Signed amount to add to the usage counter.
	 * @return int|false Updated count or affected-row count, or false when the update fails.
	 */
	public static function updateUseCount(array $reference, int $amount): bool|int {
		return self::manager()->updateUseCount($reference, $amount);
	}

	/**
	 * Ingests external resources found in HTML and rewrites them to Vestra objects.
	 *
	 * The manager discovers eligible resources, applies the resource limit, uses
	 * known changes to avoid redundant work, propagates resources as needed, and
	 * returns the rewritten HTML plus ingestion diagnostics.
	 *
	 * @param string $html HTML document or fragment to inspect.
	 * @param int|null $resourceLimit Maximum number of resources to ingest, or manager default.
	 * @param array<string, mixed> $knownChanges Previously known resource changes used for incremental ingestion.
	 * @return IngestionResult Rewritten HTML, changed resources, skipped resources, and diagnostics.
	 */
	public static function ingest(string $html, ?int $resourceLimit=null, array $knownChanges=[]): IngestionResult {
		return self::manager()->ingest($html, $resourceLimit, $knownChanges);
	}

	/**
	 * Propagates a local file into Vestra object storage.
	 *
	 * Propagation can read from the filesystem and write to configured storage. When
	 * encryption is enabled, the manager applies the configured encryption workflow
	 * before or during storage.
	 *
	 * @param string $file Local file path to propagate.
	 * @param bool $encryption Whether the propagated object should be encrypted.
	 * @return array<string,mixed>|false Stored Vestra Fabric reference on success, or false when propagation fails.
	 */
	public static function propagate(string $file, bool $encryption=false): bool|array {
		return self::manager()->propagate($file, $encryption);
	}
}
