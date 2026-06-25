<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes a Panel plugin package, configuration, and lifecycle capability.
 *
 * The manifest redacts sensitive configuration values while preserving enough
 * metadata for diagnostics and panel tooling to explain installed
 * plugin state.
 */
final class PluginManifest {

	/**
	 * Stores the plugin source, runtime configuration, and manifest metadata.
	 *
	 * @param PanelPlugin|array<string, mixed>|string $plugin Plugin source definition.
	 * @param array<string, mixed> $config Runtime plugin configuration.
	 * @param array<string, mixed> $meta Caller metadata carried into the manifest.
	 */
	private function __construct(
		private readonly PanelPlugin|array|string $plugin,
		private readonly array $config=[],
		private readonly array $meta=[]
	){}

	/**
	 * Creates a plugin manifest descriptor.
	 *
	 * @param PanelPlugin|array<string, mixed>|string $plugin Plugin instance, array definition, or class/id string.
	 * @param array<string, mixed> $config Runtime plugin configuration.
	 * @param array<string, mixed> $meta Caller metadata merged into the manifest.
	 * @return self Immutable manifest builder.
	 */
	public static function from(PanelPlugin|array|string $plugin, array $config=[], array $meta=[]): self {
		return new self($plugin, $config, $meta);
	}

	/**
	 * Builds the plugin registration summary consumed by panel discovery.
	 */
	public function toArray(): array {
		$definition=$this->definition();
		$id=Resource::normalizeName((string)($definition['id'] ?? $definition['name'] ?? 'plugin'));
		$config=is_array($definition['config'] ?? null) ? $definition['config'] : $this->config;
		$configKeys=is_array($definition['config_keys'] ?? null) ? array_values(array_map('strval', $definition['config_keys'])) : array_keys($config);
		$manifest=[
			'type'=>'plugin_manifest',
			'id'=>$id,
			'name'=>$id,
			'label'=>(string)($definition['label'] ?? self::humanize($id)),
			'version'=>$definition['version'] ?? null,
			'description'=>$definition['description'] ?? null,
			'class'=>$definition['class'] ?? null,
			'package'=>[
				'id'=>$id,
				'class'=>$definition['class'] ?? null,
				'version'=>$definition['version'] ?? null,
			],
			'configuration'=>[
				'keys'=>$configKeys,
				'key_count'=>count($configKeys),
				'configured'=>$configKeys!==[],
				'values'=>self::safeConfig($config),
			],
			'capabilities'=>self::capabilities($definition, $configKeys),
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('plugin.manifest.described', [
			'id'=>$manifest['id'],
			'class'=>(string)($manifest['class'] ?? ''),
			'config_keys'=>count($configKeys),
			'version'=>(string)($manifest['version'] ?? ''),
		]);
		return $manifest;
	}

	/**
	 * Normalizes the plugin source into a manifest definition.
	 *
	 * @return array<string, mixed> Plugin definition with id, class, config keys, and optional metadata.
	 */
	private function definition(): array {
		if($this->plugin instanceof PanelPlugin){
			$definition=[
				'id'=>Resource::normalizeName($this->plugin->id()),
				'class'=>$this->plugin::class,
				'config_keys'=>array_keys($this->config),
			];
			foreach(['label', 'version', 'description'] as $method){
				if(method_exists($this->plugin, $method)){
					$value=$this->plugin->{$method}();
					if(is_scalar($value) && trim((string)$value)!==''){
						$definition[$method]=trim((string)$value);
					}
				}
			}
			return $definition;
		}
		if(is_array($this->plugin)){
			return $this->plugin;
		}
		$class=trim($this->plugin);
		return [
			'id'=>Resource::normalizeName($class) ?: 'plugin',
			'class'=>class_exists($class) ? $class : null,
			'label'=>self::humanize($class),
			'config_keys'=>array_keys($this->config),
		];
	}

	/**
	 * Summarizes plugin metadata, configuration, lifecycle, and package support.
	 *
	 * @param array<string, mixed> $definition Normalized plugin definition.
	 * @param array<int, string> $configKeys Configuration keys exposed by the plugin.
	 * @return array<string, array<string, mixed>> Capability flags grouped by concern.
	 */
	private static function capabilities(array $definition, array $configKeys): array {
		$class=is_string($definition['class'] ?? null) ? (string)$definition['class'] : '';
		return [
			'metadata'=>[
				'has_label'=>is_string($definition['label'] ?? null) && trim((string)$definition['label'])!=='',
				'has_version'=>is_string($definition['version'] ?? null) && trim((string)$definition['version'])!=='',
				'has_description'=>is_string($definition['description'] ?? null) && trim((string)$definition['description'])!=='',
			],
			'configuration'=>[
				'keys'=>count($configKeys),
				'configured'=>$configKeys!==[],
			],
			'lifecycle'=>[
				'register'=>$class!=='' && method_exists($class, 'register'),
				'boot'=>$class!=='' && method_exists($class, 'boot'),
			],
			'package'=>[
				'class_available'=>$class!=='' && class_exists($class),
				'interface'=>$class!=='' && is_subclass_of($class, PanelPlugin::class),
			],
		];
	}

	/**
	 * Redacts sensitive plugin configuration values for manifest output.
	 *
	 * @param array<string, mixed> $config Raw plugin configuration.
	 * @return array<string, mixed> Redacted scalar-safe configuration preview.
	 */
	private static function safeConfig(array $config): array {
		$safe=[];
		foreach($config as $key=>$value){
			$key=(string)$key;
			if(preg_match('/(secret|token|password|key|credential)/i', $key)===1){
				$safe[$key]='[redacted]';
				continue;
			}
			$safe[$key]=is_scalar($value) || $value===null ? $value : get_debug_type($value);
		}
		return $safe;
	}

	/**
	 * Converts plugin ids or class names into fallback labels.
	 *
	 * @param string $value Plugin id, slug, or class name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace(['_', '-', '\\'], ' ', $value)) ?? $value);
		return $value==='' ? 'Plugin' : ucwords($value);
	}
}
