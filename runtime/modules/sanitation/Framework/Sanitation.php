<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

final class Sanitation {

	public static function manager(): SanitationManager {
		return SanitationManager::instance();
	}

	public static function flush(): void {
		SanitationManager::flush();
	}

	public static function anonymizeEmail(string $email, int $count=2, string $char='*'): string {
		return \dataphyre\sanitation::anonymize_email($email, $count, $char);
	}

	public static function sanitize(mixed $value, string|array $rule='default', array $options=[]): mixed {
		return self::manager()->sanitize($value, $rule, $options);
	}

	public static function clean(mixed $value, string|array $rule='default', array $options=[]): mixed {
		return self::sanitize($value, $rule, $options);
	}

	public static function string(mixed $value): Sanitizer {
		return self::manager()->string($value);
	}

	public static function bag(array $input): InputBag {
		return self::manager()->bag($input);
	}

	public static function presets(): array {
		return self::manager()->presets();
	}

	public static function hasPreset(string $name): bool {
		return self::manager()->hasPreset($name);
	}

	public static function registerPreset(string $name, array|callable $definition): void {
		self::manager()->registerPreset($name, $definition);
	}

	public static function presetSchema(string $name, array $preset_overrides=[]): array {
		return self::manager()->presetSchema($name, $preset_overrides);
	}

	public static function preset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return self::manager()->preset($name, $input, $preset_overrides, $defaults, $options);
	}

	public static function validatePreset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
		return self::preset($name, $input, $preset_overrides, $defaults, $options);
	}

	public static function validatedPreset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): array {
		return self::manager()->validatedPreset($name, $input, $preset_overrides, $defaults, $options);
	}

	public static function presetOrFail(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		return self::manager()->presetOrFail($name, $input, $preset_overrides, $defaults, $options, $message);
	}

	public static function schema(array $input, array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return self::manager()->schema($input, $schema, $defaults, $options);
	}

	public static function validate(array $input, array $schema, array $defaults=[], array $options=[]): SanitizationResult {
		return self::schema($input, $schema, $defaults, $options);
	}

	public static function validated(array $input, array $schema, array $defaults=[], array $options=[]): array {
		return self::manager()->validated($input, $schema, $defaults, $options);
	}

	public static function schemaOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return self::manager()->schemaOrFail($input, $schema, $defaults, $options, $message);
	}

	public static function validateOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return self::schemaOrFail($input, $schema, $defaults, $options, $message);
	}

	public static function text(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'default', $options);
	}

	public static function textNoSpecial(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'text_nospecial', $options);
	}

	public static function basicHtml(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'basic_html', $options);
	}

	public static function email(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'email', $options);
	}

	public static function url(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'url', $options);
	}

	public static function phone(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'phone_number', $options);
	}

	public static function name(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'person_name', $options);
	}

	public static function numeric(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'numeric', $options);
	}

	public static function integer(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'integer', $options);
	}

	public static function float(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'float', $options);
	}

	public static function boolean(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'boolean', $options);
	}

	public static function arrayValue(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'array', $options);
	}

	public static function listValue(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'list', $options);
	}

	public static function slug(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'slug', $options);
	}

	public static function username(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'username', $options);
	}

	public static function postalCode(mixed $value, array $options=[]): mixed {
		return self::sanitize($value, 'postal_code', $options);
	}
}
