<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Static entry point for form-scoped CSRF token creation, rendering, and validation.
 *
 * This class keeps common application code away from the lower-level `CsrfToken` value object
 * while preserving the same form-name namespace. Tokens are scoped by `$formName`, so callers
 * should use stable, purpose-specific names for each protected form or action.
 */
final class Csrf {

	/**
	 * Creates or retrieves the CSRF token object for a form namespace.
	 *
	 * @param string $formName Stable form/action namespace used to isolate token state.
	 * @return CsrfToken Token value object for rendering and validation.
	 */
	public static function token(string $formName): CsrfToken {
		return CsrfToken::for($formName);
	}

	/**
	 * Returns the raw token value for custom markup or request bodies.
	 *
	 * @param string $formName Stable form/action namespace used to isolate token state.
	 * @return string Token value expected back on submission.
	 */
	public static function value(string $formName): string {
		return static::token($formName)->value();
	}

	/**
	 * Validates a submitted token against the form namespace.
	 *
	 * The submitted value is marked sensitive so traces and error handlers do not expose CSRF
	 * material. Validation delegates to `CsrfToken` so storage, rotation, and comparison rules
	 * remain centralized.
	 *
	 * @param string $formName Stable form/action namespace used to isolate token state.
	 * @param mixed $token Submitted token value from a request body, header, or custom transport.
	 * @return bool Whether the submitted value is valid for the form namespace.
	 */
	public static function validate(string $formName, #[\SensitiveParameter] mixed $token): bool {
		return static::token($formName)->validate($token);
	}

	/**
	 * Renders a hidden input containing the scoped CSRF token.
	 *
	 * @param string $formName Stable form/action namespace used to isolate token state.
	 * @param string $fieldName Hidden input name expected by the receiving endpoint.
	 * @return string HTML hidden input with an escaped token value.
	 */
	public static function hiddenField(string $formName, string $fieldName='csrf'): string {
		return static::token($formName)->hiddenField($fieldName);
	}
}
