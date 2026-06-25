<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes the active panel theme and theme library state.
 *
 * Theme manifests expose resolved token payloads, dark-mode availability, brand
 * assets, theme-library diagnostics, and optional preview data for Flightdeck,
 * panel tooling, and shell clients. Manifest generation does not activate or mutate
 * the current theme.
 */
final class ThemeManifest {

	/**
	 * Stores the theme source and rendering options.
	 *
	 * @param PanelTheme|array|string|null $theme Theme object, serialized theme, named theme, or null for the active panel theme.
	 * @param array<string,mixed> $meta Caller-supplied manifest metadata.
	 * @param bool $includePreview True to include the heavier preview payload.
	 */
	private function __construct(
		private readonly PanelTheme|array|string|null $theme=null,
		private readonly array $meta=[],
		private readonly bool $includePreview=false
	){}

	/**
	 * Creates a theme manifest builder.
	 *
	 * @param PanelTheme|array|string|null $theme Theme source to describe.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @param bool $includePreview True to include preview colors, mode samples, and contrast checks.
	 * @return self New immutable manifest builder.
	 */
	public static function from(PanelTheme|array|string|null $theme=null, array $meta=[], bool $includePreview=false): self {
		return new self($theme, $meta, $includePreview);
	}

	/**
	 * Materializes the theme_manifest payload.
	 *
	 * The payload keeps raw resolved theme arrays intact while adding normalized
	 * counters and capabilities so operators can compare active theme
	 * coverage against the registered theme library.
	 *
	 * @return array<string,mixed> Theme manifest payload.
	 */
	public function toArray(): array {
		$theme=$this->theme();
		$active=$theme->toArray();
		$library=PanelTheme::themeLibrary();
		$diagnostics=PanelTheme::diagnostics();
		$manifest=[
			'type'=>'theme_manifest',
			'name'=>(string)($active['name'] ?? 'default'),
			'active'=>$active,
			'library'=>[
				'presets'=>is_array($diagnostics['preset_names'] ?? null) ? array_values($diagnostics['preset_names']) : array_keys($library->all()),
				'themes'=>is_array($diagnostics['theme_names'] ?? null) ? array_values($diagnostics['theme_names']) : array_keys($library->allThemes()),
				'counts'=>[
					'presets'=>(int)($diagnostics['presets'] ?? count($library->all())),
					'themes'=>(int)($diagnostics['themes'] ?? count($library->allThemes())),
					'resolved_themes'=>(int)($diagnostics['resolved_themes'] ?? count($library->allThemes())),
					'pending_themes'=>(int)($diagnostics['pending_themes'] ?? 0),
				],
			],
			'diagnostics'=>$diagnostics,
			'tokens'=>[
				'light'=>is_array($active['tokens'] ?? null) ? $active['tokens'] : [],
				'dark'=>is_array($active['dark_tokens'] ?? null) ? $active['dark_tokens'] : [],
				'variables'=>is_array($active['variables'] ?? null) ? $active['variables'] : [],
				'dark_variables'=>is_array($active['dark_variables'] ?? null) ? $active['dark_variables'] : [],
			],
			'modes'=>[
				'dark_mode'=>($active['dark_mode'] ?? false)===true,
				'default'=>(string)($active['default_mode'] ?? 'system'),
				'toggle'=>($active['mode_toggle'] ?? false)===true,
				'available'=>($active['dark_mode'] ?? false)===true ? ['light', 'dark', 'system'] : ['light'],
			],
			'assets'=>[
				'asset_roots'=>is_array($active['asset_roots'] ?? null) ? $active['asset_roots'] : [],
				'font'=>$active['font'] ?? null,
				'font_url'=>$active['font_url'] ?? null,
				'font_provider'=>$active['font_provider'] ?? null,
				'favicon'=>$active['favicon'] ?? null,
				'brand'=>is_array($active['brand'] ?? null) ? $active['brand'] : [],
				'stylesheets'=>is_array($active['css_assets'] ?? null) ? $active['css_assets'] : [],
			],
			'capabilities'=>self::capabilities($active, $diagnostics),
			'meta'=>$this->meta,
		];
		if($this->includePreview){
			$manifest['preview']=$theme->preview();
		}
		PanelTrace::record('theme.manifest.described', [
			'name'=>$manifest['name'],
			'tokens'=>(int)($manifest['capabilities']['tokens']['light'] ?? 0),
			'dark_mode'=>($manifest['modes']['dark_mode'] ?? false)===true,
			'presets'=>(int)($manifest['library']['counts']['presets'] ?? 0),
		]);
		return $manifest;
	}

