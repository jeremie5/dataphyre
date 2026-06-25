<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Normalizes operator-provided reactor names into stable runtime identifiers.
 *
 * Reactor names are used as configuration keys, channel names, and diagnostic
 * labels, so the normalizer keeps lowercase letters, digits, underscores, dots,
 * colons, and dashes while collapsing other runs into underscores.
 */
final class ReactorName {

	private const CACHE_LIMIT=128;

	/** @var array<string, string> */
	private static array $cache=[];

	/**
	 * Converts an arbitrary name into the canonical reactor identifier form.
	 *
	 * The method is pure and returns an empty string when trimming and character
	 * filtering remove the entire input.
	 *
	 * @param string $name Raw reactor name from configuration or API input.
	 * @return string Canonical lowercase identifier.
	 */
	public static function normalize(string $name): string {
		if(isset(self::$cache[$name])){
			return self::$cache[$name];
		}
		$input=$name;
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.:-]+/', '_', $name) ?? '';
		$normalized=trim($name, '_');
		if(count(self::$cache)>=self::CACHE_LIMIT){
			self::$cache=[];
		}
		self::$cache[$input]=$normalized;
		return $normalized;
	}
}
