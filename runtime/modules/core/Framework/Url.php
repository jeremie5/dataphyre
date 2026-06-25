<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Static entry point for Dataphyre's request-derived URL helpers.
 *
 * The methods delegate to the core runtime so callers can work with the current
 * request URL, the base application URL, query-string mutation, and `UrlValue`
 * wrappers without depending directly on the legacy core class names.
 */
final class Url {

	/**
	 * Returns the current request's base URL without the full query/path expansion.
	*
	 * @return string Base URL as resolved by the Dataphyre core runtime.
	 */
	public static function base(): string {
		return \dataphyre\core::url_self(false);
	}

	/**
	 * Returns the base URL wrapped as a chainable `UrlValue`.
	*
	 * @return UrlValue Value object containing `base()`.
	 */
	public static function baseValue(): UrlValue {
		return new UrlValue(static::base());
	}

	/**
	 * Returns the current request URL from Dataphyre runtime state.
	 *
	 * @param bool $full Whether to include the full request URI/query variant.
	 * @return string Current URL as resolved by `core::url_self()`.
	 */
	public static function current(bool $full=false): string {
		return \dataphyre\core::url_self($full);
	}

	/**
	 * Returns the current request URL wrapped as a chainable `UrlValue`.
	*
	 * @param bool $full Whether to include the full request URI/query variant.
	 * @return UrlValue Value object containing `current($full)`.
	 */
	public static function currentValue(bool $full=false): UrlValue {
		return new UrlValue(static::current($full));
	}

	/**
	 * Returns the full current request URL.
	*
	 * @return string Full URL as resolved by the Dataphyre core runtime.
	 */
	public static function full(): string {
		return \dataphyre\core::url_self(true);
	}

	/**
	 * Returns the full current request URL wrapped as a chainable `UrlValue`.
	*
	 * @return UrlValue Value object containing `full()`.
	 */
	public static function fullValue(): UrlValue {
		return new UrlValue(static::full());
	}

	/**
	 * Applies query-string additions and removals to an arbitrary URL.
	*
	 * The mutation rules are delegated to `core::url_updated_querystring()`,
	 * preserving Dataphyre's existing conventions for null values, explicit removal
	 * lists, and boolean removal flags.
	 *
	 * @param string $url URL to mutate.
	 * @param array<string, mixed>|null $value Query values to add or replace.
	 * @param array<int|string, mixed>|null|bool $remove Query keys or removal mode.
	 * @return string URL with updated query string.
	 */
	public static function withQuery(string $url, array|null $value=null, array|null|bool $remove=false): string {
		return \dataphyre\core::url_updated_querystring($url, $value, $remove);
	}

	/**
	 * Applies query-string additions and removals to the current request URL.
	*
	 * @param array<string, mixed>|null $value Query values to add or replace.
	 * @param array<int|string, mixed>|null|bool $remove Query keys or removal mode.
	 * @return string Current URL with updated query string.
	 */
	public static function currentWithQuery(array|null $value=null, array|null|bool $remove=false): string {
		return \dataphyre\core::url_self_updated_querystring($value, $remove);
	}

	/**
	 * Wraps an explicit URL string in a `UrlValue`.
	*
	 * @param string $url URL string to wrap.
	 * @return UrlValue Chainable URL value object.
	 */
	public static function value(string $url): UrlValue {
		return new UrlValue($url);
	}
}
