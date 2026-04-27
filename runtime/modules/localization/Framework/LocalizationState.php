<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocalizationState {

	public function __construct(
		private readonly ?string $default_language,
		private readonly ?string $user_language,
		private readonly ?string $user_theme,
		private readonly ?array $available_languages,
		private readonly ?array $available_themes,
		private readonly array $custom_parameters
	){}

	public static function fromArray(array $state): self {
		return new self(
			isset($state['default_language']) ? (string)$state['default_language'] : null,
			isset($state['user_language']) ? (string)$state['user_language'] : null,
			isset($state['user_theme']) ? (string)$state['user_theme'] : null,
			is_array($state['available_languages'] ?? null) ? $state['available_languages'] : null,
			is_array($state['available_themes'] ?? null) ? $state['available_themes'] : null,
			is_array($state['custom_parameters'] ?? null) ? $state['custom_parameters'] : []
		);
	}

	public function defaultLanguage(): ?string {
		return $this->default_language;
	}

	public function userLanguage(): ?string {
		return $this->user_language;
	}

	public function userTheme(): ?string {
		return $this->user_theme;
	}

	public function availableLanguages(): ?array {
		return $this->available_languages;
	}

	public function availableThemes(): ?array {
		return $this->available_themes;
	}

	public function customParameters(): array {
		return $this->custom_parameters;
	}
}
