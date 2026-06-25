<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent definition for a Panel theme.
 *
 * Themes normalize palettes, design tokens, dark-mode overrides, brand assets, CSS assets, fonts, and preset libraries for renderer consumption.
 */
final class PanelTheme {

	private const SHADES=[50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

	private string $name;
	private array $colors=[];
	private array $tokens=[];
	private array $darkTokens=[];
	/** @var array<string, PanelThemeAsset> */
	private array $css=[];
	private array $assetRoots=[];
	private ?string $font=null;
	private ?string $fontUrl=null;
	private ?string $fontProvider=null;
	private bool $darkMode=true;
	private string $defaultMode='system';
	private bool $modeToggle=true;
	private ?string $brandName=null;
	private ?string $brandLogo=null;
	private ?string $darkBrandLogo=null;
	private ?string $brandLogoHeight=null;
	private ?string $favicon=null;
	private static ?PanelThemeLibrary $library=null;

	/**
	 * Initializes a theme with a normalized manifest name.
	 *
	 * Construction is private so fluent builders, array definitions, presets, and
	 * registered themes all pass through the same Resource name normalization and
	 * default-name fallback.
	 *
	 * @param string $name Raw theme name from code or manifest input.
	 */
	private function __construct(string $name='default') {
		$this->name=Resource::normalizeName($name) ?: 'default';
	}

	/**
	 * Builds a Panel theme definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param string $name Raw theme name from fluent code.
	 * @return self New theme builder instance.
	 */
	public static function make(string $name='default'): self {
		return new self($name);
	}

	/**
	 * Configures color palette tokens for a Panel theme.
	 *
	 * Palette helpers normalize semantic colors into shade maps consumed by CSS variables and renderer assets.
	 *
	 * @param string $color Base color value or named color accepted by the palette normalizer.
	 * @return array<int|string,string> Shade map keyed by Panel shade number.
	 */
	public static function palette(string $color): array {
		return self::normalizePalette($color);
	}

	/**
	 * Resolves theme presets and libraries.
	 *
	 * Preset helpers normalize named, array, and object definitions before applying theme tokens.
	 *
	 * @param string|array|PanelThemePreset $preset Preset.
	 * @return PanelThemePreset Panel theme manifest result.
	 */
	public static function presetDefinition(string|array|PanelThemePreset $preset): PanelThemePreset {
		if($preset instanceof PanelThemePreset){
			return $preset;
		}
		if(is_array($preset)){
			return PanelThemePreset::fromArray($preset);
		}
		$name=Resource::normalizeName($preset);
		$registered=self::themeLibrary()->get($name);
		if($registered instanceof PanelThemePreset){
			return $registered;
		}
		return match($name){
			'brutalist'=>PanelThemePreset::brutalist(),
			'glass'=>PanelThemePreset::glass(),
			'flat_minima', 'default'=>PanelThemePreset::flatMinima(),
			default=>PanelThemePreset::make($preset),
		};
	}

	/**
	 * Returns the shared Panel theme library.
	 *
	 * The library is initialized lazily with built-in presets and then reused for preset/theme registration, loading, previews, and diagnostics.
	 * @return PanelThemeLibrary Process-local theme library instance.
	 */
	public static function themeLibrary(): PanelThemeLibrary {
		return self::$library ??= PanelThemeLibrary::make()
			->register(PanelThemePreset::flatMinima())
			->register(PanelThemePreset::brutalist())
			->register(PanelThemePreset::glass());
	}

	/**
	 * Registers a reusable theme preset.
	 *
	 * Array definitions are normalized into `PanelThemePreset` instances before registration so later preset lookups use a consistent manifest shape.
	 *
	 * @param PanelThemePreset|array $preset Preset instance or array definition to register.
	 * @return PanelThemePreset Registered preset instance.
	 */
	public static function registerPreset(PanelThemePreset|array $preset): PanelThemePreset {
		$preset=$preset instanceof PanelThemePreset ? $preset : PanelThemePreset::fromArray($preset);
		self::themeLibrary()->register($preset);
		return $preset;
	}

	/**
	 * Registers a named theme definition.
	 *
	 * Array themes are stored in the shared library and returned as normalized `PanelTheme` instances for later extension or preview use.
	 *
	 * @param PanelTheme|array $theme Theme instance or array manifest to register.
	 * @return PanelTheme Registered theme instance.
	 */
	public static function registerTheme(PanelTheme|array $theme): PanelTheme {
		self::themeLibrary()->registerTheme($theme);
		if($theme instanceof PanelTheme){
			return $theme;
		}
		$name=Resource::normalizeName((string)($theme['name'] ?? 'theme'));
		return self::themeLibrary()->getTheme($name) ?? self::fromArray($theme);
	}

	/**
	 * Looks up a registered named theme.
	 *
	 *
	 * @return ?PanelTheme Registered theme, or null when the name is unknown.
	 */
	public static function namedTheme(string $name): ?PanelTheme {
		return self::themeLibrary()->getTheme($name);
	}

	/**
	 * Loads preset definitions into the shared library.
	 *
	 *
	 * @return PanelThemeLibrary Theme library after loading matching preset files.
	 */
	public static function loadPresets(string|array $paths): PanelThemeLibrary {
		return self::themeLibrary()->loadFrom($paths);
	}

	/**
	 * Loads theme definitions into the shared library.
	 *
	 *
	 * @param string|array $paths File path, directory path, or list of paths to scan.
	 * @return PanelThemeLibrary Theme library after loading matching theme files.
	 */
	public static function loadThemes(string|array $paths): PanelThemeLibrary {
		return self::themeLibrary()->loadFrom($paths);
	}

	/**
	 * Reports theme library diagnostics.
	 *
	 * Diagnostics expose registered presets, registered themes, loaded files, and loader warnings from the shared library.
	 * @return array<string,mixed> Theme library diagnostic payload.
	 */
	public static function diagnostics(): array {
		return self::themeLibrary()->diagnostics();
	}

	/**
	 * Builds preview data for a registered theme or the default theme.
	 *
	 * @param ?string $name Registered theme name; null selects the library default preview target.
	 * @return array<string,mixed> Preview payload with colors, modes, assets, and contrast diagnostics.
	 */
	public static function previewTheme(?string $name=null): array {
		return self::themeLibrary()->preview($name);
	}

	/**
	 * Renders preview HTML for a registered theme.
	 *
	 *
	 * @param ?string $name Registered theme name; null selects the library default preview target.
	 * @param array<string,mixed> $options Additional preview rendering options.
	 * @return string Rendered preview HTML.
	 */
	public static function previewThemeHtml(?string $name=null, array $options=[]): string {
		return self::themeLibrary()->previewHtml($name, $options);
	}

	/**
	 * Builds a Panel theme definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param array<string,mixed> $definition Array manifest/configuration definition.
	 * @return self Panel theme manifest result.
	 */
	public static function fromArray(array $definition): self {
		$theme=self::make((string)($definition['name'] ?? 'default'));
		foreach(self::baseDefinitions($definition) as $base){
			$theme=$theme->extend($base);
		}
		if(isset($definition['asset_roots']) && is_array($definition['asset_roots'])){
			$theme=$theme->assetRoots($definition['asset_roots']);
		}
		if(isset($definition['preset'])){
			foreach(self::presetDefinitions($definition['preset']) as $preset){
				$theme=$theme->preset($preset);
			}
		}
		if(isset($definition['presets'])){
			foreach(self::presetDefinitions($definition['presets']) as $preset){
				$theme=$theme->preset($preset);
			}
		}
		if(isset($definition['colors']) && is_array($definition['colors'])){
			$theme=$theme->colors($definition['colors']);
		}
		if(isset($definition['tokens']) && is_array($definition['tokens'])){
			$theme=$theme->tokens($definition['tokens']);
		}
		if(isset($definition['dark_tokens']) && is_array($definition['dark_tokens'])){
			$theme=$theme->darkTokens($definition['dark_tokens']);
		}
		if(isset($definition['font'])){
			$theme=$theme->font((string)$definition['font'], isset($definition['font_url']) ? (string)$definition['font_url'] : null, isset($definition['font_provider']) ? (string)$definition['font_provider'] : null);
		}
		if(isset($definition['dark_mode'])){
			$theme=$theme->darkMode((bool)$definition['dark_mode']);
		}
		if(isset($definition['default_mode'])){
			$theme=$theme->defaultMode((string)$definition['default_mode']);
		}
		if(isset($definition['mode_toggle'])){
			$theme=$theme->modeToggle((bool)$definition['mode_toggle']);
		}
		if(isset($definition['brand']) && is_array($definition['brand'])){
			$brand=$definition['brand'];
			if(isset($brand['name'])){
				$theme=$theme->brandName((string)$brand['name']);
			}
			if(isset($brand['logo'])){
				$theme=$theme->brandLogo((string)$brand['logo']);
			}
			if(isset($brand['dark_logo'])){
				$theme=$theme->darkModeBrandLogo((string)$brand['dark_logo']);
			}
			if(isset($brand['logo_height'])){
				$theme=$theme->brandLogoHeight((string)$brand['logo_height']);
			}
		}
		if(isset($definition['brand_name'])){
			$theme=$theme->brandName((string)$definition['brand_name']);
		}
		if(isset($definition['brand_logo'])){
			$theme=$theme->brandLogo((string)$definition['brand_logo']);
		}
		if(isset($definition['dark_brand_logo'])){
			$theme=$theme->darkModeBrandLogo((string)$definition['dark_brand_logo']);
		}
		if(isset($definition['brand_logo_height'])){
			$theme=$theme->brandLogoHeight((string)$definition['brand_logo_height']);
		}
		if(isset($definition['favicon'])){
			$theme=$theme->favicon((string)$definition['favicon']);
		}
		if(isset($definition['css'])){
			$theme=$theme->css($definition['css']);
		}
		if(isset($definition['css_assets'])){
			$theme=$theme->css($definition['css_assets']);
		}
		return $theme;
	}

	/**
	 * Returns the normalized theme name.
	 *
	 * @return string Manifest-safe theme identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Clones this theme through its array manifest.
	 *
	 * Rehydrating through `fromArray()` keeps derived themes on the same normalization path as loaded manifests and fluent definitions.
	 *
	 * @param ?string $name Optional replacement theme name.
	 * @return self Independent theme instance.
	 */
	public function copy(?string $name=null): self {
		$definition=$this->toArray();
		if($name!==null){
			$definition['name']=$name;
		}
		return self::fromArray($definition);
	}

	/**
	 * Creates a variant with array or closure overrides.
	 *
	 * Array overrides are merged into the exported definition before rehydration; closure overrides mutate a copied theme and may return that copy or a replacement theme.
	 *
	 * @param array|\Closure $overrides Array definition overrides or callback receiving the copied theme.
	 * @param ?string $name Optional replacement theme name.
	 * @return self Derived theme instance.
	 */
	public function with(array|\Closure $overrides=[], ?string $name=null): self {
		if($overrides instanceof \Closure){
			$theme=$this->copy($name);
			$result=$overrides($theme);
			return $result instanceof self ? $result : $theme;
		}
		$definition=$this->toArray();
		if($name!==null){
			$definition['name']=$name;
		}
		return self::fromArray(self::mergeDefinition($definition, $overrides));
	}

	/**
	 * Creates a named variant of this theme.
	 *
	 * The variant delegates to `with()` so closure and array overrides keep the same merge and normalization behavior.
	 *
	 * @param string $name Theme name assigned to the derived variant.
	 * @param array|\Closure $overrides Array definition overrides or callback receiving the copied theme.
	 * @return self Derived theme instance.
	 */
	public function variant(string $name, array|\Closure $overrides=[]): self {
		return $this->with($overrides, $name);
	}

	/**
	 * Configures color palette tokens for a Panel theme.
	 *
	 * Palette helpers normalize semantic colors into shade maps consumed by CSS variables and renderer assets.
	 *
	 * @param array<string,array<int|string,string>|string> $colors Palette definitions keyed by semantic color name.
	 * @return self Panel theme manifest result.
	 */
	public function colors(array $colors): self {
		foreach($colors as $name=>$palette){
			$name=Resource::normalizeName((string)$name);
			if($name===''){
				continue;
			}
			$this->colors[$name]=self::normalizePalette($palette, $this->colors[$name] ?? null);
		}
		return $this;
	}

	/**
	 * Configures color palette tokens for a Panel theme.
	 *
	 * Palette helpers normalize semantic colors into shade maps consumed by CSS variables and renderer assets.
	 *
	 * @param string $name Semantic palette name, such as primary, success, warning, or danger.
	 * @param array|string $palette Full shade map or base color value.
	 * @return self The same theme instance for fluent composition.
	 */
	public function color(string $name, array|string $palette): self {
		return $this->colors([$name=>$palette]);
	}

	/**
	 * Applies a preset to this theme.
	 *
	 * Preset colors, tokens, dark tokens, asset roots, fonts, mode settings, brand assets, favicon, and CSS assets are copied into this theme using the same fluent mutators as user code.
	 *
	 * @param string|array|PanelThemePreset $preset Registered preset name, array definition, or preset instance.
	 * @return self The same theme instance for fluent composition.
	 */
	public function applyPreset(string|array|PanelThemePreset $preset): self {
		$preset=self::presetDefinition($preset);
		$data=$preset->toArray();
		if(is_array($data['colors'] ?? null)){
			$this->colors($data['colors']);
		}
		if(is_array($data['tokens'] ?? null)){
			$this->tokens($data['tokens']);
		}
		if(is_array($data['dark_tokens'] ?? null)){
			$this->darkTokens($data['dark_tokens']);
		}
		if(is_array($data['asset_roots'] ?? null)){
			$this->defaultAssetRoots($data['asset_roots']);
		}
		if(is_string($data['font'] ?? null)){
			$this->font((string)$data['font'], is_string($data['font_url'] ?? null) ? (string)$data['font_url'] : null, is_string($data['font_provider'] ?? null) ? (string)$data['font_provider'] : null);
		}
		if(is_bool($data['dark_mode'] ?? null)){
			$this->darkMode((bool)$data['dark_mode']);
		}
		if(is_string($data['default_mode'] ?? null)){
			$this->defaultMode((string)$data['default_mode']);
		}
		if(is_bool($data['mode_toggle'] ?? null)){
			$this->modeToggle((bool)$data['mode_toggle']);
		}
		if(is_array($data['brand'] ?? null)){
			foreach($data['brand'] as $key=>$value){
				if($value===null){
					continue;
				}
				match(Resource::normalizeName((string)$key)){
					'name'=>$this->brandName((string)$value),
					'logo'=>$this->brandLogo((string)$value),
					'dark_logo'=>$this->darkModeBrandLogo((string)$value),
					'logo_height'=>$this->brandLogoHeight((string)$value),
					default=>null,
				};
			}
		}
		if(is_string($data['favicon'] ?? null)){
			$this->favicon((string)$data['favicon']);
		}
		if(isset($data['css'])){
			$this->css($data['css']);
		}
		if(isset($data['css_assets'])){
			$this->css($data['css_assets']);
		}
		return $this;
	}

	/**
	 * Resolves theme presets and libraries.
	 *
	 * Preset helpers normalize named, array, and object definitions before applying theme tokens.
	 *
	 * @param string|array|PanelThemePreset $preset Registered preset name, array definition, or preset instance.
	 * @return self The same theme instance for fluent composition.
	 */
	public function preset(string|array|PanelThemePreset $preset): self {
		return $this->applyPreset($preset);
	}

	/**
	 * Extends this theme from another theme or preset.
	 *
	 * Named strings first try registered themes and then fall back to presets; inherited asset roots fill only missing namespaces while other tokens overwrite current values.
	 *
	 * @param string|array|PanelTheme|PanelThemePreset $theme Theme name, array definition, theme instance, or preset instance.
	 * @return self The same theme instance for fluent composition.
	 */
	public function extend(string|array|PanelTheme|PanelThemePreset $theme): self {
		if($theme instanceof PanelThemePreset){
			return $this->applyPreset($theme);
		}
		if(is_string($theme)){
			$named=self::namedTheme($theme);
			if($named instanceof self){
				return $this->extend($named);
			}
			return $this->applyPreset($theme);
		}
		if(is_array($theme)){
			return $this->extend(self::fromArray($theme));
		}
		$data=$theme->toArray();
		if(is_array($data['asset_roots'] ?? null)){
			$this->defaultAssetRoots($data['asset_roots']);
		}
		if(is_array($data['colors'] ?? null)){
			$this->colors($data['colors']);
		}
		if(is_array($data['tokens'] ?? null)){
			$this->tokens($data['tokens']);
		}
		if(is_array($data['dark_tokens'] ?? null)){
			$this->darkTokens($data['dark_tokens']);
		}
		if(is_string($data['font'] ?? null)){
			$this->font((string)$data['font'], is_string($data['font_url'] ?? null) ? (string)$data['font_url'] : null, is_string($data['font_provider'] ?? null) ? (string)$data['font_provider'] : null);
		}
		if(is_bool($data['dark_mode'] ?? null)){
			$this->darkMode((bool)$data['dark_mode']);
		}
		if(is_string($data['default_mode'] ?? null)){
			$this->defaultMode((string)$data['default_mode']);
		}
		if(is_bool($data['mode_toggle'] ?? null)){
			$this->modeToggle((bool)$data['mode_toggle']);
		}
		if(is_array($data['brand'] ?? null)){
			foreach($data['brand'] as $key=>$value){
				if($value===null){
					continue;
				}
				match(Resource::normalizeName((string)$key)){
					'name'=>$this->brandName((string)$value),
					'logo'=>$this->brandLogo((string)$value),
					'dark_logo'=>$this->darkModeBrandLogo((string)$value),
					'logo_height'=>$this->brandLogoHeight((string)$value),
					default=>null,
				};
			}
		}
		if(is_string($data['favicon'] ?? null)){
			$this->favicon((string)$data['favicon']);
		}
		if(isset($data['css_assets'])){
			$this->css($data['css_assets']);
		}
		elseif(isset($data['css'])){
			$this->css($data['css']);
		}
		return $this;
	}

	/**
	 * Merges scalar design tokens into this theme.
	 *
	 * Token names are normalized and scalar/null values are cast to strings for CSS custom-property output.
	 *
	 * @param array<string,scalar|null> $tokens Scalar design tokens keyed by normalized token name.
	 * @return self The same theme instance for fluent composition.
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
	 * Sets one scalar design token.
	 *
	 *
	 * @param string $name Token name before normalization.
	 * @param string $value CSS token value stored in the manifest.
	 * @return self The same theme instance for fluent composition.
	 */
	public function token(string $name, string $value): self {
		return $this->tokens([$name=>$value]);
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param array<string,scalar|null> $tokens Dark-mode design tokens keyed by normalized token name.
	 * @return self Panel theme manifest result.
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
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param string $name Dark-mode token name before normalization.
	 * @param string $value CSS token value stored for dark-mode output.
	 * @return self The same theme instance for fluent composition.
	 */
	public function darkToken(string $name, string $value): self {
		return $this->darkTokens([$name=>$value]);
	}

	/**
	 * Configures theme assets and brand presentation.
	 *
	 * Asset metadata records CSS, fonts, brand logos, favicon paths, and external asset roots for the renderer.
	 *
	 * @param array<string,scalar|null> $roots Asset-root base URLs keyed by namespace.
	 * @return self Panel theme manifest result.
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
	 * Configures theme assets and brand presentation.
	 *
	 * Asset metadata records CSS, fonts, brand logos, favicon paths, and external asset roots for the renderer.
	 *
	 * @param string $namespace Asset namespace before normalization.
	 * @param string $baseUrl Base URL used to resolve namespaced asset references.
	 * @return self The same theme instance for fluent composition.
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
	 * Adds preset-provided asset roots without replacing explicit theme roots.
	 *
	 * Presets can provide namespace defaults such as `panel::theme.css`, but a
	 * concrete theme may already have chosen a different base URL. Existing roots
	 * are therefore preserved and only missing namespaces are filled.
	 *
	 * @param array<string,string> $roots Namespace-to-base-URL map from a preset or base theme.
	 * @return self The same theme instance for fluent composition.
	 */
	private function defaultAssetRoots(array $roots): self {
		foreach($roots as $namespace=>$baseUrl){
			$namespace=Resource::normalizeName((string)$namespace);
			$baseUrl=trim((string)$baseUrl);
			if($namespace!=='' && $baseUrl!=='' && !isset($this->assetRoots[$namespace])){
				$this->assetRoots[$namespace]=$baseUrl;
			}
		}
		return $this;
	}

	/**
	 * Sets the global corner radius token.
	 *
	 * @param string $radius CSS length used by Panel surfaces and controls.
	 * @return self The same theme instance for fluent composition.
	 */
	public function radius(string $radius): self {
		return $this->token('radius', $radius);
	}

	/**
	 * Sets the shell content max-width token.
	 *
	 * @param string $width CSS width constraint used by constrained Panel layouts.
	 * @return self The same theme instance for fluent composition.
	 */
	public function maxWidth(string $width): self {
		return $this->token('max_width', $width);
	}

	/**
	 * Sets outer Panel shell padding.
	 *
	 * @param string $padding CSS padding value used around the main Panel surface.
	 * @return self The same theme instance for fluent composition.
	 */
	public function panelPadding(string $padding): self {
		return $this->token('panel_padding', $padding);
	}

	/**
	 * Sets default section padding.
	 *
	 * @param string $padding CSS padding value used by Panel sections.
	 * @return self The same theme instance for fluent composition.
	 */
	public function sectionPadding(string $padding): self {
		return $this->token('section_padding', $padding);
	}

	/**
	 * Sets default control padding.
	 *
	 * @param string $padding CSS padding value used by buttons and compact controls.
	 * @return self The same theme instance for fluent composition.
	 */
	public function controlPadding(string $padding): self {
		return $this->token('control_padding', $padding);
	}

	/**
	 * Sets default input padding.
	 *
	 * @param string $padding CSS padding value used by form inputs.
	 * @return self The same theme instance for fluent composition.
	 */
	public function inputPadding(string $padding): self {
		return $this->token('input_padding', $padding);
	}

	/**
	 * Sets table cell padding.
	 *
	 * @param string $padding CSS padding value used by data table cells.
	 * @return self The same theme instance for fluent composition.
	 */
	public function tableCellPadding(string $padding): self {
		return $this->token('table_cell_padding', $padding);
	}

	/**
	 * Sets the default layout gap token.
	 *
	 * @param string $gap CSS gap value used by renderer layout primitives.
	 * @return self The same theme instance for fluent composition.
	 */
	public function gap(string $gap): self {
		return $this->token('gap', $gap);
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param string $surface Dark-mode primary surface color.
	 * @param ?string $muted Optional dark-mode muted surface color.
	 * @return self The same theme instance for fluent composition.
	 */
	public function darkSurface(string $surface, ?string $muted=null): self {
		return $this->darkTokens(array_filter([
			'surface'=>$surface,
			'surface_muted'=>$muted,
		], static fn(mixed $value): bool => $value!==null));
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param string $background Dark-mode body background color.
	 * @return self The same theme instance for fluent composition.
	 */
	public function darkBody(string $background): self {
		return $this->darkToken('body_bg', $background);
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param string $text Dark-mode primary text color.
	 * @param ?string $muted Optional dark-mode muted text color.
	 * @param ?string $subtle Optional dark-mode subtle text color.
	 * @return self The same theme instance for fluent composition.
	 */
	public function darkText(string $text, ?string $muted=null, ?string $subtle=null): self {
		return $this->darkTokens(array_filter([
			'text'=>$text,
			'text_muted'=>$muted,
			'text_subtle'=>$subtle,
		], static fn(mixed $value): bool => $value!==null));
	}

	/**
	 * Configures theme assets and brand presentation.
	 *
	 * Asset metadata records CSS, fonts, brand logos, favicon paths, and external asset roots for the renderer.
	 *
	 * @param string $family Font family name used to build the CSS font stack.
	 * @param ?string $url Optional stylesheet URL or namespaced asset reference for the font.
	 * @param ?string $provider Optional provider label stored in the manifest.
	 * @return self The same theme instance for fluent composition.
	 */
	public function font(string $family, ?string $url=null, ?string $provider=null): self {
		$family=trim($family);
		$this->font=$family!=='' ? $family : null;
		$this->fontUrl=trim($this->resolveAssetHref((string)$url)) ?: null;
		$this->fontProvider=trim((string)$provider) ?: null;
		if($this->font!==null){
			$this->tokens['font_family']=self::fontStack($this->font);
		}
		return $this;
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param bool $enabled Whether dark-mode variables and preview data should be emitted.
	 * @return self The same theme instance for fluent composition.
	 */
	public function darkMode(bool $enabled=true): self {
		$this->darkMode=$enabled;
		return $this;
	}

	/**
	 * Sets the default color-scheme mode.
	 *
	 * Invalid values fall back to `system`, keeping renderer output constrained to light, dark, or system mode.
	 *
	 * @param string $mode Requested default mode.
	 * @return self The same theme instance for fluent composition.
	 */
	public function defaultMode(string $mode): self {
		$mode=Resource::normalizeName($mode);
		$this->defaultMode=in_array($mode, ['light', 'dark', 'system'], true) ? $mode : 'system';
		return $this;
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param bool $enabled Whether the Panel shell should expose a mode toggle when dark mode is enabled.
	 * @return self The same theme instance for fluent composition.
	 */
	public function modeToggle(bool $enabled=true): self {
		$this->modeToggle=$enabled;
		return $this;
	}

	/**
	 * Sets the brand name shown by the Panel shell.
	 *
	 *
	 * @param string $name Brand label displayed by the Panel shell.
	 * @return self The same theme instance for fluent composition.
	 */
	public function brandName(string $name): self {
		$this->brandName=trim($name) ?: null;
		return $this;
	}

	/**
	 * Sets the light-mode brand logo URL.
	 *
	 *
	 * @param string $url Logo URL or asset reference stored in the brand manifest.
	 * @return self The same theme instance for fluent composition.
	 */
	public function brandLogo(string $url): self {
		$this->brandLogo=trim($url) ?: null;
		return $this;
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 *
	 * @param string $url Dark-mode logo URL or asset reference stored in the brand manifest.
	 * @return self The same theme instance for fluent composition.
	 */
	public function darkModeBrandLogo(string $url): self {
		$this->darkBrandLogo=trim($url) ?: null;
		return $this;
	}

	/**
	 * Sets the rendered brand logo height.
	 *
	 *
	 * @return self The same theme instance for fluent composition.
	 */
	public function brandLogoHeight(string $height): self {
		$this->brandLogoHeight=trim($height) ?: null;
		return $this;
	}

	/**
	 * Configures theme assets and brand presentation.
	 *
	 * Asset metadata records CSS, fonts, brand logos, favicon paths, and external asset roots for the renderer.
	 *
	 * @param string $url Favicon URL or asset reference stored in the manifest.
	 * @return self The same theme instance for fluent composition.
	 */
	public function favicon(string $url): self {
		$this->favicon=trim($url) ?: null;
		return $this;
	}

	/**
	 * Configures theme assets and brand presentation.
	 *
	 * Asset metadata records CSS, fonts, brand logos, favicon paths, and external asset roots for the renderer.
	 *
	 * @param array|string|null $css Stylesheet href, asset definition, list of assets, or null entry to ignore.
	 * @return self The same theme instance for fluent composition.
	 */
	public function css(array|string|null $css): self {
		$entries=is_array($css) && self::isAssetDefinition($css) ? [$css] : (is_array($css) ? $css : [$css]);
		foreach($entries as $entry){
			$asset=PanelThemeAsset::from($this->resolveAssetEntry($entry));
			if($asset instanceof PanelThemeAsset){
				$this->css[$asset->name()]=$asset;
			}
		}
		return $this;
	}

	/**
	 * Adds a stylesheet asset to this theme.
	 *
	 * The href is resolved through asset roots, then stored with a stable asset name and link attributes for renderer output.
	 *
	 * @param string $href Stylesheet URL or namespaced asset reference.
	 * @param ?string $name Normalized manifest object name.
	 * @param array<string,scalar|null> $attributes Link attributes forwarded to the stylesheet asset.
	 * @return self The same theme instance for fluent composition.
	 */
	public function stylesheet(string $href, ?string $name=null, array $attributes=[]): self {
		$asset=PanelThemeAsset::stylesheet($this->resolveAssetHref($href), $name, $attributes);
		if($asset->href()!==''){
			$this->css[$asset->name()]=$asset;
		}
		return $this;
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 * @return string Panel theme manifest result.
	 */
	public function mode(): string {
		return $this->defaultMode;
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 * @return bool Panel theme manifest result.
	 */
	public function darkModeEnabled(): bool {
		return $this->darkMode;
	}

	/**
	 * Configures light/dark mode behavior for the theme.
	 *
	 * Mode tokens describe defaults, toggle availability, and dark-mode overrides used by the Panel shell.
	 * @return bool Panel theme manifest result.
	 */
	public function modeToggleEnabled(): bool {
		return $this->darkMode && $this->modeToggle;
	}

	/**
	 * Configures theme assets and brand presentation.
	 *
	 * Asset metadata records CSS, fonts, brand logos, favicon paths, and external asset roots for the renderer.
	 * @return ?string Panel theme manifest result.
	 */
	public function faviconUrl(): ?string {
		return $this->favicon;
	}

	/**
	 * Returns brand metadata for renderer manifests.
	 *
	 * @return array{name:?string,logo:?string,dark_logo:?string,logo_height:?string} Brand presentation payload.
	 */
	public function brand(): array {
		return [
			'name'=>$this->brandName,
			'logo'=>$this->brandLogo,
			'dark_logo'=>$this->darkBrandLogo,
			'logo_height'=>$this->brandLogoHeight,
		];
	}

	/**
	 * Configures theme assets and brand presentation.
	 *
	 * Asset metadata records CSS, fonts, brand logos, favicon paths, and external asset roots for the renderer.
	 * @return list<string> Stylesheet hrefs in renderer load order.
	 */
	public function cssAssets(): array {
		return array_values(array_map(static fn(array $asset): string => (string)$asset['href'], $this->stylesheetAssets()));
	}

	/**
	 * Returns stylesheet asset descriptors.
	 *
	 * @return list<array<string,mixed>> Font and CSS asset descriptors with names, hrefs, and attributes.
	 */
	public function stylesheetAssets(): array {
		$assets=[];
		if(is_string($this->fontUrl) && trim($this->fontUrl)!==''){
			$assets['font']=PanelThemeAsset::stylesheet($this->fontUrl, 'font')->toArray();
		}
		foreach($this->css as $asset){
			if($asset instanceof PanelThemeAsset && $asset->href()!==''){
				$assets[$asset->name()]=$asset->toArray();
			}
		}
		return array_values($assets);
	}

	/**
	 * Exports the normalized theme manifest array.
	 *
	 * The payload includes raw tokens, generated variable maps, asset roots, font metadata, mode settings, brand assets, favicon, and stylesheet descriptors.
	 * @return array<string,mixed> Normalized theme manifest payload.
	 */
	public function toArray(): array {
		return [
			'type'=>'theme',
			'name'=>$this->name,
			'colors'=>$this->colors,
			'tokens'=>$this->tokens,
			'dark_tokens'=>$this->darkTokens,
			'variables'=>self::variablesFrom($this->colors, $this->tokens),
			'dark_variables'=>self::variablesFrom([], array_replace(self::defaultDarkTokens(), $this->darkTokens)),
			'asset_roots'=>$this->assetRoots,
			'font'=>$this->font,
			'font_url'=>$this->fontUrl,
			'font_provider'=>$this->fontProvider,
			'dark_mode'=>$this->darkMode,
			'default_mode'=>$this->defaultMode,
			'mode_toggle'=>$this->modeToggle,
			'brand'=>$this->brand(),
			'favicon'=>$this->favicon,
			'css'=>$this->cssAssets(),
			'css_assets'=>$this->stylesheetAssets(),
		];
	}

	/**
	 * Renders CSS custom properties for this theme.
	 *
	 * Light variables are emitted under `:root`; dark variables are emitted for explicit dark mode and system dark preference when dark mode is enabled.
	 * @return string CSS custom-property block.
	 */
	public function styleVariables(): string {
		return self::styleVariablesFor($this->colors, $this->tokens, $this->darkMode, $this->darkTokens);
	}

	/**
	 * Exports the full theme manifest.
	 *
	 * This extends `toArray()` with a pre-rendered `css_variables` string for renderers that do not want to regenerate CSS.
	 * @return array<string,mixed> Theme manifest with CSS variables.
	 */
	public function manifest(): array {
		$manifest=$this->toArray();
		$manifest['css_variables']=$this->styleVariables();
		return $manifest;
	}

	/**
	 * Calculates contrast diagnostics for light and dark modes.
	 *
	 * Thresholds are passed to the per-mode contrast checker; dark diagnostics are omitted when dark mode is disabled.
	 *
	 * @param float $normalThreshold Minimum contrast ratio for normal text.
	 * @param float $largeThreshold Minimum contrast ratio for large text.
	 * @return array<string,mixed> Contrast report keyed by mode.
	 */
	public function contrastDiagnostics(float $normalThreshold=4.5, float $largeThreshold=3.0): array {
		$lightTokens=$this->tokens;
		$darkTokens=array_replace(self::defaultDarkTokens(), $this->darkTokens);
		return [
			'light'=>$this->contrastDiagnosticsForMode('light', $lightTokens, $normalThreshold, $largeThreshold),
			'dark'=>$this->darkMode ? $this->contrastDiagnosticsForMode('dark', $darkTokens, $normalThreshold, $largeThreshold) : [],
		];
	}

	/**
	 * Builds structured preview data for this theme.
	 *
	 * The preview includes brand metadata, mode settings, palette samples, rendered mode tokens, assets, and contrast diagnostics.
	 * @return array<string,mixed> Theme preview payload.
	 */
	public function preview(): array {
		$lightTokens=$this->tokens;
		$darkTokens=array_replace(self::defaultDarkTokens(), $this->darkTokens);
		return [
			'name'=>$this->name,
			'brand'=>$this->brand(),
			'default_mode'=>$this->defaultMode,
			'dark_mode'=>$this->darkMode,
			'mode_toggle'=>$this->modeToggle,
			'colors'=>$this->colorPreview(),
			'modes'=>[
				'light'=>$this->modePreview('light', $lightTokens),
				'dark'=>$this->darkMode ? $this->modePreview('dark', $darkTokens) : null,
			],
			'assets'=>[
				'favicon'=>$this->favicon,
				'stylesheets'=>$this->stylesheetAssets(),
			],
			'contrast'=>$this->contrastDiagnostics(),
		];
	}

	/**
	 * Renders preview HTML for this theme.
	 *
	 *
	 * @param array<string,mixed> $options Additional preview rendering options.
	 * @return string Rendered theme preview HTML.
	 */
	public function previewHtml(array $options=[]): string {
		return PanelThemePreview::render($this, $options);
	}

	/**
	 * Encodes the theme manifest as JSON.
	 *
	 *
	 * @param int $flags Additional `json_encode()` flags.
	 * @return string JSON manifest, or `{}` if encoding fails.
	 */
	public function toJson(int $flags=0): string {
		return json_encode($this->manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $flags) ?: '{}';
	}

	/**
	 * Renders the theme CSS file contents.
	 *
	 * @return string CSS comment header followed by custom-property output.
	 */
	public function toCss(): string {
		return "/* Dataphyre Panel theme: {$this->name} */\n".$this->styleVariables()."\n";
	}

	/**
	 * Writes the theme manifest JSON to disk.
	 *
	 *
	 * @param int $flags Additional `json_encode()` flags merged with pretty-print output.
	 * @return bool True when the file write succeeds.
	 */
	public function writeManifest(string $path, int $flags=0): bool {
		return self::writeFile($path, $this->toJson(JSON_PRETTY_PRINT | $flags)."\n");
	}

	/**
	 * Writes the generated theme CSS to disk.
	 *
	 *
	 * @return bool True when the file write succeeds.
	 */
	public function writeCss(string $path): bool {
		return self::writeFile($path, $this->toCss());
	}

	/**
	 * Exports manifest and CSS files to a directory.
	 *
	 *
	 * @param ?string $basename Optional normalized export basename; null uses the theme name.
	 * @return array{manifest:bool,css:bool,manifest_path:string,css_path:string} Export result and written paths.
	 */
	public function exportTo(string $directory, ?string $basename=null): array {
		$basename=Resource::normalizeName((string)($basename ?? $this->name)) ?: 'theme';
		$directory=trim($directory) ?: '.';
		$directory=rtrim($directory, "\\/");
		$manifestPath=$directory.DIRECTORY_SEPARATOR.$basename.'.panel-theme.json';
		$cssPath=$directory.DIRECTORY_SEPARATOR.$basename.'.panel-theme.css';
		return [
			'manifest'=>$this->writeManifest($manifestPath),
			'css'=>$this->writeCss($cssPath),
			'manifest_path'=>$manifestPath,
			'css_path'=>$cssPath,
		];
	}

	/**
	 * Renders CSS custom properties for supplied colors and tokens.
	 *
	 * The output defines semantic palette aliases and optional dark-mode overrides for explicit and system color-scheme modes.
	 *
	 * @param array<string,array<int|string,string>> $colors Palette map keyed by semantic color name.
	 * @param array<string,string> $tokens Scalar design tokens keyed by normalized token name.
	 * @param bool $darkMode Whether dark-mode custom properties should be emitted.
	 * @param array<string,string> $darkTokens Dark-mode token overrides keyed by normalized token name.
	 * @return string Compact CSS custom-property output.
	 */
	public static function styleVariablesFor(array $colors, array $tokens=[], bool $darkMode=true, array $darkTokens=[]): string {
		$lines=[':root{'];
		foreach($tokens as $name=>$value){
			$lines[]='--dp-'.$name.':'.$value.';';
		}
		foreach($colors as $name=>$palette){
			foreach($palette as $shade=>$value){
				$lines[]='--dp-'.$name.'-'.$shade.':'.$value.';';
			}
		}
		$lines[]='--dp-primary:var(--dp-primary-600);';
		$lines[]='--dp-success:var(--dp-success-600);';
		$lines[]='--dp-warning:var(--dp-warning-600);';
		$lines[]='--dp-danger:var(--dp-danger-600);';
		$lines[]='--dp-info:var(--dp-info-600);';
		$lines[]='}';
		if($darkMode){
			$dark='';
			foreach(array_replace(self::defaultDarkTokens(), $darkTokens) as $name=>$value){
				$dark.='--dp-'.$name.':'.$value.';';
			}
			$lines[]='[data-dp-theme-mode="dark"]{'.$dark.'}';
			$lines[]='@media (prefers-color-scheme:dark){[data-dp-theme-mode="system"]{'.$dark.'}}';
		}
		return implode('', $lines);
	}

	/**
	 * Converts theme tokens and palettes into CSS custom-property metadata.
	 *
	 * This array form mirrors styleVariablesFor() but keeps values structured for
	 * manifests and previews that need variable names without parsing CSS text.
	 *
	 * @param array<string,array<int|string,string>> $colors Palette map keyed by semantic color name.
	 * @param array<string,string> $tokens Scalar design tokens keyed by normalized token name.
	 * @return array<string,string> CSS custom properties keyed by `--dp-*` names.
	 */
	private static function variablesFrom(array $colors, array $tokens): array {
		$variables=[];
		foreach($tokens as $name=>$value){
			$variables['--dp-'.$name]=(string)$value;
		}
		foreach($colors as $name=>$palette){
			foreach($palette as $shade=>$value){
				$variables['--dp-'.$name.'-'.$shade]=(string)$value;
			}
		}
		return $variables;
	}

	/**
	 * Merges theme override arrays while preserving list-like replacement fields.
	 *
	 * Recursive replacement is useful for token and brand maps, but CSS and preset
	 * declarations are ordered lists or singular declarations where an override
	 * must replace the base instead of being recursively blended.
	 *
	 * @param array<string,mixed> $base Base theme manifest.
	 * @param array<string,mixed> $overrides Override manifest.
	 * @return array<string,mixed> Merged theme definition.
	 */
	private static function mergeDefinition(array $base, array $overrides): array {
		$merged=array_replace_recursive($base, $overrides);
		foreach(['css', 'css_assets', 'preset', 'presets'] as $key){
			if(array_key_exists($key, $overrides)){
				$merged[$key]=$overrides[$key];
			}
		}
		return $merged;
	}

	/**
	 * Provides dark-mode token defaults for Panel surfaces.
	 *
	 * Defaults cover the semantic tokens the renderer expects for page, surface,
	 * text, border, control, and neutral states. Explicit dark tokens replace these
	 * values during CSS generation and preview rendering.
	 *
	 * @return array<string,string> Default dark-mode token map.
	 */
	private static function defaultDarkTokens(): array {
		return [
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
		];
	}

	/**
	 * Computes accessibility contrast diagnostics for one theme mode.
	 *
	 * The diagnostic set checks token pairs used by the Panel shell plus white text
	 * over semantic action colors. Invalid or missing color tokens produce
	 * `unknown` entries instead of failing the whole preview.
	 *
	 * @param string $mode Light or dark mode label.
	 * @param array<string,string> $tokens Token map for the mode.
	 * @param float $normalThreshold WCAG-style threshold for normal text.
	 * @param float $largeThreshold WCAG-style threshold for large text or strong UI labels.
	 * @return array<int,array<string,mixed>> Contrast check results.
	 */
	private function contrastDiagnosticsForMode(string $mode, array $tokens, float $normalThreshold, float $largeThreshold): array {
		$checks=[
			['surface', 'text', 'normal'],
			['surface', 'text_muted', 'large'],
			['surface_muted', 'text', 'normal'],
			['control_bg', 'text', 'normal'],
			['neutral_bg', 'neutral_text', 'normal'],
		];
		$results=[];
		foreach($checks as [$backgroundToken, $textToken, $size]){
			$background=self::parseColor((string)($tokens[$backgroundToken] ?? ''));
			$text=self::parseColor((string)($tokens[$textToken] ?? ''));
			if($background===null || $text===null){
				$results[]=[
					'mode'=>$mode,
					'background'=>$backgroundToken,
					'text'=>$textToken,
					'status'=>'unknown',
					'ratio'=>null,
					'threshold'=>$size==='large' ? $largeThreshold : $normalThreshold,
				];
				continue;
			}
			$ratio=self::contrastRatio($background, $text);
			$threshold=$size==='large' ? $largeThreshold : $normalThreshold;
			$results[]=[
				'mode'=>$mode,
				'background'=>$backgroundToken,
				'text'=>$textToken,
				'status'=>$ratio>=$threshold ? 'pass' : 'fail',
				'ratio'=>round($ratio, 2),
				'threshold'=>$threshold,
			];
		}
		foreach(['primary', 'success', 'warning', 'danger', 'info'] as $color){
			$background=self::parseColor((string)($this->colors[$color][600] ?? ''));
			$text=self::parseColor('#ffffff');
			if($background===null){
				continue;
			}
			$ratio=self::contrastRatio($background, $text);
			$results[]=[
				'mode'=>$mode,
				'background'=>$color.'-600',
				'text'=>'white',
				'status'=>$ratio>=$largeThreshold ? 'pass' : 'fail',
				'ratio'=>round($ratio, 2),
				'threshold'=>$largeThreshold,
			];
		}
		return $results;
	}

	/**
	 * Builds a compact palette preview for each semantic color.
	 *
	 * The `key` map exposes the shade roles the renderer most often uses for soft
	 * backgrounds, muted accents, base actions, and strong accents.
	 *
	 * @return array<string,array<string,mixed>> Palette preview keyed by color name.
	 */
	private function colorPreview(): array {
		$preview=[];
		foreach($this->colors as $name=>$palette){
			$preview[$name]=[
				'shades'=>$palette,
				'key'=>[
					'soft'=>$palette[100] ?? null,
					'muted'=>$palette[400] ?? null,
					'base'=>$palette[600] ?? null,
					'strong'=>$palette[800] ?? null,
				],
			];
		}
		return $preview;
	}

	/**
	 * Builds preview data for one resolved theme mode.
	 *
	 * Preview samples group token values by renderer surface so theme editors can
	 * inspect page, surface, muted surface, control, and action styling without
	 * constructing a full Panel resource.
	 *
	 * @param string $mode Light or dark mode label.
	 * @param array<string,string> $tokens Resolved token map for the mode.
	 * @return array<string,mixed> Mode preview data.
	 */
	private function modePreview(string $mode, array $tokens): array {
		return [
			'mode'=>$mode,
			'tokens'=>$tokens,
			'variables'=>self::variablesFrom([], $tokens),
			'samples'=>[
				'page'=>[
					'background'=>$tokens['body_bg'] ?? null,
					'text'=>$tokens['text'] ?? null,
				],
				'surface'=>[
					'background'=>$tokens['surface'] ?? null,
					'text'=>$tokens['text'] ?? null,
					'muted_text'=>$tokens['text_muted'] ?? null,
					'border'=>$tokens['border'] ?? null,
				],
				'muted_surface'=>[
					'background'=>$tokens['surface_muted'] ?? null,
					'text'=>$tokens['text'] ?? null,
					'border'=>$tokens['border_soft'] ?? null,
				],
				'control'=>[
					'background'=>$tokens['control_bg'] ?? null,
					'text'=>$tokens['text'] ?? null,
					'border'=>$tokens['control_border'] ?? null,
					'padding'=>$tokens['input_padding'] ?? null,
				],
				'action'=>[
					'background'=>$this->colors['primary'][600] ?? null,
					'text'=>'#ffffff',
					'padding'=>$tokens['control_padding'] ?? null,
					'radius'=>$tokens['radius'] ?? null,
				],
			],
		];
	}

	/**
	 * Parses a six-digit hex color into RGB channels.
	 *
	 * Unsupported color syntaxes return null because contrast diagnostics require
	 * deterministic numeric channels and should not guess at CSS variables,
	 * keywords, gradients, or runtime-calculated values.
	 *
	 * @param string $color Candidate hex color.
	 * @return ?array{0:int,1:int,2:int} RGB channels, or null for unsupported input.
	 */
	private static function parseColor(string $color): ?array {
		$color=trim($color);
		if(preg_match('/^#?([0-9a-f]{6})$/i', $color, $match)!==1){
			return null;
		}
		return self::hexToRgb('#'.$match[1]);
	}

	/**
	 * Calculates the contrast ratio between two RGB colors.
	 *
	 * @param array{0:int|float,1:int|float,2:int|float} $first First RGB color.
	 * @param array{0:int|float,1:int|float,2:int|float} $second Second RGB color.
	 * @return float Contrast ratio with the lighter luminance over the darker luminance.
	 */
	private static function contrastRatio(array $first, array $second): float {
		$firstLuminance=self::relativeLuminance($first);
		$secondLuminance=self::relativeLuminance($second);
		$lighter=max($firstLuminance, $secondLuminance);
		$darker=min($firstLuminance, $secondLuminance);
		return ($lighter + 0.05) / ($darker + 0.05);
	}

	/**
	 * Calculates WCAG relative luminance for an RGB color.
	 *
	 * Channels are normalized from 0-255 into linearized sRGB before applying the
	 * standard luminance weights.
	 *
	 * @param array{0:int|float,1:int|float,2:int|float} $rgb RGB channels.
	 * @return float Relative luminance from 0 to 1.
	 */
	private static function relativeLuminance(array $rgb): float {
		$channels=array_map(static function(int|float $channel): float {
			$value=((float)$channel) / 255;
			return $value<=0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
		}, $rgb);
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Writes a generated theme artifact to disk.
	 *
	 * Parent directories are created when possible and writes use LOCK_EX. Empty
	 * paths, uncreatable directories, or failed writes return false so export
	 * callers can report partial manifest/CSS export status.
	 *
	 * @param string $path Destination path.
	 * @param string $contents File contents to write.
	 * @return bool Whether the file was written successfully.
	 */
	private static function writeFile(string $path, string $contents): bool {
		$path=trim($path);
		if($path===''){
			return false;
		}
		$directory=dirname($path);
		if($directory!=='' && $directory!=='.' && !is_dir($directory) && @mkdir($directory, 0775, true)!==true && !is_dir($directory)){
			return false;
		}
		return @file_put_contents($path, $contents, LOCK_EX)!==false;
	}

	/**
	 * Detects array entries that describe a stylesheet asset.
	 *
	 * Asset definitions can use href, url, or path keys before normalization into
	 * PanelThemeAsset objects.
	 *
	 * @param array<string,mixed> $value Potential asset definition.
	 * @return bool Whether the array looks like an asset declaration.
	 */
	private static function isAssetDefinition(array $value): bool {
		return isset($value['href']) || isset($value['url']) || isset($value['path']);
	}

	/**
	 * Resolves one CSS asset entry through configured asset roots.
	 *
	 * Strings are treated as hrefs. Array definitions have their first scalar
	 * href/url/path value resolved while preserving the rest of the asset metadata
	 * for PanelThemeAsset::from().
	 *
	 * @param mixed $entry Raw stylesheet entry.
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
	 * Resolves a namespaced asset reference to a concrete href.
	 *
	 * References in `namespace::path/to.css` form use the configured asset root for
	 * the namespace. Unknown namespaces are left untouched so unresolved references
	 * remain visible in manifests instead of silently becoming broken paths.
	 *
	 * @param string $href Raw stylesheet, font, logo, or favicon href.
	 * @return string Resolved href or the original value when no root applies.
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

	/**
	 * Normalizes one or more preset declarations from manifest input.
	 *
	 * A single string, array, or PanelThemePreset becomes one declaration. List
	 * input is filtered to supported preset shapes while non-preset values are
	 * discarded.
	 *
	 * @param mixed $value Raw `preset` or `presets` definition value.
	 * @return array<int,string|array|PanelThemePreset> Preset declarations in application order.
	 */
	private static function presetDefinitions(mixed $value): array {
		if($value instanceof PanelThemePreset || is_string($value)){
			return [$value];
		}
		if(!is_array($value)){
			return [];
		}
		if(array_is_list($value)){
			return array_values(array_filter($value, static fn(mixed $item): bool => $item instanceof PanelThemePreset || is_string($item) || is_array($item)));
		}
		return [$value];
	}

	/**
	 * Extracts base theme declarations from array configuration.
	 *
	 * The method supports `extends`, `base_theme`, and `base` aliases and preserves
	 * declaration order for list values so composed themes inherit predictably.
	 *
	 * @param array<string,mixed> $definition Raw theme definition.
	 * @return array<int,string|array|PanelTheme|PanelThemePreset> Base theme declarations.
	 */
	private static function baseDefinitions(array $definition): array {
		$bases=[];
		foreach(['extends', 'base_theme', 'base'] as $key){
			if(isset($definition[$key])){
				$value=$definition[$key];
				foreach(is_array($value) && array_is_list($value) ? $value : [$value] as $base){
					if($base instanceof PanelTheme || $base instanceof PanelThemePreset || is_string($base) || is_array($base)){
						$bases[]=$base;
					}
				}
			}
		}
		return $bases;
	}

	/**
	 * Normalizes a palette declaration into the canonical Panel shade map.
	 *
	 * Named palettes and hex colors generate all configured shades. Partial array
	 * palettes replace matching shades over the fallback palette, keeping missing
	 * shades stable for CSS variable generation.
	 *
	 * @param mixed $palette Raw palette declaration.
	 * @param ?array<int|string,string> $fallback Existing or default palette values.
	 * @return array<int,string> Palette keyed by supported shade numbers.
	 */
	private static function normalizePalette(mixed $palette, ?array $fallback=null): array {
		if(is_string($palette)){
			$named=self::namedPalette($palette);
			if($named!==null){
				return $named;
			}
			if(preg_match('/^#?([0-9a-f]{6})$/i', trim($palette), $match)===1){
				return self::paletteFromHex('#'.$match[1]);
			}
			$fallback ??=array_fill_keys(self::SHADES, trim($palette));
			$fallback[600]=trim($palette);
			return $fallback;
		}
		if(is_array($palette)){
			$normalized=[];
			foreach(self::SHADES as $shade){
				if(isset($palette[$shade]) && (is_string($palette[$shade]) || is_numeric($palette[$shade]))){
					$normalized[$shade]=(string)$palette[$shade];
				}
			}
			if($normalized!==[]){
				$base=$fallback ?? self::paletteFromHex('#2563eb');
				return array_replace($base, $normalized);
			}
		}
		return $fallback ?? self::paletteFromHex('#2563eb');
	}

	/**
	 * Returns the default semantic color palettes for Panel themes.
	 *
	 * @return array<string,array<int,string>> Default semantic color palettes.
	 */
	private static function defaultColors(): array {
		return [
			'primary'=>self::paletteFromHex('#2563eb'),
			'success'=>self::paletteFromHex('#079455'),
			'warning'=>self::paletteFromHex('#dc6803'),
			'danger'=>self::paletteFromHex('#d92d20'),
			'info'=>self::paletteFromHex('#026aa2'),
			'gray'=>self::paletteFromHex('#667085'),
		];
	}

	/**
	 * Resolves a built-in palette name to generated shades.
	 *
	 * Built-in names intentionally map to fixed base hex values so generated
	 * themes remain deterministic across requests and preview exports.
	 *
	 * @param string $name Palette name.
	 * @return ?array<int,string> Generated palette, or null for unknown names.
	 */
	private static function namedPalette(string $name): ?array {
		$name=Resource::normalizeName($name);
		$map=[
			'blue'=>'#2563eb',
			'indigo'=>'#4f46e5',
			'violet'=>'#7c3aed',
			'purple'=>'#9333ea',
			'rose'=>'#e11d48',
			'red'=>'#dc2626',
			'orange'=>'#ea580c',
			'amber'=>'#d97706',
			'yellow'=>'#ca8a04',
			'lime'=>'#65a30d',
			'green'=>'#16a34a',
			'emerald'=>'#059669',
			'teal'=>'#0d9488',
			'cyan'=>'#0891b2',
			'sky'=>'#0284c7',
			'zinc'=>'#71717a',
			'gray'=>'#667085',
			'slate'=>'#475569',
		];
		return isset($map[$name]) ? self::paletteFromHex($map[$name]) : null;
	}

	/**
	 * Generates a full shade scale from a base hex color.
	 *
	 * Lighter shades mix toward white and darker shades scale toward black using
	 * fixed ratios. The 600 shade remains the source color and is used as the
	 * semantic action default.
	 *
	 * @param string $hex Base six-digit hex color.
	 * @return array<int,string> Generated shade map.
	 */
	private static function paletteFromHex(string $hex): array {
		[$r, $g, $b]=self::hexToRgb($hex);
		$mix=[
			50=>.95,
			100=>.9,
			200=>.78,
			300=>.62,
			400=>.38,
			500=>.16,
			600=>0,
			700=>-.12,
			800=>-.24,
			900=>-.36,
			950=>-.48,
		];
		$palette=[];
		foreach($mix as $shade=>$amount){
			if($amount>=0){
				$palette[$shade]=self::rgbToHex(
					(int)round($r+((255-$r)*$amount)),
					(int)round($g+((255-$g)*$amount)),
					(int)round($b+((255-$b)*$amount))
				);
			}
			else {
				$factor=1+$amount;
				$palette[$shade]=self::rgbToHex((int)round($r*$factor), (int)round($g*$factor), (int)round($b*$factor));
			}
		}
		return $palette;
	}

	/**
	 * Converts a hex color to RGB channels.
	 *
	 * Invalid input falls back to Panel's default primary blue so palette
	 * generation always returns a complete, usable shade map.
	 *
	 * @param string $hex Six-digit hex color with or without leading #.
	 * @return array{0:int,1:int,2:int} RGB channels.
	 */
	private static function hexToRgb(string $hex): array {
		$hex=ltrim(trim($hex), '#');
		if(strlen($hex)!==6 || preg_match('/^[0-9a-f]{6}$/i', $hex)!==1){
			return [37, 99, 235];
		}
		return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
	}

	/**
	 * Formats RGB channels as a clamped six-digit hex color.
	 *
	 * @param int $r Red channel.
	 * @param int $g Green channel.
	 * @param int $b Blue channel.
	 * @return string Hex color in #rrggbb form.
	 */
	private static function rgbToHex(int $r, int $g, int $b): string {
		return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
	}

	/**
	 * Builds the CSS font-family stack for a configured brand font.
	 *
	 * Font names containing spaces are quoted, embedded quotes are stripped, and a
	 * generic Arial/sans-serif fallback is appended for stable Panel rendering.
	 *
	 * @param string $font Font family name.
	 * @return string CSS font-family stack.
	 */
	private static function fontStack(string $font): string {
		$font=trim($font);
		if($font===''){
			return 'Arial, sans-serif';
		}
		$quoted=str_contains($font, ' ') ? '"'.str_replace('"', '', $font).'"' : $font;
		return $quoted.', Arial, sans-serif';
	}
}
