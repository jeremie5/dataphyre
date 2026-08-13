<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Deterministic capability graph for Panel-owned browser assets.
 *
 * The graph deliberately separates declared capabilities from the smaller set
 * that changes Panel's built-in CSS/JavaScript payload. Unknown declarations
 * are retained for host-owned asset adapters, but they can never select a
 * built-in source fragment or become part of an asset-route token.
 */
final class PanelAssetCapabilityManifest implements \JsonSerializable {
	public const SCHEMA_VERSION=1;

	/** @var list<string> */
	private const ORDER=[
		'shell', 'collection-layout', 'navigation', 'form', 'upload', 'editor', 'editor-assets', 'studio-editor', 'modal', 'record',
		'table', 'data-surface', 'board', 'chart', 'widget-runtime', 'auth', 'reactor', 'collaboration',
		'media', 'extensions', 'platform', 'quality-client',
	];

	/** @var array<string,list<string>> */
	private const DEPENDENCIES=[
		'collection-layout'=>['shell'],
		'navigation'=>['shell'],
		// Modal payloads can introduce arbitrary generated fields without a full
		// page navigation, so their runtime must already own every form/editor seam.
		'modal'=>['shell', 'form', 'upload', 'editor', 'editor-assets'],
		'record'=>['shell'],
		'table'=>['shell'],
		'data-surface'=>['shell'],
		'board'=>['table'],
		'widget-runtime'=>['shell'],
		'form'=>['shell'],
		'upload'=>['form'],
		'editor'=>['form'],
		'editor-assets'=>['editor'],
		'studio-editor'=>['shell', 'form'],
		'chart'=>['shell'],
		'auth'=>['form'],
		'reactor'=>['shell'],
		'collaboration'=>['reactor'],
		'media'=>['shell'],
		'extensions'=>['shell'],
		'platform'=>['shell'],
		'quality-client'=>['shell'],
	];

	/** Capabilities that alter at least one built-in aggregate payload. */
	private const BUNDLE_CAPABILITIES=[
		'shell', 'collection-layout', 'navigation', 'modal', 'record', 'table', 'data-surface', 'board', 'form',
		'editor', 'editor-assets', 'studio-editor', 'chart', 'widget-runtime', 'auth', 'reactor',
	];

	/** @var array<string,string> */
	private const ALIASES=[
		'core'=>'shell',
		'presentation'=>'collection-layout',
		'layout'=>'collection-layout',
		'brick'=>'collection-layout',
		'masonry'=>'collection-layout',
		'collection_layout'=>'collection-layout',
		'forms'=>'form',
		'tables'=>'table',
		'boards'=>'board',
		'records'=>'record',
		'uploads'=>'upload',
		'editors'=>'editor',
		'editor_assets'=>'editor-assets',
		'editorassets'=>'editor-assets',
		'asset-browser'=>'editor-assets',
		'studio_editor'=>'studio-editor',
		'studioeditor'=>'studio-editor',
		'quality_client'=>'quality-client',
		'qualityclient'=>'quality-client',
		'widget'=>'widget-runtime',
		'widgets'=>'widget-runtime',
		'interactive-widget'=>'widget-runtime',
		'data_surface'=>'data-surface',
		'datasurface'=>'data-surface',
		'virtual-collection'=>'data-surface',
		'virtualization'=>'data-surface',
		'plugins'=>'extensions',
		'extension'=>'extensions',
		'reactivity'=>'reactor',
		'live'=>'reactor',
	];

	/** @var list<string> */
	private array $requested;
	/** @var list<string> */
	private array $capabilities;
	private string $mode;

	/**
	 * @param list<string> $requested
	 * @param list<string> $capabilities
	 */
	private function __construct(array $requested, array $capabilities, string $mode) {
		$this->requested=$requested;
		$this->capabilities=$capabilities;
		$this->mode=$mode;
	}

