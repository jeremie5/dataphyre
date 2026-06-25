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
 * Base controller helper surface for Dataphyre MVC actions.
 *
 * Controllers receive route context and expose helpers for middleware, views, responses, validation, sanitation, translation, redirects, and request input handling.
 */
abstract class Controller {

	private ?MvcRouteContext $mvcRouteContext=null;
	private array $mvcControllerMiddleware=[];

	/**
	 * Stores the active MVC route context on the controller.
	 *
	 * The dispatcher injects route context so controller helpers can resolve route names, parameters, and current application metadata.
	 *
	 * @param ?MvcRouteContext $context MVC route or validation context.
	 * @return void.
	 */
	public function setMvcRouteContext(?MvcRouteContext $context): void {
		$this->mvcRouteContext=$context;
	}

	/**
	 * Returns registered controller middleware after optional action filtering.
	 *
	 * When no method is supplied, the full registered middleware payload list is returned. When an action method is supplied, only/except constraints are applied using lowercase action names before dispatch builds the controller middleware stack.
	 *
	 * @param ?string $method Optional controller action method used for constraint filtering.
	 * @return list<mixed> Middleware definitions selected for the action.
	 */
	public function mvcControllerMiddleware(?string $method=null): array {
		if($method===null){
			$middleware=[];
			foreach($this->mvcControllerMiddleware as $entry){
				$middleware[]=$entry['middleware'];
			}
			return $middleware;
		}
		$method=strtolower($method);
		$selected=[];
		foreach($this->mvcControllerMiddleware as $entry){
			$only=$entry['only'];
			$except=$entry['except'];
			if($only!==[] && !in_array($method, $only, true)){
				continue;
			}
			if($except!==[] && in_array($method, $except, true)){
				continue;
			}
			$selected[]=$entry['middleware'];
		}
		return $selected;
	}

	/**
	 * Registers or resolves controller middleware.
	 *
	 * Middleware definitions are stored in registration order. The returned registration object can later add only/except action constraints to the entries created by this call.
	 *
	 * @param array|string|callable ...$middleware Middleware definition list.
	 * @return ControllerMiddlewareRegistration.
	 */
	protected function middleware(array|string|callable ...$middleware): ControllerMiddlewareRegistration {
		$indexes=[];
		foreach($middleware as $definition){
			if(is_array($definition) && array_is_list($definition)){
				foreach($definition as $item){
					$indexes[]=$this->addControllerMiddleware($item);
				}
				continue;
			}
			$indexes[]=$this->addControllerMiddleware($definition);
		}
		return new ControllerMiddlewareRegistration($this->mvcControllerMiddleware, $indexes);
	}

	/**
	 * Stores one middleware definition and returns its mutable registration index.
	 *
	 * Entries keep the original middleware payload plus empty only/except
	 * constraints. ControllerMiddlewareRegistration later mutates those constraint
	 * arrays by index, so insertion order and array keys are part of the lifecycle
	 * contract between middleware() and the fluent constraint object.
	 *
	 * @param mixed $definition Middleware class, callable, array definition, or framework-supported token.
	 * @return int Index of the newly stored middleware entry.
	 */
	private function addControllerMiddleware(mixed $definition): int {
		$this->mvcControllerMiddleware[]=[
			'middleware'=>$definition,
			'only'=>[],
			'except'=>[],
		];
		return array_key_last($this->mvcControllerMiddleware);
	}

	/**
	 * Creates a deferred view result for a controller action.
	 *
	 * The template and data are stored until the MVC response layer renders the view, allowing middleware and response normalization to keep their normal lifecycle.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string,mixed> $data Template variables.
	 * @return ViewResult.
	 */
	protected function view(string $template, array $data=[]): ViewResult {
		return ViewResult::make($template, $data);
	}

	/**
	 * Renders a template path through the MVC templating bridge.
	 *
	 * Theme values and slots are forwarded without mutation so controller actions can override layout context for this render only.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string,mixed> $data Template variables.
	 * @param array<string,mixed> $themeValues Theme override values.
	 * @param array<string,mixed> $slots Named slot content.
	 * @return string.
	 */
	protected function renderTemplate(string $template, array $data=[], array $themeValues=[], array $slots=[]): string {
		return Mvc::renderTemplate($template, $data, $themeValues, $slots);
	}

	/**
	 * Renders an inline template string through the MVC templating bridge.
	 *
	 * The template name is used only for diagnostics, asset extraction, and cache identity; the source itself is passed directly to the renderer.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string,mixed> $data Template variables.
	 * @param array<string,mixed> $themeValues Theme override values.
	 * @param array<string,mixed> $slots Named slot content.
	 * @param string $templateName Diagnostic/cache name for the inline template.
	 * @return string.
	 */
	protected function renderTemplateString(string $template, array $data=[], array $themeValues=[], array $slots=[], string $templateName='inline.tpl'): string {
		return Mvc::renderTemplateString($template, $data, $themeValues, $slots, $templateName);
	}

	/**
	 * Extracts declared assets from a template path.
	 *
	 * The returned manifest is grouped by asset section and can be rendered separately from the template body.
	 *
	 * @param string $template Template path or inline template source.
	 * @return array<string,mixed> Asset manifest grouped by section.
	 */
	protected function templateAssets(string $template): array {
		return Mvc::templateAssets($template);
	}

