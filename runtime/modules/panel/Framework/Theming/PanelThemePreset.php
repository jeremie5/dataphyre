<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent theme preset builder for reusable panel visual systems.
 *
 * A preset collects color palette names, light and dark CSS tokens, asset namespace roots,
 * font metadata, dark-mode behavior, brand assets, stylesheet assets, favicon, and arbitrary
 * metadata before applying that bundle to a PanelTheme.
 */
final class PanelThemePreset {

	private string $name;
	private array $colors=[];
	private array $tokens=[];
	private array $darkTokens=[];
	private array $css=[];
	private array $assetRoots=[];
	private ?string $font=null;
	private ?string $fontUrl=null;
	private ?string $fontProvider=null;
	private ?bool $darkMode=null;
	private ?string $defaultMode=null;
	private ?bool $modeToggle=null;
	private array $brand=[];
	private ?string $favicon=null;
	private array $meta=[];

	/**
	 * Creates a preset builder with a normalized name.
	 *
	 * construction is private so preset instances enter through named
	 * factories. Blank or invalid names collapse to `preset`, keeping serialized
	 * theme payloads identifiable for manifests and diagnostics.
	 *
	 * @param string $name Raw preset identifier.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name) ?: 'preset';
	}

	/**
	 * Creates an empty preset with a normalized name.
	 *
	 * @param string $name Preset identifier.
	 * @return self Mutable preset builder.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Rebuilds a preset from a serialized theme definition.
	 *
	 * Recognized keys include colors, tokens, dark_tokens, asset_roots, font metadata,
	 * dark_mode/default_mode/mode_toggle, brand fields, favicon, css/css_assets, and meta.
	 *
	 * @param array<string,mixed> $definition Serialized preset payload.
	 * @return self Mutable preset populated from the definition.
	 */
	public static function fromArray(array $definition): self {
		$preset=self::make((string)($definition['name'] ?? 'preset'));
		if(isset($definition['colors']) && is_array($definition['colors'])){
			$preset=$preset->colors($definition['colors']);
		}
		if(isset($definition['tokens']) && is_array($definition['tokens'])){
			$preset=$preset->tokens($definition['tokens']);
		}
		if(isset($definition['dark_tokens']) && is_array($definition['dark_tokens'])){
			$preset=$preset->darkTokens($definition['dark_tokens']);
		}
		if(isset($definition['asset_roots']) && is_array($definition['asset_roots'])){
			$preset=$preset->assetRoots($definition['asset_roots']);
		}
		if(isset($definition['font'])){
			$preset=$preset->font((string)$definition['font'], isset($definition['font_url']) ? (string)$definition['font_url'] : null, isset($definition['font_provider']) ? (string)$definition['font_provider'] : null);
		}
		if(isset($definition['dark_mode'])){
			$preset=$preset->darkMode((bool)$definition['dark_mode']);
		}
		if(isset($definition['default_mode'])){
			$preset=$preset->defaultMode((string)$definition['default_mode']);
		}
		if(isset($definition['mode_toggle'])){
			$preset=$preset->modeToggle((bool)$definition['mode_toggle']);
		}
		if(isset($definition['brand']) && is_array($definition['brand'])){
			$preset=$preset->brand($definition['brand']);
		}
		foreach(['brand_name'=>'name', 'brand_logo'=>'logo', 'dark_brand_logo'=>'dark_logo', 'brand_logo_height'=>'logo_height'] as $key=>$brandKey){
			if(isset($definition[$key])){
				$preset=$preset->brand([$brandKey=>(string)$definition[$key]]);
			}
		}
		if(isset($definition['favicon'])){
			$preset=$preset->favicon((string)$definition['favicon']);
		}
		if(isset($definition['css'])){
			$preset=$preset->css($definition['css']);
		}
		if(isset($definition['css_assets'])){
			$preset=$preset->css($definition['css_assets']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$preset=$preset->meta($definition['meta']);
		}
		return $preset;
	}

	/**
	 * Creates the bundled brutalist preset.
	 *
	 * The preset uses heavy borders, hard shadows, square corners, saturated navigation accents,
	 * and explicit light/dark token overrides.
	 *
	 * @param string $name Preset identifier.
	 * @return self Brutalist preset builder.
	 */
	public static function brutalist(string $name='brutalist'): self {
		return self::make($name)
			->colors([
				'primary'=>'blue',
				'success'=>'green',
				'warning'=>'yellow',
				'danger'=>'red',
				'info'=>'cyan',
				'gray'=>'zinc',
			])
			->tokens([
				'theme_effects'=>'brutalist',
				'radius'=>'0px',
				'max_width'=>'none',
				'panel_padding'=>'18px',
				'section_padding'=>'14px',
				'control_padding'=>'9px 12px',
				'input_padding'=>'9px 12px',
				'table_cell_padding'=>'11px 12px',
				'gap'=>'12px',
				'body_bg'=>'#f4f1e8',
				'surface'=>'#fffdf4',
				'surface_muted'=>'#ede8d8',
				'text'=>'#111111',
				'text_muted'=>'#333333',
				'text_subtle'=>'#555555',
				'border'=>'#111111',
				'border_soft'=>'#111111',
				'control_bg'=>'#fffdf4',
				'control_border'=>'#111111',
				'neutral_bg'=>'#e6e0cf',
				'neutral_text'=>'#111111',
				'brutalist_shadow'=>'5px 5px 0 #111111',
				'brutalist_shadow_soft'=>'3px 3px 0 #111111',
				'brutalist_focus'=>'0 0 0 3px #fffdf4,0 0 0 6px #111111',
				'nav_shell_bg'=>'#fffdf4',
				'nav_shell_border'=>'#111111',
				'nav_shell_shadow'=>'5px 5px 0 #111111',
				'nav_brand_bg'=>'#facc15',
				'nav_brand_border'=>'#111111',
				'nav_search_bg'=>'#fffdf4',
				'nav_current_bg'=>'#dbeafe',
				'nav_current_border'=>'#111111',
				'nav_item_hover_bg'=>'#facc15',
				'nav_item_active_bg'=>'#2563eb',
				'nav_badge_bg'=>'#fffdf4',
				'nav_icon_bg'=>'#fffdf4',
				'nav_submenu_rail'=>'#111111',
			])
			->darkTokens([
				'body_bg'=>'#0b0b0b',
				'surface'=>'#171717',
				'surface_muted'=>'#242424',
				'text'=>'#f8f8f8',
				'text_muted'=>'#dedede',
				'text_subtle'=>'#b8b8b8',
				'border'=>'#f8f8f8',
				'border_soft'=>'#f8f8f8',
				'control_bg'=>'#0f0f0f',
				'control_border'=>'#f8f8f8',
				'neutral_bg'=>'#2a2a2a',
				'neutral_text'=>'#f8f8f8',
				'brutalist_shadow'=>'5px 5px 0 #f8f8f8',
				'brutalist_shadow_soft'=>'3px 3px 0 #f8f8f8',
				'brutalist_focus'=>'0 0 0 3px #0b0b0b,0 0 0 6px #f8f8f8',
				'nav_shell_bg'=>'#171717',
				'nav_shell_border'=>'#f8f8f8',
				'nav_brand_bg'=>'#1d4ed8',
				'nav_brand_border'=>'#f8f8f8',
				'nav_search_bg'=>'#0f0f0f',
				'nav_current_bg'=>'#1e3a8a',
				'nav_current_border'=>'#f8f8f8',
				'nav_item_hover_bg'=>'#3f3f46',
				'nav_item_active_bg'=>'#facc15',
				'nav_badge_bg'=>'#0f0f0f',
				'nav_icon_bg'=>'#0f0f0f',
				'nav_submenu_rail'=>'#f8f8f8',
			])
			->meta(['description'=>'Hard-edged admin surfaces with flat color, heavy borders, and almost no rounding.']);
	}

	/**
	 * Creates the bundled glass preset.
	 *
	 * The preset defines translucent surfaces, blur, soft shadows, shimmer variables, and
	 * light/dark navigation chrome suitable for glassmorphism panel layouts.
	 *
	 * @param string $name Preset identifier.
	 * @return self Glass preset builder.
	 */
	public static function glass(string $name='glass'): self {
		return self::make($name)
			->colors([
				'primary'=>'sky',
				'success'=>'emerald',
				'warning'=>'amber',
				'danger'=>'rose',
				'info'=>'cyan',
				'gray'=>'slate',
			])
			->tokens([
				'theme_effects'=>'glass',
				'radius'=>'16px',
				'max_width'=>'1560px',
				'panel_padding'=>'clamp(18px,2.4vw,34px)',
				'section_padding'=>'16px',
				'control_padding'=>'9px 13px',
				'input_padding'=>'10px 12px',
				'table_cell_padding'=>'12px 14px',
				'gap'=>'14px',
				'body_bg'=>'radial-gradient(circle at 12% -8%,rgba(14,165,233,.30),transparent 34rem),radial-gradient(circle at 92% 8%,rgba(168,85,247,.20),transparent 30rem),linear-gradient(135deg,#f8fbff 0%,#edf6ff 48%,#f7f2ff 100%)',
				'surface'=>'rgba(255,255,255,.62)',
				'surface_muted'=>'rgba(255,255,255,.38)',
				'text'=>'#0f172a',
				'text_muted'=>'#475569',
				'text_subtle'=>'#64748b',
				'border'=>'rgba(148,163,184,.36)',
				'border_soft'=>'rgba(226,232,240,.46)',
				'control_bg'=>'rgba(255,255,255,.66)',
				'control_border'=>'rgba(148,163,184,.42)',
				'neutral_bg'=>'rgba(255,255,255,.48)',
				'neutral_text'=>'#1e293b',
				'glass_surface_bg'=>'linear-gradient(135deg,rgba(255,255,255,.72),rgba(255,255,255,.38))',
				'glass_surface_strong_bg'=>'linear-gradient(135deg,rgba(255,255,255,.84),rgba(255,255,255,.50))',
				'glass_surface_muted_bg'=>'linear-gradient(135deg,rgba(255,255,255,.52),rgba(255,255,255,.28))',
				'glass_control_bg'=>'linear-gradient(135deg,rgba(255,255,255,.76),rgba(255,255,255,.46))',
				'glass_menu_bg'=>'linear-gradient(135deg,rgba(255,255,255,.90),rgba(255,255,255,.64))',
				'glass_overlay_bg'=>'rgba(15,23,42,.36)',
				'glass_highlight'=>'linear-gradient(135deg,rgba(255,255,255,.70),rgba(255,255,255,0) 48%)',
				'glass_edge'=>'inset 0 1px 0 rgba(255,255,255,.58), inset 0 -1px 0 rgba(255,255,255,.20)',
				'glass_noise_opacity'=>'.11',
				'glass_tone_strength'=>'14%',
				'glass_focus'=>'0 0 0 4px rgba(14,165,233,.18),0 18px 50px rgba(14,165,233,.13)',
				'glass_active_glow'=>'0 18px 42px rgba(14,165,233,.22)',
				'glass_shimmer'=>'linear-gradient(90deg,transparent,rgba(255,255,255,.46),transparent)',
				'glass_scroll_thumb'=>'rgba(14,165,233,.36)',
				'glass_scroll_track'=>'rgba(255,255,255,.24)',
				'glass_mobile_blur'=>'blur(14px) saturate(1.12)',
				'glass_border'=>'rgba(255,255,255,.52)',
				'glass_shadow'=>'0 22px 60px rgba(31,41,55,.13)',
				'glass_shadow_soft'=>'0 12px 34px rgba(31,41,55,.09)',
				'glass_shadow_lifted'=>'0 34px 90px rgba(31,41,55,.18)',
				'glass_blur'=>'blur(22px) saturate(1.22)',
				'nav_shell_bg'=>'linear-gradient(180deg,rgba(255,255,255,.64),rgba(255,255,255,.34))',
				'nav_shell_border'=>'rgba(255,255,255,.46)',
				'nav_shell_shadow'=>'0 24px 70px rgba(15,23,42,.14)',
				'nav_brand_bg'=>'rgba(255,255,255,.34)',
				'nav_brand_border'=>'rgba(255,255,255,.38)',
				'nav_search_bg'=>'rgba(255,255,255,.46)',
				'nav_current_bg'=>'linear-gradient(135deg,rgba(14,165,233,.18),rgba(255,255,255,.22))',
				'nav_current_border'=>'rgba(14,165,233,.24)',
				'nav_item_hover_bg'=>'rgba(255,255,255,.30)',
				'nav_item_active_bg'=>'linear-gradient(135deg,rgba(14,165,233,.92),rgba(99,102,241,.88))',
				'nav_badge_bg'=>'rgba(255,255,255,.38)',
				'nav_icon_bg'=>'rgba(255,255,255,.38)',
				'nav_submenu_rail'=>'rgba(148,163,184,.42)',
			])
			->darkTokens([
				'body_bg'=>'radial-gradient(circle at 12% -8%,rgba(14,165,233,.26),transparent 34rem),radial-gradient(circle at 92% 8%,rgba(168,85,247,.20),transparent 30rem),linear-gradient(135deg,#08111f 0%,#0d1728 52%,#15112a 100%)',
				'surface'=>'rgba(15,23,42,.58)',
				'surface_muted'=>'rgba(15,23,42,.36)',
				'text'=>'#f8fafc',
				'text_muted'=>'#cbd5e1',
				'text_subtle'=>'#94a3b8',
				'border'=>'rgba(148,163,184,.26)',
				'border_soft'=>'rgba(148,163,184,.18)',
				'control_bg'=>'rgba(15,23,42,.56)',
				'control_border'=>'rgba(148,163,184,.30)',
				'neutral_bg'=>'rgba(30,41,59,.58)',
				'neutral_text'=>'#e2e8f0',
				'glass_surface_bg'=>'linear-gradient(135deg,rgba(30,41,59,.70),rgba(15,23,42,.44))',
				'glass_surface_strong_bg'=>'linear-gradient(135deg,rgba(30,41,59,.82),rgba(15,23,42,.58))',
				'glass_surface_muted_bg'=>'linear-gradient(135deg,rgba(30,41,59,.50),rgba(15,23,42,.30))',
				'glass_control_bg'=>'linear-gradient(135deg,rgba(30,41,59,.76),rgba(15,23,42,.52))',
				'glass_menu_bg'=>'linear-gradient(135deg,rgba(30,41,59,.92),rgba(15,23,42,.72))',
				'glass_overlay_bg'=>'rgba(2,6,23,.56)',
				'glass_highlight'=>'linear-gradient(135deg,rgba(255,255,255,.13),rgba(255,255,255,0) 48%)',
				'glass_edge'=>'inset 0 1px 0 rgba(255,255,255,.14), inset 0 -1px 0 rgba(255,255,255,.06)',
				'glass_noise_opacity'=>'.07',
				'glass_tone_strength'=>'18%',
				'glass_focus'=>'0 0 0 4px rgba(14,165,233,.22),0 18px 50px rgba(14,165,233,.16)',
				'glass_active_glow'=>'0 18px 46px rgba(14,165,233,.20)',
				'glass_shimmer'=>'linear-gradient(90deg,transparent,rgba(255,255,255,.16),transparent)',
				'glass_scroll_thumb'=>'rgba(125,211,252,.30)',
				'glass_scroll_track'=>'rgba(15,23,42,.32)',
				'glass_mobile_blur'=>'blur(12px) saturate(1.08)',
				'glass_border'=>'rgba(255,255,255,.13)',
				'glass_shadow'=>'0 24px 72px rgba(0,0,0,.34)',
				'glass_shadow_soft'=>'0 14px 38px rgba(0,0,0,.24)',
				'glass_shadow_lifted'=>'0 38px 96px rgba(0,0,0,.42)',
				'nav_shell_bg'=>'linear-gradient(180deg,rgba(15,23,42,.64),rgba(15,23,42,.36))',
				'nav_shell_border'=>'rgba(255,255,255,.12)',
				'nav_brand_bg'=>'rgba(30,41,59,.38)',
				'nav_brand_border'=>'rgba(255,255,255,.12)',
				'nav_search_bg'=>'rgba(15,23,42,.46)',
				'nav_current_bg'=>'linear-gradient(135deg,rgba(14,165,233,.22),rgba(30,41,59,.26))',
				'nav_current_border'=>'rgba(125,211,252,.22)',
				'nav_item_hover_bg'=>'rgba(255,255,255,.08)',
				'nav_item_active_bg'=>'linear-gradient(135deg,rgba(14,165,233,.88),rgba(99,102,241,.82))',
				'nav_badge_bg'=>'rgba(255,255,255,.10)',
				'nav_icon_bg'=>'rgba(255,255,255,.10)',
				'nav_submenu_rail'=>'rgba(148,163,184,.24)',
			])
			->meta(['description'=>'Glassmorphism surfaces with translucent cards, soft depth, and blurred chrome.']);
	}

	/**
	 * Creates the bundled flat-minima preset.
	 *
	 * The preset uses restrained spacing, flat surfaces, small radius values, minimal shadows,
	 * and dashboard-oriented navigation tokens.
	 *
	 * @param string $name Preset identifier.
	 * @return self Flat-minima preset builder.
	 */
	public static function flatMinima(string $name='flat_minima'): self {
		return self::make($name)
			->colors([
				'primary'=>'blue',
				'success'=>'emerald',
				'warning'=>'amber',
				'danger'=>'rose',
				'info'=>'cyan',
				'gray'=>'slate',
			])
			->tokens([
				'theme_effects'=>'flat_minima',
				'radius'=>'8px',
				'max_width'=>'1440px',
				'panel_padding'=>'24px',
				'section_padding'=>'16px',
				'control_padding'=>'8px 12px',
				'input_padding'=>'9px 11px',
				'table_cell_padding'=>'10px 12px',
				'gap'=>'12px',
				'body_bg'=>'#f8fafc',
				'surface'=>'#ffffff',
				'surface_muted'=>'#f8fafc',
				'text'=>'#0f172a',
				'text_muted'=>'#64748b',
				'text_subtle'=>'#94a3b8',
				'border'=>'#e2e8f0',
				'border_soft'=>'#edf2f7',
				'control_bg'=>'#ffffff',
				'control_border'=>'#cbd5e1',
				'neutral_bg'=>'#f1f5f9',
				'neutral_text'=>'#334155',
				'nav_width'=>'292px',
				'nav_shell_bg'=>'#ffffff',
				'nav_shell_border'=>'#e2e8f0',
				'nav_shell_shadow'=>'none',
				'nav_shell_radius'=>'8px',
				'nav_shell_padding'=>'10px',
				'nav_brand_bg'=>'#ffffff',
				'nav_brand_border'=>'#e2e8f0',
				'nav_search_bg'=>'#f8fafc',
				'nav_current_bg'=>'#eff6ff',
				'nav_current_border'=>'#bfdbfe',
				'nav_item_radius'=>'6px',
				'nav_item_height'=>'40px',
				'nav_item_hover_bg'=>'#f1f5f9',
				'nav_item_active_bg'=>'#2563eb',
				'nav_badge_bg'=>'#f1f5f9',
				'nav_icon_bg'=>'#f8fafc',
				'nav_submenu_rail'=>'#e2e8f0',
			])
			->darkTokens([
				'body_bg'=>'#020617',
				'surface'=>'#0f172a',
				'surface_muted'=>'#111827',
				'text'=>'#f8fafc',
				'text_muted'=>'#cbd5e1',
				'text_subtle'=>'#94a3b8',
				'border'=>'#334155',
				'border_soft'=>'#1f2937',
				'control_bg'=>'#020617',
				'control_border'=>'#334155',
				'neutral_bg'=>'#1e293b',
				'neutral_text'=>'#e2e8f0',
				'nav_shell_bg'=>'#0f172a',
				'nav_shell_border'=>'#334155',
				'nav_brand_bg'=>'#0f172a',
				'nav_brand_border'=>'#334155',
				'nav_search_bg'=>'#020617',
				'nav_current_bg'=>'#172554',
				'nav_current_border'=>'#1d4ed8',
				'nav_item_hover_bg'=>'#1e293b',
				'nav_item_active_bg'=>'#2563eb',
				'nav_badge_bg'=>'#1e293b',
				'nav_icon_bg'=>'#111827',
				'nav_submenu_rail'=>'#334155',
			])
			->meta(['description'=>'Flat, minimal admin surfaces inspired by modern Tailwind and Filament dashboards.']);
	}

	/**
	 * Returns the normalized preset name.
	 *
	 * @return string Preset identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Merges named palette selections into the preset.
	 *
	 * Color keys are normalized; palette values are preserved so PanelTheme can resolve either
	 * palette names or richer palette definitions.
	 *
	 * @param array<string,mixed> $colors Color role map such as primary, success, warning, danger, info, and gray.
	 * @return self Same preset for fluent chaining.
	 */
	public function colors(array $colors): self {
		foreach($colors as $name=>$palette){
			$name=Resource::normalizeName((string)$name);
			if($name!==''){
				$this->colors[$name]=$palette;
			}
		}
		return $this;
	}

	/**
	 * Merges light/default CSS token values into the preset.
	 *
	 * Token names are normalized and scalar/null values are string-cast before storage.
	 *
	 * @param array<string,scalar|null> $tokens Token map that becomes panel CSS variables.
	 * @return self Same preset for fluent chaining.
	 */
	public function tokens(array $tokens): self {
		foreach($tokens as $name=>$value){
			$name=Resource::normalizeName((string)$name);
			if($name!=='' && (is_scalar($value) || $value===null)){
				$this->tokens[$name]=(string)$value;
			}
		}
		return $this;
	}

	/**
	 * Sets one light/default token value.
	 *
	 * @param string $name Token name.
	 * @param string $value CSS value.
	 * @return self Same preset for fluent chaining.
	 */
	public function token(string $name, string $value): self {
		return $this->tokens([$name=>$value]);
	}

	/**
	 * Merges dark-mode CSS token overrides into the preset.
	 *
	 * @param array<string,scalar|null> $tokens Token map applied when dark mode is active.
	 * @return self Same preset for fluent chaining.
	 */
	public function darkTokens(array $tokens): self {
		foreach($tokens as $name=>$value){
			$name=Resource::normalizeName((string)$name);
			if($name!=='' && (is_scalar($value) || $value===null)){
				$this->darkTokens[$name]=(string)$value;
			}
		}
		return $this;
	}

	/**
	 * Sets one dark-mode token value.
	 *
	 * @param string $name Token name.
	 * @param string $value CSS value.
	 * @return self Same preset for fluent chaining.
	 */
	public function darkToken(string $name, string $value): self {
		return $this->darkTokens([$name=>$value]);
	}

	/**
	 * Sets the global radius token.
	 *
	 * @param string $radius CSS radius value.
	 * @return self Same preset for fluent chaining.
	 */
	public function radius(string $radius): self {
		return $this->token('radius', $radius);
	}

	/**
	 * Sets the main panel maximum width token.
	 *
	 * @param string $width CSS max-width value.
	 * @return self Same preset for fluent chaining.
	 */
	public function maxWidth(string $width): self {
		return $this->token('max_width', $width);
	}

	/**
	 * Sets the outer panel padding token.
	 *
	 * @param string $padding CSS padding value.
	 * @return self Same preset for fluent chaining.
	 */
	public function panelPadding(string $padding): self {
		return $this->token('panel_padding', $padding);
	}

	/**
	 * Sets the section padding token.
	 *
	 * @param string $padding CSS padding value.
	 * @return self Same preset for fluent chaining.
	 */
	public function sectionPadding(string $padding): self {
		return $this->token('section_padding', $padding);
	}

	/**
	 * Sets the generic control padding token.
	 *
	 * @param string $padding CSS padding value.
	 * @return self Same preset for fluent chaining.
	 */
	public function controlPadding(string $padding): self {
		return $this->token('control_padding', $padding);
	}

	/**
	 * Sets the input padding token.
	 *
	 * @param string $padding CSS padding value.
	 * @return self Same preset for fluent chaining.
	 */
	public function inputPadding(string $padding): self {
		return $this->token('input_padding', $padding);
	}

	/**
	 * Sets the table cell padding token.
	 *
	 * @param string $padding CSS padding value.
	 * @return self Same preset for fluent chaining.
	 */
	public function tableCellPadding(string $padding): self {
		return $this->token('table_cell_padding', $padding);
	}

	/**
	 * Sets the default layout gap token.
	 *
	 * @param string $gap CSS gap value.
	 * @return self Same preset for fluent chaining.
	 */
	public function gap(string $gap): self {
		return $this->token('gap', $gap);
	}

	/**
	 * Sets dark-mode surface tokens.
	 *
	 * @param string $surface Main dark surface value.
	 * @param ?string $muted Optional muted dark surface value.
	 * @return self Same preset for fluent chaining.
	 */
	public function darkSurface(string $surface, ?string $muted=null): self {
		return $this->darkTokens(array_filter([
			'surface'=>$surface,
			'surface_muted'=>$muted,
		], static fn(mixed $value): bool => $value!==null));
	}

	/**
	 * Sets the dark-mode body background token.
	 *
	 * @param string $background CSS background value.
	 * @return self Same preset for fluent chaining.
	 */
	public function darkBody(string $background): self {
		return $this->darkToken('body_bg', $background);
	}

	/**
	 * Sets dark-mode text color tokens.
	 *
	 * @param string $text Primary dark text value.
	 * @param ?string $muted Optional muted dark text value.
	 * @param ?string $subtle Optional subtle dark text value.
	 * @return self Same preset for fluent chaining.
	 */
	public function darkText(string $text, ?string $muted=null, ?string $subtle=null): self {
		return $this->darkTokens(array_filter([
			'text'=>$text,
			'text_muted'=>$muted,
			'text_subtle'=>$subtle,
		], static fn(mixed $value): bool => $value!==null));
	}

	/**
	 * Registers asset namespace roots used by stylesheet and font URL resolution.
	 *
	 * Hrefs in the form `namespace::path/file.css` are resolved against these roots.
	 *
	 * @param array<string,string> $roots Map of namespace to base URL.
	 * @return self Same preset for fluent chaining.
	 */
	public function assetRoots(array $roots): self {
		foreach($roots as $namespace=>$baseUrl){
			if(is_scalar($baseUrl) || $baseUrl===null){
				$this->assetRoot((string)$namespace, (string)$baseUrl);
			}
		}
		return $this;
	}

	/**
	 * Registers one asset namespace root.
	 *
	 * @param string $namespace Namespace prefix used before `::`.
	 * @param string $baseUrl Base URL for assets in that namespace.
	 * @return self Same preset for fluent chaining.
	 */
	public function assetRoot(string $namespace, string $baseUrl): self {
		$namespace=Resource::normalizeName($namespace);
		$baseUrl=trim($baseUrl);
		if($namespace!=='' && $baseUrl!==''){
			$this->assetRoots[$namespace]=$baseUrl;
		}
		return $this;
	}

	/**
	 * Sets font family metadata and optional font stylesheet URL.
	 *
	 * Font URLs are asset-root resolved before storage.
	 *
	 * @param string $family CSS font-family value or family name.
	 * @param ?string $url Optional stylesheet or font asset URL.
	 * @param ?string $provider Optional provider label.
	 * @return self Same preset for fluent chaining.
	 */
	public function font(string $family, ?string $url=null, ?string $provider=null): self {
		$this->font=trim($family) ?: null;
		$this->fontUrl=trim($this->resolveAssetHref((string)$url)) ?: null;
		$this->fontProvider=trim((string)$provider) ?: null;
		return $this;
	}

	/**
	 * Enables or disables dark-mode support for themes created from the preset.
	 *
	 * @param bool $enabled Whether dark mode should be available.
	 * @return self Same preset for fluent chaining.
	 */
	public function darkMode(bool $enabled=true): self {
		$this->darkMode=$enabled;
		return $this;
	}

	/**
	 * Sets the default color-scheme mode.
	 *
	 * Accepted modes are `light`, `dark`, and `system`; unsupported values clear the override.
	 *
	 * @param string $mode Requested default mode.
	 * @return self Same preset for fluent chaining.
	 */
	public function defaultMode(string $mode): self {
		$mode=Resource::normalizeName($mode);
		$this->defaultMode=in_array($mode, ['light', 'dark', 'system'], true) ? $mode : null;
		return $this;
	}

	/**
	 * Controls whether the panel should render a mode toggle.
	 *
	 * @param bool $enabled Whether users can toggle modes.
	 * @return self Same preset for fluent chaining.
	 */
	public function modeToggle(bool $enabled=true): self {
		$this->modeToggle=$enabled;
		return $this;
	}

	/**
	 * Merges brand metadata such as name, logo, dark logo, and logo height.
	 *
	 * @param array<string,scalar|null> $brand Brand key/value map for the panel shell.
	 * @return self Same preset for fluent chaining.
	 */
	public function brand(array $brand): self {
		foreach($brand as $name=>$value){
			$name=Resource::normalizeName((string)$name);
			if($name!=='' && (is_scalar($value) || $value===null)){
				$this->brand[$name]=trim((string)$value) ?: null;
			}
		}
		return $this;
	}

	/**
	 * Sets the favicon URL for themes created from the preset.
	 *
	 * @param string $url Favicon URL.
	 * @return self Same preset for fluent chaining.
	 */
	public function favicon(string $url): self {
		$this->favicon=trim($url) ?: null;
		return $this;
	}

	/**
	 * Adds stylesheet assets to the preset.
	 *
	 * Accepts a single href, a single asset definition, or a list of hrefs/asset definitions.
	 * Asset namespace hrefs are resolved before PanelThemeAsset normalization.
	 *
	 * @param array|string|null $css Stylesheet href or asset definition(s).
	 * @return self Same preset for fluent chaining.
	 */
	public function css(array|string|null $css): self {
		$entries=is_array($css) && self::isAssetDefinition($css) ? [$css] : (is_array($css) ? $css : [$css]);
		foreach($entries as $entry){
			$asset=PanelThemeAsset::from($this->resolveAssetEntry($entry));
			if($asset instanceof PanelThemeAsset){
				$this->css[$asset->name()]=$asset->toArray();
			}
		}
		return $this;
	}

	/**
	 * Adds one named stylesheet asset.
	 *
	 * @param string $href Stylesheet URL or namespaced asset href.
	 * @param ?string $name Optional asset name; PanelThemeAsset derives one when omitted.
	 * @param array<string,scalar|null> $attributes Link attributes forwarded to the theme manifest.
	 * @return self Same preset for fluent chaining.
	 */
	public function stylesheet(string $href, ?string $name=null, array $attributes=[]): self {
		$asset=PanelThemeAsset::stylesheet($this->resolveAssetHref($href), $name, $attributes);
		if($asset->href()!==''){
			$this->css[$asset->name()]=$asset->toArray();
		}
		return $this;
	}

	/**
	 * Merges arbitrary metadata into the preset payload.
	 *
	 * @param array<string,mixed> $meta Metadata for design tooling or theme consumers.
	 * @return self Same preset for fluent chaining.
	 */
	public function meta(array $meta): self {
		$this->meta=array_replace($this->meta, $meta);
		return $this;
	}

	/**
	 * Applies this preset to an existing theme.
	 *
	 * @param PanelTheme $theme Theme instance that will receive this preset payload.
	 * @return PanelTheme Theme returned by PanelTheme::applyPreset().
	 */
	public function applyTo(PanelTheme $theme): PanelTheme {
		return $theme->applyPreset($this);
	}

	/**
	 * Creates a new theme and applies this preset to it.
	 *
	 * @param string $name Theme name passed to PanelTheme::make().
	 * @return PanelTheme Theme configured with this preset.
	 */
	public function toTheme(string $name='default'): PanelTheme {
		return $this->applyTo(PanelTheme::make($name));
	}

	/**
	 * Serializes the preset to the shape consumed by PanelTheme::applyPreset().
	 *
	 * @return array{type:string,name:string,colors:array,tokens:array,dark_tokens:array,asset_roots:array,font:?string,font_url:?string,font_provider:?string,dark_mode:?bool,default_mode:?string,mode_toggle:?bool,brand:array,favicon:?string,css:array,css_assets:array,meta:array} Preset payload.
	 */
	public function toArray(): array {
		return [
			'type'=>'preset',
			'name'=>$this->name,
			'colors'=>$this->colors,
			'tokens'=>$this->tokens,
			'dark_tokens'=>$this->darkTokens,
			'asset_roots'=>$this->assetRoots,
			'font'=>$this->font,
			'font_url'=>$this->fontUrl,
			'font_provider'=>$this->fontProvider,
			'dark_mode'=>$this->darkMode,
			'default_mode'=>$this->defaultMode,
			'mode_toggle'=>$this->modeToggle,
			'brand'=>$this->brand,
			'favicon'=>$this->favicon,
			'css'=>array_values(array_map(static fn(array $asset): string => (string)$asset['href'], $this->css)),
			'css_assets'=>array_values($this->css),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Detects whether an array is a stylesheet asset definition.
	 *
	 * preset CSS accepts both plain href strings and structured asset
	 * arrays. This helper recognizes the stable href/url/path keys before the value
	 * is normalized through PanelThemeAsset.
	 *
	 * @param array<string, mixed> $value Candidate asset definition.
	 * @return bool True when the array has a recognized asset URL key.
	 */
	private static function isAssetDefinition(array $value): bool {
		return isset($value['href']) || isset($value['url']) || isset($value['path']);
	}

	/**
	 * Resolves namespaced asset references inside one CSS entry.
	 *
	 * string entries are resolved directly. Structured asset definitions
	 * keep their metadata intact while the first recognized href/url/path value is
	 * rewritten through resolveAssetHref(). Unrecognized values are returned
	 * unchanged so PanelThemeAsset can apply its own validation.
	 *
	 * @param mixed $entry CSS asset entry supplied by a preset definition.
	 * @return mixed resolved asset href string, updated asset definition array, or unchanged unsupported entry.
	 */
	private function resolveAssetEntry(mixed $entry): mixed {
		if(is_string($entry)){
			return $this->resolveAssetHref($entry);
		}
		if(!is_array($entry) || !self::isAssetDefinition($entry)){
			return $entry;
		}
		foreach(['href', 'url', 'path'] as $key){
			if(isset($entry[$key]) && is_scalar($entry[$key])){
				$entry[$key]=$this->resolveAssetHref((string)$entry[$key]);
				break;
			}
		}
		return $entry;
	}

	/**
	 * Resolves `namespace::path` asset hrefs against configured asset roots.
	 *
	 * only normalized namespaces present in assetRoots are expanded.
	 * Unknown namespaces are left intact so unresolved design-system references stay
	 * visible to operators instead of becoming broken relative URLs.
	 *
	 * @param string $href Raw href or namespaced href.
	 * @return string Resolved href, or the original href when no root matches.
	 */
	private function resolveAssetHref(string $href): string {
		$href=trim($href);
		if(preg_match('/^([A-Za-z0-9_.-]+)::(.+)$/', $href, $match)!==1){
			return $href;
		}
		$namespace=Resource::normalizeName($match[1]);
		$base=trim((string)($this->assetRoots[$namespace] ?? ''));
		if($base===''){
			return $href;
		}
		return rtrim($base, "\\/").'/'.ltrim($match[2], "\\/");
	}
}
