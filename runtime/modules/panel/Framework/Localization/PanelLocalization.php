<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * In-memory localization catalogue for panel labels, actions, resources, and diagnostics.
 *
 * PanelLocalization stores translations as a normalized two-level catalogue: locale => dotted key => string value. Nested
 * input arrays are flattened into dotted keys, optional scopes prefix those keys, and all locale identifiers are normalized
 * to language or language-region form such as en or fr-CA. The object is mutable so panel resources can add translations
 * while they are assembled, but exported output is deterministic because locale and key maps are sorted after writes.
 *
 * Lookup order is explicit and stable: requested locale, requested base language, fallback locale, fallback base language.
 * Missing keys return the caller default when supplied, otherwise the normalized key itself, which keeps operator UI
 * debuggable when a translation catalogue is incomplete.
 */
final class PanelLocalization implements \JsonSerializable {

	/** @var string Active locale used when a lookup does not specify one. */
	private string $locale='en';
	/** @var string Fallback locale used after requested locale and base-language checks fail. */
	private string $fallbackLocale='en';
	/** @var array<string, array<string, string>> Normalized translation catalogue keyed by locale and dotted translation key. */
	private array $catalogue=[];
	/** @var array<string, mixed> Free-form manifest metadata. */
	private array $meta=[];

	/**
	 * Creates a localization catalogue from compact or manifest-shaped input.
	 *
	 * Input may be a raw locale catalogue or a localization manifest containing locale, fallback_locale,
	 * translations, catalogue, catalog, and meta keys. Metadata from the constructor argument and catalogue data is
	 * merged before translations are loaded.
	 *
	 * @param array<string, mixed> $catalogue Raw catalogue or localization manifest data.
	 * @param ?string $locale Active locale override.
	 * @param ?string $fallbackLocale Fallback locale override.
	 * @param array<string, mixed> $meta Metadata merged into manifest output.
	 */
	public function __construct(array $catalogue=[], ?string $locale=null, ?string $fallbackLocale=null, array $meta=[]) {
		$this->locale($locale ?? (string)($catalogue['locale'] ?? 'en'));
		$this->fallbackLocale($fallbackLocale ?? (string)($catalogue['fallback_locale'] ?? $catalogue['fallbackLocale'] ?? $catalogue['fallback'] ?? $this->locale));
		$this->meta($meta);
		$translations=$catalogue['translations'] ?? $catalogue['catalogue'] ?? $catalogue['catalog'] ?? $catalogue;
		unset($translations['locale'], $translations['fallback_locale'], $translations['fallbackLocale'], $translations['fallback'], $translations['translations'], $translations['catalogue'], $translations['catalog'], $translations['meta']);
		if(is_array($catalogue['meta'] ?? null)){
			$this->meta($catalogue['meta']);
		}
		$this->catalogue($translations);
	}

	/**
	 * Builds a localization object for fluent panel configuration.
	 *
	 * @param array<string, mixed> $catalogue Raw catalogue or localization manifest data.
	 * @param ?string $locale Active locale override.
	 * @param ?string $fallbackLocale Fallback locale override.
	 * @param array<string, mixed> $meta Metadata merged into manifest output.
	 * @return self Localization catalogue with normalized locale state.
	 */
	public static function make(array $catalogue=[], ?string $locale=null, ?string $fallbackLocale=null, array $meta=[]): self {
		return new self($catalogue, $locale, $fallbackLocale, $meta);
	}

	/**
	 * Normalizes an existing localization source into a PanelLocalization instance.
	 *
	 * Existing instances are returned unchanged unless a locale override is requested; overrides clone the object so callers
	 * can derive a per-request locale view without mutating a shared catalogue.
	 *
	 * @param self|array<string, mixed>|null $localization Existing catalogue, localization manifest data, or null for an empty catalogue.
	 * @param ?string $locale Active locale override.
	 * @param ?string $fallbackLocale Fallback locale override.
	 * @return self Localization instance for runtime use.
	 */
	public static function from(self|array|null $localization=null, ?string $locale=null, ?string $fallbackLocale=null): self {
		if($localization instanceof self){
			if($locale!==null || $fallbackLocale!==null){
				$clone=clone $localization;
				if($locale!==null){
					$clone->locale($locale);
				}
				if($fallbackLocale!==null){
					$clone->fallbackLocale($fallbackLocale);
				}
				return $clone;
			}
			return $localization;
		}
		return new self(is_array($localization) ? $localization : [], $locale, $fallbackLocale);
	}

	/**
	 * Gets or sets the active locale.
	 *
	 * Blank or invalid input falls back to en after normalization.
	 *
	 * @param ?string $locale Locale override, or null to read.
	 * @return string|self Current locale when reading, otherwise this catalogue.
	 */
	public function locale(?string $locale=null): string|self {
		if($locale===null){
			return $this->locale;
		}
		$this->locale=self::normalizeLocale($locale) ?: 'en';
		return $this;
	}

