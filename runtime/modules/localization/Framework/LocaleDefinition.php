<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Immutable localization string definition.
 *
 * LocaleDefinition represents one translated string record as loaded from the
 * localization store. The scope is determined by type plus optional theme/path:
 * global strings apply runtime-wide, theme strings apply to a theme, and local
 * strings apply to a specific path. The object is side-effect free and preserves
 * the storage column shape used by JSON serialization.
 */
final class LocaleDefinition implements \JsonSerializable {

	/**
	 * Creates a localization definition value.
	 *
	 * @param int|null $id Storage identifier when the string exists in persistence.
	 * @param string $language Language code for this string.
	 * @param string|null $theme Theme scope for theme strings.
	 * @param string|null $path Path scope for local strings.
	 * @param string $type Scope type: global, theme, local, or another store-defined value.
	 * @param string $name Translation key/name.
	 * @param string $string Translated string value.
	 * @param string|null $editTime Last edit timestamp from storage.
	 * @param string|null $sourceBranch Source branch associated with file-backed edits.
	 * @param string|null $sourceCommit Source commit associated with file-backed edits.
	 */
	public function __construct(
		private readonly ?int $id,
		private readonly string $language,
		private readonly ?string $theme,
		private readonly ?string $path,
		private readonly string $type,
		private readonly string $name,
		private readonly string $string,
		private readonly ?string $editTime,
		private readonly ?string $sourceBranch=null,
		private readonly ?string $sourceCommit=null
	){}

	/**
	 * Rehydrates a locale definition from a storage row.
	 *
	 * The accepted keys mirror the legacy localization table: id, lang, theme,
	 * path, type, name, string, and edit_time. Empty theme/path/edit_time values are
	 * normalized to null so scope checks are predictable.
	 *
	 * @param array<string, mixed> $data Localization row or serialized payload.
	 * @return self Locale definition value.
	 */
	public static function fromArray(array $data): self {
		return new self(
			isset($data['id']) ? (int)$data['id'] : null,
			(string)($data['lang'] ?? ''),
			isset($data['theme']) && $data['theme']!=='' ? (string)$data['theme'] : null,
			isset($data['path']) && $data['path']!=='' ? (string)$data['path'] : null,
			(string)($data['type'] ?? ''),
			(string)($data['name'] ?? ''),
			(string)($data['string'] ?? ''),
			isset($data['edit_time']) && $data['edit_time']!=='' ? (string)$data['edit_time'] : null,
			isset($data['source_branch']) && $data['source_branch']!=='' ? (string)$data['source_branch'] : null,
			isset($data['source_commit']) && $data['source_commit']!=='' ? (string)$data['source_commit'] : null
		);
	}

	/**
	 * Returns the storage identifier.
	 *
	 * @return int|null Persisted row id, or null for unsaved definitions.
	 */
	public function id(): ?int { return $this->id; }
	/**
	 * Returns the language code.
	 *
	 * @return string Locale language code.
	 */
	public function language(): string { return $this->language; }
	/**
	 * Returns the theme scope.
	 *
	 * @return string|null Theme name for theme-scoped strings.
	 */
	public function theme(): ?string { return $this->theme; }
	/**
	 * Returns the path scope.
	 *
	 * @return string|null Path for local strings.
	 */
	public function path(): ?string { return $this->path; }
	/**
	 * Returns the localization scope type.
	 *
	 * @return string Scope type stored with the definition.
	 */
	public function type(): string { return $this->type; }
	/**
	 * Returns the translation key.
	 *
	 * @return string String name/key within its scope.
	 */
	public function name(): string { return $this->name; }
	/**
	 * Returns the translated string value.
	 *
	 * @return string Localized text.
	 */
	public function string(): string { return $this->string; }
	/**
	 * Returns the storage edit timestamp.
	 *
	 * @return string|null Last edit timestamp, or null when unavailable.
	 */
	public function editTime(): ?string { return $this->editTime; }
	/**
	 * Returns the source branch associated with a file-backed definition.
	 *
	 * @return string|null Source branch, or null when unavailable.
	 */
	public function sourceBranch(): ?string { return $this->sourceBranch; }
	/**
	 * Returns the source commit associated with a file-backed definition.
	 *
	 * @return string|null Source commit, or null when unavailable.
	 */
	public function sourceCommit(): ?string { return $this->sourceCommit; }
	/**
	 * Reports whether this string is globally scoped.
	 *
	 * @return bool True when type is global.
	 */
	public function isGlobal(): bool { return $this->type==='global'; }
	/**
	 * Reports whether this string is theme scoped.
	 *
	 * @return bool True when type is theme.
	 */
	public function isTheme(): bool { return $this->type==='theme'; }
	/**
	 * Reports whether this string is path scoped.
	 *
	 * @return bool True when type is local.
	 */
	public function isLocal(): bool { return $this->type==='local'; }

	/**
	 * Serializes the definition using localization storage keys.
	 *
	 * @return array<string, mixed> id, lang, theme, path, type, name, string, and edit_time.
	 */
	public function jsonSerialize(): array {
		if($this->sourceBranch!==null && $this->sourceCommit!==null){
			return [
				'id'=>$this->id,
				'lang'=>$this->language,
				'theme'=>$this->theme,
				'path'=>$this->path,
				'type'=>$this->type,
				'name'=>$this->name,
				'string'=>$this->string,
				'edit_time'=>$this->editTime,
				'source_branch'=>$this->sourceBranch,
				'source_commit'=>$this->sourceCommit,
			];
		}
		$data=[
			'id'=>$this->id,
			'lang'=>$this->language,
			'theme'=>$this->theme,
			'path'=>$this->path,
			'type'=>$this->type,
			'name'=>$this->name,
			'string'=>$this->string,
			'edit_time'=>$this->editTime,
		];
		if($this->sourceBranch!==null){
			$data['source_branch']=$this->sourceBranch;
		}
		if($this->sourceCommit!==null){
			$data['source_commit']=$this->sourceCommit;
		}
		return $data;
	}
}
