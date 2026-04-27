<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocaleDefinitionMutation implements \JsonSerializable {

	public function __construct(
		private readonly string $type,
		private readonly string $language,
		private readonly string $name,
		private readonly string $string='',
		private readonly ?string $theme=null,
		private readonly ?string $path=null
	){}

	public static function global(string $language, string $name, string $string=''): self {
		return new self('global', $language, $name, $string);
	}

	public static function theme(string $language, string $theme, string $name, string $string=''): self {
		return new self('theme', $language, $name, $string, $theme);
	}

	public static function local(string $language, string $theme, string $path, string $name, string $string=''): self {
		return new self('local', $language, $name, $string, $theme, $path);
	}

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

	public function type(): string { return $this->type; }
	public function language(): string { return $this->language; }
	public function name(): string { return $this->name; }
	public function string(): string { return $this->string; }
	public function theme(): ?string { return $this->theme; }
	public function path(): ?string { return $this->path; }

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