	/**
	 * Extracts declared assets from an inline template string.
	 *
	 * The template name identifies the inline source for diagnostics and asset cache keys.
	 *
	 * @param string $template Template path or inline template source.
	 * @param string $templateName Diagnostic/cache name for the inline template.
	 * @return array<string,mixed> Asset manifest grouped by section.
	 */
	protected function templateStringAssets(string $template, string $templateName='inline.tpl'): array {
		return Mvc::templateStringAssets($template, $templateName);
	}

	/**
	 * Renders asset HTML for a template path.
	 *
	 * Section selection is delegated to the MVC asset bridge; `all` asks the bridge for every rendered asset section.
	 *
	 * @param string $template Template path or inline template source.
	 * @param string $section Asset section to render.
	 * @return string.
	 */
	protected function templateAssetHtml(string $template, string $section='all'): string {
		return Mvc::templateAssetHtml($template, $section);
	}

	/**
	 * Renders asset HTML for an inline template string.
	 *
	 * Section selection is delegated to the MVC asset bridge, and the template name identifies the inline source for diagnostics and cache keys.
	 *
	 * @param string $template Template path or inline template source.
	 * @param string $section Asset section to render.
	 * @param string $templateName Diagnostic/cache name for the inline template.
	 * @return string.
	 */
	protected function templateStringAssetHtml(string $template, string $section='all', string $templateName='inline.tpl'): string {
		return Mvc::templateStringAssetHtml($template, $section, $templateName);
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Response helpers keep action methods focused on payloads while preserving status, headers, redirects, and exception behavior.
	 *
	 * @param array|\JsonSerializable $payload Response payload.
	 * @param int $status HTTP status code for the JSON response.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return Response.
	 */
	protected function json(array|\JsonSerializable $payload, int $status=200, array $headers=[]): Response {
		return Response::json($payload, $status, $headers);
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Response helpers keep action methods focused on payloads while preserving status, headers, redirects, and exception behavior.
	 *
	 * @param array|\JsonSerializable $payload Response payload.
	 * @param ?string $location Location.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return Response.
	 */
	protected function created(array|\JsonSerializable $payload, ?string $location=null, array $headers=[]): Response {
		return Response::created($payload, $location, $headers);
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Response helpers keep action methods focused on payloads while preserving status, headers, redirects, and exception behavior.
	 * @return Response.
	 */
	protected function noContent(): Response {
		return Response::noContent();
	}

	/**
	 * Validates or sanitizes controller request input.
	 *
	 * The MVC validator receives the Request object and returns the validated payload shape expected by controller actions. Validation failures are handled by the MVC validation service rather than by this helper.
	 *
	 * @param Request $request HTTP request being handled.
	 * @param array<string,mixed> $rules Field validation rules.
	 * @param array<string,string|list<string>> $messages Rule-keyed validation messages.
	 * @param array<string,string> $attributes Human-readable field labels.
	 * @return array<string,mixed> Validated request data.
	 */
	protected function validate(Request $request, array $rules, array $messages=[], array $attributes=[]): array {
		return Mvc::validate($request, $rules, $messages, $attributes);
	}

	/**
	 * Anonymizes an email address through the MVC sanitation bridge.
	 *
	 * The helper delegates to MVC sanitation utilities so controllers use the same email masking rules as views and background handlers.
	 *
	 * @param string $email Email address to anonymize.
	 * @param int $count Number of visible leading characters to keep.
	 * @param string $char Replacement character used for hidden characters.
	 * @return string.
	 */
	protected function anonymizeEmail(string $email, int $count=2, string $char='*'): string {
		return Mvc::anonymizeEmail($email, $count, $char);
	}

	/**
	 * Sanitizes one value with an explicit rule or rule set.
	 *
	 * The value is passed through MVC sanitation without reading request state, making it suitable for controller-local normalization before persistence or rendering.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param string|array $rule Rule.
	 * @param array<string,mixed> $options Sanitation options.
	 * @return mixed.
	 */
	protected function sanitize(mixed $value, string|array $rule='default', array $options=[]): mixed {
		return Mvc::sanitize($value, $rule, $options);
	}

	/**
	 * Alias for sanitize() using the MVC clean helper.
	 *
	 * This preserves the MVC runtime's clean/sanitize distinction while keeping controller usage concise.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param string|array $rule Rule.
	 * @param array<string,mixed> $options Sanitation options.
	 * @return mixed.
	 */
	protected function clean(mixed $value, string|array $rule='default', array $options=[]): mixed {
		return Mvc::clean($value, $rule, $options);
	}

	/**
	 * Returns the MVC sanitizer wrapper for one value.
	 *
	 * The returned value follows the sanitizer service's fluent or scalar behavior, depending on the sanitation module configuration.
	 *
	 * @param mixed $value Value to wrap for sanitation.
	 * @return mixed.
	 */
	protected function sanitizer(mixed $value): mixed {
		return Mvc::sanitizer($value);
	}

	/**
	 * Extracts the input bag used by sanitation helpers.
	 *
	 * Request objects and raw arrays are normalized through the MVC facade before schema sanitation reads field values.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @return mixed.
	 */
	protected function inputBag(Request|array $request): mixed {
		return Mvc::inputBag($request);
	}

	/**
	 * Sanitizes request input against a field schema.
	 *
	 * Defaults are merged by the MVC sanitation layer before or during rule application, preserving the runtime's configured missing-field behavior.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string,mixed> $schema Field sanitation schema.
	 * @param array<string,mixed> $defaults Default values merged into sanitized data.
	 * @param array<string,mixed> $options Sanitation options.
	 * @return mixed.
	 */
	protected function sanitizeSchema(Request|array $request, array $schema, array $defaults=[], array $options=[]): mixed {
		return Mvc::sanitizeSchema($request, $schema, $defaults, $options);
	}

	/**
	 * Sanitizes request input and returns the normalized array payload.
	 *
	 * This helper is the array-returning companion to sanitizeSchema() for controller actions that expect a concrete sanitized data shape.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string,mixed> $schema Field sanitation schema.
	 * @param array<string,mixed> $defaults Default values merged into sanitized data.
	 * @param array<string,mixed> $options Sanitation options.
	 * @return array<string,mixed> Sanitized request data.
	 */
	protected function sanitized(Request|array $request, array $schema, array $defaults=[], array $options=[]): array {
		return Mvc::sanitized($request, $schema, $defaults, $options);
	}

	/**
	 * Sanitizes request input or fails through the MVC sanitation service.
	 *
	 * Failure behavior and response/exception shape are owned by the MVC facade, keeping controller actions out of sanitation error formatting.
	 *
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string,mixed> $schema Field sanitation schema.
	 * @param array<string,mixed> $defaults Default values merged into sanitized data.
	 * @param array<string,mixed> $options Sanitation options.
	 * @param ?string $message Optional failure message.
	 * @return array<string,mixed> Sanitized request data.
	 */
	protected function sanitizedOrFail(Request|array $request, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
		return Mvc::sanitizedOrFail($request, $schema, $defaults, $options, $message);
	}

	/**
	 * Sanitizes request input with a named schema preset.
	 *
	 * Preset overrides are applied by the MVC sanitation layer so shared input schemas can be adjusted per controller action.
	 *
	 * @param string $name Named sanitation preset.
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string,mixed> $presetOverrides Schema overrides applied to the named preset.
	 * @param array<string,mixed> $defaults Default values merged into sanitized data.
	 * @param array<string,mixed> $options Sanitation options.
	 * @return mixed.
	 */
	protected function sanitizePreset(string $name, Request|array $request, array $presetOverrides=[], array $defaults=[], array $options=[]): mixed {
		return Mvc::sanitizePreset($name, $request, $presetOverrides, $defaults, $options);
	}

	/**
	 * Sanitizes request input with a named preset and returns an array payload.
	 *
	 * This helper is the array-returning companion to sanitizePreset().
	 *
	 * @param string $name Named sanitation preset.
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string,mixed> $presetOverrides Schema overrides applied to the named preset.
	 * @param array<string,mixed> $defaults Default values merged into sanitized data.
	 * @param array<string,mixed> $options Sanitation options.
	 * @return array<string,mixed> Sanitized request data.
	 */
	protected function sanitizedPreset(string $name, Request|array $request, array $presetOverrides=[], array $defaults=[], array $options=[]): array {
		return Mvc::sanitizedPreset($name, $request, $presetOverrides, $defaults, $options);
	}

	/**
	 * Sanitizes request input with a named preset or fails through MVC.
	 *
	 * Failure behavior and response/exception shape are owned by the MVC facade, preserving a single sanitation failure contract for controllers.
	 *
	 * @param string $name Named sanitation preset.
	 * @param Request|array $request HTTP request being handled.
	 * @param array<string,mixed> $presetOverrides Schema overrides applied to the named preset.
	 * @param array<string,mixed> $defaults Default values merged into sanitized data.
	 * @param array<string,mixed> $options Sanitation options.
	 * @param ?string $message Optional failure message.
	 * @return array<string,mixed> Sanitized request data.
	 */
	protected function sanitizedPresetOrFail(string $name, Request|array $request, array $presetOverrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
		return Mvc::sanitizedPresetOrFail($name, $request, $presetOverrides, $defaults, $options, $message);
	}

	/**
	 * Translates controller-facing text through Dataphyre localization.
	 *
	 * Translation helpers pass route/page/theme context through to the localization module when available.
	 *
	 * @param string $key Translation key.
	 * @param ?string $fallback Fallback text when the key cannot be resolved.
	 * @param ?array $parameters Route parameters.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return string.
	 */
	protected function translate(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		return Mvc::translate($key, $fallback, $parameters, $language, $page, $theme);
	}

	/**
	 * Translates controller-facing text through Dataphyre localization.
	 *
	 * Translation helpers pass route/page/theme context through to the localization module when available.
	 *
	 * @param string $key Translation key.
	 * @param ?array $parameters Route parameters.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return ?string.
	 */
	protected function translateOrNull(
		string $key,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): ?string {
		return Mvc::translateOrNull($key, $parameters, $language, $page, $theme);
	}

	/**
	 * Checks whether localization has a translation for a key.
	 *
	 * The lookup is delegated to the MVC localization bridge using optional language, page, and theme context.
	 *
	 * @param string $key Translation key to test.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return bool.
	 */
	protected function translationHas(string $key, ?string $language=null, ?string $page=null, ?string $theme=null): bool {
		return Mvc::translationHas($key, $language, $page, $theme);
	}

	/**
	 * Checks whether localization is missing a translation for a key.
	 *
	 * This is the inverse convenience wrapper around translationHas().
	 *
	 * @param string $key Translation key to test.
	 * @param ?string $language Language.
	 * @param ?string $page Page.
	 * @param ?string $theme Theme.
	 * @return bool.
	 */
	protected function translationMissing(string $key, ?string $language=null, ?string $page=null, ?string $theme=null): bool {
		return Mvc::translationMissing($key, $language, $page, $theme);
	}

	/**
	 * Chooses a pluralized translation variant through localization.
	 *
	 * Choice resolution selects zero, singular, or plural translation keys through the MVC localization bridge.
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
	protected function choice(
		int|float $count,
		string $oneKey,
		string $manyKey,
		?string $zeroKey=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		return Mvc::choice($count, $oneKey, $manyKey, $zeroKey, $parameters, $language, $page, $theme);
	}

	/**
	 * Formats a numeric amount through the MVC currency bridge.
	 *
	 * Formatting is delegated to the MVC currency bridge so controller responses and views use the same free/zero and display-currency rules.
	 *
	 * @param float|int|null $amount Amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @param ?string $currency Currency.
	 * @return string.
	 */
	protected function moneyFormat(float|int|null $amount, bool $showFree=false, ?string $currency=null): string {
		return Mvc::moneyFormat($amount, $showFree, $currency);
	}

	/**
	 * Converts an amount between currencies through the MVC currency bridge.
	 *
	 * Conversion and optional formatting are delegated to the MVC currency bridge, including exchange-rate and display rounding behavior.
	 *
	 * @param float|int|null $amount Amount.
	 * @param string $sourceCurrency Currency code of the input amount.
	 * @param string $targetCurrency Currency code requested for output.
	 * @param bool $formatted Whether to return a display string instead of a numeric amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @return string|float.
	 */
	protected function moneyConvert(float|int|null $amount, string $sourceCurrency, string $targetCurrency, bool $formatted=false, bool $showFree=true): string|float {
		return Mvc::moneyConvert($amount, $sourceCurrency, $targetCurrency, $formatted, $showFree);
	}

	/**
	 * Converts an amount to the active display currency.
	 *
	 * The MVC currency bridge applies the active display currency and optional formatting rules.
	 *
	 * @param float|int|null $amount Amount.
	 * @param bool $formatted Whether to return a display string instead of a numeric amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @param ?string $currency Currency.
	 * @return string|float.
	 */
	protected function moneyToDisplay(float|int|null $amount, bool $formatted=false, bool $showFree=true, ?string $currency=null): string|float {
		return Mvc::moneyToDisplay($amount, $formatted, $showFree, $currency);
	}

	/**
	 * Converts an amount to the configured base currency.
	 *
	 * The MVC currency bridge converts the supplied currency into the configured base currency before optional formatting.
	 *
	 * @param float|int|null $amount Amount.
	 * @param string $originalCurrency Currency code of the input amount.
	 * @param bool $formatted Whether to return a display string instead of a numeric amount.
	 * @param bool $showFree Whether zero/null amounts may render as a free label.
	 * @return string|float.
	 */
	protected function moneyToBase(float|int|null $amount, string $originalCurrency, bool $formatted=false, bool $showFree=true): string|float {
		return Mvc::moneyToBase($amount, $originalCurrency, $formatted, $showFree);
	}

	/**
	 * Rounds an amount using currency precision rules.
	 *
	 * Rounding is delegated to the MVC currency bridge so cash rounding and currency precision stay centralized.
	 *
	 * @param float|int|null $amount Amount.
	 * @param string $currency ISO currency code used to choose precision rules.
	 * @param bool $cash Whether to apply cash-rounding increments.
	 * @return float.
	 */
	protected function moneyRound(float|int|null $amount, string $currency, bool $cash=false): float {
		return Mvc::moneyRound($amount, $currency, $cash);
	}

	/**
	 * Splits an amount into currency-safe parts.
	 *
	 * Allocation is delegated to the MVC currency bridge to preserve currency precision and cash-rounding behavior across split parts.
	 *
	 * @param float|int|null $amount Amount.
	 * @param string $currency ISO currency code used for split precision and rounding.
	 * @param int $parts Number of parts to split into.
	 * @param bool $cash Whether to apply cash-rounding increments.
	 * @return list<float> Split amounts.
	 */
	protected function moneySplit(float|int|null $amount, string $currency, int $parts, bool $cash=false): array {
		return Mvc::moneySplit($amount, $currency, $parts, $cash);
	}

	/**
	 * Allocates an amount by ratio using currency precision rules.
	 *
	 * Allocation is delegated to the MVC currency bridge so ratio rounding remains consistent with other money helpers.
	 *
	 * @param float|int|null $amount Amount.
	 * @param string $currency ISO currency code used for allocation precision.
	 * @param list<float|int> $ratios Allocation ratios.
	 * @param bool $cash Whether to apply cash-rounding increments.
	 * @return list<float> Allocated amounts.
	 */
	protected function moneyAllocate(float|int|null $amount, string $currency, array $ratios, bool $cash=false): array {
		return Mvc::moneyAllocate($amount, $currency, $ratios, $cash);
	}

	/**
	 * Translates controller-facing text through Dataphyre localization.
	 *
	 * Translation helpers pass route/page/theme context through to the localization module when available.
	 *
	 * @param string $date Date string accepted by the localization layer.
	 * @param string $language Target language code.
	 * @param string $format Output date format.
	 * @return string.
	 */
	protected function translateDate(string $date, string $language, string $format): string {
		return Mvc::translateDate($date, $language, $format);
	}

	/**
	 * Formats a date through the MVC localization bridge.
	 *
	 * This is an alias for localized date formatting through the MVC localization bridge.
	 *
	 * @param string $date Date string accepted by the localization layer.
	 * @param string $language Target language code.
	 * @param string $format Output date format.
	 * @return string.
	 */
	protected function localizedDate(string $date, string $language, string $format): string {
		return Mvc::localizedDate($date, $language, $format);
	}

	/**
	 * Reads a value through the MVC cache bridge.
	 *
	 * Cache access is delegated to the MVC cache bridge so controller code does not depend on the underlying cache backend.
	 *
	 * @param string $key Cache key.
	 * @param mixed $default Value returned when the key is missing.
	 * @return mixed.
	 */
	protected function cacheGet(string $key, mixed $default=null): mixed {
		return Mvc::cacheGet($key, $default);
	}

	/**
	 * Writes a value through the MVC cache bridge.
	 *
	 * Cache writes are delegated to the MVC cache bridge using seconds as the portable TTL unit.
	 *
	 * @param string $key Cache key.
	 * @param mixed $value Value to store.
	 * @param int $seconds TTL in seconds, or backend default when zero.
	 * @return bool.
	 */
	protected function cachePut(string $key, mixed $value, int $seconds=0): bool {
		return Mvc::cachePut($key, $value, $seconds);
	}

	/**
	 * Reads or resolves a cached value through the MVC cache bridge.
	 *
	 * The resolver runs only when the cache bridge cannot return an existing value for the key.
	 *
	 * @param string $key Cache key.
	 * @param int $seconds TTL in seconds for newly resolved values.
	 * @param callable $resolver Callback used to produce and cache the value on a cache miss.
	 * @return mixed.
	 */
	protected function cacheRemember(string $key, int $seconds, callable $resolver): mixed {
		return Mvc::cacheRemember($key, $seconds, $resolver);
	}

	/**
	 * Deletes a cached value through the MVC cache bridge.
	 *
	 * Removal is delegated to the MVC cache bridge and returns the backend deletion status.
	 *
	 * @param string $key Cache key to remove.
	 * @return bool.
	 */
	protected function cacheForget(string $key): bool {
		return Mvc::cacheForget($key);
	}

	/**
	 * Increments a cached numeric value when the backend supports it.
	 *
	 * Numeric mutation is delegated to the MVC cache bridge and returns false when the backend cannot increment.
	 *
	 * @param string $key Cache key to increment.
	 * @param int $offset Amount added to the cached value.
	 * @return int|false.
	 */
	protected function cacheIncrement(string $key, int $offset=1): int|false {
		return Mvc::cacheIncrement($key, $offset);
	}

	/**
	 * Decrements a cached numeric value when the backend supports it.
	 *
	 * Numeric mutation is delegated to the MVC cache bridge and returns false when the backend cannot decrement.
	 *
	 * @param string $key Cache key to decrement.
	 * @param int $offset Amount subtracted from the cached value.
	 * @return int|false.
	 */
	protected function cacheDecrement(string $key, int $offset=1): int|false {
		return Mvc::cacheDecrement($key, $offset);
	}

	/**
	 * Dispatches an async task through the selected MVC driver.
	 *
	 * The task payload is handed to the MVC async bridge, which chooses the requested driver and owns queue/runtime failure behavior.
	 *
	 * @param mixed $task Callable, job object, or async task descriptor.
	 * @param list<mixed> $arguments Task arguments.
	 * @param ?string $driver Driver.
	 * @return mixed.
	 */
	protected function asyncDispatch(mixed $task, array $arguments=[], ?string $driver=null): mixed {
		return Mvc::asyncDispatch($task, $arguments, $driver);
	}

	/**
	 * Runs an async task inline through the MVC async bridge.
	 *
	 * The task executes through the MVC async bridge without external queue dispatch.
	 *
	 * @param mixed $task Callable, job object, or async task descriptor.
	 * @param list<mixed> $arguments Task arguments.
	 * @return mixed.
	 */
	protected function asyncInline(mixed $task, array $arguments=[]): mixed {
		return Mvc::asyncInline($task, $arguments);
	}

	/**
	 * Dispatches a list of async tasks through the selected MVC driver.
	 *
	 * Dispatches a list of async task descriptors through the selected MVC async
	 * driver and returns the driver's aggregate result.
	 *
	 * @param list<mixed> $tasks Task definitions.
	 * @param ?string $driver Async driver name, or null for the configured default.
	 * @return mixed.
	 */
	protected function asyncAll(array $tasks, ?string $driver=null): mixed {
		return Mvc::asyncAll($tasks, $driver);
	}

	/**
	 * Schedules an async task after a delay.
	 *
	 * Schedules a callable to run after the requested delay through the MVC async
	 * bridge. The returned task identifier can be passed back to asyncCancel().
	 *
	 * @param callable $task Deferred task callback.
	 * @param int $milliseconds Delay before the task becomes eligible to run.
	 * @return int|false.
	 */
	protected function asyncAfter(callable $task, int $milliseconds): int|false {
		return Mvc::asyncAfter($task, $milliseconds);
	}

	/**
	 * Registers a recurring async task.
	 *
	 * Registers a repeated callable with the MVC async bridge. The returned task
	 * identifier is false when the active driver cannot schedule recurring work.
	 *
	 * @param callable $task Repeated task callback.
	 * @param int $milliseconds Interval between task runs.
	 * @return int|false.
	 */
	protected function asyncEvery(callable $task, int $milliseconds): int|false {
		return Mvc::asyncEvery($task, $milliseconds);
	}

	/**
	 * Requests cancellation of a scheduled async task.
	 *
	 * Requests cancellation of a task previously scheduled by the MVC async bridge.
	 *
	 * @param int $taskId Async task identifier returned by a scheduling helper.
	 * @return void.
	 */
	protected function asyncCancel(int $taskId): void {
		Mvc::asyncCancel($taskId);
	}

	/**
	 * Builds server-rendered markup for a Reactor component mount.
	 *
	 * Builds the server-rendered mount markup for a Reactor component with initial
	 * state and HTML attributes supplied by the controller action.
	 *
	 * @param string $component Reactor component name or class alias.
	 * @param array<string,mixed> $state Initial component state.
	 * @param array<string,mixed> $attributes HTML/component attributes.
	 * @return string.
	 */
	protected function reactorMount(string $component, array $state=[], array $attributes=[]): string {
		return Mvc::reactorMount($component, $state, $attributes);
	}

	/**
	 * Dispatches a Reactor component request through MVC.
	 *
	 * Hands a Reactor request payload to the MVC Reactor bridge and returns the
	 * normalized component response.
	 *
	 * @param array|object|null $request HTTP request being handled.
	 * @return Response.
	 */
	protected function reactorDispatch(array|object|null $request=null): Response {
		return Mvc::reactorDispatch($request);
	}

	/**
	 * Dispatches a batch of Reactor component requests through MVC.
	 *
	 * Processes a batch of Reactor component requests using the bridge's request
	 * parser when no explicit batch payload is provided.
	 *
	 * @param ?array $requests Reactor request payloads, or null to read the active request.
	 * @return Response.
	 */
	protected function reactorBatch(?array $requests=null): Response {
		return Mvc::reactorBatch($requests);
	}

	/**
	 * Returns the Reactor component manifest exposed by MVC.
	 *
	 * Returns the component manifest exposed by the Reactor bridge.
	 *
	 * @return array<string,mixed> Reactor component manifest.
	 */
	protected function reactorManifest(): array {
		return Mvc::reactorManifest();
	}

	/**
	 * Creates a local file response.
	 *
	 * Creates a file response for a local filesystem path. Response owns header
	 * normalization and file streaming.
	 *
	 * @param string $path Local file path to stream.
	 * @param ?string $name Download/display filename override.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return Response.
	 */
	protected function file(string $path, ?string $name=null, array $headers=[]): Response {
		return Response::file($path, $name, $headers);
	}

	/**
	 * Creates an attachment response for a local file.
	 *
	 * Creates an attachment response for a local filesystem path.
	 *
	 * @param string $path Local file path to stream.
	 * @param ?string $name Download filename override.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return Response.
	 */
	protected function download(string $path, ?string $name=null, array $headers=[]): Response {
		return Response::download($path, $name, $headers);
	}

	/**
	 * Streams a stored object as a response.
	 *
	 * Streams a file from a configured storage disk through the MVC storage bridge.
	 *
	 * @param string $path Storage object path.
	 * @param ?string $disk Storage disk name, or null for the default disk.
	 * @param ?string $name Download/display filename override.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return Response.
	 */
	protected function storageFile(string $path, ?string $disk=null, ?string $name=null, array $headers=[]): Response {
		return Mvc::storageFile($path, $disk, $name, $headers);
	}

	/**
	 * Streams a stored object as an attachment response.
	 *
	 * Creates an attachment response for a file on a configured storage disk.
	 *
	 * @param string $path Storage object path.
	 * @param ?string $disk Storage disk name, or null for the default disk.
	 * @param ?string $name Download filename override.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return Response.
	 */
	protected function storageDownload(string $path, ?string $disk=null, ?string $name=null, array $headers=[]): Response {
		return Mvc::storageDownload($path, $disk, $name, $headers);
	}

	/**
	 * Creates a temporary URL for a stored object.
	 *
	 * Asks the configured storage disk to create a time-limited public URL for an
	 * object path.
	 *
	 * @param string $path Storage object path.
	 * @param int|\DateTimeInterface $expires Expiry timestamp or date object.
	 * @param ?string $disk Storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Temporary URL adapter options.
	 * @return string|false.
	 */
	protected function storageTemporaryUrl(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		return Mvc::storageTemporaryUrl($path, $expires, $disk, $options);
	}

	/**
	 * Sends a mail payload through the MVC mail bridge.
	 *
	 * Sends a normalized mail payload through the selected MVC mail provider.
	 *
	 * @param array<string,mixed> $message Mail message payload.
	 * @param ?string $provider Mail provider name, or null for the configured default.
	 * @param array<string,mixed> $options Mail provider options.
	 * @return array<string,mixed> Provider send result.
	 */
	protected function sendMail(array $message, ?string $provider=null, array $options=[]): array {
		return Mvc::sendMail($message, $provider, $options);
	}

	/**
	 * Queues a mail payload through the MVC mail bridge.
	 *
	 * Queues a normalized mail payload through the selected MVC mail provider.
	 *
	 * @param array<string,mixed> $message Mail message payload.
	 * @param ?string $provider Mail provider name, or null for the configured default.
	 * @param array<string,mixed> $options Queue/provider options.
	 * @return array<string,mixed> Queue dispatch result.
	 */
	protected function queueMail(array $message, ?string $provider=null, array $options=[]): array {
		return Mvc::queueMail($message, $provider, $options);
	}

	/**
	 * Renders controller views and template assets.
	 *
	 * Controller helpers delegate to the MVC facade so actions can return view results, rendered strings, and asset HTML without touching the templating bridge directly.
	 *
	 * @param string $template Template path or inline template source.
	 * @param array<string,mixed> $data Template variables.
	 * @param array<string,mixed> $options Mail rendering options.
	 * @return array<string,mixed> Rendered mail payload.
	 */
	protected function renderMail(string $template, array $data=[], array $options=[]): array {
		return Mvc::renderMail($template, $data, $options);
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Throws an HTTP exception immediately, carrying the status, optional message,
	 * and headers into the framework's exception response handling.
	 *
	 * @param int $status HTTP status code for the exception response.
	 * @param string $message Optional response message.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return never.
	 */
	protected function abort(int $status, string $message='', array $headers=[]): never {
		throw new HttpException($status, $message, $headers);
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Throws an HTTP exception only when the condition is true.
	 *
	 * @param bool $condition Guard result that triggers the abort when true.
	 * @param int $status HTTP status code for the exception response.
	 * @param string $message Optional response message.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return void.
	 */
	protected function abortIf(bool $condition, int $status, string $message='', array $headers=[]): void {
		if($condition){
			$this->abort($status, $message, $headers);
		}
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Throws an HTTP exception when the condition is false.
	 *
	 * @param bool $condition Guard result that allows the request when true.
	 * @param int $status HTTP status code for the exception response.
	 * @param string $message Optional response message.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return void.
	 */
	protected function abortUnless(bool $condition, int $status, string $message='', array $headers=[]): void {
		if(!$condition){
			$this->abort($status, $message, $headers);
		}
	}

	/**
	 * Returns the active authentication context.
	 *
	 * Returns the active authentication context for the requested guard or auth
	 * type through the MVC auth bridge.
	 *
	 * @param ?string $authType Authentication type/guard name, or null for the default.
	 * @return array<string,mixed> Authentication context payload.
	 */
	protected function authContext(?string $authType=null): array {
		return Mvc::authContext($authType);
	}

	/**
	 * Checks whether the active auth type has a user.
	 *
	 * Checks whether the requested authentication type currently has an authenticated
	 * user.
	 *
	 * @param ?string $authType Authentication type/guard name, or null for the default.
	 * @return bool.
	 */
	protected function loggedIn(?string $authType=null): bool {
		return Mvc::loggedIn($authType);
	}

	/**
	 * Returns the current authenticated user identifier.
	 *
	 * Reads the active authenticated user identifier for the requested auth type.
	 *
	 * @param ?string $authType Authentication type/guard name, or null for the default.
	 * @return bool|int|string.
	 */
	protected function userId(?string $authType=null): bool|int|string {
		return Mvc::userId($authType);
	}

	/**
	 * Evaluates one permission requirement.
	 *
	 * Evaluates one permission requirement against the current subject and context.
	 *
	 * @param mixed $requiredPermission Permission name, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string,mixed> $context Permission evaluation context.
	 * @return bool.
	 */
	protected function can(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return Mvc::can($requiredPermission, $subject, $context);
	}

	/**
	 * Evaluates whether any permission requirement passes.
	 *
	 * Evaluates whether any supplied permission requirement passes for the subject.
	 *
	 * @param mixed $requiredPermission Permission name, list, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string,mixed> $context Permission evaluation context.
	 * @return bool.
	 */
	protected function canAny(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return Mvc::canAny($requiredPermission, $subject, $context);
	}

	/**
	 * Enforces one permission requirement.
	 *
	 * Enforces one permission requirement through the MVC permission bridge.
	 *
	 * @param mixed $requiredPermission Permission name, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string,mixed> $context Permission evaluation context.
	 * @return bool.
	 */
	protected function authorize(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return Mvc::authorize($requiredPermission, $subject, $context);
	}

	/**
	 * Enforces that any permission requirement passes.
	 *
	 * Enforces that at least one permission requirement passes for the subject.
	 *
	 * @param mixed $requiredPermission Permission name, list, object, or rule descriptor.
	 * @param mixed $subject Resource or domain object being checked.
	 * @param array<string,mixed> $context Permission evaluation context.
	 * @return bool.
	 */
	protected function authorizeAny(mixed $requiredPermission, mixed $subject=null, array $context=[]): bool {
		return Mvc::authorizeAny($requiredPermission, $subject, $context);
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Creates a redirect response to an explicit location without resolving a route
	 * name.
	 *
	 * @param string $location Absolute or relative redirect target.
	 * @param int $status HTTP redirect status code.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return RedirectResult.
	 */
	protected function redirect(string $location, int $status=302, array $headers=[]): RedirectResult {
		return new RedirectResult($location, $status, $headers);
	}

	/**
	 * Reads session data or returns the Session facade class.
	 *
	 * Returns the Session facade class when no key is supplied, otherwise reads a
	 * value from the active session store.
	 *
	 * @param ?string $key Session key to read, or null to return the facade class.
	 * @param mixed $default Value returned when the session key is absent.
	 * @return mixed.
	 */
	protected function session(?string $key=null, mixed $default=null): mixed {
		return $key===null ? Session::class : Session::get($key, $default);
	}

	/**
	 * Reads flashed input from the previous request.
	 *
	 * Reads flashed input from the previous request.
	 *
	 * @param ?string $key Flashed input key, or null for all old input.
	 * @param mixed $default Value returned when the flashed key is absent.
	 * @return mixed.
	 */
	protected function old(?string $key=null, mixed $default=null): mixed {
		return Session::old($key, $default);
	}

	/**
	 * Reads flashed validation errors.
	 *
	 * Reads flashed validation errors from the named error bag.
	 *
	 * @param string $bag Validation error bag name.
	 * @return array<string,string|list<string>> Flashed validation errors for the bag.
	 */
	protected function errors(string $bag='default'): array {
		return Session::errors($bag);
	}

	/**
	 * Returns the active session CSRF token.
	 *
	 * Returns the CSRF token stored in the active session.
	 *
	 * @return string.
	 */
	protected function csrfToken(): string {
		return Session::token();
	}

	/**
	 * Resolves a named route URL.
	 *
	 * Resolves a named route inside the current MVC route context when possible, or
	 * through the named application passed by the caller.
	 *
	 * @param string $name Route name to resolve.
	 * @param array<string,scalar|null> $parameters Route parameters.
	 * @param array<string,scalar|list<scalar|null>|null> $query Query string parameters.
	 * @param ?string $app MVC application name.
	 * @return string.
	 */
	protected function route(string $name, array $parameters=[], array $query=[], ?string $app=null): string {
		if($app===null && $this->mvcRouteContext instanceof MvcRouteContext){
			return $this->mvcRouteContext->app()->routes()->url($name, $parameters, $query);
		}
		return Mvc::url($name, $parameters, $query, $app);
	}

	/**
	 * Resolves a signed named route URL.
	 *
	 * Resolves a named route and appends the framework signature fields used for
	 * temporary URL validation.
	 *
	 * @param string $name Route name to resolve.
	 * @param array<string,scalar|null> $parameters Route parameters.
	 * @param array<string,scalar|list<scalar|null>|null> $query Query string parameters.
	 * @param ?int $expiresAt Unix timestamp after which the signature expires.
	 * @param ?string $app MVC application name.
	 * @return string.
	 */
	protected function signedRoute(string $name, array $parameters=[], array $query=[], ?int $expiresAt=null, ?string $app=null): string {
		if($app===null && $this->mvcRouteContext instanceof MvcRouteContext){
			return $this->mvcRouteContext->app()->routes()->signedUrl($name, $parameters, $query, $expiresAt);
		}
		return Mvc::signedUrl($name, $parameters, $query, $expiresAt, $app);
	}

	/**
	 * Builds a normalized HTTP response from a controller action.
	 *
	 * Resolves a named route and wraps it in a redirect response.
	 *
	 * @param string $name Route name to resolve.
	 * @param array<string,scalar|null> $parameters Route parameters.
	 * @param array<string,scalar|list<scalar|null>|null> $query Query string parameters.
	 * @param int $status HTTP redirect status code.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @param ?string $app MVC application name.
	 * @return RedirectResult.
	 */
	protected function redirectToRoute(
		string $name,
		array $parameters=[],
		array $query=[],
		int $status=302,
		array $headers=[],
		?string $app=null
	): RedirectResult {
		return $this->redirect($this->route($name, $parameters, $query, $app), $status, $headers);
	}

	/**
	 * Builds a redirect to the referrer or fallback location.
	 *
	 * Redirects to the HTTP referrer header, falling back to the supplied location
	 * when the header is missing or empty.
	 *
	 * @param string $fallback Redirect target used when no referrer is available.
	 * @param int $status HTTP redirect status code.
	 * @param array<string,string|list<string>> $headers Response headers.
	 * @return RedirectResult.
	 */
	protected function back(string $fallback='/', int $status=302, array $headers=[]): RedirectResult {
		$location=(string)($_SERVER['HTTP_REFERER'] ?? $fallback);
		return $this->redirect($location!=='' ? $location : $fallback, $status, $headers);
	}
}

/**
 * Fluent constraint object for controller-level middleware registration.
 *
 * Registration objects keep references to the controller middleware entries they created so `only()` and `except()` can constrain those entries after declaration.
 */
final class ControllerMiddlewareRegistration {

	private array $middleware;

	/**
	 * Stores references to middleware entries created by one registration call.
	 *
	 * Action methods use this helper to delegate response, request, route, validation, sanitation, or localization work to the MVC runtime.
	 *
	 * @param array<int,array{middleware:mixed,only:list<string>,except:list<string>}> &$middleware Middleware definition list retained by the controller.
	 * @param list<int> $indexes Middleware entry indexes owned by this registration.
	 */
	public function __construct(array &$middleware, private array $indexes){
		$this->middleware=&$middleware;
	}

	/**
	 * Restricts registered middleware entries to selected actions.
	 *
	 * Action methods use this helper to delegate response, request, route, validation, sanitation, or localization work to the MVC runtime.
	 *
	 * @param array|string ...$methods Controller action names allowed to run the middleware.
	 * @return self.
	 */
	public function only(array|string ...$methods): self {
		$methods=$this->normalizeMethods($methods);
		foreach($this->indexes as $index){
			if(isset($this->middleware[$index])){
				$this->middleware[$index]['only']=$methods;
			}
		}
		return $this;
	}

	/**
	 * Excludes registered middleware entries from selected actions.
	 *
	 * Action methods use this helper to delegate response, request, route, validation, sanitation, or localization work to the MVC runtime.
	 *
	 * @param array|string ...$methods Controller action names that should skip the middleware.
	 * @return self.
	 */
	public function except(array|string ...$methods): self {
		$methods=$this->normalizeMethods($methods);
		foreach($this->indexes as $index){
			if(isset($this->middleware[$index])){
				$this->middleware[$index]['except']=$methods;
			}
		}
		return $this;
	}

	/**
	 * Normalizes method constraint input for middleware registration.
	 *
	 * Nested lists from only()/except() are flattened, lowercased, trimmed, emptied
	 * names are ignored, and duplicates are removed so route dispatch can compare
	 * controller action names with a stable lowercase set.
	 *
	 * @param list<array|string> $methods Variadic method constraints supplied to only() or except().
	 * @return array<int, string> Unique normalized action names.
	 */
	private function normalizeMethods(array $methods): array {
		$normalized=[];
		foreach($methods as $method){
			foreach(is_array($method) ? $method : [$method] as $item){
				$item=strtolower(trim((string)$item));
				if($item!==''){
					$normalized[]=$item;
				}
			}
		}
		return array_values(array_unique($normalized));
	}
}
