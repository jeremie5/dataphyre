<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable runtime context passed to editor policies and adapters. */
final class PanelEditorContext implements \JsonSerializable {
	private function __construct(
		private string $field,
		private string $mode,
		private string $language,
		private string $stage,
		private mixed $record,
		private ?PanelRequest $request,
		private array $values,
	) {}

	public static function make(string $field='', string $mode='plain', string $language='plain', string $stage='validate', mixed $record=null, ?PanelRequest $request=null, array $values=[]): self {
		return new self(
			Resource::normalizeName($field),
			self::normalizeMode($mode),
			self::normalizeLanguage($language),
			PanelEditorManifest::name($stage, 'validate'),
			$record,
			$request,
			$values,
		);
	}

	public function field(): string { return $this->field; }
	public function mode(): string { return $this->mode; }
	public function language(): string { return $this->language; }
	public function stage(): string { return $this->stage; }
	public function record(): mixed { return $this->record; }
	public function request(): ?PanelRequest { return $this->request; }
	public function values(): array { return $this->values; }
	public function withStage(string $stage): self { return self::make($this->field, $this->mode, $this->language, $stage, $this->record, $this->request, $this->values); }

	public function toArray(): array {
		return [
			'field'=>$this->field,
			'mode'=>$this->mode,
			'language'=>$this->language,
			'stage'=>$this->stage,
			'operation'=>$this->request?->operation(),
			'has_record'=>$this->record!==null,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	public static function normalizeMode(string $mode): string {
		$mode=Resource::normalizeName($mode);
		return match($mode){
			'rich', 'rich_editor', 'rich_text', 'wysiwyg'=>'rich_text',
			'htm'=>'html',
			'md'=>'markdown',
			'source'=>'code',
			default=>in_array($mode, ['plain','markdown','html','rich_text','code'], true) ? $mode : 'plain',
		};
	}

	public static function normalizeLanguage(string $language): string {
		$language=strtolower(trim($language));
		$language=preg_replace('/[^a-z0-9_+#.-]+/', '_', $language) ?? '';
		return trim($language, '_.-') ?: 'plain';
	}
}
