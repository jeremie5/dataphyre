<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocalizationContext {

	public function __construct(
		private readonly LocalizationManager $manager,
		private readonly ?string $language=null,
		private readonly ?string $theme=null,
		private readonly ?string $page=null
	){}

	public function language(?string $language): self {
		return new self($this->manager, $language, $this->theme, $this->page);
	}

	public function theme(?string $theme): self {
		return new self($this->manager, $this->language, $theme, $this->page);
	}

	public function page(?string $page): self {
		return new self($this->manager, $this->language, $this->theme, $page);
	}

	public function translate(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->manager->translate($key, $fallback, $parameters, $this->language, $this->page, $this->theme);
	}

	public function has(string $key): bool {
		return $this->manager->has($key, $this->language, $this->page, $this->theme);
	}

	public function missing(string $key): bool {
		return $this->manager->missing($key, $this->language, $this->page, $this->theme);
	}

	public function translateOrNull(string $key, ?array $parameters=null): ?string {
		return $this->manager->translateOrNull($key, $parameters, $this->language, $this->page, $this->theme);
	}

	public function globalString(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->translate('global:'.$key, $fallback, $parameters);
	}

	public function themeString(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->translate('theme:'.$key, $fallback, $parameters);
	}

	public function local(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->translate('local:'.$key, $fallback, $parameters);
	}

	public function choice(
		int|float $count,
		string $one_key,
		string $many_key,
		?string $zero_key=null,
		?array $parameters=null
	): string {
		return $this->manager->choice($count, $one_key, $many_key, $zero_key, $parameters, $this->language, $this->page, $this->theme);
	}
}
