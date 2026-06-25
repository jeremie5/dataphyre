<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Immutable description of one locale definition write.
 *
 * A mutation identifies the localization scope being changed, the language,
 * translation key, replacement string, and optional theme/path context. Locale
 * definition batches use these values as declarative write instructions before
 * applying them to global, theme, or local translation stores.
 */
final class LocaleDefinitionMutation implements \JsonSerializable {

	/**
	 * Captures a locale definition write target and replacement value.
	 *
	 * The constructor stores values as provided so importers can preserve source
	 * data exactly. Prefer named factories when the target scope is known.
	 *
	 * @param string $type Mutation scope, usually global, theme, or local.
	 * @param string $language Language code being updated.
	 * @param string $name Translation key or definition name.
	 * @param string $string Replacement translation string.
	 * @param ?string $theme Theme identifier for theme and local definitions.
	 * @param ?string $path Local definition path for path-scoped writes.
	 */
	public function __construct(
		private readonly string $type,
		private readonly string $language,
		private readonly string $name,
		private readonly string $string='',
		private readonly ?string $theme=null,
		private readonly ?string $path=null
	){}

	/**
	 * Creates a global locale definition mutation.
	 *
	 * Global mutations are language-wide and do not carry theme or path context.
	 *
	 * @param string $language Language code being updated.
	 * @param string $name Translation key or definition name.
	 * @param string $string Replacement translation string.
	 * @return self Global locale definition mutation.
	 */
	public static function global(string $language, string $name, string $string=''): self {
		return new self('global', $language, $name, $string);
	}

	/**
	 * Creates a theme-scoped locale definition mutation.
	 *
	 * Theme mutations affect a translation key within a named theme for the
	 * selected language.
	 *
	 * @param string $language Language code being updated.
	 * @param string $theme Theme identifier being updated.
	 * @param string $name Translation key or definition name.
	 * @param string $string Replacement translation string.
	 * @return self Theme-scoped locale definition mutation.
	 */
	public static function forTheme(string $language, string $theme, string $name, string $string=''): self {
		return new self('theme', $language, $name, $string, $theme);
	}

	/**
	 * Creates a local path-scoped locale definition mutation.
	 *
	 * Local mutations target a translation key in a theme-specific local
	 * definition path for the selected language.
	 *
	 * @param string $language Language code being updated.
	 * @param string $theme Theme identifier being updated.
	 * @param string $path Local definition path.
	 * @param string $name Translation key or definition name.
	 * @param string $string Replacement translation string.
	 * @return self Local path-scoped locale definition mutation.
	 */
	public static function local(string $language, string $theme, string $path, string $name, string $string=''): self {
		return new self('local', $language, $name, $string, $theme, $path);
	}

	/**
	 * Hydrates a locale definition mutation from serialized data.
	 *
	 * Accepts `language` or `lang` for the language code, and `string` or
	 * `value` for the replacement text. Missing optional theme/path fields remain
	 * null so scope-specific validators can decide whether the payload is valid.
	 *
	 * @param array<string, mixed> $data Serialized mutation payload.
	 * @return self Locale definition mutation built from the payload.
	 */
	public static function fromArray(array $data): self {
		return new self(
			(string)($data['type'] ?? ''),
			(string)($data['language'] ?? $data['lang'] ?? ''),
			(string)($data['name'] ?? ''),
			(string)($data['string'] ?? $data['value'] ?? ''),
			isset($data['theme']) ? (string)$data['theme'] : null,
			isset($data['path']) ? (string)$data['path'] : null
		);
	}

	/**
	 * Returns the mutation scope.
	 *
	 * @return string Scope identifier such as global, theme, or local.
	 */
	public function type(): string { return $this->type; }
	/**
	 * Returns the language code targeted by the mutation.
	 *
	 * @return string Language code being updated.
	 */
	public function language(): string { return $this->language; }
	/**
	 * Returns the translation key or definition name.
	 *
	 * @return string Locale definition key.
	 */
	public function name(): string { return $this->name; }
	/**
	 * Returns the replacement translation string.
	 *
	 * @return string Translation value to write.
	 */
	public function string(): string { return $this->string; }
	/**
	 * Returns the theme context for scoped mutations.
	 *
	 * @return ?string Theme identifier, or null for global mutations.
	 */
	public function theme(): ?string { return $this->theme; }
	/**
	 * Returns the local definition path for local mutations.
	 *
	 * @return ?string Local definition path, or null when not path-scoped.
	 */
	public function path(): ?string { return $this->path; }

	/**
	 * Serializes the mutation for batch processing and diagnostics.
	 *
	 * @return array{type: string, language: string, name: string, string: string, theme: ?string, path: ?string} Locale definition mutation payload.
	 */
	public function jsonSerialize(): array {
		return [
			'type'=>$this->type,
			'language'=>$this->language,
			'name'=>$this->name,
			'string'=>$this->string,
			'theme'=>$this->theme,
			'path'=>$this->path,
		];
	}
}
