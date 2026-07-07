<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

/**
 * Static facade for Dataphyre MVC applications, routes, views, and responses.
 *
 * The facade delegates to `MvcManager`, route collections, templating bridges, and HTTP response helpers so application code can stay compact and typed.
 */
final class Mvc {

	/**
	 * Coordinates MVC application registry state.
	 *
	 * The facade delegates to `MvcManager` so application bootstraps can register, flush, and retrieve named MVC applications.
	 * @return MvcManager.
	 */
	public static function manager(): MvcManager {
		return MvcManager::instance();
	}

	/**
	 * Coordinates MVC application registry state.
	 *
	 * The facade delegates to `MvcManager` so application bootstraps can register, flush, and retrieve named MVC applications.
	 * @return void.
	 */
	public static function flush(): void {
		MvcManager::flush();
	}

	/**
	 * Reads MVC configuration with a safe fallback.
	 *
	 * The helper bridges to the kernel MVC module when loaded and otherwise returns the supplied default.
	 *
	 * @param string $key Configuration key requested from the MVC kernel module.
	 * @param mixed $default Value returned when the kernel module is unavailable or the key is unset.
	 * @return mixed.
	 */
	public static function config(string $key, mixed $default=null): mixed {
		return class_exists('\dataphyre\mvc', false)
			? \dataphyre\mvc::config($key, $default)
			: $default;
	}

	/**
	 * Coordinates MVC application registry state.
	 *
	 * The facade delegates to `MvcManager` so application bootstraps can register, flush, and retrieve named MVC applications.
	 *
	 * @param string $name Registered MVC application name.
	 * @return MvcApplication.
	 */
	public static function app(string $name='default'): MvcApplication {
		return self::manager()->app($name);
	}

	/**
	 * Coordinates MVC application registry state.
	 *
	 * The facade delegates to `MvcManager` so application bootstraps can register, flush, and retrieve named MVC applications.
	 *
	 * @param string $name MVC application name to register or replace.
	 * @param MvcApplication|array $application Application.
	 * @return MvcApplication.
	 */
	public static function register(string $name, MvcApplication|array $application): MvcApplication {
		return self::manager()->register($name, $application);
	}

	/**
	 * Coordinates MVC application registry state.
	 *
	 * The facade delegates to `MvcManager` so application bootstraps can register, flush, and retrieve named MVC applications.
	 * @return MvcApplication.
	 */
	public static function defaultApp(): MvcApplication {
		return self::manager()->defaultApp();
	}

	/**
	 * Works with MVC routes, URLs, and signed route security.
	 *
	 * Route helpers resolve named routes, build parameterized URLs, and validate request signatures against application signing configuration.
	 *
	 * @param ?string $app MVC application name.
	 * @return RouteCollection.
	 */
	public static function routes(?string $app=null): RouteCollection {
		return self::manager()->routes($app);
	}

	/**
	 * Works with MVC routes, URLs, and signed route security.
	 *
	 * Route helpers resolve named routes, build parameterized URLs, and validate request signatures against application signing configuration.
	 *
	 * @param ?string $app MVC application name.
	 * @return array.
	 */
	public static function routeList(?string $app=null): array {
		return self::routes($app)->list();
	}

	/**
	 * Works with MVC routes, URLs, and signed route security.
	 *
	 * Route helpers resolve named routes, build parameterized URLs, and validate request signatures against application signing configuration.
	 *
	 * @param string $route Named route key resolved from the selected application.
	 * @param array<string, mixed> $parameters Named route parameters for path interpolation.
	 * @param array<string, mixed> $query Query-string parameters appended to the generated URL.
	 * @param ?string $app MVC application name.
	 * @return string.
	 */
	public static function url(string $route, array $parameters=[], array $query=[], ?string $app=null): string {
		return self::routes($app)->url($route, $parameters, $query);
	}

	/**
	 * Works with MVC routes, URLs, and signed route security.
	 *
	 * Route helpers resolve named routes, build parameterized URLs, and validate request signatures against application signing configuration.
	 *
	 * @param string $route Named route key resolved from the selected application.
	 * @param array<string, mixed> $parameters Named route parameters for path interpolation.
	 * @param array<string, mixed> $query Query-string parameters appended before signing.
	 * @param ?int $expiresAt Optional Unix timestamp after which the signature is invalid.
	 * @param ?string $app MVC application name.
	 * @return string.
	 */
	public static function signedUrl(string $route, array $parameters=[], array $query=[], ?int $expiresAt=null, ?string $app=null): string {
		return self::routes($app)->signedUrl($route, $parameters, $query, $expiresAt);
	}

	/**
	 * Works with MVC routes, URLs, and signed route security.
	 *
	 * Signature validation uses the selected application's `signed_url_secret` setting and falls back to DATAPHYRE_MVC_SIGNING_KEY when the application has no configured secret.
	 *
	 * @param Request $request HTTP request being handled.
	 * @param ?string $app MVC application name.
	 * @return bool.
	 */
	public static function hasValidSignature(Request $request, ?string $app=null): bool {
		$application=$app===null ? self::defaultApp() : self::app($app);
		$secret=$application->config('signed_url_secret');
		if(!is_string($secret) || trim($secret)===''){
			$secret=(string)getenv('DATAPHYRE_MVC_SIGNING_KEY');
		}
		return SignedUrl::valid($request, $secret);
	}

	/**
	 * Creates a deferred view result for MVC response normalization.
	 *
	 * The template and data are stored until the response layer renders the view, keeping action return values lightweight.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string, mixed> $data View data exposed to the template.
	 * @return ViewResult.
	 */
	public static function view(string $template, array $data=[]): ViewResult {
		return ViewResult::make($template, $data);
	}

	/**
	 * Delegates the `templating available` helper to the MVC runtime.
	 *
	 * The check intentionally tests both the legacy kernel class and the namespaced templating facade so MVC can run across old and new runtime boot modes.
	 * @return bool.
	 */
	public static function templatingAvailable(): bool {
		return class_exists('\dataphyre\templating', false) || class_exists('\Dataphyre\Templating\Templating', false);
	}

	/**
	 * Renders a template path through the available templating bridge.
	 *
	 * Legacy kernel rendering is preferred when loaded; otherwise the namespaced templating facade is used. When no templating bridge exists, rendering fails closed to an empty string.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string, mixed> $data View data exposed to the template.
	 * @param array<string, mixed> $themeValues Theme variables passed to the templating bridge.
	 * @param array<string, mixed> $slots Named slot content passed to the templating bridge.
	 * @return string.
	 */
	public static function renderTemplate(string $template, array $data=[], array $themeValues=[], array $slots=[]): string {
		if(class_exists('\dataphyre\templating', false) && method_exists('\dataphyre\templating', 'render')){
			return (string)\dataphyre\templating::render($template, $data, $themeValues, $slots);
		}
		if(class_exists('\Dataphyre\Templating\Templating', false)){
			$result=\Dataphyre\Templating\Templating::render($template, $data, $themeValues, $slots);
			return is_object($result) && method_exists($result, 'content') ? (string)$result->content() : (string)$result;
		}
		return '';
	}

	/**
	 * Renders an inline template string through the available templating bridge.
	 *
	 * The template name is forwarded for diagnostics, cache identity, and asset extraction. When no templating bridge exists, rendering fails closed to an empty string.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string, mixed> $data View data exposed to the template.
	 * @param array<string, mixed> $themeValues Theme variables passed to the templating bridge.
	 * @param array<string, mixed> $slots Named slot content passed to the templating bridge.
	 * @param string $templateName Diagnostic/cache name for the inline template.
	 * @return string.
	 */
	public static function renderTemplateString(string $template, array $data=[], array $themeValues=[], array $slots=[], string $templateName='inline.tpl'): string {
		if(class_exists('\dataphyre\templating', false) && method_exists('\dataphyre\templating', 'render_string')){
			return (string)\dataphyre\templating::render_string($template, $data, $themeValues, $slots, $templateName);
		}
		if(class_exists('\Dataphyre\Templating\Templating', false)){
			$result=\Dataphyre\Templating\Templating::renderString($template, $data, $themeValues, $slots, $templateName);
			return is_object($result) && method_exists($result, 'content') ? (string)$result->content() : (string)$result;
		}
		return '';
	}