	/**
	 * Resolves the requested theme source into a PanelTheme instance.
	 *
	 * String sources first resolve named themes, then fall back to a same-named
	 * preset builder so missing custom themes still produce a useful manifest.
	 *
	 * @return PanelTheme Theme instance to describe.
	 */
	private function theme(): PanelTheme {
		if($this->theme instanceof PanelTheme){
			return $this->theme;
		}
		if(is_array($this->theme)){
			return PanelTheme::fromArray($this->theme);
		}
		if(is_string($this->theme) && trim($this->theme)!==''){
			$name=Resource::normalizeName($this->theme);
			return PanelTheme::namedTheme($name) ?? PanelTheme::make($name)->preset($name);
		}
		return Panel::theme();
	}

	/**
	 * Summarizes theme-token, mode, asset, and library capabilities.
	 *
	 * @param array<string,mixed> $theme Resolved active theme array.
	 * @param array<string,mixed> $diagnostics Theme-library diagnostics.
	 * @return array<string,mixed> Capability summary payload.
	 */
	private static function capabilities(array $theme, array $diagnostics): array {
		$colors=is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
		$brand=is_array($theme['brand'] ?? null) ? $theme['brand'] : [];
		$stylesheets=is_array($theme['css_assets'] ?? null) ? $theme['css_assets'] : [];
		$missingBases=is_array($diagnostics['missing_bases'] ?? null) ? count($diagnostics['missing_bases']) : 0;
		$missingPresets=is_array($diagnostics['missing_presets'] ?? null) ? count($diagnostics['missing_presets']) : 0;
		$cycles=is_array($diagnostics['cycles'] ?? null) ? count($diagnostics['cycles']) : 0;
		return [
			'colors'=>[
				'palettes'=>count($colors),
				'shades'=>array_sum(array_map(static fn(mixed $palette): int => is_array($palette) ? count($palette) : 0, $colors)),
			],
			'tokens'=>[
				'light'=>is_array($theme['tokens'] ?? null) ? count($theme['tokens']) : 0,
				'dark'=>is_array($theme['dark_tokens'] ?? null) ? count($theme['dark_tokens']) : 0,
				'variables'=>is_array($theme['variables'] ?? null) ? count($theme['variables']) : 0,
				'dark_variables'=>is_array($theme['dark_variables'] ?? null) ? count($theme['dark_variables']) : 0,
			],
			'modes'=>[
				'total'=>($theme['dark_mode'] ?? false)===true ? 3 : 1,
				'dark'=>($theme['dark_mode'] ?? false)===true,
				'toggle'=>($theme['mode_toggle'] ?? false)===true,
			],
			'assets'=>[
				'asset_roots'=>is_array($theme['asset_roots'] ?? null) ? count($theme['asset_roots']) : 0,
				'stylesheets'=>count($stylesheets),
				'font'=>is_string($theme['font'] ?? null) && trim((string)$theme['font'])!=='',
				'favicon'=>is_string($theme['favicon'] ?? null) && trim((string)$theme['favicon'])!=='',
				'brand'=>is_string($brand['name'] ?? null) && trim((string)$brand['name'])!=='',
				'brand_logo'=>is_string($brand['logo'] ?? null) && trim((string)$brand['logo'])!=='',
			],
			'library'=>[
				'presets'=>(int)($diagnostics['presets'] ?? 0),
				'themes'=>(int)($diagnostics['themes'] ?? 0),
				'resolved_themes'=>(int)($diagnostics['resolved_themes'] ?? 0),
				'pending_themes'=>(int)($diagnostics['pending_themes'] ?? 0),
				'blocking_issues'=>$missingBases + $missingPresets + $cycles,
			],
		];
	}
}
