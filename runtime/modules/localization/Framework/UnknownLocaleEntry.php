<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class UnknownLocaleEntry implements \JsonSerializable {

	public function __construct(
		private readonly string $name,
		private readonly ?string $theme,
		private readonly ?string $path,
		private readonly ?string $scope,
		private readonly ?string $string,
		private readonly ?string $detection_language
	){}

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

	public function name(): string {
		return $this->name;
	}

	public function theme(): ?string {
		return $this->theme;
	}

	public function path(): ?string {
		return $this->path;
	}

	public function scope(): ?string {
		return $this->scope;
	}

	public function string(): ?string {
		return $this->string;
	}

	public function detectionLanguage(): ?string {
		return $this->detection_language;
	}

	public function isGlobal(): bool {
		return $this->scope==='global';
	}

	public function isTheme(): bool {
		return $this->scope==='theme';
	}

	public function isLocal(): bool {
		return $this->scope==='local';
	}

	public function jsonSerialize(): array {
		return [
			'name'=>$this->name,
			'theme'=>$this->theme,
			'path'=>$this->path,
			'scope'=>$this->scope,
			'string'=>$this->string,
			'detection_language'=>$this->detection_language,
		];
	}
}