	/**
	 * Gets or sets the fallback locale used after requested-locale candidates fail.
	 *
	 * Blank fallback input resolves to the current active locale so the lookup chain always has at least one usable locale.
	 *
	 * @param ?string $locale Fallback locale override, or null to read.
	 * @return string|self Current fallback locale when reading, otherwise this catalogue.
	 */
	public function fallbackLocale(?string $locale=null): string|self {
		if($locale===null){
			return $this->fallbackLocale;
		}
		$this->fallbackLocale=self::normalizeLocale($locale) ?: $this->locale;
		return $this;
	}

	/**
	 * Gets the full catalogue or merges a locale map into it.
	 *
	 * Setter input must be shaped as locale => translations. Non-array locale entries are skipped so manifest data can
	 * include adjacent metadata without corrupting the catalogue.
	 *
	 * @param ?array<string, array<string, mixed>> $catalogue Locale map to merge, or null to read.
	 * @return array<string, array<string, string>>|self Full catalogue when reading, otherwise this catalogue.
	 */
	public function catalogue(?array $catalogue=null): array|self {
		if($catalogue===null){
			return $this->catalogue;
		}
		foreach($catalogue as $locale=>$translations){
			if(!is_array($translations)){
				continue;
			}
			$this->add((string)$locale, $translations);
		}
		return $this;
	}

	/**
	 * Returns translations stored for one normalized locale.
	 *
	 * This method does not apply fallback lookup; it exposes only the stored locale bucket for export and diagnostics.
	 *
	 * @param ?string $locale Locale to inspect, or null for the active locale.
	 * @return array<string, string> Translation map for the locale.
	 */
	public function translations(?string $locale=null): array {
		$locale=self::normalizeLocale($locale ?? $this->locale);
		return $this->catalogue[$locale] ?? [];
	}

	/**
	 * Merges translations into a locale bucket.
	 *
	 * Nested arrays are flattened into dotted keys, scalar/Stringable/null values become strings, and later writes replace
	 * earlier values for the same locale/key pair. Scope is applied before flattening so a resource can keep local keys
	 * short while exporting globally unique catalogue entries.
	 *
	 * @param string $locale Locale bucket to update.
	 * @param array<string, mixed> $translations Nested or flat translation map.
	 * @param string $scope Optional dotted key prefix.
	 * @return self Same catalogue after merging translations.
	 */
	public function add(string $locale, array $translations, string $scope=''): self {
		$locale=self::normalizeLocale($locale);
		if($locale===''){
			return $this;
		}
		$flat=self::flattenTranslations($translations, $scope);
		if($flat===[]){
			return $this;
		}
		$this->catalogue[$locale]=array_replace($this->catalogue[$locale] ?? [], $flat);
		ksort($this->catalogue[$locale]);
		ksort($this->catalogue);
		return $this;
	}

	/**
	 * Sets one translation value in a locale bucket.
	 *
	 * Empty normalized locale or key values are ignored. Values are converted with stringValue(), so booleans become true or
	 * false strings and null becomes an empty string.
	 *
	 * @param string $locale Locale bucket to update.
	 * @param string $key Translation key relative to the optional scope.
	 * @param string|\Stringable|int|float|bool|null $value Translation value.
	 * @param string $scope Optional dotted key prefix.
	 * @return self Same catalogue after setting the translation.
	 */
	public function set(string $locale, string $key, string|\Stringable|int|float|bool|null $value, string $scope=''): self {
		$locale=self::normalizeLocale($locale);
		$key=self::scopedKey($key, $scope);
		if($locale==='' || $key===''){
			return $this;
		}
		$this->catalogue[$locale][$key]=self::stringValue($value);
		ksort($this->catalogue[$locale]);
		ksort($this->catalogue);
		return $this;
	}

