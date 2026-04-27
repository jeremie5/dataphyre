<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class TemplatingState {

	private function __construct(
		private bool $is_dev_mode,
		private string $cache_dir,
		private array $global_context,
		private bool $strict_mode,
		private array $template_contracts,
		private array $asset_policy
	){}

	public static function fromArray(array $state): self {
		return new self(
			(bool)($state['is_dev_mode'] ?? false),
			(string)($state['cache_dir'] ?? ''),
			is_array($state['global_context'] ?? null) ? $state['global_context'] : [],
			(bool)($state['strict_mode'] ?? false),
			is_array($state['template_contracts'] ?? null) ? $state['template_contracts'] : [],
			is_array($state['asset_policy'] ?? null) ? $state['asset_policy'] : []
		);
	}

	public function isDevMode(): bool {
		return $this->is_dev_mode;
	}

	public function cacheDir(): string {
		return $this->cache_dir;
	}

	public function globalContext(): array {
		return $this->global_context;
	}

	public function strictMode(): bool {
		return $this->strict_mode;
	}

	public function hasGlobal(string $key): bool {
		return array_key_exists($key, $this->global_context);
	}

	public function global(string $key, mixed $default=null): mixed {
		return $this->global_context[$key] ?? $default;
	}

	public function templateContracts(): array {
		return $this->template_contracts;
	}

	public function assetPolicy(): AssetPolicy {
		return AssetPolicy::fromArray($this->asset_policy);
	}

	public function hasTemplateContract(string $template_name): bool {
		return array_key_exists($this->normalizeTemplateName($template_name), $this->template_contracts);
	}

	public function templateContract(string $template_name): ?TemplateContract {
		$template_name=$this->normalizeTemplateName($template_name);
		if(!isset($this->template_contracts[$template_name]) || !is_array($this->template_contracts[$template_name])){
			return null;
		}
		return TemplateContract::fromArray($this->template_contracts[$template_name]);
	}

	public function toArray(): array {
		return [
			'is_dev_mode'=>$this->is_dev_mode,
			'cache_dir'=>$this->cache_dir,
			'global_context'=>$this->global_context,
			'strict_mode'=>$this->strict_mode,
			'template_contracts'=>$this->template_contracts,
			'asset_policy'=>$this->asset_policy,
		];
	}

	private function normalizeTemplateName(string $template_name): string {
		$resolved=realpath($template_name);
		return $resolved===false
			? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($template_name))
			: $resolved;
	}
}