	/**
	 * Builds a graph from strings, lists, or boolean maps.
	 *
	 * `full` is an explicit legacy fallback. Unknown modes also fail safe to
	 * `full`; a typo must never accidentally remove behavior from a response.
	 */
	public static function make(mixed $declarations=[], string $mode='capability'): self {
		$mode=self::normalizeName($mode);
		if(in_array($mode, ['physical', 'chunked', 'physical_chunks'], true)){
			$mode='physical';
		}
		elseif(in_array($mode, ['split', 'scoped', 'automatic', 'auto'], true)){
			$mode='capability';
		}
		if(!in_array($mode, ['capability', 'physical', 'full'], true)){
			$mode='full';
		}
		$requested=self::normalizeDeclarations($declarations);
		if(in_array('*', $requested, true)){
			$requested=self::ORDER;
		}
		$capabilities=self::dependencyClosure($requested);
		if(!in_array('shell', $capabilities, true)){
			$capabilities=self::dependencyClosure(array_merge(['shell'], $capabilities));
		}
		return new self($requested, $capabilities, $mode);
	}

	/** @return list<string> */
	public static function knownCapabilities(): array {
		return self::ORDER;
	}

	/** @return list<string> */
	public static function fullCapabilities(): array {
		return self::ORDER;
	}

	/** @return list<string> */
	public function requested(): array {
		return $this->requested;
	}

	/** @return list<string> */
	public function capabilities(): array {
		return $this->capabilities;
	}

	/** @return list<string> */
	public function bundleCapabilities(): array {
		return array_values(array_filter(
			$this->capabilities,
			static fn(string $capability): bool=>in_array($capability, self::BUNDLE_CAPABILITIES, true),
		));
	}

	public function mode(): string {
		return $this->mode;
	}

	public function isFull(): bool {
		return $this->mode==='full';
	}

	public function isPhysical(): bool {
		return $this->mode==='physical';
	}

	public function has(string $capability): bool {
		$capability=self::alias(self::normalizeName($capability));
		return $capability!=='' && in_array($capability, $this->capabilities, true);
	}

	/**
	 * Returns the allow-listed token carried by capability-scoped asset URLs.
	 */
	public function token(): string {
		return implode('.', $this->bundleCapabilities());
	}

	/**
	 * Decodes a route token without accepting unknown or duplicate names.
	 *
	 * @return ?list<string> Null means the request must fail closed as an unknown
	 * asset variant rather than silently serving a different capability bundle.
	 */
	public static function decodeToken(string $token): ?array {
		$token=trim($token);
		if($token==='' || strlen($token)>240 || preg_match('/\A[a-z0-9.-]+\z/', $token)!==1){
			return null;
		}
		$parts=explode('.', $token);
		if($parts===[] || count($parts)!==count(array_unique($parts))){
			return null;
		}
		foreach($parts as $part){
			if(!in_array($part, self::BUNDLE_CAPABILITIES, true)){
				return null;
			}
		}
		$manifest=self::make($parts);
		return hash_equals($manifest->token(), $token) ? $manifest->bundleCapabilities() : null;
	}

	/** @return list<string> Logical stylesheet chunks in stable cascade order. */
	public function styleChunks(): array {
		$chunks=['core'];
		foreach([
			'collection-layout'=>'collection-layout',
			'record'=>'record', 'table'=>'table', 'data-surface'=>'data-surface', 'board'=>'board', 'form'=>'form',
			'editor-assets'=>'editor-assets',
			'studio-editor'=>'studio-editor',
			'modal'=>'modal', 'reactor'=>'reactor', 'chart'=>'chart',
			'widget-runtime'=>'widget-runtime', 'navigation'=>'navigation', 'auth'=>'auth',
		] as $chunk=>$capability){
			if($this->has($capability)){
				$chunks[]=$chunk;
			}
		}
		return $chunks;
	}

