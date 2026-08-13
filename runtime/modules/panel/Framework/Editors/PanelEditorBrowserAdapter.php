<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Inert browser-editor integration descriptor.
 *
 * The server declares an adapter contract but never claims that a browser
 * dependency is loaded. The separately cacheable Panel editor package probes
 * required globals or a host-registered bridge and preserves the canonical
 * textarea whenever an enhancement is unavailable.
 */
final class PanelEditorBrowserAdapter implements \JsonSerializable {
	public const SCHEMA_VERSION=1;
	public const KIND_SURFACE='surface';
	public const KIND_SYNTAX='syntax';
	public const STRATEGY_GLOBAL='global';
	public const STRATEGY_REGISTRY='registry';

	/** @var list<string> */
	private array $modes=[];
	/** @var list<string> */
	private array $languages=[];
	/** @var list<string> */
	private array $capabilities=[];
	/** @var list<string> */
	private array $requiredGlobals=[];
	private array $options=[];
	private string $fallback='native';
	private string $strategy=self::STRATEGY_REGISTRY;
	private bool $enabled=true;

	private function __construct(private string $adapterKind, private string $adapterName, private string $driverName) {
		$this->adapterKind=in_array($adapterKind, [self::KIND_SURFACE,self::KIND_SYNTAX], true) ? $adapterKind : self::KIND_SURFACE;
		$this->driverName=PanelEditorManifest::name($driverName);
		$this->adapterName=PanelEditorManifest::name($adapterName, $this->driverName);
	}

	/** Declares a host-registered browser editing surface. */
	public static function surface(string $name, string $driver='', array|string $modes='*'): self {
		$adapter=new self(self::KIND_SURFACE, $name, $driver!=='' ? $driver : $name);
		return $adapter->modes($modes)->capabilities(['source_sync','lifecycle']);
	}

	/** Declares a host-registered browser tokenization adapter. */
	public static function syntax(string $name, string $driver='', array|string $languages='*'): self {
		$adapter=new self(self::KIND_SYNTAX, $name, $driver!=='' ? $driver : $name);
		return $adapter->languages($languages)->fallback('source')->capabilities(['text_tokens']);
	}

	/** Rehydrates an inert descriptor without binding executable browser state. */
	public static function fromArray(array $definition): self {
		$declaredKind=PanelEditorManifest::name((string)($definition['kind'] ?? self::KIND_SURFACE), self::KIND_SURFACE);
		$validKind=in_array($declaredKind, [self::KIND_SURFACE,self::KIND_SYNTAX], true);
		$kind=$validKind ? $declaredKind : self::KIND_SURFACE;
		$name=(string)($definition['name'] ?? $definition['driver'] ?? '');
		$driver=(string)($definition['driver'] ?? $name);
		$adapter=new self($kind, $name, $driver);
		$adapter=$adapter
			->modes(is_array($definition['modes'] ?? null) || is_string($definition['modes'] ?? null) ? $definition['modes'] : [])
			->languages(is_array($definition['languages'] ?? null) || is_string($definition['languages'] ?? null) ? $definition['languages'] : [])
			->capabilities(is_array($definition['capabilities'] ?? null) || is_string($definition['capabilities'] ?? null) ? $definition['capabilities'] : [])
			->requiredGlobals(is_array($definition['required_globals'] ?? null) || is_string($definition['required_globals'] ?? null) ? $definition['required_globals'] : [])
			->options(is_array($definition['options'] ?? null) ? $definition['options'] : [])
			->fallback((string)($definition['fallback'] ?? ($kind===self::KIND_SYNTAX ? 'source' : 'native')))
			->strategy((string)($definition['strategy'] ?? self::STRATEGY_REGISTRY))
			->enabled($validKind && (bool)($definition['enabled'] ?? true));
		return $adapter;
	}

	/** First-party direct-global TinyMCE bridge descriptor. */
	public static function tinyMce(array $options=[]): self {
		return self::surface('tinymce', 'tinymce', ['rich_text','html'])
			->strategy(self::STRATEGY_GLOBAL)->requiredGlobals('tinymce')->options($options)
			->capabilities(['rich_text','commands','async_mount']);
	}

	/** First-party direct-global CKEditor 5 classic-build bridge descriptor. */
	public static function ckEditor5(array $options=[]): self {
		return self::surface('ckeditor5', 'ckeditor5', ['rich_text','html'])
			->strategy(self::STRATEGY_GLOBAL)->requiredGlobals('ClassicEditor')->options($options)
			->capabilities(['rich_text','commands','async_mount']);
	}

	/** First-party direct-global Monaco bridge descriptor. */
	public static function monaco(array $options=[]): self {
		return self::surface('monaco', 'monaco', ['code','plain','markdown','html'])
			->strategy(self::STRATEGY_GLOBAL)->requiredGlobals('monaco.editor')->fallback('source')->options($options)
			->capabilities(['code','commands','language']);
	}

	/** First-party host-registry descriptor for module-oriented Tiptap builds. */
	public static function tiptap(array $options=[]): self {
		return self::surface('tiptap', 'tiptap', ['rich_text','html'])->options($options)
			->capabilities(['rich_text','commands','module_bridge']);
	}

