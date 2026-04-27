<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Url {

	public static function base(): string {
		return \dataphyre\core::url_self(false);
	}

	public static function baseValue(): UrlValue {
		return new UrlValue(static::base());
	}

	public static function current(bool $full=false): string {
		return \dataphyre\core::url_self($full);
	}

	public static function currentValue(bool $full=false): UrlValue {
		return new UrlValue(static::current($full));
	}

	public static function full(): string {
		return \dataphyre\core::url_self(true);
	}

	public static function fullValue(): UrlValue {
		return new UrlValue(static::full());
	}

	public static function withQuery(string $url, array|null $value=null, array|null|bool $remove=false): string {
		return \dataphyre\core::url_updated_querystring($url, $value, $remove);
	}

	public static function currentWithQuery(array|null $value=null, array|null|bool $remove=false): string {
		return \dataphyre\core::url_self_updated_querystring($value, $remove);
	}

	public static function value(string $url): UrlValue {
		return new UrlValue($url);
	}
}
