<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Csrf {

	public static function token(string $form_name): CsrfToken {
		return CsrfToken::for($form_name);
	}

	public static function value(string $form_name): string {
		return static::token($form_name)->value();
	}

	public static function validate(string $form_name, #[\SensitiveParameter] mixed $token): bool {
		return static::token($form_name)->validate($token);
	}

	public static function hiddenField(string $form_name, string $field_name='csrf'): string {
		return static::token($form_name)->hiddenField($field_name);
	}
}
