<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Explicit server-side contract for markup that is safe to place in Panel HTML.
 *
 * Ordinary strings never acquire this contract through renderer metadata. Use
 * sanitize() for user-authored rich text. trusted() and fromTrusted() are an
 * intentionally sharp boundary reserved for markup generated entirely by
 * trusted application or framework code.
 *
 * JSON serialization deliberately emits only the underlying string. Trust does
 * not survive a transport or persistence round trip; receiving code must
 * sanitize the string or explicitly establish a new trusted boundary.
 */
final class PanelSafeHtml implements \Stringable, \JsonSerializable {
	private const SOURCE_SANITIZED='sanitized';
	private const SOURCE_TRUSTED='trusted';

	private function __construct(
		private readonly string $html,
		private readonly string $source,
	){}

	/**
	 * Marks framework-generated markup as trusted without rewriting it.
	 *
	 * Never pass request, database, translation, or other externally controlled
	 * text to this method unless every interpolated value has already been escaped.
	 */
	public static function trusted(string $html): self {
		if(preg_match('//u', $html)!==1){
			throw new \InvalidArgumentException('Trusted Panel HTML must be valid UTF-8.');
		}
		return new self($html, self::SOURCE_TRUSTED);
	}

	/** Alias whose name reads naturally at an integration boundary. */
	public static function fromTrusted(string $html): self {
		return self::trusted($html);
	}

	/**
	 * Sanitizes untrusted rich text through Panel's strict DOM allow list.
	 *
	 * Unsafe elements, attributes, and URL schemes are stripped. If DOM parsing
	 * is unavailable, input is invalid UTF-8, or the sanitizer rejects the input,
	 * the entire value is escaped so this factory remains fail closed.
	 */
	public static function sanitize(string $html, ?PanelEditorSanitizationPolicy $policy=null): self {
		$safe=self::escaped($html);
		if(preg_match('//u', $html)===1){
			$policy=($policy ?? PanelEditorSanitizationPolicy::strict())->stripUnsafe();
			$result=(new PanelEditorHtmlSanitizer())->sanitize(
				$html,
				$policy,
				PanelEditorContext::make('', 'html', 'html', 'render'),
			);
			if($result->valid()){
				$safe=$result->content();
			}
		}
		return new self($safe, self::SOURCE_SANITIZED);
	}

	/** Returns the safe markup for an HTML response boundary. */
	public function html(): string {
		return $this->html;
	}

	/** Returns whether the value bypassed sanitization at an explicit trust call. */
	public function isTrusted(): bool {
		return $this->source===self::SOURCE_TRUSTED;
	}

	/** Returns `trusted` or `sanitized` for diagnostics and contract tests. */
	public function source(): string {
		return $this->source;
	}

	public function __toString(): string {
		return $this->html;
	}

	public function jsonSerialize(): string {
		return $this->html;
	}

	private static function escaped(string $html): string {
		return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}
