<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Cdn;

final class CdnManager {

	private static ?self $instance=null;

	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function configured(): bool {
		return \dataphyre\cdn::configured();
	}

	public function baseUrl(): string {
		return trim((string)(DP_CDN_CFG['base_url'] ?? ''));
	}

	public function blockStorageUrl(): string {
		return trim((string)(DP_CDN_CFG['block_storage_url'] ?? ''));
	}

	public function blockUrl(string $encoded_blockpath, array $parameters=[]): string {
		return \dataphyre\cdn::block_url($encoded_blockpath, $parameters);
	}

	public function assetUrl(string $blockpath, string $extension='', array $parameters=[]): string {
		return \dataphyre\cdn::asset_url($blockpath, $extension, $parameters);
	}

	public function updateUseCount(string $blockpath, int $amount): bool|int {
		return \dataphyre\cdn::update_use_count($blockpath, $amount);
	}

	public function ingest(string $html, ?int $resource_limit=null, array $known_changes=[]): IngestionResult {
		return IngestionResult::fromArray(
			\dataphyre\cdn::ingest_resources($html, $resource_limit, $known_changes)
		);
	}

	public function propagate(string $file, bool $encryption=false): bool|string {
		return \dataphyre\cdn::propagate($file, $encryption);
	}
}