	/** @return list<string> Runtime source chunks in executable order. */
	public function runtimeChunks(): array {
		// State/table and navigation currently also own cross-surface focus,
		// refresh, dirty-state, and responsive lifecycle helpers. They remain core
		// chunks until those shared seams move into the runtime kernel.
		$chunks=['kernel', 'shell', 'command', 'state-table', 'navigation'];
		$chunks[]='transport';
		if($this->has('form')){ $chunks[]='form'; }
		if($this->has('editor')){ $chunks[]='editor'; }
		if($this->has('studio-editor')){ $chunks[]='studio-editor'; }
		// Validation/upload also owns generic contrast, adaptive-grid, and input
		// policy helpers consumed by the accessibility controller.
		$chunks[]='validation-upload';
		$chunks[]='accessibility';
		$chunks[]='theme';
		if($this->has('data-surface')){ $chunks[]='data-surface'; }
		if($this->has('widget-runtime')){ $chunks[]='widget-runtime'; }
		if($this->has('modal')){ $chunks[]='modal'; }
		if($this->has('board')){ $chunks[]='board'; }
		return $chunks;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$base=[
			'schema_version'=>self::SCHEMA_VERSION,
			'mode'=>$this->mode,
			'requested'=>$this->requested,
			'capabilities'=>$this->capabilities,
			'bundle_capabilities'=>$this->bundleCapabilities(),
			'token'=>$this->token(),
			'style_chunks'=>$this->styleChunks(),
			'runtime_chunks'=>$this->runtimeChunks(),
		];
		$base['id']=substr(hash('sha256', json_encode($base, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)), 0, 20);
		return $base;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/** @return list<string> */
	private static function normalizeDeclarations(mixed $declarations): array {
		if(is_string($declarations)){
			$declarations=preg_split('/[\s,]+/', $declarations, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		}
		elseif(is_scalar($declarations)){
			$declarations=[(string)$declarations];
		}
		if(!is_array($declarations)){
			return [];
		}
		$normalized=[];
		foreach($declarations as $key=>$value){
			if(is_string($key)){
				if(in_array($value, [false, 0, 0.0, '0', 'false', 'off', 'no', null], true)){
					continue;
				}
				$raw=$key;
			}
			else {
				$raw=is_scalar($value) ? (string)$value : '';
			}
			$name=self::alias(self::normalizeName($raw));
			if($name!=='' && ($name==='*' || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name)===1)){
				$normalized[$name]=true;
			}
		}
		$names=array_keys($normalized);
		return self::ordered($names, true);
	}

	/** @param list<string> $requested @return list<string> */
	private static function dependencyClosure(array $requested): array {
		$resolved=[];
		$visit=function(string $capability) use (&$visit, &$resolved): void {
			if(isset($resolved[$capability])){ return; }
			foreach(self::DEPENDENCIES[$capability] ?? [] as $dependency){
				$visit($dependency);
			}
			$resolved[$capability]=true;
		};
		foreach($requested as $capability){
			$visit($capability);
		}
		return self::ordered(array_keys($resolved), true);
	}

	/** @param list<string> $names @return list<string> */
	private static function ordered(array $names, bool $includeUnknown): array {
		$set=array_fill_keys($names, true);
		$result=[];
		foreach(self::ORDER as $name){
			if(isset($set[$name])){
				$result[]=$name;
				unset($set[$name]);
			}
		}
		if($includeUnknown && $set!==[]){
			$unknown=array_keys($set);
			sort($unknown, SORT_STRING);
			array_push($result, ...$unknown);
		}
		return $result;
	}

	private static function alias(string $name): string {
		return self::ALIASES[$name] ?? $name;
	}

	private static function normalizeName(string $name): string {
		$name=strtolower(trim($name));
		if($name==='*'){ return '*'; }
		$name=preg_replace('/[^a-z0-9_-]+/', '_', $name) ?? '';
		return trim($name, '_-');
	}
}
