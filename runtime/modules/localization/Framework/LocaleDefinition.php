<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocaleDefinition implements \JsonSerializable {

	public function __construct(
		private readonly ?int $id,
		private readonly string $language,
		private readonly ?string $theme,
		private readonly ?string $path,
		private readonly string $type,
		private readonly string $name,
		private readonly string $string,
		private readonly ?string $edit_time
	){}

	public static function fromArray(array $data): self {
		return new self(
			isset($data['id']) ? (int)$data['id'] : null,
			(string)($data['lang'] ?? ''),
			isset($data['theme']) && $data['theme']!=='' ? (string)$data['theme'] : null,
			isset($data['path']) && $data['path']!=='' ? (string)$data['path'] : null,
			(string)($data['type'] ?? ''),
			(string)($data['name'] ?? ''),
			(string)($data['string'] ?? ''),
			isset($data['edit_time']) && $data['edit_time']!=='' ? (string)$data['edit_time'] : null
		);
	}

	public function id(): ?int { return $this->id; }
	public function language(): string { return $this->language; }
	public function theme(): ?string { return $this->theme; }
	public function path(): ?string { return $this->path; }
	public function type(): string { return $this->type; }
	public function name(): string { return $this->name; }
	public function string(): string { return $this->string; }
	public function editTime(): ?string { return $this->edit_time; }
	public function isGlobal(): bool { return $this->type==='global'; }
	public function isTheme(): bool { return $this->type==='theme'; }
	public function isLocal(): bool { return $this->type==='local'; }

	public function jsonSerialize(): array {
		return [
			'id'=>$this->id,
			'lang'=>$this->language,
			'theme'=>$this->theme,
			'path'=>$this->path,
			'type'=>$this->type,
			'name'=>$this->name,
			'string'=>$this->string,
			'edit_time'=>$this->edit_time,
		];
	}
}
