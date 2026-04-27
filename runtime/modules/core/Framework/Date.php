<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Date {

	public static function now(string $format='Y-m-d H:i:s.u'): string {
		return \dataphyre\core::high_precision_server_date($format);
	}

	public static function nowValue(?string $timezone=null): DateValue {
		$value=DateValue::fromValue(static::now('Y-m-d H:i:s.u'), static::serverTimezone());
		if($timezone===null){
			return $value;
		}
		return $value->inTimezone($timezone);
	}

	public static function format(string $date, string $format='n/j/Y g:i A', bool $translation=true): string {
		return \dataphyre\core::format_date($date, $format, $translation);
	}

	public static function toUser(string|int $date, string $user_timezone, string $format='n/j/Y g:i A', bool $translation=true): string {
		return \dataphyre\core::convert_to_user_date($date, $user_timezone, $format, $translation);
	}

	public static function toServer(string|int $date, string $user_timezone, string $format='n/j/Y g:i A'): string {
		return \dataphyre\core::convert_to_server_date($date, $user_timezone, $format);
	}

	public static function serverTimezone(): string {
		return static::normalizeTimezone(Config::get('base_timezone', date_default_timezone_get() ?: 'UTC'));
	}

	public static function defaultUserTimezone(): string {
		$default=Config::get('default_timezone', static::serverTimezone());
		return static::normalizeTimezone($default);
	}

	public static function value(string|int $date, ?string $timezone=null): DateValue {
		return DateValue::fromValue($date, $timezone ?? static::serverTimezone());
	}

	public static function serverValue(string|int $date): DateValue {
		return static::value($date, static::serverTimezone());
	}

	public static function userValue(string|int $date, string $user_timezone): DateValue {
		return static::value($date, static::normalizeUserTimezone($user_timezone));
	}

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

	public static function normalizeUserTimezone(?string $timezone): string {
		$timezone=is_string($timezone) ? trim($timezone) : '';
		if($timezone!=='' && in_array($timezone, timezone_identifiers_list(), true)){
			return $timezone;
		}
		return static::defaultUserTimezone();
	}
}
