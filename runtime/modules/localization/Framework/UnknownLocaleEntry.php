<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Diagnostic record for a localization entry that is not defined.
 *
 * Unknown locale entries describe missing strings discovered during localization
 * scans or runtime detection. They preserve the missing key, lookup scope, theme,
 * source path, original string when available, and detection language so tooling
 * can report what needs to be translated.
 */
final class UnknownLocaleEntry implements \JsonSerializable {

	/**
	 * Creates an immutable missing-localization diagnostic entry.
	 *
	 * Scope is usually one of `global`, `theme`, or `local`, but the object keeps
	 * the original value so scanners can surface malformed input instead of
	 * silently discarding it.
	 *
	 * @param string $name Missing locale key or normalized entry name.
	 * @param ?string $theme Theme involved in the missing lookup.
	 * @param ?string $path Source file or route path where the string was detected.
	 * @param ?string $scope Localization scope recorded by the scanner.
	 * @param ?string $string Source string that triggered detection.
	 * @param ?string $detectionLanguage Language detected from the source string.
	 */
	public function __construct(
		private readonly string $name,
		private readonly ?string $theme,
		private readonly ?string $path,
		private readonly ?string $scope,
		private readonly ?string $string,
		private readonly ?string $detectionLanguage
	){}

	/**
	 * Creates a diagnostic entry from scanner output.
	 *
	 * The entry name is trimmed and uppercased to match the locale-key convention
	 * used by the unknown-string registry. Optional array values are coerced to
	 * strings when present.
	 *
	 * @param string $name Missing locale key.
	 * @param array<string,mixed> $data Scanner payload containing theme, path, scope, string, and detection_lang fields.
	 * @return self Normalized diagnostic entry.
	 */
	public static function fromArray(string $name, array $data): self {
		return new self(
			strtoupper(trim($name)),
			isset($data['theme']) ? (string)$data['theme'] : null,
			isset($data['path']) ? (string)$data['path'] : null,
			isset($data['scope']) ? (string)$data['scope'] : null,
			isset($data['string']) ? (string)$data['string'] : null,
			isset($data['detection_lang']) ? (string)$data['detection_lang'] : null
		);
	}

	/**
	 * Returns the missing locale entry name.
	 *
	 *
	 * @return string Uppercase missing locale key.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the theme involved in the missing lookup.
	 *
	 *
	 * @return ?string Theme identifier, or null for non-theme lookups.
	 */
	public function theme(): ?string {
		return $this->theme;
	}

	/**
	 * Returns the source path associated with the missing entry.
	 *
	 *
	 * @return ?string Source file, route, or scanner path.
	 */
	public function path(): ?string {
		return $this->path;
	}

	/**
	 * Returns the localization scope recorded for the missing entry.
	 *
	 *
	 * @return ?string Scope such as `global`, `theme`, `local`, or null.
	 */
	public function scope(): ?string {
		return $this->scope;
	}

	/**
	 * Returns the source string that was detected as missing.
	 *
	 *
	 * @return ?string Source string, or null when only a key was recorded.
	 */
	public function string(): ?string {
		return $this->string;
	}

	/**
	 * Returns the language detected from the source string.
	 *
	 *
	 * @return ?string Detected language code, or null when detection was unavailable.
	 */
	public function detectionLanguage(): ?string {
		return $this->detectionLanguage;
	}

	/**
	 * Reports whether the missing entry belongs to the global scope.
	 *
	 *
	 * @return bool True when scope is exactly `global`.
	 */
	public function isGlobal(): bool {
		return $this->scope==='global';
	}

	/**
	 * Reports whether the missing entry belongs to the theme scope.
	 *
	 *
	 * @return bool True when scope is exactly `theme`.
	 */
	public function isTheme(): bool {
		return $this->scope==='theme';
	}

	/**
	 * Reports whether the missing entry belongs to the page-local scope.
	 *
	 *
	 * @return bool True when scope is exactly `local`.
	 */
	public function isLocal(): bool {
		return $this->scope==='local';
	}

	/**
	 * Serializes the missing-localization diagnostic payload.
	 *
	 * @return array{name:string,theme:?string,path:?string,scope:?string,string:?string,detection_language:?string} JSON-ready missing-locale entry.
	 */
	public function jsonSerialize(): array {
		return [
			'name'=>$this->name,
			'theme'=>$this->theme,
			'path'=>$this->path,
			'scope'=>$this->scope,
			'string'=>$this->string,
			'detection_language'=>$this->detectionLanguage,
		];
	}
}
