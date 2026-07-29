<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use InvalidArgumentException;
use JsonSerializable;

/** Small immutable locale map used by public recovery copy. */
final class LocalizedText implements JsonSerializable {
	/** @var array<string,string> */
	private array $values;

	/** @param string|array<string,string> $value */
	public function __construct(string|array $value) {
		$values=is_string($value) ? ['en'=>$value] : $value;
		$normalized=[];
		foreach($values as $locale=>$text){
			$key=self::normalizeLocale((string)$locale);
			$text=trim((string)$text);
			if($key!=='' && $text!==''){
				$normalized[$key]=$text;
			}
		}
		if($normalized===[]){
			throw new InvalidArgumentException('Recovery copy requires at least one non-empty locale value.');
		}
		$this->values=$normalized;
	}

	/** @param string|array<string,string>|self $value */
	public static function from(string|array|self $value): self {
		return $value instanceof self ? $value : new self($value);
	}

	public function forLocale(string $locale, ?string $fallbackLocale='en'): string {
		$locale=self::normalizeLocale($locale);
		$language=strtok($locale, '-') ?: $locale;
		foreach(array_values(array_unique(array_filter([
			$locale,
			$language,
			self::normalizeLocale((string)$fallbackLocale),
			strtok(self::normalizeLocale((string)$fallbackLocale), '-') ?: '',
			'en',
		]))) as $candidate){
			if(isset($this->values[$candidate])){
				return $this->values[$candidate];
			}
		}
		return reset($this->values);
	}

	/** @return array<string,string> */
	public function all(): array {
		return $this->values;
	}

	public function jsonSerialize(): array {
		return $this->values;
	}

	private static function normalizeLocale(string $locale): string {
		$locale=strtolower(str_replace('_', '-', trim($locale)));
		return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $locale)===1 ? $locale : '';
	}
}
