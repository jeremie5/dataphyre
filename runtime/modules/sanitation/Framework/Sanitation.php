<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

/**
 * Static entry point for Dataphyre sanitation services.
 *
 * Sanitation exposes the process-local SanitationManager singleton through a
 * compact framework-facing API. It preserves the manager's schema, preset, and
 * typed-cleaner behavior while giving application code a single import point.
 */
final class Sanitation {

	/**
	 * Returns the shared sanitation manager instance.
	 *
	 * @return SanitationManager Process-local sanitation manager.
	 */
	public static function manager(): SanitationManager {
		return SanitationManager::instance();
	}

	/**
	 * Resets the shared sanitation manager and registered presets.
	 */
	public static function flush(): void {
		SanitationManager::flush();
	}

	/**
	 * Masks an email address through the legacy kernel sanitation helper.
	 *
	 * @param string $email Email address to anonymize.
	 * @param int $count Number of visible characters retained by the kernel helper.
	 * @param string $char Masking character.
	 * @return string Anonymized email address.
	 */
	public static function anonymizeEmail(string $email, int $count=2, string $char='*'): string {
		return \dataphyre\sanitation::anonymize_email($email, $count, $char);
	}

	/**
	 * Sanitizes one value with a compact rule or expanded rule config.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param string|array<string,mixed> $rule Sanitation type, pipe rule, or expanded rule config.
	 * @param array<string,mixed> $options Runtime options such as field, labels, messages, or context.
	 * @return mixed Sanitized and cast value, false on validation failure, or null when omitted or nullable.
	 */
	public static function sanitize(mixed $value, string|array $rule='default', array $options=[]): mixed {
		return self::manager()->sanitize($value, $rule, $options);
	}

	/**
	 * Alias for sanitize() for call sites that read in cleaner vocabulary.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param string|array<string,mixed> $rule Sanitation type, pipe rule, or expanded rule config.
	 * @param array<string,mixed> $options Runtime options.
	 * @return mixed Sanitized and cast value from sanitize(), false on validation failure, or null when omitted or nullable.
	 */
	public static function clean(mixed $value, string|array $rule='default', array $options=[]): mixed {
		return self::sanitize($value, $rule, $options);
	}

	/**
	 * Starts a fluent sanitizer for one value.
	 *
	 * @param mixed $value Value captured by the fluent sanitizer.
	 * @return Sanitizer Fluent sanitizer bound to the shared manager.
	 */
	public static function string(mixed $value): Sanitizer {
		return self::manager()->string($value);
	}

	/**
	 * Wraps raw input in an InputBag bound to the shared manager.
	 *
	 * @param array<string,mixed> $input Raw input data.
	 * @return InputBag Input wrapper for dot-path access and validation.
	 */
	public static function bag(array $input): InputBag {
		return self::manager()->bag($input);
	}

	/**
	 * Lists registered sanitation preset names.
	 *
	 * @return list<string> Preset identifiers.
	 */
	public static function presets(): array {
		return self::manager()->presets();
	}

	/**
	 * Checks whether a named sanitation preset exists.
	 *
	 * @param string $name Preset identifier.
	 * @return bool Preset registration decision.
	 */
	public static function hasPreset(string $name): bool {
		return self::manager()->hasPreset($name);
	}

	/**
	 * Registers or replaces a sanitation preset on the shared manager.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string,mixed>|callable $definition Preset definition accepted by PresetRegistry.
	 */
	public static function registerPreset(string $name, array|callable $definition): void {
		self::manager()->registerPreset($name, $definition);
	}

	/**
	 * Resolves only the schema portion of a named preset.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string,mixed> $preset_overrides Overrides applied while resolving the preset.
	 * @return array<string,mixed> Field-to-rule schema.
	 */
	public static function presetSchema(string $name, array $preset_overrides=[]): array {
		return self::manager()->presetSchema($name, $preset_overrides);
	}