	/**
	 * Checks whether a key exists in the requested locale chain.
	 *
	 * The same candidate order as translate() is used, so this answers "would this key resolve from the catalogue" rather
	 * than "is this key present in exactly one locale bucket".
	 *
	 * @param string $key Translation key relative to the optional scope.
	 * @param ?string $locale Requested locale, or null for the active locale.
	 * @param string $scope Optional dotted key prefix.
	 * @return bool Whether any candidate locale contains the scoped key.
	 */
	public function has(string $key, ?string $locale=null, string $scope=''): bool {
		$key=self::scopedKey($key, $scope);
		if($key===''){
			return false;
		}
		foreach($this->candidateLocales($locale) as $candidate){
			if(array_key_exists($key, $this->catalogue[$candidate] ?? [])){
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves and interpolates a translation key.
	 *
	 * Placeholder replacement supports :name, {name}, {{name}}, and {{ name }} forms. Non-scalar parameter values are JSON
	 * encoded before replacement so diagnostic messages can safely include structured context.
	 *
	 * @param string $key Translation key relative to the optional scope.
	 * @param array<string|int, mixed> $parameters Placeholder values.
	 * @param ?string $locale Requested locale, or null for the active locale.
	 * @param string|\Stringable|null $default Default message when no catalogue entry exists.
	 * @param string $scope Optional dotted key prefix.
	 * @return string Interpolated translation, default, or normalized key.
	 */
	public function translate(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null, string $scope=''): string {
		$key=self::scopedKey($key, $scope);
		if($key===''){
			return self::interpolate(self::stringValue($default), $parameters);
		}
		foreach($this->candidateLocales($locale) as $candidate){
			if(array_key_exists($key, $this->catalogue[$candidate] ?? [])){
				return self::interpolate($this->catalogue[$candidate][$key], $parameters);
			}
		}
		return self::interpolate($default===null ? $key : self::stringValue($default), $parameters);
	}

	/**
	 * Alias for translate() used by Symfony-style localization call sites.
	 *
	 * @param string $key Translation key relative to the optional scope.
	 * @param array<string|int, mixed> $parameters Placeholder values.
	 * @param ?string $locale Requested locale, or null for the active locale.
	 * @param string|\Stringable|null $default Default message when no catalogue entry exists.
	 * @param string $scope Optional dotted key prefix.
	 * @return string Interpolated translation, default, or normalized key.
	 */
	public function trans(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null, string $scope=''): string {
		return $this->translate($key, $parameters, $locale, $default, $scope);
	}

	/**
	 * Short alias for translate() used in dense panel declarations.
	 *
	 * @param string $key Translation key relative to the optional scope.
	 * @param array<string|int, mixed> $parameters Placeholder values.
	 * @param ?string $locale Requested locale, or null for the active locale.
	 * @param string|\Stringable|null $default Default message when no catalogue entry exists.
	 * @param string $scope Optional dotted key prefix.
	 * @return string Interpolated translation, default, or normalized key.
	 */
	public function t(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null, string $scope=''): string {
		return $this->translate($key, $parameters, $locale, $default, $scope);
	}

	/**
	 * Creates a scoped localization helper.
	 *
	 * Scoped helpers share this catalogue and prefix every key with the supplied scope. They are useful for resources,
	 * widgets, and table definitions that own a local translation namespace.
	 *
	 * @param string $scope Dotted key prefix.
	 * @return PanelLocalizationScope Helper bound to this catalogue and scope.
	 */
	public function scope(string $scope): PanelLocalizationScope {
		return new PanelLocalizationScope($this, $scope);
	}

	/**
	 * Gets or merges manifest metadata.
	 *
	 * @param ?array<string, mixed> $meta Metadata to merge, or null to read.
	 * @return array<string, mixed>|self Current metadata when reading, otherwise this catalogue.
	 */
	public function meta(?array $meta=null): array|self {
		if($meta===null){
			return $this->meta;
		}
		$this->meta=array_replace($this->meta, $meta);
		return $this;
	}

	/**
	 * Returns a documentation-ready manifest with one-time metadata overrides.
	 *
	 * @param array<string, mixed> $meta Metadata merged over the serialized meta block.
	 * @return array<string, mixed> Localization manifest data.
	 */
	public function manifest(array $meta=[]): array {
		return array_replace_recursive($this->toArray(), ['meta'=>$meta]);
	}

	/**
	 * Serializes the catalogue for diagnostics and JSON output.
	 *
	 * Counts describe distinct locales, distinct translation keys across all locales, and total locale/key entries. Capability
	 * flags document the lookup and interpolation behavior that consumers can rely on.
	 *
	 * @return array{type:string, locale:string, fallback_locale:string, locales:array<int, string>, catalogue:array<string, array<string, string>>, counts:array{locales:int, keys:int, translations:int}, capabilities:array<string, mixed>, meta:array<string, mixed>} Localization manifest data.
	 */
	public function toArray(): array {
		$keys=[];
		foreach($this->catalogue as $translations){
			foreach(array_keys($translations) as $key){
				$keys[$key]=true;
			}
		}
		return [
			'type'=>'panel_localization',
			'locale'=>$this->locale,
			'fallback_locale'=>$this->fallbackLocale,
			'locales'=>array_keys($this->catalogue),
			'catalogue'=>$this->catalogue,
			'counts'=>[
				'locales'=>count($this->catalogue),
				'keys'=>count($keys),
				'translations'=>array_sum(array_map('count', $this->catalogue)),
			],
			'capabilities'=>[
				'scoped_keys'=>true,
				'fallback_locale'=>true,
				'locale_fallback_base'=>true,
				'parameter_interpolation'=>['colon', 'braces', 'double_braces'],
				'route_agnostic'=>true,
			],
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the localization catalogue for json_encode().
	 *
	 * @return array<string, mixed> Localization manifest data.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Builds the ordered locale fallback chain for a lookup.
	 *
	 * @param ?string $locale Requested locale, or null for the active locale.
	 * @return array<int, string> Unique normalized locale candidates in lookup order.
	 */
	private function candidateLocales(?string $locale=null): array {
		$locales=[];
		foreach([$locale ?? $this->locale, self::baseLocale($locale ?? $this->locale), $this->fallbackLocale, self::baseLocale($this->fallbackLocale)] as $candidate){
			$candidate=self::normalizeLocale($candidate);
			if($candidate!=='' && !in_array($candidate, $locales, true)){
				$locales[]=$candidate;
			}
		}
		return $locales;
	}

	/**
	 * Normalizes locale tags into language or language-region form.
	 *
	 * Separators are normalized to hyphens, the language part is lowercased, and later parts are uppercased. Non-alphanumeric
	 * characters are stripped so values are safe as catalogue keys.
	 *
	 * @param ?string $locale Raw locale tag.
	 * @return string Normalized locale tag, or an empty string for blank input.
	 */
	private static function normalizeLocale(?string $locale): string {
		$locale=trim((string)$locale);
		if($locale===''){
			return '';
		}
		$parts=preg_split('/[-_]/', $locale) ?: [];
		$normalized=[];
		foreach($parts as $index=>$part){
			$part=preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '';
			if($part===''){
				continue;
			}
			$normalized[]=$index===0 ? strtolower($part) : strtoupper($part);
		}
		return implode('-', $normalized);
	}

	/**
	 * Returns the base language for a locale tag.
	 *
	 * @param ?string $locale Raw or normalized locale tag.
	 * @return string Base language portion.
	 */
	private static function baseLocale(?string $locale): string {
		$locale=self::normalizeLocale($locale);
		return str_contains($locale, '-') ? substr($locale, 0, strpos($locale, '-')) : $locale;
	}

	/**
	 * Combines a scope and key into a normalized dotted catalogue key.
	 *
	 * Colons are treated as dot separators to accommodate route-like and Laravel-style translation keys.
	 *
	 * @param string $key Translation key.
	 * @param string $scope Optional scope prefix.
	 * @return string Dotted scoped key, or an empty string when both inputs are blank.
	 */
	private static function scopedKey(string $key, string $scope=''): string {
		$key=trim(str_replace(':', '.', $key), " \t\n\r\0\x0B.");
		$scope=trim(str_replace(':', '.', $scope), " \t\n\r\0\x0B.");
		return $scope!=='' && $key!=='' ? $scope.'.'.$key : ($key!=='' ? $key : $scope);
	}

	/**
	 * Flattens nested translation input into dotted string values.
	 *
	 * @param array<string, mixed> $translations Nested or flat translation map.
	 * @param string $scope Scope already accumulated from parent keys.
	 * @return array<string, string> Sorted flat translation map.
	 */
	private static function flattenTranslations(array $translations, string $scope=''): array {
		$flat=[];
		foreach($translations as $key=>$value){
			$key=self::scopedKey((string)$key, $scope);
			if($key===''){
				continue;
			}
			if(is_array($value)){
				$flat=array_replace($flat, self::flattenTranslations($value, $key));
				continue;
			}
			if(is_scalar($value) || $value instanceof \Stringable || $value===null){
				$flat[$key]=self::stringValue($value);
			}
		}
		ksort($flat);
		return $flat;
	}

	/**
	 * Replaces supported placeholders inside a translation string.
	 *
	 * @param string $message Translation template.
	 * @param array<string|int, mixed> $parameters Placeholder values.
	 * @return string Interpolated message.
	 */
	private static function interpolate(string $message, array $parameters): string {
		if($message==='' || $parameters===[]){
			return $message;
		}
		$replacements=[];
		foreach($parameters as $key=>$value){
			if(!is_string($key) && !is_int($key)){
				continue;
			}
			$key=trim((string)$key);
			if($key===''){
				continue;
			}
			$value=self::stringValue(is_scalar($value) || $value instanceof \Stringable || $value===null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			$replacements[':'.$key]=$value;
			$replacements['{'.$key.'}']=$value;
			$replacements['{{ '.$key.' }}']=$value;
			$replacements['{{'.$key.'}}']=$value;
		}
		return strtr($message, $replacements);
	}

	/**
	 * Converts accepted translation values into storage strings.
	 *
	 * @param string|\Stringable|int|float|bool|null $value Translation or placeholder value.
	 * @return string String representation stored in the catalogue.
	 */
	private static function stringValue(string|\Stringable|int|float|bool|null $value): string {
		if($value===null){
			return '';
		}
		if(is_bool($value)){
			return $value ? 'true' : 'false';
		}
		return (string)$value;
	}
}
