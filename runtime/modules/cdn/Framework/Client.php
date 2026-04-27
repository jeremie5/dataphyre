<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Cdn;

final class Client {

	public static function manager(): CdnManager {
		return CdnManager::instance();
	}

	public static function configured(): bool {
		return self::manager()->configured();
	}

	public static function baseUrl(): string {
		return self::manager()->baseUrl();
	}

	public static function blockStorageUrl(): string {
		return self::manager()->blockStorageUrl();
	}

	public static function blockUrl(string $encoded_blockpath, array $parameters=[]): string {
		return self::manager()->blockUrl($encoded_blockpath, $parameters);
	}

	public static function assetUrl(string $blockpath, string $extension='', array $parameters=[]): string {
		return self::manager()->assetUrl($blockpath, $extension, $parameters);
	}

	public static function encodeBlockPath(string $blockpath): string {
		return BlockPath::encode($blockpath);
	}

	public static function decodeBlockPath(string $blockpath): string {
		return BlockPath::decode($blockpath);
	}

	public static function blockPathToBlockId(string $blockpath): int {
		return BlockPath::toId($blockpath);
	}

	public static function blockIdToBlockPath(int $blockid): string {
		return BlockPath::fromId($blockid);
	}

	public static function updateUseCount(string $blockpath, int $amount): bool|int {
		return self::manager()->updateUseCount($blockpath, $amount);
	}

	public static function ingest(string $html, ?int $resource_limit=null, array $known_changes=[]): IngestionResult {
		return self::manager()->ingest($html, $resource_limit, $known_changes);
	}

	public static function propagate(string $file, bool $encryption=false): bool|string {
		return self::manager()->propagate($file, $encryption);
	}
}