	/**
	 * Sanitizes input through a named preset and returns a result object.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $preset_overrides Overrides applied while resolving the preset.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @return SanitizationResult Cleaned data and validation errors.
	 */
	public static function preset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return self::manager()->preset($name, $input, $preset_overrides, $defaults, $options);
	}

	/**
	 * Validates input through a named preset.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $preset_overrides Overrides applied while resolving the preset.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @return SanitizationResult Cleaned data and validation errors.
	 */
	public static function validatePreset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return self::preset($name, $input, $preset_overrides, $defaults, $options);
	}

	/**
	 * Returns sanitized values from a named preset.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $preset_overrides Overrides applied while resolving the preset.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @return array<string,mixed> Sanitized values.
	 */
	public static function validatedPreset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): array {
		return self::manager()->validatedPreset($name, $input, $preset_overrides, $defaults, $options);
	}

	/**
	 * Returns sanitized preset values or throws on validation failure.
	 *
	 * @param string $name Preset identifier.
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $preset_overrides Overrides applied while resolving the preset.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string,mixed> Sanitized values.
	 */
	public static function presetOrFail(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		return self::manager()->presetOrFail($name, $input, $preset_overrides, $defaults, $options, $message);
	}

	/**
	 * Sanitizes input through a field schema and returns a result object.
	 *
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $schema Field-to-rule schema.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @return SanitizationResult Cleaned data and validation errors.
	 */
	public static function schema(array $input, array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return self::manager()->schema($input, $schema, $defaults, $options);
	}

	/**
	 * Validates input through a field schema.
	 *
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $schema Field-to-rule schema.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @return SanitizationResult Cleaned data and validation errors.
	 */
	public static function validate(array $input, array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return self::schema($input, $schema, $defaults, $options);
	}

	/**
	 * Returns sanitized values from a field schema.
	 *
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $schema Field-to-rule schema.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @return array<string,mixed> Sanitized values.
	 */
	public static function validated(array $input, array $schema, array $defaults=[], array $options=[]): array {
		return self::manager()->validated($input, $schema, $defaults, $options);
	}

	/**
	 * Returns sanitized schema values or throws on validation failure.
	 *
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $schema Field-to-rule schema.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string,mixed> Sanitized values.
	 */
	public static function schemaOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return self::manager()->schemaOrFail($input, $schema, $defaults, $options, $message);
	}

	/**
	 * Validates a schema and throws on validation failure.
	 *
	 * @param array<string,mixed> $input Raw input data.
	 * @param array<string,mixed> $schema Field-to-rule schema.
	 * @param array<string,mixed> $defaults Initial output data.
	 * @param array<string,mixed> $options Schema-level options.
	 * @param ?string $message Optional exception message override.
	 * @return array<string,mixed> Sanitized values.
	 */
	public static function validateOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return self::schemaOrFail($input, $schema, $defaults, $options, $message);
	}

	/**
	 * Sanitizes a value as default text.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Sanitized text string, false on validation failure, or null when omitted or nullable.
	 */
	public static function text(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'default', $options);
	}

	/**
	 * Sanitizes a value as text without special characters.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Text string with special characters removed, false on validation failure, or null when omitted or nullable.
	 */
	public static function textNoSpecial(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'text_nospecial', $options);
	}

	/**
	 * Sanitizes a value as basic safe HTML.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Sanitized HTML fragment allowed by the basic_html rule, false on validation failure, or null when omitted or nullable.
	 */
	public static function basicHtml(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'basic_html', $options);
	}

	/**
	 * Sanitizes a value as an email address.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Normalized email address, false on validation failure, or null when omitted or nullable.
	 */
	public static function email(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'email', $options);
	}

	/**
	 * Sanitizes a value as a URL.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Normalized URL, false on validation failure, or null when omitted or nullable.
	 */
	public static function url(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'url', $options);
	}

	/**
	 * Sanitizes a value as a phone number.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Normalized phone number, false on validation failure, or null when omitted or nullable.
	 */
	public static function phone(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'phone_number', $options);
	}

	/**
	 * Sanitizes a value as a person name.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Sanitized person name, false on validation failure, or null when omitted or nullable.
	 */
	public static function name(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'person_name', $options);
	}

	/**
	 * Sanitizes a value as a numeric string.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Numeric string accepted by the numeric rule, false on validation failure, or null when omitted or nullable.
	 */
	public static function numeric(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'numeric', $options);
	}

	/**
	 * Sanitizes a value as an integer.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return int|false|null Integer value accepted by the integer rule, false on validation failure, or null when omitted or nullable.
	 */
	public static function integer(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'integer', $options);
	}

	/**
	 * Sanitizes a value as a float.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return float|false|null Float value accepted by the float rule, false on validation failure, or null when omitted or nullable.
	 */
	public static function float(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'float', $options);
	}

	/**
	 * Sanitizes a value as a boolean.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return bool|null Boolean value accepted by the boolean rule, false when invalid, or null when omitted or nullable.
	 */
	public static function boolean(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'boolean', $options);
	}

	/**
	 * Sanitizes a value as an associative array.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return array<string,mixed>|false|null Associative array accepted by the array rule, false on validation failure, or null when omitted or nullable.
	 */
	public static function arrayValue(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'array', $options);
	}

	/**
	 * Sanitizes a value as a list array.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return list<mixed>|false|null List array accepted by the list rule, false on validation failure, or null when omitted or nullable.
	 */
	public static function listValue(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'list', $options);
	}

	/**
	 * Sanitizes a value as a slug.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null URL-safe slug string, false on validation failure, or null when omitted or nullable.
	 */
	public static function slug(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'slug', $options);
	}

	/**
	 * Sanitizes a value as a username.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Sanitized username, false on validation failure, or null when omitted or nullable.
	 */
	public static function username(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'username', $options);
	}

	/**
	 * Sanitizes a value as a postal code.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param array<string,mixed> $options Runtime options.
	 * @return string|false|null Sanitized postal code, false on validation failure, or null when omitted or nullable.
	 */
	public static function postalCode(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'postal_code', $options);
	}
}