	/**
	 * Extracts an asset manifest from a template path.
	 *
	 * Missing templating support or non-array manifest results resolve to an empty manifest so callers can render conditionally without extra module checks.
	 *
	 * @param string $template Template path or inline template source.
	 * @return array.
	 */
	public static function templateAssets(string $template): array {
		if(class_exists('\dataphyre\templating', false) && method_exists('\dataphyre\templating', 'asset_manifest')){
			$manifest=\dataphyre\templating::asset_manifest($template);
			return is_array($manifest) ? $manifest : [];
		}
		if(class_exists('\Dataphyre\Templating\Templating', false)){
			$manifest=\Dataphyre\Templating\Templating::assetManifest($template);
			return is_object($manifest) && method_exists($manifest, 'toArray') ? $manifest->toArray() : [];
		}
		return [];
	}

	/**
	 * Extracts an asset manifest from an inline template string.
	 *
	 * The template name is forwarded for diagnostics and cache identity. Missing templating support resolves to an empty manifest.
	 *
	 * @param string $template Template path or inline template source.
	 * @param string $templateName Diagnostic/cache name for the inline template.
	 * @return array.
	 */
	public static function templateStringAssets(string $template, string $templateName='inline.tpl'): array {
		if(class_exists('\dataphyre\templating', false) && method_exists('\dataphyre\templating', 'asset_manifest_string')){
			$manifest=\dataphyre\templating::asset_manifest_string($template, $templateName);
			return is_array($manifest) ? $manifest : [];
		}
		if(class_exists('\Dataphyre\Templating\Templating', false)){
			$manifest=\Dataphyre\Templating\Templating::assetManifestString($template, $templateName);
			return is_object($manifest) && method_exists($manifest, 'toArray') ? $manifest->toArray() : [];
		}
		return [];
	}

	/**
	 * Renders asset HTML for a template path.
	 *
	 * Asset extraction happens first; empty or unsupported manifests produce an empty string through assetHtml().
	 *
	 * @param string $template Template path or inline template source.
	 * @param string $section Asset section to render, or all for every section.
	 * @return string.
	 */
	public static function templateAssetHtml(string $template, string $section='all'): string {
		return self::assetHtml(self::templateAssets($template), $section);
	}

	/**
	 * Renders asset HTML for an inline template string.
	 *
	 * Asset extraction happens first; the template name is used for diagnostics and cache identity before assetHtml() renders the selected section.
	 *
	 * @param string $template Template path or inline template source.
	 * @param string $section Asset section to render, or all for every section.
	 * @param string $templateName Diagnostic/cache name for the inline template.
	 * @return string.
	 */
	public static function templateStringAssetHtml(string $template, string $section='all', string $templateName='inline.tpl'): string {
		return self::assetHtml(self::templateStringAssets($template, $templateName), $section);
	}

	/**
	 * Builds normalized HTTP responses for MVC handlers.
	 *
	 * Response helpers return typed `Response` or result objects that the dispatcher can emit consistently.
	 *
	 * @param array|\JsonSerializable $payload Response payload.
	 * @param int $status HTTP status code for the JSON response.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return Response.
	 */
	public static function json(array|\JsonSerializable $payload, int $status=200, array $headers=[]): Response {
		return Response::json($payload, $status, $headers);
	}

	/**
	 * Builds a 201 JSON response for a newly created resource.
	 *
	 * The optional Location header is delegated to the HTTP response helper.
	 *
	 * @param array|\JsonSerializable $payload Response payload.
	 * @param ?string $location Optional Location header value.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return Response.
	 */
	public static function created(array|\JsonSerializable $payload, ?string $location=null, array $headers=[]): Response {
		return Response::created($payload, $location, $headers);
	}

	/**
	 * Builds an empty 204 response.
	 *
	 * @return Response.
	 */
	public static function noContent(): Response {
		return Response::noContent();
	}

	/**
	 * Validates request data through the MVC validator.
	 *
	 * Request objects are converted to array data before rules are applied. Validation failure behavior is owned by Validator.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string, mixed> $rules Validation rules keyed by input path.
	 * @param array<string, string> $messages Custom validation messages keyed by rule or field.
	 * @param array<string, string> $attributes Display names keyed by input path.
	 * @return array.
	 */
	public static function validate(Request|array $request, array $rules, array $messages=[], array $attributes=[]): array {
		return Validator::validate(self::requestData($request), $rules, $messages, $attributes);
	}

	/**
	 * Reports whether any supported sanitation module is loaded.
	 *
	 * Both namespaced and legacy sanitation APIs are accepted for compatibility.
	 * @return bool.
	 */
	public static function sanitationAvailable(): bool {
		return class_exists('\Dataphyre\Sanitation\Sanitation', false) || class_exists('\dataphyre\sanitation', false);
	}