	/** First-party host-registry descriptor for module-oriented CodeMirror 6 builds. */
	public static function codeMirror6(array $options=[]): self {
		return self::surface('codemirror6', 'codemirror6', ['code','plain','markdown','html'])->fallback('source')->options($options)
			->capabilities(['code','commands','language','module_bridge']);
	}

	/** First-party direct-global Prism token adapter descriptor. */
	public static function prism(array|string $languages='*', array $options=[]): self {
		return self::syntax('prism', 'prism', $languages)->strategy(self::STRATEGY_GLOBAL)
			->requiredGlobals('Prism')->options($options);
	}

	/** First-party direct-global highlight.js token adapter descriptor. */
	public static function highlightJs(array|string $languages='*', array $options=[]): self {
		return self::syntax('highlightjs', 'highlightjs', $languages)->strategy(self::STRATEGY_GLOBAL)
			->requiredGlobals('hljs')->options($options);
	}

	public function modes(array|string $modes): self {
		$clone=clone $this; $clone->modes=[];
		foreach((array)$modes as $mode){
			$mode=trim((string)$mode)==='*' ? '*' : PanelEditorContext::normalizeMode((string)$mode);
			if($mode!=='' && !in_array($mode, $clone->modes, true)){ $clone->modes[]=$mode; }
		}
		return $clone;
	}

	public function languages(array|string $languages): self {
		$clone=clone $this; $clone->languages=[];
		foreach((array)$languages as $language){
			$language=trim((string)$language)==='*' ? '*' : PanelEditorContext::normalizeLanguage((string)$language);
			if($language!=='' && !in_array($language, $clone->languages, true)){ $clone->languages[]=$language; }
		}
		return $clone;
	}

	public function capabilities(array|string $capabilities): self {
		$clone=clone $this;
		foreach((array)$capabilities as $capability){
			$capability=PanelEditorManifest::name((string)$capability);
			if($capability!=='' && !in_array($capability, $clone->capabilities, true)){ $clone->capabilities[]=$capability; }
		}
		return $clone;
	}

	public function requiredGlobals(array|string $paths): self {
		$clone=clone $this; $clone->requiredGlobals=[];
		foreach((array)$paths as $path){
			$path=trim((string)$path);
			if(preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*\z/', $path)!==1){ continue; }
			if(!in_array($path, $clone->requiredGlobals, true)){ $clone->requiredGlobals[]=$path; }
		}
		return $clone;
	}

	public function options(array $options): self { $clone=clone $this; $clone->options=PanelEditorManifest::sanitize($options); return $clone; }
	public function fallback(string $fallback): self { $clone=clone $this; $fallback=PanelEditorManifest::name($fallback, 'native'); $clone->fallback=in_array($fallback, ['native','source','error'], true) ? $fallback : 'native'; return $clone; }
	public function strategy(string $strategy): self { $clone=clone $this; $strategy=PanelEditorManifest::name($strategy, self::STRATEGY_REGISTRY); $clone->strategy=in_array($strategy, [self::STRATEGY_GLOBAL,self::STRATEGY_REGISTRY], true) ? $strategy : self::STRATEGY_REGISTRY; return $clone; }
	public function enabled(bool $enabled=true): self { $clone=clone $this; $clone->enabled=$enabled; return $clone; }

	public function kind(): string { return $this->adapterKind; }
	public function name(): string { return $this->adapterName; }
	public function driver(): string { return $this->driverName; }
	public function fallbackMode(): string { return $this->fallback; }
	public function loadStrategy(): string { return $this->strategy; }
	public function isEnabled(): bool { return $this->enabled; }
	public function isConfigured(): bool { return $this->enabled && $this->adapterName!=='' && $this->driverName!==''; }
	public function isSurface(): bool { return $this->adapterKind===self::KIND_SURFACE; }
	public function isSyntax(): bool { return $this->adapterKind===self::KIND_SYNTAX; }
	public function supportsMode(string $mode): bool { return $this->isConfigured() && $this->isSurface() && (in_array('*', $this->modes, true) || in_array(PanelEditorContext::normalizeMode($mode), $this->modes, true)); }
	public function supportsLanguage(string $language): bool { return $this->isConfigured() && $this->isSyntax() && (in_array('*', $this->languages, true) || in_array(PanelEditorContext::normalizeLanguage($language), $this->languages, true)); }

	public function manifest(): array {
		return PanelEditorManifest::sanitize([
			'schema_version'=>self::SCHEMA_VERSION,
			'kind'=>$this->adapterKind,
			'name'=>$this->adapterName,
			'driver'=>$this->driverName,
			'strategy'=>$this->strategy,
			'modes'=>$this->modes,
			'languages'=>$this->languages,
			'capabilities'=>$this->capabilities,
			'required_globals'=>$this->requiredGlobals,
			'fallback'=>$this->fallback,
			'enabled'=>$this->enabled,
			'configured'=>$this->isConfigured(),
			'runtime_probe'=>'browser',
			'options'=>$this->options,
		]);
	}

	public function toArray(): array { return $this->manifest(); }
	public function jsonSerialize(): array { return $this->manifest(); }
}
