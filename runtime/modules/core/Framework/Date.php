<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Static date entry point for legacy core helpers and immutable date values.
 *
 * `Date` keeps existing Dataphyre date formatting and timezone conversion calls
 * available behind framework-level names while offering `DateValue` factories for
 * code that needs typed, immutable date handling. String-returning methods
 * delegate to the core kernel helpers; value-returning methods normalize
 * timezones before constructing `DateValue` instances.
 */
final class Date {

	/**
	 * Returns the current server date formatted by the core high-precision clock.
	 *
	 * The default format includes microseconds. The timestamp source and server
	 * timezone behavior remain owned by `dataphyre\core::high_precision_server_date()`.
	 *
	 * @param string $format PHP date format passed to the core clock helper.
	 * @return string Current server date formatted with the requested pattern.
	 */
	public static function now(string $format='Y-m-d H:i:s.u'): string {
		return \dataphyre\core::high_precision_server_date($format);
	}

	/**
	 * Returns the current server date as an immutable DateValue.
	 *
	 * The value is first captured in the configured server timezone. When a target
	 * timezone is supplied, the returned `DateValue` is converted into that
	 * timezone while representing the same instant.
	 *
	 * @param ?string $timezone Optional target timezone for the returned value.
	 * @return DateValue Current instant represented as a typed date value.
	 */
	public static function nowValue(?string $timezone=null): DateValue {
		$value=DateValue::fromValue(static::now('Y-m-d H:i:s.u'), static::serverTimezone());
		if($timezone===null){
			return $value;
		}
		return $value->inTimezone($timezone);
	}

	/**
	 * Formats a date string through the legacy core formatter.
	 *
	 * Translation is delegated to the core helper, preserving existing locale and
	 * phrase behavior for month/day names or other translated date fragments.
	 *
	 * @param string $date Date expression accepted by the core formatter.
	 * @param string $format PHP date format for output.
	 * @param bool $translation True when localized date text should be applied by core.
	 * @return string Formatted date string.
	 */
	public static function format(string $date, string $format='n/j/Y g:i A', bool $translation=true): string {
		return \dataphyre\core::format_date($date, $format, $translation);
	}

	/**
	 * Converts a server-side date into a user's timezone and output format.
	 *
	 * This method preserves the legacy core conversion contract for formatted
	 * strings. Use `serverValue()->inTimezone()` when code needs a typed value
	 * instead of a display string.
	 *
	 * @param string|int $date Server date string or timestamp accepted by core.
	 * @param string $userTimezone User timezone identifier.
	 * @param string $format PHP date format for output.
	 * @param bool $translation True when localized date text should be applied by core.
	 * @return string Date formatted for the user's timezone.
	 */
	public static function toUser(string|int $date, string $userTimezone, string $format='n/j/Y g:i A', bool $translation=true): string {
		return \dataphyre\core::convert_to_user_date($date, $userTimezone, $format, $translation);
	}

	/**
	 * Converts a user-supplied date into the server timezone and output format.
	 *
	 * The conversion is delegated to the legacy core helper so form-processing
	 * paths and existing applications keep their historical parsing behavior.
	 *
	 * @param string|int $date User-local date string or timestamp accepted by core.
	 * @param string $userTimezone Timezone the input date should be interpreted in.
	 * @param string $format PHP date format for the server-side output.
	 * @return string Date converted for server-side storage or comparison.
	 */
	public static function toServer(string|int $date, string $userTimezone, string $format='n/j/Y g:i A'): string {
		return \dataphyre\core::convert_to_server_date($date, $userTimezone, $format);
	}

	/**
	 * Returns the normalized server timezone used by Dataphyre date values.
	 *
	 * `base_timezone` from configuration wins. Invalid or missing configuration
	 * falls back to PHP's default timezone, then to UTC.
	 *
	 * @return string Valid PHP timezone identifier for server-side dates.
	 */
	public static function serverTimezone(): string {
		return static::normalizeTimezone(Config::get('base_timezone', date_default_timezone_get() ?: 'UTC'));
	}

	/**
	 * Returns the normalized default user timezone.
	 *
	 * `default_timezone` from configuration wins. When it is absent or invalid,
	 * the server timezone becomes the user-facing fallback.
	 *
	 * @return string Valid PHP timezone identifier for user-facing dates.
	 */
	public static function defaultUserTimezone(): string {
		$default=Config::get('default_timezone', static::serverTimezone());
		return static::normalizeTimezone($default);
	}

	/**
	 * Creates a DateValue from a date expression and optional timezone.
	 *
	 * Null timezone uses the normalized server timezone. The parsing and immutable
	 * representation contract is provided by `DateValue::fromValue()`.
	 *
	 * @param string|int $date Date string or timestamp accepted by `DateValue`.
	 * @param ?string $timezone Timezone for interpreting the input date.
	 * @return DateValue Immutable date value.
	 */
	public static function value(string|int $date, ?string $timezone=null): DateValue {
		return DateValue::fromValue($date, $timezone ?? static::serverTimezone());
	}

	/**
	 * Creates a DateValue interpreted in the configured server timezone.
	 *
	 *
	 * @param string|int $date Server-side date string or timestamp.
	 * @return DateValue Immutable date value in the server timezone.
	 */
	public static function serverValue(string|int $date): DateValue {
		return static::value($date, static::serverTimezone());
	}

	/**
	 * Creates a DateValue interpreted in a user's timezone.
	 *
	 * Invalid or blank user timezone values fall back to the configured default
	 * user timezone before the value is constructed.
	 *
	 * @param string|int $date User-local date string or timestamp.
	 * @param string $userTimezone User timezone identifier to normalize.
	 * @return DateValue Immutable date value in the normalized user timezone.
	 */
	public static function userValue(string|int $date, string $userTimezone): DateValue {
		return static::value($date, static::normalizeUserTimezone($userTimezone));
	}

	/**
	 * Normalizes an arbitrary timezone into a valid PHP timezone identifier.
	 *
	 * Valid input is returned unchanged. Invalid input falls back to PHP's current
	 * default timezone when that value is valid; otherwise UTC is returned.
	 *
	 * @param ?string $timezone Candidate timezone identifier.
	 * @return string Valid PHP timezone identifier.
	 */
	public static function normalizeTimezone(?string $timezone): string {
		$timezone=is_string($timezone) ? trim($timezone) : '';
		if($timezone!=='' && in_array($timezone, timezone_identifiers_list(), true)){
			return $timezone;
		}
		$fallback=date_default_timezone_get();
		if(is_string($fallback) && $fallback!=='' && in_array($fallback, timezone_identifiers_list(), true)){
			return $fallback;
		}
		return 'UTC';
	}

	/**
	 * Normalizes a user timezone with the configured user default as fallback.
	 *
	 * Unlike `normalizeTimezone()`, this method does not fall directly to PHP's
	 * default timezone for invalid user input. It uses `defaultUserTimezone()` so
	 * user-facing date behavior follows application configuration.
	 *
	 * @param ?string $timezone Candidate user timezone identifier.
	 * @return string Valid PHP timezone identifier for user-facing dates.
	 */
	public static function normalizeUserTimezone(?string $timezone): string {
		$timezone=is_string($timezone) ? trim($timezone) : '';
		if($timezone!=='' && in_array($timezone, timezone_identifiers_list(), true)){
			return $timezone;
		}
		return static::defaultUserTimezone();
	}
}