	/**
	 * Masks an email address through the sanitation module or local fallback.
	 *
	 * Namespaced sanitation is preferred, then the legacy sanitation module. If neither is loaded, a deterministic local mask preserves the domain and leading local characters.
	 *
	 * @param string $email Email address to anonymize.
	 * @param int $count Number of visible leading characters to keep.
	 * @param string $char Replacement character used for hidden characters.
	 * @return string.
	 */
	public static function anonymizeEmail(string $email, int $count=2, string $char='*'): string {
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'anonymizeEmail')){
			return \Dataphyre\Sanitation\Sanitation::anonymizeEmail($email, $count, $char);
		}
		if(class_exists('\dataphyre\sanitation', false) && method_exists('\dataphyre\sanitation', 'anonymize_email')){
			return \dataphyre\sanitation::anonymize_email($email, $count, $char);
		}
		$email=trim($email);
		if($email==='' || !str_contains($email, '@')){
			return '';
		}
		[$local, $domain]=explode('@', $email, 2);
		$visible=max(0, min($count, strlen($local)));
		return substr($local, 0, $visible).str_repeat($char, max(0, strlen($local)-$visible)).'@'.$domain;
	}

	/**
	 * Sanitizes one value through the available sanitation module.
	 *
	 * Namespaced sanitation receives full rule and option data. The legacy module is used only for string rules; when no sanitizer exists the original value is returned unchanged.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param string|array $rule Rule.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @return mixed.
	 */
	public static function sanitize(mixed $value, string|array $rule='default', array $options=[]): mixed {
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false)){
			return \Dataphyre\Sanitation\Sanitation::sanitize($value, $rule, $options);
		}
		if(class_exists('\dataphyre\sanitation', false) && method_exists('\dataphyre\sanitation', 'sanitize') && is_string($rule)){
			return \dataphyre\sanitation::sanitize($value, $rule);
		}
		return $value;
	}

	/**
	 * Alias for sanitize().
	 *
	 * @param mixed $value Value to sanitize.
	 * @param string|array $rule Rule.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @return mixed.
	 */
	public static function clean(mixed $value, string|array $rule='default', array $options=[]): mixed {
		return self::sanitize($value, $rule, $options);
	}

	/**
	 * Returns the namespaced sanitizer string wrapper when available.
	 *
	 * When the namespaced sanitation module is missing, null is returned instead of invoking legacy globals.
	 *
	 * @param mixed $value Value to wrap for sanitation.
	 * @return mixed.
	 */
	public static function sanitizer(mixed $value): mixed {
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'string')){
			return \Dataphyre\Sanitation\Sanitation::string($value);
		}
		return null;
	}

	/**
	 * Builds a sanitation input bag from a request or raw data array.
	 *
	 * The helper returns null when the namespaced sanitation bag API is unavailable.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @return mixed.
	 */
	public static function inputBag(Request|array $request): mixed {
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'bag')){
			return \Dataphyre\Sanitation\Sanitation::bag(self::requestData($request));
		}
		return null;
	}

	/**
	 * Sanitizes request data against a field schema.
	 *
	 * Namespaced sanitation receives schema, defaults, and options. Legacy sanitation receives data and schema only. Without sanitation support, the raw request data is returned.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string, mixed> $schema Sanitation schema keyed by input path.
	 * @param array<string, mixed> $defaults Default values merged by the sanitation module.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @return mixed.
	 */
	public static function sanitizeSchema(Request|array $request, array $schema, array $defaults=[], array $options=[]): mixed {
		$data=self::requestData($request);
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'schema')){
			return \Dataphyre\Sanitation\Sanitation::schema($data, $schema, $defaults, $options);
		}
		if(class_exists('\dataphyre\sanitation', false) && method_exists('\dataphyre\sanitation', 'sanitize_many')){
			return \dataphyre\sanitation::sanitize_many($data, $schema);
		}
		return $data;
	}

	/**
	 * Sanitizes request data and guarantees an array payload.
	 *
	 * Namespaced validated sanitation is preferred. Fallback schema sanitation is coerced to an array when possible; otherwise the original request data is returned.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string, mixed> $schema Sanitation schema keyed by input path.
	 * @param array<string, mixed> $defaults Default values merged by the sanitation module.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @return array.
	 */
	public static function sanitized(Request|array $request, array $schema, array $defaults=[], array $options=[]): array {
		$data=self::requestData($request);
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'validated')){
			return \Dataphyre\Sanitation\Sanitation::validated($data, $schema, $defaults, $options);
		}
		$result=self::sanitizeSchema($data, $schema, $defaults, $options);
		return is_array($result) ? $result : $data;
	}

	/**
	 * Sanitizes request data or lets the sanitation module fail explicitly.
	 *
	 * When schemaOrFail is unavailable, the helper falls back to sanitized() and therefore cannot raise module-specific sanitation failures.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string, mixed> $schema Sanitation schema keyed by input path.
	 * @param array<string, mixed> $defaults Default values merged by the sanitation module.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @param ?string $message Optional sanitation failure message.
	 * @return array.
	 */
	public static function sanitizedOrFail(Request|array $request, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		$data=self::requestData($request);
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'schemaOrFail')){
			return \Dataphyre\Sanitation\Sanitation::schemaOrFail($data, $schema, $defaults, $options, $message);
		}
		return self::sanitized($data, $schema, $defaults, $options);
	}

	/**
	 * Sanitizes request data with a named sanitation preset.
	 *
	 * Presets are supported only by the namespaced sanitation module; without it, the raw request data is returned.
	 *
	 * @param string $name Named sanitation preset.
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string, mixed> $presetOverrides Overrides applied to the named sanitation preset.
	 * @param array<string, mixed> $defaults Default values merged by the sanitation module.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @return mixed.
	 */
	public static function sanitizePreset(string $name, Request|array $request, array $presetOverrides=[], array $defaults=[], array $options=[]): mixed {
		$data=self::requestData($request);
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'preset')){
			return \Dataphyre\Sanitation\Sanitation::preset($name, $data, $presetOverrides, $defaults, $options);
		}
		return $data;
	}

	/**
	 * Sanitizes request data with a named preset and guarantees an array payload.
	 *
	 * Namespaced validated preset sanitation is preferred; without preset support, the raw request data is returned.
	 *
	 * @param string $name Named sanitation preset.
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string, mixed> $presetOverrides Overrides applied to the named sanitation preset.
	 * @param array<string, mixed> $defaults Default values merged by the sanitation module.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @return array.
	 */
	public static function sanitizedPreset(string $name, Request|array $request, array $presetOverrides=[], array $defaults=[], array $options=[]): array {
		$data=self::requestData($request);
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'validatedPreset')){
			return \Dataphyre\Sanitation\Sanitation::validatedPreset($name, $data, $presetOverrides, $defaults, $options);
		}
		return $data;
	}

	/**
	 * Sanitizes request data with a named preset or lets the module fail explicitly.
	 *
	 * When presetOrFail is unavailable, the helper falls back to returning raw request data and cannot raise module-specific sanitation failures.
	 *
	 * @param string $name Named sanitation preset.
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string, mixed> $presetOverrides Overrides applied to the named sanitation preset.
	 * @param array<string, mixed> $defaults Default values merged by the sanitation module.
	 * @param array<string, mixed> $options Sanitation options passed to the loaded module.
	 * @param ?string $message Optional sanitation failure message.
	 * @return array.
	 */
	public static function sanitizedPresetOrFail(string $name, Request|array $request, array $presetOverrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		$data=self::requestData($request);
		if(class_exists('\Dataphyre\Sanitation\Sanitation', false) && method_exists('\Dataphyre\Sanitation\Sanitation', 'presetOrFail')){
			return \Dataphyre\Sanitation\Sanitation::presetOrFail($name, $data, $presetOverrides, $defaults, $options, $message);
		}
		return $data;
	}

	/**
	 * Reports whether any supported localization module is loaded.
	 *
	 * Both namespaced and legacy localization APIs are accepted for compatibility.
	 * @return bool.
	 */
	public static function localizationAvailable(): bool {
		return class_exists('\dataphyre\localization', false) || class_exists('\Dataphyre\Localization\Localization', false);
	}

	/**
	 * Resolves a translation string through the available localization bridge.
	 *
	 * Namespaced localization is preferred, then the legacy locale() API. Without localization support, the fallback or key is interpolated with supplied parameters.
	 *
	 * @param string $key Translation key.
	 * @param ?string $fallback Fallback text when the key cannot be resolved.
	 * @param ?array $parameters Route parameters.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return string.
	 */
	public static function translate(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		if(class_exists('\Dataphyre\Localization\Localization', false)){
			return \Dataphyre\Localization\Localization::translate($key, $fallback, $parameters, $language, $page, $theme);
		}
		if(class_exists('\dataphyre\localization', false) && method_exists('\dataphyre\localization', 'locale')){
			return \dataphyre\localization::locale($key, $fallback, $parameters, $language, $page);
		}
		return self::interpolate($fallback ?? $key, $parameters);
	}

	/**
	 * Resolves a translation string or returns null when missing.
	 *
	 * The legacy locale() fallback treats empty strings and unchanged keys as missing translations.
	 *
	 * @param string $key Translation key.
	 * @param ?array $parameters Route parameters.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return ?string.
	 */
	public static function translateOrNull(
		string $key,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): ?string {
		if(class_exists('\Dataphyre\Localization\Localization', false)){
			return \Dataphyre\Localization\Localization::translateOrNull($key, $parameters, $language, $page, $theme);
		}
		if(class_exists('\dataphyre\localization', false) && method_exists('\dataphyre\localization', 'locale')){
			$value=\dataphyre\localization::locale($key, null, $parameters, $language, $page);
			return $value==='' || $value===$key ? null : $value;
		}
		return null;
	}

	/**
	 * Reports whether a translation key exists.
	 *
	 * Namespaced localization uses its native existence check; fallback behavior delegates to translateOrNull().
	 *
	 * @param string $key Translation key to test.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return bool.
	 */
	public static function translationHas(string $key, ?string $language=null, ?string $page=null, ?string $theme=null): bool {
		if(class_exists('\Dataphyre\Localization\Localization', false)){
			return \Dataphyre\Localization\Localization::has($key, $language, $page, $theme);
		}
		return self::translateOrNull($key, null, $language, $page, $theme)!==null;
	}

	/**
	 * Reports whether a translation key is missing.
	 *
	 * Namespaced localization uses its native missing check; fallback behavior negates translationHas().
	 *
	 * @param string $key Translation key to test.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return bool.
	 */
	public static function translationMissing(string $key, ?string $language=null, ?string $page=null, ?string $theme=null): bool {
		if(class_exists('\Dataphyre\Localization\Localization', false)){
			return \Dataphyre\Localization\Localization::missing($key, $language, $page, $theme);
		}
		return !self::translationHas($key, $language, $page, $theme);
	}

	/**
	 * Resolves a pluralized translation string.
	 *
	 * Namespaced localization handles pluralization when loaded. Without it, the key is selected locally from zero, singular, or plural branches and resolved through translate().
	 *
	 * @param int|float $count Count used to select the translation branch.
	 * @param string $oneKey Translation key for singular values.
	 * @param string $manyKey Translation key for plural values.
	 * @param ?string $zeroKey ZeroKey.
	 * @param ?array $parameters Route parameters.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return string.
	 */
	public static function choice(
		int|float $count,
		string $oneKey,
		string $manyKey,
		?string $zeroKey=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		if(class_exists('\Dataphyre\Localization\Localization', false)){
			return \Dataphyre\Localization\Localization::choice($count, $oneKey, $manyKey, $zeroKey, $parameters, $language, $page, $theme);
		}
		$key=$count==0 && $zeroKey!==null ? $zeroKey : ($count==1 ? $oneKey : $manyKey);
		return self::translate($key, null, ['count'=>$count]+($parameters ?? []), $language, $page, $theme);
	}

	/**
	 * Delegates the `currency available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function currencyAvailable(): bool {
		return class_exists('\Dataphyre\Currency\Currency', false) || class_exists('\dataphyre\currency', false);
	}

	/**
	 * Formats an amount through the available currency bridge.
	 *
	 * Namespaced currency is preferred, then the legacy formatter. Without currency support, the numeric amount is string-cast with null becoming zero.
	 *
	 * @param float|int|string|null $amount Amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @param ?string $currency Currency.
	 * @return string.
	 */
	public static function moneyFormat(float|int|string|null $amount, bool $showFree=false, ?string $currency=null): string {
		if(class_exists('\Dataphyre\Currency\Currency', false)){
			return \Dataphyre\Currency\Currency::format($amount, $showFree, $currency);
		}
		if(class_exists('\dataphyre\currency', false) && method_exists('\dataphyre\currency', 'formatter')){
			return \dataphyre\currency::formatter($amount, $showFree, $currency);
		}
		return (string)($amount ?? 0);
	}

	/**
	 * Converts an amount between currencies.
	 *
	 * Namespaced currency is preferred, then the legacy converter. Without currency support, formatted output falls back to moneyFormat() and numeric output returns the original amount as float.
	 *
	 * @param float|int|string|null $amount Amount.
	 * @param string $sourceCurrency Currency code of the input amount.
	 * @param string $targetCurrency Currency code requested for output.
	 * @param bool $formatted Whether to return a display string instead of a numeric amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @return string|float.
	 */
	public static function moneyConvert(
		float|int|string|null $amount,
		string $sourceCurrency,
		string $targetCurrency,
		bool $formatted=false,
		bool $showFree=true
	): string|float {
		if(class_exists('\Dataphyre\Currency\Currency', false)){
			return \Dataphyre\Currency\Currency::convert($amount, $sourceCurrency, $targetCurrency, $formatted, $showFree);
		}
		if(class_exists('\dataphyre\currency', false) && method_exists('\dataphyre\currency', 'convert')){
			return \dataphyre\currency::convert($amount, $sourceCurrency, $targetCurrency, $formatted, $showFree);
		}
		return $formatted ? self::moneyFormat($amount, $showFree, $targetCurrency) : (float)($amount ?? 0);
	}

	/**
	 * Converts an amount to the active display currency.
	 *
	 * Without currency support, formatted output falls back to moneyFormat() and numeric output returns the original amount as float.
	 *
	 * @param float|int|string|null $amount Amount.
	 * @param bool $formatted Whether to return a display string instead of a numeric amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @param ?string $currency Currency.
	 * @return string|float.
	 */
	public static function moneyToDisplay(float|int|string|null $amount, bool $formatted=false, bool $showFree=true, ?string $currency=null): string|float {
		if(class_exists('\Dataphyre\Currency\Currency', false)){
			return \Dataphyre\Currency\Currency::convertToDisplay($amount, $formatted, $showFree, $currency);
		}
		if(class_exists('\dataphyre\currency', false) && method_exists('\dataphyre\currency', 'convert_to_user_currency')){
			return \dataphyre\currency::convert_to_user_currency($amount, $formatted, $showFree, $currency);
		}
		return $formatted ? self::moneyFormat($amount, $showFree, $currency) : (float)($amount ?? 0);
	}

	/**
	 * Converts an amount to the configured base currency.
	 *
	 * Without currency support, formatted output falls back to moneyFormat() using the original currency and numeric output returns the original amount as float.
	 *
	 * @param float|int|string|null $amount Amount.
	 * @param string $originalCurrency Currency code of the input amount.
	 * @param bool $formatted Whether to return a display string instead of a numeric amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @return string|float.
	 */
	public static function moneyToBase(float|int|string|null $amount, string $originalCurrency, bool $formatted=false, bool $showFree=true): string|float {
		if(class_exists('\Dataphyre\Currency\Currency', false)){
			return \Dataphyre\Currency\Currency::convertToBase($amount, $originalCurrency, $formatted, $showFree);
		}
		if(class_exists('\dataphyre\currency', false) && method_exists('\dataphyre\currency', 'convert_to_website_currency')){
			return \dataphyre\currency::convert_to_website_currency($amount, $originalCurrency, $formatted, $showFree);
		}
		return $formatted ? self::moneyFormat($amount, $showFree, $originalCurrency) : (float)($amount ?? 0);
	}

	/**
	 * Rounds an amount using currency precision rules.
	 *
	 * Currency modules own precision and cash-rounding rules. Without currency support, amounts are rounded to two decimals.
	 *
	 * @param float|int|string|null $amount Amount.
	 * @param string $currency ISO currency code used to choose precision rules.
	 * @param bool $cash Whether to apply cash-rounding increments.
	 * @return float.
	 */
	public static function moneyRound(float|int|string|null $amount, string $currency, bool $cash=false): float {
		if(class_exists('\Dataphyre\Currency\Currency', false)){
			return \Dataphyre\Currency\Currency::roundAmount($amount, $currency, $cash);
		}
		if(class_exists('\dataphyre\currency', false) && method_exists('\dataphyre\currency', 'round_amount')){
			return \dataphyre\currency::round_amount($amount, $currency, $cash);
		}
		return round((float)($amount ?? 0), 2);
	}

	/**
	 * Splits an amount into equal currency-aware parts.
	 *
	 * Currency modules own rounding remainders. Without currency support, an empty allocation is returned.
	 *
	 * @param float|int|string|null $amount Amount.
	 * @param string $currency ISO currency code used for split precision and rounding.
	 * @param int $parts Number of parts to split into.
	 * @param bool $cash Whether to apply cash-rounding increments.
	 * @return array.
	 */
	public static function moneySplit(float|int|string|null $amount, string $currency, int $parts, bool $cash=false): array {
		if(class_exists('\Dataphyre\Currency\Currency', false)){
			return \Dataphyre\Currency\Currency::splitAmount($amount, $currency, $parts, $cash);
		}
		if(class_exists('\dataphyre\currency', false) && method_exists('\dataphyre\currency', 'split_amount')){
			return \dataphyre\currency::split_amount($amount, $currency, $parts, $cash);
		}
		return [];
	}

	/**
	 * Allocates an amount by ratio using currency-aware rounding.
	 *
	 * Currency modules own ratio normalization and rounding remainders. Without currency support, an empty allocation is returned.
	 *
	 * @param float|int|string|null $amount Amount.
	 * @param string $currency ISO currency code used for allocation precision.
	 * @param array<int, int|float> $ratios Allocation ratios used by the currency module.
	 * @param bool $cash Whether to apply cash-rounding increments.
	 * @return array.
	 */
	public static function moneyAllocate(float|int|string|null $amount, string $currency, array $ratios, bool $cash=false): array {
		if(class_exists('\Dataphyre\Currency\Currency', false)){
			return \Dataphyre\Currency\Currency::allocateAmount($amount, $currency, $ratios, $cash);
		}
		if(class_exists('\dataphyre\currency', false) && method_exists('\dataphyre\currency', 'allocate_amount')){
			return \dataphyre\currency::allocate_amount($amount, $currency, $ratios, $cash);
		}
		return [];
	}

	/**
	 * Delegates the `date translation available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function dateTranslationAvailable(): bool {
		return class_exists('\dataphyre\date_translation', false);
	}

	/**
	 * Translates or formats a date through the date translation module.
	 *
	 * When the module is unavailable or returns null, the original date string is preserved.
	 *
	 * @param string $date Date string accepted by the date translation module.
	 * @param string $language Target language code.
	 * @param string $format Output date format.
	 * @return string.
	 */
	public static function translateDate(string $date, string $language, string $format): string {
		if(self::dateTranslationAvailable() && method_exists('\dataphyre\date_translation', 'translate_date')){
			return (string)(\dataphyre\date_translation::translate_date($date, $language, $format) ?? $date);
		}
		return $date;
	}

	/**
	 * Alias for translateDate().
	 *
	 * @param string $date Date string accepted by the date translation module.
	 * @param string $language Target language code.
	 * @param string $format Output date format.
	 * @return string.
	 */
	public static function localizedDate(string $date, string $language, string $format): string {
		return self::translateDate($date, $language, $format);
	}

	/**
	 * Delegates the `cache available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function cacheAvailable(): bool {
		return class_exists('\dataphyre\cache', false);
	}

	/**
	 * Reads a cache value through the legacy cache module.
	 *
	 * Missing cache support or a null cached value returns the supplied default.
	 *
	 * @param string $key Cache key.
	 * @param mixed $default Value returned when the key is unavailable.
	 * @return mixed.
	 */
	public static function cacheGet(string $key, mixed $default=null): mixed {
		if(!self::cacheAvailable() || !method_exists('\dataphyre\cache', 'get')){
			return $default;
		}
		$value=\dataphyre\cache::get($key);
		return $value===null ? $default : $value;
	}

	/**
	 * Stores a cache value through the legacy cache module.
	 *
	 * The helper returns false when cache support or the set API is unavailable.
	 *
	 * @param string $key Cache key.
	 * @param mixed $value Value to store.
	 * @param int $seconds TTL in seconds, or backend default when zero.
	 * @return bool.
	 */
	public static function cachePut(string $key, mixed $value, int $seconds=0): bool {
		return self::cacheAvailable()
			&& method_exists('\dataphyre\cache', 'set')
			&& \dataphyre\cache::set($key, $value, $seconds)===true;
	}

	/**
	 * Reads a cached value or resolves and stores it.
	 *
	 * A sentinel distinguishes missing values from cached falsey values. The resolver runs only after a cache miss.
	 *
	 * @param string $key Cache key.
	 * @param int $seconds TTL in seconds for newly resolved values.
	 * @param callable $resolver Callback used to produce or retrieve the cached value on misses.
	 * @return mixed.
	 */
	public static function cacheRemember(string $key, int $seconds, callable $resolver): mixed {
		$sentinel=new \stdClass();
		$value=self::cacheGet($key, $sentinel);
		if($value!==$sentinel){
			return $value;
		}
		$value=$resolver();
		self::cachePut($key, $value, $seconds);
		return $value;
	}

	/**
	 * Removes a cache key through the legacy cache module.
	 *
	 * The helper returns false when cache support or the delete API is unavailable.
	 *
	 * @param string $key Cache key to remove.
	 * @return bool.
	 */
	public static function cacheForget(string $key): bool {
		return self::cacheAvailable()
			&& method_exists('\dataphyre\cache', 'delete')
			&& \dataphyre\cache::delete($key)===true;
	}

	/**
	 * Increments a numeric cache value.
	 *
	 * Numeric backend results are cast to int; unavailable cache support or non-numeric results return false.
	 *
	 * @param string $key Cache key to increment.
	 * @param int $offset Amount added to the cached value.
	 * @return int|false.
	 */
	public static function cacheIncrement(string $key, int $offset=1): int|false {
		if(!self::cacheAvailable() || !method_exists('\dataphyre\cache', 'increment')){
			return false;
		}
		$value=\dataphyre\cache::increment($key, $offset);
		return is_numeric($value) ? (int)$value : false;
	}

	/**
	 * Decrements a numeric cache value.
	 *
	 * Numeric backend results are cast to int; unavailable cache support or non-numeric results return false.
	 *
	 * @param string $key Cache key to decrement.
	 * @param int $offset Amount subtracted from the cached value.
	 * @return int|false.
	 */
	public static function cacheDecrement(string $key, int $offset=1): int|false {
		if(!self::cacheAvailable() || !method_exists('\dataphyre\cache', 'decrement')){
			return false;
		}
		$value=\dataphyre\cache::decrement($key, $offset);
		return is_numeric($value) ? (int)$value : false;
	}

	/**
	 * Delegates the `async available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function asyncAvailable(): bool {
		return class_exists('\Dataphyre\Async\Async', false) || class_exists('\dataphyre\async', false);
	}

	/**
	 * Dispatches an asynchronous task through the available async bridge.
	 *
	 * Namespaced async receives the task descriptor, arguments, and driver. Legacy async is used only for callable tasks. Without async support, null is returned.
	 *
	 * @param mixed $task Callable, job object, or async task descriptor.
	 * @param array<int, mixed> $arguments Arguments passed to the async task.
	 * @param ?string $driver Optional async driver name.
	 * @return mixed.
	 */
	public static function asyncDispatch(mixed $task, array $arguments=[], ?string $driver=null): mixed {
		if(class_exists('\Dataphyre\Async\Async', false)){
			return \Dataphyre\Async\Async::dispatch($task, $arguments, $driver);
		}
		if(class_exists('\dataphyre\async', false) && method_exists('\dataphyre\async', 'async') && is_callable($task)){
			return \dataphyre\async::async(static fn(): mixed => $task(...$arguments));
		}
		return null;
	}

	/**
	 * Executes an async-compatible task inline.
	 *
	 * Namespaced async owns inline execution when available. Otherwise callable tasks run directly and non-callable tasks return null.
	 *
	 * @param mixed $task Callable, job object, or async task descriptor.
	 * @param array<int, mixed> $arguments Arguments passed to the inline task.
	 * @return mixed.
	 */
	public static function asyncInline(mixed $task, array $arguments=[]): mixed {
		if(class_exists('\Dataphyre\Async\Async', false)){
			return \Dataphyre\Async\Async::inline($task, $arguments);
		}
		if(is_callable($task)){
			return $task(...$arguments);
		}
		return null;
	}

	/**
	 * Dispatches multiple async tasks and waits according to the async module contract.
	 *
	 * Only the namespaced async module supports this helper; without it, null is returned.
	 *
	 * @param array<int|string, mixed> $tasks Async task definitions passed to the async module.
	 * @param ?string $driver Optional async driver name.
	 * @return mixed.
	 */
	public static function asyncAll(array $tasks, ?string $driver=null): mixed {
		if(class_exists('\Dataphyre\Async\Async', false)){
			return \Dataphyre\Async\Async::all($tasks, $driver);
		}
		return null;
	}

	/**
	 * Schedules a callable to run after a delay.
	 *
	 * Namespaced async is preferred, with legacy set_timeout as a fallback. Missing async support returns false.
	 *
	 * @param callable $task Callable scheduled for delayed execution.
	 * @param int $milliseconds Delay in milliseconds.
	 * @return int|false.
	 */
	public static function asyncAfter(callable $task, int $milliseconds): int|false {
		if(class_exists('\Dataphyre\Async\Async', false)){
			return \Dataphyre\Async\Async::after($task, $milliseconds);
		}
		if(class_exists('\dataphyre\async', false) && method_exists('\dataphyre\async', 'set_timeout')){
			return (int)\dataphyre\async::set_timeout($task, $milliseconds);
		}
		return false;
	}

	/**
	 * Schedules a callable to run repeatedly.
	 *
	 * Namespaced async is preferred, with legacy set_interval as a fallback. Missing async support returns false.
	 *
	 * @param callable $task Callable scheduled for repeated execution.
	 * @param int $milliseconds Interval in milliseconds.
	 * @return int|false.
	 */
	public static function asyncEvery(callable $task, int $milliseconds): int|false {
		if(class_exists('\Dataphyre\Async\Async', false)){
			return \Dataphyre\Async\Async::every($task, $milliseconds);
		}
		if(class_exists('\dataphyre\async', false) && method_exists('\dataphyre\async', 'set_interval')){
			return (int)\dataphyre\async::set_interval($task, $milliseconds);
		}
		return false;
	}

	/**
	 * Cancels a scheduled async task when supported.
	 *
	 * Namespaced async is preferred, then the legacy cancel API. Missing async support makes cancellation a no-op.
	 *
	 * @param int $taskId Identifier returned by asyncAfter() or asyncEvery().
	 * @return void.
	 */
	public static function asyncCancel(int $taskId): void {
		if(class_exists('\Dataphyre\Async\Async', false)){
			\Dataphyre\Async\Async::cancel($taskId);
			return;
		}
		if(class_exists('\dataphyre\async', false) && method_exists('\dataphyre\async', 'cancel')){
			\dataphyre\async::cancel($taskId);
		}
	}

	/**
	 * Delegates the `reactor available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function reactorAvailable(): bool {
		return class_exists('\Dataphyre\Reactor\Reactor', false);
	}

	/**
	 * Renders an initial Reactor component mount.
	 *
	 * Missing Reactor support or mount API returns an empty string instead of failing controller rendering.
	 *
	 * @param string $component Reactor component name.
	 * @param array<string, mixed> $state Initial Reactor component state.
	 * @param array<string, mixed> $attributes Render attributes passed to Reactor.
	 * @return string.
	 */
	public static function reactorMount(string $component, array $state=[], array $attributes=[]): string {
		if(!self::reactorAvailable() || !method_exists('\Dataphyre\Reactor\Reactor', 'mount')){
			return '';
		}
		return \Dataphyre\Reactor\Reactor::mount($component, $state, $attributes);
	}

	/**
	 * Delegates the `reactor dispatch` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param array|object|null $request HTTP request being handled.
	 * @return Response.
	 */
	public static function reactorDispatch(array|object|null $request=null): Response {
		if(class_exists('\Dataphyre\Reactor\ReactorEndpoint', false) && method_exists('\Dataphyre\Reactor\ReactorEndpoint', 'handle')){
			return self::reactorResponse(\Dataphyre\Reactor\ReactorEndpoint::handle($request));
		}
		if(self::reactorAvailable() && method_exists('\Dataphyre\Reactor\Reactor', 'dispatch')){
			return self::reactorResponse(\Dataphyre\Reactor\Reactor::dispatch($request));
		}
		return Response::json([
			'status'=>503,
			'ok'=>false,
			'html'=>'',
			'state'=>[],
			'effects'=>[],
			'message'=>'Reactor module is unavailable.',
		], 503, ['X-Dataphyre-Reactor'=>'1']);
	}

	/**
	 * Delegates the `reactor batch` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param ?array<int, array<string, mixed>> $requests Reactor request payloads.
	 * @return Response.
	 */
	public static function reactorBatch(?array $requests=null): Response {
		if(class_exists('\Dataphyre\Reactor\ReactorEndpoint', false) && method_exists('\Dataphyre\Reactor\ReactorEndpoint', 'handleBatch')){
			$batch=\Dataphyre\Reactor\ReactorEndpoint::handleBatch($requests);
			$status=200;
			foreach($batch as $response){
				$itemStatus=(int)($response['status'] ?? 500);
				if($itemStatus>=400){
					$status=max($status, min(599, $itemStatus));
				}
			}
			return Response::json([
				'status'=>$status,
				'ok'=>$status<400,
				'batch'=>$batch,
				'message'=>$status<400 ? '' : 'One or more Reactor requests failed.',
			], $status, ['X-Dataphyre-Reactor'=>'1', 'X-Dataphyre-Reactor-Batch'=>'1']);
		}
		return Response::json([
			'status'=>503,
			'ok'=>false,
			'batch'=>[],
			'message'=>'Reactor module is unavailable.',
		], 503, ['X-Dataphyre-Reactor'=>'1', 'X-Dataphyre-Reactor-Batch'=>'1']);
	}

	/**
	 * Delegates the `reactor manifest` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return array.
	 */
	public static function reactorManifest(): array {
		if(self::reactorAvailable() && method_exists('\Dataphyre\Reactor\Reactor', 'manifest')){
			$manifest=\Dataphyre\Reactor\Reactor::manifest();
			return is_array($manifest) ? $manifest : [];
		}
		return [];
	}

	/**
	 * Delegates the `file` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param string $path Storage object path.
	 * @param ?string $name Name.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return Response.
	 */
	public static function file(string $path, ?string $name=null, array $headers=[]): Response {
		return Response::file($path, $name, $headers);
	}

	/**
	 * Delegates the `download` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param string $path Storage object path.
	 * @param ?string $name Name.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return Response.
	 */
	public static function download(string $path, ?string $name=null, array $headers=[]): Response {
		return Response::download($path, $name, $headers);
	}

	/**
	 * Delegates the `storage available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function storageAvailable(): bool {
		return class_exists('\dataphyre\storage', false) || class_exists('\Dataphyre\Storage\Storage', false);
	}

	/**
	 * Delegates the `storage file` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param string $path Storage object path.
	 * @param ?string $disk Disk.
	 * @param ?string $name Name.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return Response.
	 */
	public static function storageFile(string $path, ?string $disk=null, ?string $name=null, array $headers=[]): Response {
		return self::storageResponse($path, $disk, $name, false, $headers);
	}

	/**
	 * Delegates the `storage download` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param string $path Storage object path.
	 * @param ?string $disk Disk.
	 * @param ?string $name Name.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return Response.
	 */
	public static function storageDownload(string $path, ?string $disk=null, ?string $name=null, array $headers=[]): Response {
		return self::storageResponse($path, $disk, $name, true, $headers);
	}

	/**
	 * Works with MVC routes, URLs, and signed route security.
	 *
	 * Route helpers resolve named routes, build parameterized URLs, and validate request signatures against application signing configuration.
	 *
	 * @param string $path Storage object path.
	 * @param int|\DateTimeInterface $expires Expires.
	 * @param ?string $disk Disk.
	 * @param array<string, mixed> $options Temporary URL options passed to the storage module.
	 * @return string|false.
	 */
	public static function storageTemporaryUrl(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		if(class_exists('\dataphyre\storage', false) && method_exists('\dataphyre\storage', 'temporary_url')){
			return \dataphyre\storage::temporary_url($path, $expires, $disk, $options);
		}
		if(class_exists('\Dataphyre\Storage\Storage', false)){
			return \Dataphyre\Storage\Storage::temporaryUrl($path, $expires, $disk, $options);
		}
		return false;
	}

	/**
	 * Delegates the `mailer available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function mailerAvailable(): bool {
		return class_exists('\dataphyre\mailer', false) || class_exists('\Dataphyre\Mailer\Mailer', false);
	}

	/**
	 * Delegates the `send mail` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param array<string, mixed> $message Mail message payload.
	 * @param ?string $provider Provider.
	 * @param array<string, mixed> $options Mail delivery options.
	 * @return array.
	 */
	public static function sendMail(array $message, ?string $provider=null, array $options=[]): array {
		if(class_exists('\dataphyre\mailer', false) && method_exists('\dataphyre\mailer', 'send')){
			$result=\dataphyre\mailer::send($message, $provider, $options);
			return is_array($result) ? $result : self::mailerResultToArray($result);
		}
		if(class_exists('\Dataphyre\Mailer\Mailer', false)){
			return self::mailerResultToArray(\Dataphyre\Mailer\Mailer::send($message, $provider, $options));
		}
		return [
			'ok'=>false,
			'provider'=>$provider,
			'status'=>500,
			'message'=>'Mailer module is unavailable.',
		];
	}

	/**
	 * Delegates the `queue mail` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param array<string, mixed> $message Mail message payload.
	 * @param ?string $provider Provider.
	 * @param array<string, mixed> $options Mail queue options.
	 * @return array.
	 */
	public static function queueMail(array $message, ?string $provider=null, array $options=[]): array {
		if(class_exists('\dataphyre\mailer', false) && method_exists('\dataphyre\mailer', 'queue')){
			$result=\dataphyre\mailer::queue($message, $provider, $options);
			return is_array($result) ? $result : self::mailerResultToArray($result);
		}
		if(class_exists('\Dataphyre\Mailer\Mailer', false)){
			return self::mailerResultToArray(\Dataphyre\Mailer\Mailer::queue($message, $provider, $options));
		}
		return [
			'ok'=>false,
			'provider'=>$provider,
			'status'=>500,
			'message'=>'Mailer module is unavailable.',
		];
	}

	/**
	 * Renders MVC views and template assets.
	 *
	 * Template helpers bridge the MVC layer to Dataphyre Templating when available and return normalized content or asset payloads.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string, mixed> $data Mail template data.
	 * @param array<string, mixed> $options Mail render options.
	 * @return array.
	 */
	public static function renderMail(string $template, array $data=[], array $options=[]): array {
		if(class_exists('\dataphyre\mailer', false) && method_exists('\dataphyre\mailer', 'render')){
			$result=\dataphyre\mailer::render($template, $data, $options);
			return is_array($result) ? $result : [];
		}
		if(class_exists('\Dataphyre\Mailer\Mailer', false)){
			$result=\Dataphyre\Mailer\Mailer::render($template, $data, $options);
			return is_array($result) ? $result : [];
		}
		return [
			'subject'=>'',
			'html'=>'',
			'text'=>'',
		];
	}

	/**
	 * Builds normalized HTTP responses for MVC handlers.
	 *
	 * Response helpers return typed `Response` or result objects that the dispatcher can emit consistently.
	 *
	 * @param int $status HTTP status code for the exception response.
	 * @param string $message Optional response message.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return never.
	 */
	public static function abort(int $status, string $message='', array $headers=[]): never {
		throw new HttpException($status, $message, $headers);
	}

	/**
	 * Builds normalized HTTP responses for MVC handlers.
	 *
	 * Response helpers return typed `Response` or result objects that the dispatcher can emit consistently.
	 *
	 * @param bool $condition Guard result that triggers the abort when true.
	 * @param int $status HTTP status code for the exception response.
	 * @param string $message Optional response message.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return void.
	 */
	public static function abortIf(bool $condition, int $status, string $message='', array $headers=[]): void {
		if($condition){
			self::abort($status, $message, $headers);
		}
	}

	/**
	 * Builds normalized HTTP responses for MVC handlers.
	 *
	 * Response helpers return typed `Response` or result objects that the dispatcher can emit consistently.
	 *
	 * @param bool $condition Guard result that allows the request when true.
	 * @param int $status HTTP status code for the exception response.
	 * @param string $message Optional response message.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return void.
	 */
	public static function abortUnless(bool $condition, int $status, string $message='', array $headers=[]): void {
		if(!$condition){
			self::abort($status, $message, $headers);
		}
	}

	/**
	 * Delegates the `access available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function accessAvailable(): bool {
		return class_exists('\dataphyre\access', false);
	}

	/**
	 * Delegates the `auth context` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param ?string $authType AuthType.
	 * @return array.
	 */
	public static function authContext(?string $authType=null): array {
		if(!self::accessAvailable() || !method_exists('\dataphyre\access', 'auth_context')){
			return [
				'auth_type'=>$authType,
				'logged_in'=>false,
				'userid'=>false,
			];
		}
		return \dataphyre\access::auth_context($authType);
	}

	/**
	 * Delegates the `logged in` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param ?string $authType AuthType.
	 * @return bool.
	 */
	public static function loggedIn(?string $authType=null): bool {
		return self::accessAvailable()
			&& method_exists('\dataphyre\access', 'logged_in')
			&& \dataphyre\access::logged_in($authType)===true;
	}

	/**
	 * Delegates the `user id` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param ?string $authType AuthType.
	 * @return bool|int|string.
	 */
	public static function userId(?string $authType=null): bool|int|string {
		if(!self::accessAvailable() || !method_exists('\dataphyre\access', 'userid')){
			return false;
		}
		return \dataphyre\access::userid($authType);
	}

	/**
	 * Delegates the `permission available` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 * @return bool.
	 */
	public static function permissionAvailable(): bool {
		return class_exists('\dataphyre\permission', false) || class_exists('\Dataphyre\Permission\Permission', false);
	}

	/**
	 * Delegates the `can` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param mixed $requiredPermission Permission name, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string, mixed> $context MVC route or validation context.
	 * @return bool.
	 */
	public static function can(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		if(class_exists('\dataphyre\permission', false) && method_exists('\dataphyre\permission', 'check')){
			return \dataphyre\permission::check($requiredPermission, $subject, $context)===true;
		}
		if(class_exists('\Dataphyre\Permission\Permission', false)){
			return \Dataphyre\Permission\Permission::check($requiredPermission, $subject, $context)===true;
		}
		return false;
	}

	/**
	 * Delegates the `can any` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param mixed $requiredPermission Permission name, list, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string, mixed> $context MVC route or validation context.
	 * @return bool.
	 */
	public static function canAny(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		if(class_exists('\dataphyre\permission', false) && method_exists('\dataphyre\permission', 'any')){
			return \dataphyre\permission::any($requiredPermission, $subject, $context)===true;
		}
		if(class_exists('\Dataphyre\Permission\Permission', false)){
			return \Dataphyre\Permission\Permission::any($requiredPermission, $subject, $context)===true;
		}
		return false;
	}

	/**
	 * Delegates the `authorize` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param mixed $requiredPermission Permission name, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string, mixed> $context MVC route or validation context.
	 * @return bool.
	 */
	public static function authorize(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		if(self::can($requiredPermission, $subject, $context)){
			return true;
		}
		throw new HttpException(403, 'Permission denied.');
	}

	/**
	 * Delegates the `authorize any` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param mixed $requiredPermission Permission name, list, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string, mixed> $context MVC route or validation context.
	 * @return bool.
	 */
	public static function authorizeAny(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		if(self::canAny($requiredPermission, $subject, $context)){
			return true;
		}
		throw new HttpException(403, 'Permission denied.');
	}

	/**
	 * Builds normalized HTTP responses for MVC handlers.
	 *
	 * Response helpers return typed `Response` or result objects that the dispatcher can emit consistently.
	 *
	 * @param string $location Absolute or relative redirect target.
	 * @param int $status HTTP redirect status code.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @return RedirectResult.
	 */
	public static function redirect(string $location, int $status=302, array $headers=[]): RedirectResult {
		return new RedirectResult($location, $status, $headers);
	}

	/**
	 * Builds normalized HTTP responses for MVC handlers.
	 *
	 * Response helpers return typed `Response` or result objects that the dispatcher can emit consistently.
	 *
	 * @param string $name Route name to resolve.
	 * @param array<string, mixed> $parameters Named route parameters for path interpolation.
	 * @param array<string, mixed> $query Query-string parameters appended to the generated URL.
	 * @param int $status HTTP redirect status code.
	 * @param array<string, string|array<int, string>> $headers Response headers.
	 * @param ?string $app MVC application name.
	 * @return RedirectResult.
	 */
	public static function redirectToRoute(
		string $name,
		array $parameters=[],
		array $query=[],
		int $status=302,
		array $headers=[],
		?string $app=null
	): RedirectResult {
		return self::redirect(self::url($name, $parameters, $query, $app), $status, $headers);
	}

	/**
	 * Delegates the `dispatch` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param ?Request $request HTTP request being handled.
	 * @param ?string $app MVC application name.
	 * @return Response.
	 */
	public static function dispatch(?Request $request=null, ?string $app=null): Response {
		return self::manager()->dispatch($request, $app);
	}

	/**
	 * Delegates the `host` helper to the MVC runtime.
	 *
	 * Runtime availability and helper behavior are resolved through loaded Dataphyre modules.
	 *
	 * @param ?string $app MVC application name.
	 * @return MvcHost.
	 */
	public static function host(?string $app=null): MvcHost {
		return new MvcHost(self::manager(), $app);
	}

	/**
	 * Builds an HTTP response for a stored file.
	 *
	 * this adapter bridges optional Storage modules into the MVC response
	 * contract. It reads the file body, derives metadata-backed headers, falls back
	 * to filename and MIME guesses when needed, and throws a 404 HttpException when
	 * the file cannot be read.
	 */
	private static function storageResponse(string $path, ?string $disk, ?string $name, bool $attachment, array $headers): Response {
		$body=self::storageGet($path, $disk);
		if(!is_string($body)){
			throw new HttpException(404, 'Stored file not found.');
		}
		$metadata=self::storageMetadata($path, $disk);
		$filename=$name!==null && trim($name)!=='' ? trim($name) : basename(str_replace('\\', '/', $path));
		$defaults=[
			'Content-Type'=>self::storageMimeType($path, $metadata),
			'Content-Length'=>(string)strlen($body),
			'Content-Disposition'=>self::contentDisposition($attachment ? 'attachment' : 'inline', $filename),
		];
		if(isset($metadata['modified_at']) && is_numeric($metadata['modified_at'])){
			$defaults['Last-Modified']=gmdate('D, d M Y H:i:s', (int)$metadata['modified_at']).' GMT';
		}
		return Response::make($body, 200, array_replace($defaults, $headers));
	}

	/**
	 * Reads a stored file through the available Storage implementation.
	 *
	 * legacy kernel storage and Framework Storage are both supported.
	 * Missing modules or unreadable files return false so storageResponse can turn
	 * the absence into the MVC HTTP error boundary.
	 */
	private static function storageGet(string $path, ?string $disk): string|false {
		if(class_exists('\dataphyre\storage', false) && method_exists('\dataphyre\storage', 'get')){
			return \dataphyre\storage::get($path, $disk);
		}
		if(class_exists('\Dataphyre\Storage\Storage', false)){
			return \Dataphyre\Storage\Storage::get($path, $disk);
		}
		return false;
	}

	/**
	 * Reads storage metadata as a normalized array.
	 *
	 * metadata can come from the legacy kernel as an array or from the
	 * Framework storage object via toArray. Unknown modules, missing metadata, and
	 * non-array payloads collapse to an empty metadata set.
	 */
	private static function storageMetadata(string $path, ?string $disk): array {
		if(class_exists('\dataphyre\storage', false) && method_exists('\dataphyre\storage', 'metadata')){
			$metadata=\dataphyre\storage::metadata($path, $disk);
			return is_array($metadata) ? $metadata : [];
		}
		if(class_exists('\Dataphyre\Storage\Storage', false)){
			$metadata=\Dataphyre\Storage\Storage::metadata($path, $disk);
			return is_object($metadata) && method_exists($metadata, 'toArray') ? $metadata->toArray() : [];
		}
		return [];
	}

	/**
	 * Resolves a response MIME type from storage metadata or file extension.
	 *
	 * explicit storage metadata wins; otherwise common web asset
	 * extensions map to stable content types and unknown files fall back to
	 * application/octet-stream.
	 */
	private static function storageMimeType(string $path, array $metadata): string {
		$mime=(string)($metadata['mime_type'] ?? '');
		if($mime!==''){
			return $mime;
		}
		return match(strtolower((string)pathinfo($path, PATHINFO_EXTENSION))){
			'css'=>'text/css; charset=utf-8',
			'csv'=>'text/csv; charset=utf-8',
			'gif'=>'image/gif',
			'htm', 'html'=>'text/html; charset=utf-8',
			'jpg', 'jpeg'=>'image/jpeg',
			'js'=>'application/javascript; charset=utf-8',
			'json'=>'application/json; charset=utf-8',
			'pdf'=>'application/pdf',
			'png'=>'image/png',
			'svg'=>'image/svg+xml',
			'txt'=>'text/plain; charset=utf-8',
			'webp'=>'image/webp',
			default=>'application/octet-stream',
		};
	}

	/**
	 * Builds a safe Content-Disposition header value.
	 *
	 * filenames are represented in both ASCII-safe and RFC 5987 UTF-8
	 * forms so downloads remain compatible while preserving the caller's original
	 * display name when clients support it.
	 */
	private static function contentDisposition(string $disposition, string $filename): string {
		$ascii=preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'download';
		$ascii=str_replace(['\\', '"'], ['_', '\\"'], $ascii);
		return $disposition.'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);
	}

	/**
	 * Converts mailer result objects into array payloads.
	 *
	 * MVC mail helpers accept legacy arrays, Framework result objects, and
	 * JsonSerializable values. Non-array or unrecognized payloads become an empty
	 * array so callers always receive a predictable response shape.
	 */
	private static function mailerResultToArray(mixed $result): array {
		if(is_array($result)){
			return $result;
		}
		if(is_object($result) && method_exists($result, 'toArray')){
			$array=$result->toArray();
			return is_array($array) ? $array : [];
		}
		if($result instanceof \JsonSerializable){
			$json=$result->jsonSerialize();
			return is_array($json) ? $json : [];
		}
		return [];
	}

	/**
	 * Renders template asset manifest HTML for a requested section.
	 *
	 * manifest HTML strings take precedence over tag arrays, and section
	 * aliases map to head/body/all keys so templating integrations can return
	 * either pre-rendered markup or structured tag lists.
	 */
	private static function assetHtml(array $manifest, string $section): string {
		$section=strtolower(trim($section));
		$key=match($section){
			'head'=>'head_html',
			'body'=>'body_html',
			default=>'html',
		};
		if(isset($manifest[$key]) && is_string($manifest[$key])){
			return $manifest[$key];
		}
		$listKey=match($section){
			'head'=>'head_tags',
			'body'=>'body_tags',
			default=>'all_tags',
		};
		$tags=$manifest[$listKey] ?? [];
		return is_array($tags) ? implode("\n", array_map('strval', $tags)) : '';
	}

	/**
	 * Normalizes validation and sanitation input data.
	 *
	 * Request objects merge query, body input, files, and route parameters
	 * with later sources overriding earlier keys, while array inputs pass through
	 * unchanged for tests and programmatic callers.
	 */
	private static function requestData(Request|array $request): array {
		return $request instanceof Request
			? array_replace($request->query(), $request->input(), $request->files(), $request->routeParameters())
			: $request;
	}

	/**
	 * Converts Reactor-style results into MVC JSON responses.
	 *
	 * arrays and JsonSerializable results become the response payload,
	 * status is taken from a status() method or payload status and clamped to HTTP
	 * bounds, and a Reactor marker header identifies the integration surface.
	 */
	private static function reactorResponse(mixed $response): Response {
		$payload=$response instanceof \JsonSerializable ? $response->jsonSerialize() : (is_array($response) ? $response : []);
		$status=method_exists($response, 'status') ? (int)$response->status() : (int)($payload['status'] ?? 200);
		$status=max(100, min(599, $status));
		return Response::json($payload, $status, ['X-Dataphyre-Reactor'=>'1']);
	}

	/**
	 * Interpolates route and template parameter placeholders.
	 *
	 * both legacy <{name}> tokens and colon-style :name tokens are
	 * replaced with string-cast parameter values. Missing parameter arrays leave
	 * the input unchanged.
	 */
	private static function interpolate(string $value, ?array $parameters=null): string {
		foreach($parameters ?? [] as $key=>$replacement){
			$value=str_replace(['<{'.$key.'}>', ':'.$key], (string)$replacement, $value);
		}
		return $value;
	}
}
