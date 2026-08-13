<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Value object returned by editor normalization, sanitization, and validation. */
final class PanelEditorContentResult implements \JsonSerializable {
	/** @param list<string> $errors @param list<string> $warnings */
	public function __construct(
		private string $content,
		private array $errors=[],
		private array $warnings=[],
		private bool $changed=false,
		private array $meta=[],
	) {
		$this->errors=self::messages($errors);
		$this->warnings=self::messages($warnings);
		$this->meta=PanelEditorManifest::sanitize($meta);
	}

	public static function accept(string $content, bool $changed=false, array $warnings=[], array $meta=[]): self {
		return new self($content, [], $warnings, $changed, $meta);
	}

	public static function reject(string $content, array|string $errors, bool $changed=false, array $meta=[]): self {
		return new self($content, is_array($errors) ? $errors : [$errors], [], $changed, $meta);
	}

	public function content(): string { return $this->content; }
	public function errors(): array { return $this->errors; }
	public function warnings(): array { return $this->warnings; }
	public function changed(): bool { return $this->changed; }
	public function valid(): bool { return $this->errors===[]; }
	public function meta(): array { return $this->meta; }

	public function withContent(string $content, bool $changed=false): self {
		return new self($content, $this->errors, $this->warnings, $this->changed || $changed || $content!==$this->content, $this->meta);
	}

	/** @param list<string> $errors */
	public function withErrors(array $errors): self {
		return new self($this->content, array_merge($this->errors, $errors), $this->warnings, $this->changed, $this->meta);
	}

	public function requireValid(string $message='Editor content failed its server-side policy.'): string {
		if(!$this->valid()){
			throw new \DomainException($message.' '.implode(' ', $this->errors));
		}
		return $this->content;
	}

	public function toArray(): array {
		return [
			'content'=>$this->content,
			'valid'=>$this->valid(),
			'changed'=>$this->changed,
			'errors'=>$this->errors,
			'warnings'=>$this->warnings,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @return list<string> */
	private static function messages(array $messages): array {
		$normalized=[];
		foreach($messages as $message){
			$message=trim((string)$message);
			if($message!=='' && !in_array($message, $normalized, true)){
				$normalized[]=$message;
			}
		}
		return $normalized;
	}
}
