<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	$module_root=dirname(__DIR__);
	$runtime_modules=dirname($module_root);
	$files=[
		$runtime_modules.'/http/Framework/ActionArguments.php',
		$runtime_modules.'/http/Framework/UploadedFile.php',
		$runtime_modules.'/http/Framework/Request.php',
		$runtime_modules.'/http/Framework/Response.php',
		$runtime_modules.'/routing/Framework/CompilableRoute.php',
		$runtime_modules.'/routing/Framework/ControllerAction.php',
		$runtime_modules.'/routing/Framework/Route.php',
		$runtime_modules.'/routing/Framework/RouteManifest.php',
		$runtime_modules.'/routing/Framework/RouteCompiler.php',
		$runtime_modules.'/routing/kernel/compiled_route_dispatcher.php',
		$runtime_modules.'/mvc/Framework/ResponseResult.php',
		$runtime_modules.'/mvc/Framework/ContainerException.php',
		$runtime_modules.'/mvc/Framework/Container.php',
		$runtime_modules.'/mvc/Framework/ServiceProviderContract.php',
		$runtime_modules.'/mvc/Framework/ServiceProvider.php',
		$runtime_modules.'/mvc/Framework/CallbackServiceProvider.php',
		$runtime_modules.'/mvc/Framework/ProviderRegistry.php',
		$runtime_modules.'/mvc/Framework/RedirectResult.php',
		$runtime_modules.'/mvc/Framework/ViewResult.php',
		$runtime_modules.'/mvc/Framework/HttpException.php',
		$runtime_modules.'/mvc/Framework/Validator.php',
		$runtime_modules.'/mvc/Framework/ValidationException.php',
		$runtime_modules.'/mvc/Framework/FormRequest.php',
		$runtime_modules.'/mvc/Framework/Model.php',
		$runtime_modules.'/mvc/Framework/Mvc.php',
		$runtime_modules.'/mvc/Framework/Controller.php',
		$runtime_modules.'/mvc/Framework/MvcRouteContext.php',
		$runtime_modules.'/mvc/Framework/RouteDefinition.php',
		$runtime_modules.'/mvc/Framework/RouteModelNotFoundException.php',
		$runtime_modules.'/mvc/Framework/RouteModelBinder.php',
		$runtime_modules.'/mvc/Framework/RouteList.php',
		$runtime_modules.'/mvc/Framework/SignedUrl.php',
		$runtime_modules.'/mvc/Framework/AccessMiddleware.php',
		$runtime_modules.'/mvc/Framework/GuestMiddleware.php',
		$runtime_modules.'/mvc/Framework/PermissionMiddleware.php',
		$runtime_modules.'/mvc/Framework/PermissionAnyMiddleware.php',
		$runtime_modules.'/mvc/Framework/SignedUrlMiddleware.php',
		$runtime_modules.'/mvc/Framework/ThrottleMiddleware.php',
		$runtime_modules.'/mvc/Framework/CacheMiddleware.php',
		$runtime_modules.'/mvc/Framework/Session.php',
		$runtime_modules.'/mvc/Framework/SessionMiddleware.php',
		$runtime_modules.'/mvc/Framework/CsrfMiddleware.php',
		$runtime_modules.'/mvc/Framework/RouteCollection.php',
		$runtime_modules.'/mvc/Framework/MvcApplication.php',
		$runtime_modules.'/mvc/Framework/MvcDispatcher.php',
		$runtime_modules.'/mvc/Framework/MvcManager.php',
		$runtime_modules.'/mvc/Framework/MvcHost.php',
	];
	foreach($files as $file){
		require_once($file);
	}
}

namespace dataphyre {
	/**
	 * In-memory Access facade used by MVC middleware regression tests.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class access {
		public static bool $logged_in=false;
		public static bool|int|string $userid=false;
		public static ?string $auth_type=null;

		/**
		 * Resets the authentication fixture to an anonymous session baseline.
		 *
		 * Regression scenarios call this before dispatching routes so middleware
		 * assertions cannot inherit a login state or guard name from a previous
		 * case in the same PHP process.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$logged_in=false;
			self::$userid=false;
			self::$auth_type=null;
		}

		/**
		 * Builds the auth context array consumed by MVC access middleware.
		 *
		 * The fixture remembers the explicit guard that was requested and falls
		 * back to the last guard, then to the session guard. The returned shape is
		 * intentionally small: auth_type, logged_in, and userid.
		 *
		 * @param string|null $auth_type Requested authentication guard.
		 * @return array{auth_type:string,logged_in:bool,userid:bool|int|string} Current fixture authentication state.
		 */
		public static function auth_context(?string $auth_type=null): array {
			self::$auth_type=$auth_type ?? self::$auth_type ?? 'session';
			return [
				'auth_type'=>self::$auth_type,
				'logged_in'=>self::$logged_in,
				'userid'=>self::$userid,
			];
		}

		/**
		 * Reports the configured login flag while recording the guard under test.
		 *
		 * No session backend is consulted; tests seed the public fixture
		 * properties directly to exercise access and guest middleware branches.
		 *
		 * @param string|null $auth_type Requested authentication guard.
		 * @return bool True when the fixture should behave as an authenticated user.
		 */
		public static function logged_in(?string $auth_type=null): bool {
			self::$auth_type=$auth_type ?? self::$auth_type ?? 'session';
			return self::$logged_in;
		}

		/**
		 * Provides the seeded user identifier only for authenticated scenarios.
		 *
		 * The false sentinel mirrors legacy Dataphyre access helpers and lets
		 * regression tests verify that anonymous controller paths do not receive a
		 * user identifier by accident.
		 *
		 * @param string|null $auth_type Requested authentication guard.
		 * @return bool|int|string Seeded user id, or false when the fixture is anonymous.
		 */
		public static function userid(?string $auth_type=null): bool|int|string {
			self::$auth_type=$auth_type ?? self::$auth_type ?? 'session';
			return self::logged_in($auth_type) ? self::$userid : false;
		}
	}

	/**
	 * In-memory Permission facade used by MVC authorization regression tests.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class permission {
		public static array $allowed=[];
		public static array $calls=[];

		/**
		 * Clears allowed permissions and authorization call history.
		 *
		 * This isolates permission middleware tests from earlier scenarios while
		 * preserving the fixture's simple string-permission model.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$allowed=[];
			self::$calls=[];
		}

		/**
		 * Evaluates an all-of permission requirement against the seeded allow list.
		 *
		 * The call log captures requested permissions, subject, and whether an MVC
		 * request was supplied in context so tests can assert both the decision and
		 * the authorization payload emitted by middleware.
		 *
		 * @param mixed $required_permission Permission string or list required by the route.
		 * @param mixed $subject Optional subject passed through middleware.
		 * @param array{request?:\Dataphyre\Http\Request,route?:mixed,params?:array<string,mixed>} $context Authorization context emitted by MVC middleware, commonly including the request.
		 * @return bool True when every requested permission is present in the fixture allow list.
		 */
		public static function check(mixed $required_permission, mixed $subject=null, array $context=[]): bool {
			self::$calls[]=[
				'mode'=>'all',
				'permissions'=>array_values((array)$required_permission),
				'subject'=>$subject,
				'has_request'=>isset($context['request']),
			];
			foreach((array)$required_permission as $permission){
				if(!in_array((string)$permission, self::$allowed, true)){
					return false;
				}
			}
			return true;
		}

		/**
		 * Evaluates an any-of permission requirement against the seeded allow list.
		 *
		 * This mirrors permission-any middleware behavior: the first allowed
		 * permission grants access, while the call log still records the full
		 * requirement for assertions.
		 *
		 * @param mixed $required_permission Permission string or list accepted by the route.
		 * @param mixed $subject Optional subject passed through middleware.
		 * @param array{request?:\Dataphyre\Http\Request,route?:mixed,params?:array<string,mixed>} $context Authorization context emitted by MVC middleware, commonly including the request.
		 * @return bool True when at least one requested permission is present in the fixture allow list.
		 */
		public static function any(mixed $required_permission, mixed $subject=null, array $context=[]): bool {
			self::$calls[]=[
				'mode'=>'any',
				'permissions'=>array_values((array)$required_permission),
				'subject'=>$subject,
				'has_request'=>isset($context['request']),
			];
			foreach((array)$required_permission as $permission){
				if(in_array((string)$permission, self::$allowed, true)){
					return true;
				}
			}
			return false;
		}
	}

	/**
	 * In-memory Storage facade used by MVC upload and URL regression tests.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class storage {
		public static array $files=[];
		public static array $metadata=[];
		public static array $temporary_urls=[];
		public static array $calls=[];

		/**
		 * Clears storage fixture disks, metadata, temporary URLs, and call history.
		 *
		 * Seeded arrays use the shape disk => path => value so tests can emulate
		 * multiple disks without touching filesystem adapters.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$files=[];
			self::$metadata=[];
			self::$temporary_urls=[];
			self::$calls=[];
		}

		/**
		 * Fetches seeded file contents from the requested fixture disk.
		 *
		 * The lookup records the disk and options exactly as supplied and returns
		 * false for missing paths, matching Dataphyre storage call sites that
		 * branch on a missing object rather than an exception.
		 *
		 * @param string $path Storage object path.
		 * @param string|null $disk Fixture disk name; default disk is used when omitted.
		 * @param array<string,mixed> $options Adapter options forwarded by MVC helpers to the storage facade.
		 * @return string|false Seeded file contents, or false when the path is absent.
		 */
		public static function get(string $path, ?string $disk=null, array $options=[]): string|false {
			self::$calls[]=['method'=>'get', 'path'=>$path, 'disk'=>$disk, 'options'=>$options];
			return self::$files[$disk ?? 'default'][$path] ?? false;
		}

		/**
		 * Fetches seeded metadata for a fixture storage object.
		 *
		 * Metadata is not normalized here; tests control the exact associative
		 * payload so MVC helpers can be checked against backend-specific shapes.
		 *
		 * @param string $path Storage object path.
		 * @param string|null $disk Fixture disk name; default disk is used when omitted.
		 * @return array<string,mixed>|false Seeded metadata, or false when no metadata was seeded.
		 */
		public static function metadata(string $path, ?string $disk=null): array|false {
			self::$calls[]=['method'=>'metadata', 'path'=>$path, 'disk'=>$disk];
			return self::$metadata[$disk ?? 'default'][$path] ?? false;
		}

		/**
		 * Provides a seeded temporary URL for a fixture storage object.
		 *
		 * Expiration and options are captured for assertions but are not evaluated;
		 * the fixture is deterministic so signed URL tests can inspect the outgoing
		 * request without wall-clock sensitivity.
		 *
		 * @param string $path Storage object path.
		 * @param int|\DateTimeInterface $expires Requested expiry timestamp or date object.
		 * @param string|null $disk Fixture disk name; default disk is used when omitted.
		 * @param array<string,mixed> $options Adapter options forwarded by MVC helpers to the storage facade.
		 * @return string|false Seeded temporary URL, or false when absent.
		 */
		public static function temporary_url(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
			self::$calls[]=['method'=>'temporary_url', 'path'=>$path, 'disk'=>$disk, 'expires'=>$expires, 'options'=>$options];
			return self::$temporary_urls[$disk ?? 'default'][$path] ?? false;
		}
	}

	/**
	 * In-memory Mailer facade used by MVC notification regression tests.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class mailer {
		public static array $calls=[];

		/**
		 * Clears recorded mailer calls between notification regression scenarios.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls=[];
		}

		/**
		 * Records an immediate mail delivery request and returns a stable receipt.
		 *
		 * No transport is invoked. The deterministic message id and accepted status
		 * let controllers exercise success handling without coupling tests to a
		 * queue, SMTP server, or provider SDK.
		 *
		 * @param array{to?:string|list<string>,from?:string,subject?:string,html?:string,text?:string,template?:string,data?:array<string,mixed>} $message Message payload supplied by application code.
		 * @param string|null $provider Optional provider alias.
		 * @param array<string,mixed> $options Provider options forwarded by the caller.
		 * @return array{ok:bool,queued:bool,provider:string,message_id:string,status:int} Mail delivery receipt fixture.
		 */
		public static function send(array $message, ?string $provider=null, array $options=[]): array {
			self::$calls[]=['method'=>'send', 'message'=>$message, 'provider'=>$provider, 'options'=>$options];
			return [
				'ok'=>true,
				'queued'=>false,
				'provider'=>$provider ?? 'default',
				'message_id'=>'sent-1',
				'status'=>202,
			];
		}

		/**
		 * Records a queued mail request and returns a stable queue receipt.
		 *
		 * The fixture deliberately uses the same accepted status as send() while
		 * marking queued=true so assertions can distinguish controller intent.
		 *
		 * @param array{to?:string|list<string>,from?:string,subject?:string,html?:string,text?:string,template?:string,data?:array<string,mixed>} $message Message payload supplied by application code.
		 * @param string|null $provider Optional provider alias.
		 * @param array<string,mixed> $options Queue or provider options forwarded by the caller.
		 * @return array{ok:bool,queued:bool,provider:string,message_id:string,status:int} Mail queue receipt fixture.
		 */
		public static function queue(array $message, ?string $provider=null, array $options=[]): array {
			self::$calls[]=['method'=>'queue', 'message'=>$message, 'provider'=>$provider, 'options'=>$options];
			return [
				'ok'=>true,
				'queued'=>true,
				'provider'=>$provider ?? 'default',
				'message_id'=>'queued-1',
				'status'=>202,
			];
		}

		/**
		 * Renders a deterministic message preview from a template name and data.
		 *
		 * This exercises notification preview code without loading template files;
		 * the subject includes the optional name field while HTML and text retain
		 * the template identifier for easy assertions.
		 *
		 * @param string $template Template identifier requested by the caller.
		 * @param array<string,mixed> $data Template data fixture.
		 * @param array<string,mixed> $options Rendering options forwarded by the caller.
		 * @return array{subject:string,html:string,text:string} Rendered mail preview fixture.
		 */
		public static function render(string $template, array $data=[], array $options=[]): array {
			self::$calls[]=['method'=>'render', 'template'=>$template, 'data'=>$data, 'options'=>$options];
			return [
				'subject'=>'Hello '.($data['name'] ?? 'there'),
				'html'=>'<p>'.$template.'</p>',
				'text'=>$template,
			];
		}
	}

	/**
	 * In-memory Templating facade used by MVC view rendering regression tests.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class templating {
		public static array $calls=[];

		/**
		 * Clears template rendering call history between MVC view scenarios.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls=[];
		}

		/**
		 * Renders a deterministic HTML shell for a named view template.
		 *
		 * The title value is escaped with the same UTF-8 assumptions as the
		 * framework response path, allowing regression tests to verify output
		 * escaping without loading the full template engine.
		 *
		 * @param string $template View template identifier.
		 * @param array<string,mixed> $data View data passed by controller code.
		 * @param array<string,mixed> $theme_values Theme values forwarded by the framework.
		 * @param array<string,string> $slots Named slot content forwarded by the framework.
		 * @return string Minimal HTML document fragment used by view assertions.
		 */
		public static function render(string $template, array $data=[], array $theme_values=[], array $slots=[]): string {
			self::$calls[]=['method'=>'render', 'template'=>$template, 'data'=>$data, 'theme_values'=>$theme_values, 'slots'=>$slots];
			return '<main>'.htmlspecialchars((string)($data['title'] ?? $template), ENT_QUOTES, 'UTF-8').'</main>';
		}

		/**
		 * Renders an inline template string through the lightweight fixture path.
		 *
		 * Only the {{ name }} placeholder is expanded because the regression suite
		 * uses this method to confirm that inline rendering is selected, not to
		 * validate the production template parser.
		 *
		 * @param string $template Inline template source.
		 * @param array<string,mixed> $data Template data used by the fixture replacement.
		 * @param array<string,mixed> $theme_values Theme values forwarded by the framework.
		 * @param array<string,string> $slots Named slot content forwarded by the framework.
		 * @param string $template_name Synthetic template name for diagnostics.
		 * @return string Inline rendering result.
		 */
		public static function render_string(string $template, array $data=[], array $theme_values=[], array $slots=[], string $template_name='inline.tpl'): string {
			self::$calls[]=['method'=>'render_string', 'template'=>$template, 'data'=>$data, 'theme_values'=>$theme_values, 'slots'=>$slots, 'template_name'=>$template_name];
			return str_replace('{{ name }}', (string)($data['name'] ?? ''), $template);
		}

		/**
		 * Supplies a deterministic asset manifest for a named view template.
		 *
		 * The returned structure covers head, body, combined HTML, and tag arrays
		 * so MVC response composition can assert every supported manifest field.
		 *
		 * @param string $template View template identifier.
		 * @return array{head_html:string,body_html:string,html:string,head_tags:array<int,string>,body_tags:array<int,string>,all_tags:array<int,string>} Asset manifest fixture.
		 */
		public static function asset_manifest(string $template): array {
			self::$calls[]=['method'=>'asset_manifest', 'template'=>$template];
			return [
				'head_html'=>'<link rel="stylesheet" href="/assets/app.css">',
				'body_html'=>'<script src="/assets/app.js"></script>',
				'html'=>'<link rel="stylesheet" href="/assets/app.css">'."\n".'<script src="/assets/app.js"></script>',
				'head_tags'=>['<link rel="stylesheet" href="/assets/app.css">'],
				'body_tags'=>['<script src="/assets/app.js"></script>'],
				'all_tags'=>[
					'<link rel="stylesheet" href="/assets/app.css">',
					'<script src="/assets/app.js"></script>',
				],
			];
		}

		/**
		 * Supplies a deterministic asset manifest for an inline template string.
		 *
		 * This keeps inline template assets distinct from named template assets so
		 * view tests can prove the correct manifest method was selected.
		 *
		 * @param string $template Inline template source.
		 * @param string $template_name Synthetic template name for diagnostics.
		 * @return array{head_tags:array<int,string>,all_tags:array<int,string>} Inline asset manifest fixture.
		 */
		public static function asset_manifest_string(string $template, string $template_name='inline.tpl'): array {
			self::$calls[]=['method'=>'asset_manifest_string', 'template'=>$template, 'template_name'=>$template_name];
			return [
				'head_tags'=>['<link rel="preload" href="/assets/card.css" as="style">'],
				'all_tags'=>['<link rel="preload" href="/assets/card.css" as="style">'],
			];
		}
	}

	/**
	 * In-memory Localization facade used by MVC translation regression tests.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class localization {
		public static array $strings=[];
		public static array $calls=[];

		/**
		 * Clears seeded translations and localization call history.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$strings=[];
			self::$calls=[];
		}

		/**
		 * Resolves a seeded localized string and applies Dataphyre placeholders.
		 *
		 * Lookup first checks the language-page-key tuple, then a global key,
		 * followed by the provided fallback and finally the key itself. Both
		 * <{name}> and :name placeholder forms are supported for regression parity.
		 *
		 * @param string $string_name Translation key.
		 * @param string|null $fallback_string Fallback text when no seed exists.
		 * @param array|null $parameters Placeholder replacements.
		 * @param string|null $forced_language Language override used in the seeded key.
		 * @param string|null $forced_page Page override used in the seeded key.
		 * @return string Resolved fixture translation.
		 */
		public static function locale(string $string_name, ?string $fallback_string=null, ?array $parameters=null, ?string $forced_language=null, ?string $forced_page=null): string {
			self::$calls[]=[
				'method'=>'locale',
				'key'=>$string_name,
				'fallback'=>$fallback_string,
				'parameters'=>$parameters,
				'language'=>$forced_language,
				'page'=>$forced_page,
			];
			$key=($forced_language ?? 'default').'|'.($forced_page ?? '').'|'.$string_name;
			$value=self::$strings[$key] ?? self::$strings[$string_name] ?? $fallback_string ?? $string_name;
			foreach($parameters ?? [] as $name=>$replacement){
				$value=str_replace(['<{'.$name.'}>', ':'.$name], (string)$replacement, $value);
			}
			return $value;
		}
	}

	/**
	 * In-memory Currency facade used by MVC money-formatting regression tests.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class currency {
		public static array $calls=[];

		/**
		 * Clears currency call history between money-formatting scenarios.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls=[];
		}

		/**
		 * Formats an amount using a deterministic currency label and decimal scale.
		 *
		 * This fixture does not inspect locale state. It only exercises controller
		 * and view branches that expect the production formatter's free-label and
		 * currency-prefix behavior.
		 *
		 * @param float|null $amount Amount to format.
		 * @param bool|null $show_free Whether zero should render as the free label.
		 * @param string|null $currency Currency code to prefix; CAD is used when omitted.
		 * @return string Formatted fixture amount.
		 */
		public static function formatter(float|null $amount, bool|null $show_free=false, string|null $currency=null): string {
			self::$calls[]=['method'=>'formatter', 'amount'=>$amount, 'show_free'=>$show_free, 'currency'=>$currency];
			if((float)$amount===0.0 && $show_free===true){
				return 'Free';
			}
			return ($currency ?? 'CAD').' '.number_format((float)$amount, 2, '.', '');
		}

		/**
		 * Converts an amount with a stable multiplier for cross-currency tests.
		 *
		 * The 1.5 multiplier is not a market rate; it is a deterministic fixture
		 * value that lets regression tests assert conversion and formatting paths.
		 *
		 * @param float|null $amount Amount to convert.
		 * @param string $source_currency Source currency code recorded for assertions.
		 * @param string $target_currency Target currency code used for formatted output.
		 * @param bool|null $formatted Whether to return a formatted string instead of a float.
		 * @param bool|null $show_free Free-label flag accepted for signature parity.
		 * @return string|float Converted fixture amount.
		 */
		public static function convert(float|null $amount, string $source_currency, string $target_currency, bool|null $formatted=false, bool|null $show_free=true): string|float {
			self::$calls[]=['method'=>'convert', 'amount'=>$amount, 'source'=>$source_currency, 'target'=>$target_currency, 'formatted'=>$formatted, 'show_free'=>$show_free];
			$value=round(((float)$amount)*1.5, 2);
			return $formatted ? $target_currency.' '.number_format($value, 2, '.', '') : $value;
		}

		/**
		 * Converts an amount into the seeded user currency with a stable multiplier.
		 *
		 * The fixture records the requested currency and uses CAD when formatting
		 * without an explicit user currency.
		 *
		 * @param float|null $amount Amount to convert.
		 * @param bool|null $formatted Whether to return a formatted string instead of a float.
		 * @param bool|null $show_free Free-label flag accepted for signature parity.
		 * @param string|null $currency User currency code for formatted output.
		 * @return string|float Converted fixture amount.
		 */
		public static function convert_to_user_currency(float|null $amount, bool|null $formatted=false, bool|null $show_free=true, string|null $currency=null): string|float {
			self::$calls[]=['method'=>'convert_to_user_currency', 'amount'=>$amount, 'formatted'=>$formatted, 'show_free'=>$show_free, 'currency'=>$currency];
			$value=round(((float)$amount)*1.25, 2);
			return $formatted ? ($currency ?? 'CAD').' '.number_format($value, 2, '.', '') : $value;
		}

		/**
		 * Converts an amount back to the website base currency with a stable multiplier.
		 *
		 * The original currency is recorded for assertions while formatted output
		 * always uses the BASE label expected by MVC fixture tests.
		 *
		 * @param float|null $amount Amount to convert.
		 * @param string $original_currency Source currency recorded for assertions.
		 * @param bool|null $formatted Whether to return a formatted string instead of a float.
		 * @param bool|null $show_free Free-label flag accepted for signature parity.
		 * @return string|float Converted fixture amount.
		 */
		public static function convert_to_website_currency(float|null $amount, string $original_currency, bool|null $formatted=false, bool|null $show_free=true): string|float {
			self::$calls[]=['method'=>'convert_to_website_currency', 'amount'=>$amount, 'original'=>$original_currency, 'formatted'=>$formatted, 'show_free'=>$show_free];
			$value=round(((float)$amount)*0.8, 2);
			return $formatted ? 'BASE '.number_format($value, 2, '.', '') : $value;
		}

		/**
		 * Rounds a fixture amount using card or cash precision.
		 *
		 * Cash mode intentionally rounds to one decimal place so tests can detect
		 * that the cash flag reached the currency layer.
		 *
		 * @param float|null $amount Amount to round.
		 * @param string $currency Currency code recorded for assertions.
		 * @param bool $cash Whether to use the fixture cash precision.
		 * @return float Rounded fixture amount.
		 */
		public static function round_amount(float|null $amount, string $currency, bool $cash=false): float {
			self::$calls[]=['method'=>'round_amount', 'amount'=>$amount, 'currency'=>$currency, 'cash'=>$cash];
			return round((float)$amount, $cash ? 1 : 2);
		}

		/**
		 * Splits an amount into equal fixture parts.
		 *
		 * The result is intentionally simple and does not distribute remainders;
		 * regression tests use it to verify that split requests are routed to the
		 * currency facade with the expected part count.
		 *
		 * @param float|null $amount Amount to split.
		 * @param string $currency Currency code recorded for assertions.
		 * @param int $parts Number of fixture parts to return.
		 * @param bool $cash Whether cash rounding was requested.
		 * @return array<int,float> Equal fixture allocations.
		 */
		public static function split_amount(float|null $amount, string $currency, int $parts, bool $cash=false): array {
			self::$calls[]=['method'=>'split_amount', 'amount'=>$amount, 'currency'=>$currency, 'parts'=>$parts, 'cash'=>$cash];
			$part=round(((float)$amount)/max(1, $parts), 2);
			return array_fill(0, max(0, $parts), $part);
		}

		/**
		 * Allocates an amount across weighted ratios using deterministic rounding.
		 *
		 * Zero-sum ratios are treated as a total of one to avoid division by zero,
		 * matching the fixture's preference for stable assertions over accounting
		 * precision.
		 *
		 * @param float|null $amount Amount to allocate.
		 * @param string $currency Currency code recorded for assertions.
		 * @param array<int,float|int> $ratios Allocation weights.
		 * @param bool $cash Whether cash rounding was requested.
		 * @return array<int,float> Weighted fixture allocations.
		 */
		public static function allocate_amount(float|null $amount, string $currency, array $ratios, bool $cash=false): array {
			self::$calls[]=['method'=>'allocate_amount', 'amount'=>$amount, 'currency'=>$currency, 'ratios'=>$ratios, 'cash'=>$cash];
			$total=array_sum($ratios) ?: 1;
			return array_map(static fn(float|int $ratio): float => round(((float)$amount)*((float)$ratio/$total), 2), $ratios);
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class date_translation {
		public static array $calls=[];

		/**
		 * Clears date translation call history between localization scenarios.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls=[];
		}

		/**
		 * Translates a fixture date string or tags it with language and format.
		 *
		 * English requests return the original string, while other languages get a
		 * deterministic lang|format|date marker that proves translation was invoked.
		 *
		 * @param string $string Date string supplied by the caller.
		 * @param string $lang Requested language code.
		 * @param string $format Requested output format.
		 * @return string|null Translated fixture date marker.
		 */
		public static function translate_date(string $string, string $lang, string $format): ?string {
			self::$calls[]=['date'=>$string, 'language'=>$lang, 'format'=>$format];
			if(str_starts_with($lang, 'en')){
				return $string;
			}
			return $lang.'|'.$format.'|'.$string;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class cache {
		public static array $store=[];
		public static array $expirations=[];
		public static array $calls=[];

		/**
		 * Clears cache values, expiration metadata, and call history.
		 *
		 * Expiration values are recorded for assertions but not enforced by this
		 * in-memory fixture.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$store=[];
			self::$expirations=[];
			self::$calls=[];
		}

		/**
		 * Reads a value from the process-local cache fixture.
		 *
		 * Missing keys return null, matching the cache call sites exercised by MVC
		 * response and middleware regression tests.
		 *
		 * @param string $key Cache key.
		 * @return mixed Seeded value or null when absent.
		 */
		public static function get(string $key): mixed {
			self::$calls[]=['method'=>'get', 'key'=>$key];
			if(!array_key_exists($key, self::$store)){
				return null;
			}
			return self::$store[$key];
		}

		/**
		 * Writes a value and expiration marker into the cache fixture.
		 *
		 * The method always reports success so tests can focus on MVC cache
		 * integration behavior rather than backend failure handling.
		 *
		 * @param string $key Cache key.
		 * @param mixed $value Value to store.
		 * @param int $expiration Expiration seconds recorded for assertions.
		 * @return bool Always true after storing the fixture value.
		 */
		public static function set(string $key, mixed $value, int $expiration=0): bool {
			self::$calls[]=['method'=>'set', 'key'=>$key, 'value'=>$value, 'expiration'=>$expiration];
			self::$store[$key]=$value;
			self::$expirations[$key]=$expiration;
			return true;
		}

		/**
		 * Removes a cache key from the fixture store and expiration map.
		 *
		 * Deleting a missing key is successful to match tolerant cache backends.
		 *
		 * @param string $key Cache key.
		 * @return bool Always true after the delete attempt.
		 */
		public static function delete(string $key): bool {
			self::$calls[]=['method'=>'delete', 'key'=>$key];
			unset(self::$store[$key], self::$expirations[$key]);
			return true;
		}

		/**
		 * Increments an integer cache value by the requested offset.
		 *
		 * Missing and non-integer values are coerced through PHP integer casting so
		 * middleware tests can exercise counter behavior without backend adapters.
		 *
		 * @param string $key Cache key.
		 * @param int $offset Increment amount.
		 * @return int Updated fixture counter value.
		 */
		public static function increment(string $key, int $offset=1): int {
			self::$calls[]=['method'=>'increment', 'key'=>$key, 'offset'=>$offset];
			self::$store[$key]=(int)(self::$store[$key] ?? 0)+$offset;
			return self::$store[$key];
		}

		/**
		 * Decrements an integer cache value without dropping below zero.
		 *
		 * The zero floor is part of the fixture contract and keeps throttling
		 * regression assertions deterministic.
		 *
		 * @param string $key Cache key.
		 * @param int $offset Decrement amount.
		 * @return int Updated fixture counter value.
		 */
		public static function decrement(string $key, int $offset=1): int {
			self::$calls[]=['method'=>'decrement', 'key'=>$key, 'offset'=>$offset];
			self::$store[$key]=max(0, (int)(self::$store[$key] ?? 0)-$offset);
			return self::$store[$key];
		}
	}
}

namespace Dataphyre\Sanitation {
	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class SanitizationResult implements \JsonSerializable {
		/**
		 * Captures sanitized data, validation errors, and the original input payload.
		 *
		 * @param array<string,mixed> $data Sanitized output fields.
		 * @param array<string,list<string>|string> $errors Validation error map keyed by field.
		 * @param array<string,mixed> $input Original input payload retained for JSON assertions.
		 */
		public function __construct(
			private array $data,
			private array $errors=[],
			private array $input=[]
		){}

		/**
		 * Exposes the sanitized fixture payload.
		 *
		 * @return array<string,mixed> Sanitized output fields.
		 */
		public function validated(): array {
			return $this->data;
		}

		/**
		 * Exposes the validation error map captured by the result.
		 *
		 * The current fixture seeds errors explicitly; schema application itself
		 * stays permissive so MVC tests can focus on controller validation flow.
		 *
		 * @return array<string,list<string>|string> Validation error map.
		 */
		public function errors(): array {
			return $this->errors;
		}

		/**
		 * Ensures the fixture result has no errors before continuing.
		 *
		 * This mirrors production-style fluent validation APIs by returning itself
		 * on success and throwing when an error map was seeded into the result.
		 *
		 * @param string|null $message Exception message override.
		 * @param array<string,mixed> $context Additional context accepted for signature parity.
		 * @return self Current result when no errors are present.
		 *
		 * @throws \RuntimeException When the fixture contains validation errors.
		 */
		public function ensureValid(?string $message=null, array $context=[]): self {
			if($this->errors!==[]){
				throw new \RuntimeException($message ?? 'Sanitization failed.');
			}
			return $this;
		}

		/**
		 * Serializes sanitized data, errors, and input for response assertions.
		 *
		 * @return array{data:array<string,mixed>,errors:array<string,list<string>|string>,input:array<string,mixed>} JSON-ready result payload.
		 */
		public function jsonSerialize(): array {
			return [
				'data'=>$this->data,
				'errors'=>$this->errors,
				'input'=>$this->input,
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class InputBag {
		/**
		 * Wraps an input array in a small sanitation helper.
		 *
		 * @param array<string,mixed> $input Raw input payload.
		 */
		public function __construct(private array $input){}

		/**
		 * Sanitizes one input key with the requested rule or returns a default.
		 *
		 * Missing keys do not create calls to Sanitation::sanitize(), making it
		 * possible for tests to distinguish absent input from rejected input.
		 *
		 * @param string $key Input key to read.
		 * @param string|array $rule Sanitation rule name or rule definition.
		 * @param mixed $default Value returned when the key is absent.
		 * @return mixed Sanitized value or the default for missing keys.
		 */
		public function clean(string $key, string|array $rule='default', mixed $default=null): mixed {
			if(!array_key_exists($key, $this->input)){
				return $default;
			}
			return Sanitation::sanitize($this->input[$key], $rule);
		}

		/**
		 * Sanitizes one input key as an email address.
		 *
		 * The fixture maps sanitizer failure back to the provided default so
		 * controller tests can assert nullable email handling through one helper.
		 *
		 * @param string $key Input key to read.
		 * @param string|null $default Value returned when missing or invalid.
		 * @return string|null Sanitized email value or default.
		 */
		public function email(string $key, ?string $default=null): ?string {
			$value=$this->clean($key, 'email', $default);
			return $value===false ? $default : $value;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class Sanitizer {
		/**
		 * Creates a fluent sanitizer wrapper around one value.
		 *
		 * @param mixed $value Raw value to sanitize through fluent helpers.
		 */
		public function __construct(private mixed $value){}

		/**
		 * Sanitizes the wrapped value as a URL slug.
		 *
		 * @return string Lowercase hyphenated slug fixture.
		 */
		public function slug(): string {
			return Sanitation::sanitize($this->value, 'slug');
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class Sanitation {
		public static array $calls=[];
		private static array $presets=[];

		/**
		 * Clears sanitation call history and registered schema presets.
		 *
		 * Presets are process-local to this regression file so each scenario can
		 * register only the schema definitions it intends to exercise.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls=[];
			self::$presets=[];
		}

		/**
		 * Masks the local part of an email address after the requested prefix.
		 *
		 * This deterministic anonymizer is used by response tests that need to
		 * verify personal-data redaction without depending on the production
		 * sanitation service.
		 *
		 * @param string $email Email address containing a local part and domain.
		 * @param int $count Number of leading local-part characters to preserve.
		 * @param string $char Masking character repeated across the remaining local part.
		 * @return string Anonymized email fixture.
		 */
		public static function anonymizeEmail(string $email, int $count=2, string $char='*'): string {
			self::$calls[]=['method'=>'anonymizeEmail', 'email'=>$email, 'count'=>$count, 'char'=>$char];
			[$local, $domain]=explode('@', trim($email), 2);
			return substr($local, 0, $count).str_repeat($char, max(0, strlen($local)-$count)).'@'.$domain;
		}

		/**
		 * Applies the minimal sanitizer rule set needed by MVC regression tests.
		 *
		 * Supported fixture rules are email, integer, slug, and default string
		 * cleanup. Array rules read their type from the "type" entry while options
		 * are recorded for assertions but not interpreted.
		 *
		 * @param mixed $value Raw value to sanitize.
		 * @param string|array $rule Rule name or rule definition.
		 * @param array<string,mixed> $options Additional options forwarded by callers.
		 * @return mixed Sanitized fixture value, or false for rejected email/integer values.
		 */
		public static function sanitize(mixed $value, string|array $rule='default', array $options=[]): mixed {
			self::$calls[]=['method'=>'sanitize', 'value'=>$value, 'rule'=>$rule, 'options'=>$options];
			$type=is_array($rule) ? (string)($rule['type'] ?? 'default') : $rule;
			$value=is_string($value) ? trim($value) : $value;
			return match($type){
				'email'=>is_string($value) ? strtolower($value) : false,
				'integer'=>is_numeric($value) ? (int)$value : false,
				'slug'=>strtolower(str_replace(' ', '-', preg_replace('/[^A-Za-z0-9 ]/', '', (string)$value) ?? '')),
				default=>is_string($value) ? strip_tags($value) : $value,
			};
		}

		/**
		 * Starts a fluent sanitizer chain for one value.
		 *
		 * @param mixed $value Raw value to wrap.
		 * @return Sanitizer Fluent sanitizer fixture.
		 */
		public static function string(mixed $value): Sanitizer {
			self::$calls[]=['method'=>'string', 'value'=>$value];
			return new Sanitizer($value);
		}

		/**
		 * Wraps an input payload in an InputBag fixture.
		 *
		 * @param array<string,mixed> $input Raw input payload.
		 * @return InputBag Input helper bound to the provided payload.
		 */
		public static function bag(array $input): InputBag {
			self::$calls[]=['method'=>'bag', 'input'=>$input];
			return new InputBag($input);
		}

		/**
		 * Registers a named schema preset for later fixture validation calls.
		 *
		 * Definitions may be arrays or callables returning arrays. The fixture
		 * resolves callable presets lazily so tests can assert factory behavior.
		 *
		 * @param string $name Preset name.
		 * @param array<string,mixed>|callable $definition Schema definition or factory.
		 * @return void
		 */
		public static function registerPreset(string $name, array|callable $definition): void {
			self::$calls[]=['method'=>'registerPreset', 'name'=>$name];
			self::$presets[$name]=$definition;
		}

		/**
		 * Applies a schema and wraps the sanitized fields in a result object.
		 *
		 * Only schema fields present in the input are sanitized; defaults are used
		 * as the initial output payload and remain untouched for absent fields.
		 *
		 * @param array<string,mixed> $input Raw input payload.
		 * @param array<string,string|array<string,mixed>> $schema Field-to-rule schema map.
		 * @param array<string,mixed> $defaults Initial output values.
		 * @param array<string,mixed> $options Options recorded for assertion parity.
		 * @return SanitizationResult Result object containing sanitized data and original input.
		 */
		public static function schema(array $input, array $schema, array $defaults=[], array $options=[]): SanitizationResult {
			self::$calls[]=['method'=>'schema', 'input'=>$input, 'schema'=>$schema, 'defaults'=>$defaults, 'options'=>$options];
			return new SanitizationResult(self::applySchema($input, $schema, $defaults), [], $input);
		}

		/**
		 * Applies a schema and returns the sanitized payload directly.
		 *
		 * This path mirrors controller helpers that want validated data without a
		 * wrapper object while preserving the same fixture schema semantics.
		 *
		 * @param array<string,mixed> $input Raw input payload.
		 * @param array<string,string|array<string,mixed>> $schema Field-to-rule schema map.
		 * @param array<string,mixed> $defaults Initial output values.
		 * @param array<string,mixed> $options Options recorded for assertion parity.
		 * @return array<string,mixed> Sanitized payload.
		 */
		public static function validated(array $input, array $schema, array $defaults=[], array $options=[]): array {
			self::$calls[]=['method'=>'validated', 'input'=>$input, 'schema'=>$schema, 'defaults'=>$defaults, 'options'=>$options];
			return self::applySchema($input, $schema, $defaults);
		}

		/**
		 * Applies a schema through the exception-oriented API surface.
		 *
		 * The regression fixture is permissive and does not synthesize validation
		 * errors, so the message is captured for assertions and successful data is
		 * returned directly.
		 *
		 * @param array<string,mixed> $input Raw input payload.
		 * @param array<string,string|array<string,mixed>> $schema Field-to-rule schema map.
		 * @param array<string,mixed> $defaults Initial output values.
		 * @param array<string,mixed> $options Options recorded for assertion parity.
		 * @param string|null $message Exception message that production code would use on failure.
		 * @return array<string,mixed> Sanitized payload.
		 */
		public static function schemaOrFail(array $input, array $schema, array $defaults=[], array $options=[], ?string $message=null): array {
			self::$calls[]=['method'=>'schemaOrFail', 'input'=>$input, 'schema'=>$schema, 'defaults'=>$defaults, 'options'=>$options, 'message'=>$message];
			return self::applySchema($input, $schema, $defaults);
		}

		/**
		 * Applies a named preset and wraps sanitized fields in a result object.
		 *
		 * Preset overrides replace fields in the resolved preset schema, allowing
		 * tests to check route-specific schema adjustments without mutating the
		 * registered preset.
		 *
		 * @param string $name Preset name.
		 * @param array<string,mixed> $input Raw input payload.
		 * @param array<string,string|array<string,mixed>> $preset_overrides Field rules that replace preset entries.
		 * @param array<string,mixed> $defaults Initial output values.
		 * @param array<string,mixed> $options Options recorded for assertion parity.
		 * @return SanitizationResult Result object containing sanitized data and original input.
		 */
		public static function preset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): SanitizationResult {
			self::$calls[]=['method'=>'preset', 'name'=>$name, 'input'=>$input, 'overrides'=>$preset_overrides, 'defaults'=>$defaults, 'options'=>$options];
			return new SanitizationResult(self::applySchema($input, self::presetSchema($name, $preset_overrides), $defaults), [], $input);
		}

		/**
		 * Applies a named preset and returns sanitized fields directly.
		 *
		 * @param string $name Preset name.
		 * @param array<string,mixed> $input Raw input payload.
		 * @param array<string,string|array<string,mixed>> $preset_overrides Field rules that replace preset entries.
		 * @param array<string,mixed> $defaults Initial output values.
		 * @param array<string,mixed> $options Options recorded for assertion parity.
		 * @return array<string,mixed> Sanitized payload.
		 */
		public static function validatedPreset(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[]): array {
			self::$calls[]=['method'=>'validatedPreset', 'name'=>$name, 'input'=>$input, 'overrides'=>$preset_overrides, 'defaults'=>$defaults, 'options'=>$options];
			return self::applySchema($input, self::presetSchema($name, $preset_overrides), $defaults);
		}

		/**
		 * Applies a named preset through the exception-oriented API surface.
		 *
		 * The message is recorded for parity with production callers; this fixture
		 * returns sanitized data unless the underlying sanitizer rejects an
		 * individual field with false.
		 *
		 * @param string $name Preset name.
		 * @param array<string,mixed> $input Raw input payload.
		 * @param array<string,string|array<string,mixed>> $preset_overrides Field rules that replace preset entries.
		 * @param array<string,mixed> $defaults Initial output values.
		 * @param array<string,mixed> $options Options recorded for assertion parity.
		 * @param string|null $message Exception message that production code would use on failure.
		 * @return array<string,mixed> Sanitized payload.
		 */
		public static function presetOrFail(string $name, array $input, array $preset_overrides=[], array $defaults=[], array $options=[], ?string $message=null): array {
			self::$calls[]=['method'=>'presetOrFail', 'name'=>$name, 'input'=>$input, 'overrides'=>$preset_overrides, 'defaults'=>$defaults, 'options'=>$options, 'message'=>$message];
			return self::applySchema($input, self::presetSchema($name, $preset_overrides), $defaults);
		}

		/**
		 * Resolves a preset schema and overlays call-specific rule overrides.
		 *
		 * Callable presets are evaluated on demand. Definitions may either expose a
		 * top-level "schema" entry or be the schema map themselves.
		 *
		 * @param string $name Preset name.
		 * @param array<string,string|array<string,mixed>> $preset_overrides Field rules that replace preset entries.
		 * @return array<string,string|array<string,mixed>> Resolved field-to-rule schema map.
		 */
		private static function presetSchema(string $name, array $preset_overrides=[]): array {
			$definition=self::$presets[$name] ?? [];
			if(is_callable($definition)){
				$definition=$definition();
			}
			$schema=(array)($definition['schema'] ?? $definition);
			return array_replace($schema, $preset_overrides);
		}

		/**
		 * Applies field rules to present input keys while preserving defaults.
		 *
		 * This helper is the shared schema engine for every sanitation fixture API.
		 * It intentionally ignores schema fields absent from input so default values
		 * keep their caller-provided shape.
		 *
		 * @param array<string,mixed> $input Raw input payload.
		 * @param array<string,string|array<string,mixed>> $schema Field-to-rule schema map.
		 * @param array<string,mixed> $defaults Initial output values.
		 * @return array<string,mixed> Sanitized payload.
		 */
		private static function applySchema(array $input, array $schema, array $defaults=[]): array {
			$data=$defaults;
			foreach($schema as $field=>$rule){
				if(array_key_exists((string)$field, $input)){
					$data[(string)$field]=self::sanitize($input[(string)$field], $rule);
				}
			}
			return $data;
		}
	}
}

namespace Dataphyre\Async {
	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class Async {
		public static array $calls=[];
		public static int $next_timer_id=100;

		/**
		 * Clears async call history and restores deterministic timer ids.
		 *
		 * Timer ids start at 100 so assertions can distinguish fixture timers from
		 * incidental integer counters elsewhere in the regression runner.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls=[];
			self::$next_timer_id=100;
		}

		/**
		 * Dispatches a task through the fake async boundary.
		 *
		 * Callable tasks execute synchronously with the supplied arguments; other
		 * task descriptors are recorded but produce a null value so queue-style
		 * dispatch can be tested without a worker.
		 *
		 * @param mixed $task Callable or task descriptor.
		 * @param list<mixed> $arguments Arguments passed to callable tasks.
		 * @param string|null $driver Async driver alias recorded for assertions.
		 * @return array{method:string,driver:string|null,value:mixed} Dispatch receipt fixture.
		 */
		public static function dispatch(mixed $task, array $arguments=[], ?string $driver=null): array {
			self::$calls[]=['method'=>'dispatch', 'task'=>$task, 'arguments'=>$arguments, 'driver'=>$driver];
			return [
				'method'=>'dispatch',
				'driver'=>$driver,
				'value'=>is_callable($task) ? $task(...$arguments) : null,
			];
		}

		/**
		 * Executes a callable through the inline async path.
		 *
		 * This fixture makes inline execution observable while preserving the same
		 * receipt shape used by controller helper tests.
		 *
		 * @param mixed $task Callable or task descriptor.
		 * @param list<mixed> $arguments Arguments passed to callable tasks.
		 * @return array{method:string,value:mixed} Inline execution receipt fixture.
		 */
		public static function inline(mixed $task, array $arguments=[]): array {
			self::$calls[]=['method'=>'inline', 'task'=>$task, 'arguments'=>$arguments];
			return [
				'method'=>'inline',
				'value'=>is_callable($task) ? $task(...$arguments) : null,
			];
		}

		/**
		 * Records a batch async dispatch request without executing task bodies.
		 *
		 * The returned count lets controller tests verify batching and driver
		 * propagation without introducing scheduling order into assertions.
		 *
		 * @param list<mixed> $tasks Task descriptors or callables supplied by the caller.
		 * @param string|null $driver Async driver alias recorded for assertions.
		 * @return array{method:string,driver:string|null,count:int} Batch dispatch receipt fixture.
		 */
		public static function all(array $tasks, ?string $driver=null): array {
			self::$calls[]=['method'=>'all', 'tasks'=>$tasks, 'driver'=>$driver];
			return [
				'method'=>'all',
				'driver'=>$driver,
				'count'=>count($tasks),
			];
		}

		/**
		 * Executes a delayed callback immediately and returns a deterministic timer id.
		 *
		 * Immediate execution keeps tests synchronous while preserving the timer
		 * scheduling contract expected by controller helper code.
		 *
		 * @param callable $task Callback scheduled by the caller.
		 * @param int $milliseconds Requested delay recorded for assertions.
		 * @return int Fixture timer id.
		 */
		public static function after(callable $task, int $milliseconds): int {
			self::$calls[]=['method'=>'after', 'milliseconds'=>$milliseconds];
			$task();
			return self::$next_timer_id++;
		}

		/**
		 * Executes a recurring callback once and returns a deterministic timer id.
		 *
		 * The fixture does not repeat callbacks; it only proves the recurring path
		 * was selected and that cancellation can target the returned id.
		 *
		 * @param callable $task Callback scheduled by the caller.
		 * @param int $milliseconds Requested interval recorded for assertions.
		 * @return int Fixture timer id.
		 */
		public static function every(callable $task, int $milliseconds): int {
			self::$calls[]=['method'=>'every', 'milliseconds'=>$milliseconds];
			$task();
			return self::$next_timer_id++;
		}

		/**
		 * Records a timer cancellation request.
		 *
		 * @param int $task_id Timer id returned by after() or every().
		 * @return void
		 */
		public static function cancel(int $task_id): void {
			self::$calls[]=['method'=>'cancel', 'task_id'=>$task_id];
		}
	}
}

namespace Dataphyre\Reactor {
	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ReactorResponse implements \JsonSerializable {
		/**
		 * Captures a Reactor payload and HTTP status for response conversion tests.
		 *
		 * @param array<string,mixed> $payload Reactor payload fields.
		 * @param int $status HTTP status code.
		 */
		public function __construct(
			private array $payload,
			private int $status=200
		){}

		/**
		 * Exposes the HTTP status attached to the fake Reactor response.
		 *
		 * @return int HTTP status code.
		 */
		public function status(): int {
			return $this->status;
		}

		/**
		 * Serializes the response with status first for JSON response assertions.
		 *
		 * @return array<string,mixed> Reactor payload merged with the status code.
		 */
		public function jsonSerialize(): array {
			return ['status'=>$this->status]+$this->payload;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class Reactor {
		public static array $calls=[];

		/**
		 * Clears Reactor call history between component regression scenarios.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls=[];
		}

		/**
		 * Renders a deterministic component mount marker.
		 *
		 * State and attributes are recorded for assertions while the returned HTML
		 * only includes the component name and optional label needed by view tests.
		 *
		 * @param string $component Component name.
		 * @param array<string,mixed> $state Initial component state.
		 * @param array<string,string|int|float|bool|null> $attributes Mount attributes forwarded by the controller.
		 * @return string HTML fixture for a mounted component.
		 */
		public static function mount(string $component, array $state=[], array $attributes=[]): string {
			self::$calls[]=['method'=>'mount', 'component'=>$component, 'state'=>$state, 'attributes'=>$attributes];
			return '<div data-reactor-component="'.$component.'">'.($state['label'] ?? '').'</div>';
		}

		/**
		 * Dispatches a Reactor request into a deterministic response object.
		 *
		 * Array requests may provide an action field; object and null requests fall
		 * back to refresh so controller response normalization remains predictable.
		 *
		 * @param array|object|null $request Reactor request payload.
		 * @return ReactorResponse Fake Reactor response ready for MVC conversion.
		 */
		public static function dispatch(array|object|null $request=null): ReactorResponse {
			self::$calls[]=['method'=>'dispatch', 'request'=>$request];
			$action=is_array($request) ? (string)($request['action'] ?? 'refresh') : 'refresh';
			return new ReactorResponse([
				'ok'=>true,
				'html'=>'<strong>'.$action.'</strong>',
				'state'=>['action'=>$action],
				'effects'=>[],
				'message'=>'',
			], 200);
		}

		/**
		 * Provides the minimal component manifest used by Reactor helper tests.
		 *
		 * @return array{module:string,components:array<string,array{name:string}>} Reactor manifest fixture.
		 */
		public static function manifest(): array {
			self::$calls[]=['method'=>'manifest'];
			return [
				'module'=>'reactor',
				'components'=>['counter'=>['name'=>'counter']],
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ReactorEndpoint {
		/**
		 * Routes a single endpoint request to the Reactor dispatcher.
		 *
		 * @param array|object|null $request Reactor request payload.
		 * @return ReactorResponse Fake Reactor response.
		 */
		public static function handle(array|object|null $request=null): ReactorResponse {
			Reactor::$calls[]=['method'=>'endpoint.handle', 'request'=>$request];
			return Reactor::dispatch($request);
		}

		/**
		 * Routes a batch endpoint request to the Reactor dispatcher.
		 *
		 * Each request is serialized after dispatch so MVC tests can assert the
		 * endpoint's array response format without invoking HTTP transport.
		 *
		 * @param array<int,array|object>|null $requests Batch request payloads.
		 * @return array<int,array> Serialized Reactor responses.
		 */
		public static function handleBatch(?array $requests=null): array {
			Reactor::$calls[]=['method'=>'endpoint.batch', 'requests'=>$requests];
			$responses=[];
			foreach($requests ?? [] as $request){
				$responses[]=Reactor::dispatch($request)->jsonSerialize();
			}
			return $responses;
		}
	}
}

namespace Dataphyre\Mvc\Regression {
	use Dataphyre\Http\Request;
	use Dataphyre\Mvc\MvcRouteContext;

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class HeaderMiddleware {
		/**
		 * Adds a marker header after the downstream MVC handler completes.
		 *
		 * @param Request $request Incoming HTTP request fixture.
		 * @param callable $next Next middleware or controller callable.
		 * @return mixed Response returned by the downstream handler.
		 */
		public function handle(Request $request, callable $next): mixed {
			$response=$next($request);
			$response->headers['X-Middleware']='seen';
			return $response;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class TagMiddleware {
		/**
		 * Adds the supplied middleware parameter as a response header.
		 *
		 * @param Request $request Incoming HTTP request fixture.
		 * @param callable $next Next middleware or controller callable.
		 * @param string $tag Route middleware parameter.
		 * @return mixed Response returned by the downstream handler.
		 */
		public function handle(Request $request, callable $next, string $tag): mixed {
			$response=$next($request);
			$response->headers['X-Tag']=$tag;
			return $response;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class StackMiddleware {
		/**
		 * Appends a middleware tag to the response stack header.
		 *
		 * This verifies nested middleware ordering by accumulating tags in the
		 * order their after-handlers run.
		 *
		 * @param Request $request Incoming HTTP request fixture.
		 * @param callable $next Next middleware or controller callable.
		 * @param string $tag Middleware stack marker.
		 * @return mixed Response returned by the downstream handler.
		 */
		public function handle(Request $request, callable $next, string $tag): mixed {
			$response=$next($request);
			$existing=(string)($response->headers['X-Stack'] ?? '');
			$response->headers['X-Stack']=$existing.$tag;
			return $response;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class TerminableMiddleware {
		/**
		 * Adds a handled marker before terminable middleware is finalized.
		 *
		 * @param Request $request Incoming HTTP request fixture.
		 * @param callable $next Next middleware or controller callable.
		 * @param string $tag Middleware parameter.
		 * @return mixed Response returned by the downstream handler.
		 */
		public function handle(Request $request, callable $next, string $tag): mixed {
			$response=$next($request);
			$response->headers['X-Handled']=$tag;
			return $response;
		}

		/**
		 * Records terminate-time request and response details in a header.
		 *
		 * @param Request $request Request passed to the terminable middleware phase.
		 * @param \Dataphyre\Http\Response $response Response being finalized.
		 * @param string $tag Middleware parameter.
		 * @return void
		 */
		public function terminate(Request $request, \Dataphyre\Http\Response $response, string $tag): void {
			$response->headers['X-Terminated']=$tag.':'.$request->path().':'.$response->status;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ExampleController extends \Dataphyre\Mvc\Controller {
		/**
		 * Returns a route parameter captured from controller action dispatch.
		 *
		 * @param Request $request Current request fixture.
		 * @param string $name Route parameter injected by the dispatcher.
		 * @return array{controller:string} Controller dispatch payload.
		 */
		public function show(Request $request, string $name): array {
			return ['controller'=>$name];
		}

		/**
		 * Exposes route context injection alongside the scalar route parameter.
		 *
		 * @param Request $request Current request fixture.
		 * @param MvcRouteContext $context MVC route context injected by the dispatcher.
		 * @param string $name Route parameter injected by the dispatcher.
		 * @return array{route:string|null,name:string,parameter:mixed} Route context payload.
		 */
		public function context(Request $request, MvcRouteContext $context, string $name): array {
			return [
				'route'=>$context->name(),
				'name'=>$name,
				'parameter'=>$context->parameter('name'),
			];
		}

		/**
		 * Builds a redirect result through the controller route helper.
		 *
		 * @param Request $request Current request fixture.
		 * @param string $name Route parameter forwarded to the named route.
		 * @return \Dataphyre\Mvc\RedirectResult Named-route redirect fixture.
		 */
		public function redirectToHello(Request $request, string $name): \Dataphyre\Mvc\RedirectResult {
			return $this->redirectToRoute('hello', ['name'=>$name], ['from'=>'controller']);
		}

		/**
		 * Creates a temporary file and returns it through the download helper.
		 *
		 * The file is deleted after the response object is created, exercising the
		 * controller helper's ability to capture download metadata immediately.
		 *
		 * @param string $name File stem used by the route.
		 * @return \Dataphyre\Http\Response File download response fixture.
		 */
		public function downloadSample(string $name): \Dataphyre\Http\Response {
			$file=sys_get_temp_dir().'/dataphyre_mvc_download_'.$name.'.txt';
			file_put_contents($file, 'download-'.$name);
			try{
				return $this->download($file, $name.'.txt');
			}finally{
				if(is_file($file)){
					@unlink($file);
				}
			}
		}

		/**
		 * Aborts through the controller helper with a custom header.
		 *
		 * @param string $name Route parameter included in the error message.
		 * @return never
		 *
		 * @throws \Dataphyre\Mvc\HttpException Always thrown with status 403.
		 */
		public function abortSample(string $name): never {
			$this->abort(403, 'No '.$name, ['X-Abort'=>'controller']);
		}

		/**
		 * Returns a created response using the controller helper.
		 *
		 * @param string $name Route parameter included in the response payload.
		 * @return \Dataphyre\Http\Response Created response fixture.
		 */
		public function createdSample(string $name): \Dataphyre\Http\Response {
			return $this->created(['created'=>$name], '/controller/'.$name);
		}

		/**
		 * Returns an empty no-content response using the controller helper.
		 *
		 * @return \Dataphyre\Http\Response No-content response fixture.
		 */
		public function noContentSample(): \Dataphyre\Http\Response {
			return $this->noContent();
		}

		/**
		 * Runs controller-level validation against route and request input.
		 *
		 * @param Request $request Current request fixture.
		 * @param string $category Route parameter retained for action signature coverage.
		 * @return array{validated:array} Validated request payload.
		 */
		public function validateSample(Request $request, string $category): array {
			return ['validated'=>$this->validate($request, [
				'category'=>'required|string',
				'name'=>'required|string|min:3',
				'email'=>'required|email',
			])];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ControllerMiddlewareController extends \Dataphyre\Mvc\Controller {
		/**
		 * Registers controller-level middleware constraints for dispatch tests.
		 */
		public function __construct(){
			$this->middleware('header')->only('show');
			$this->middleware('tag:controller')->except('plain');
		}

		/**
		 * Confirms that only/except middleware rules allow this action to be wrapped.
		 *
		 * @return array{controller_middleware:bool} Controller middleware payload.
		 */
		public function show(): array {
			return ['controller_middleware'=>true];
		}

		/**
		 * Confirms that except middleware rules leave this action untagged.
		 *
		 * @return array{plain:bool} Plain action payload.
		 */
		public function plain(): array {
			return ['plain'=>true];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class AccessController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exposes controller authentication helper results as a response payload.
		 *
		 * @return array{logged_in:bool,user_id:bool|int|string,auth_type:string|null} Access helper payload.
		 */
		public function show(): array {
			return [
				'logged_in'=>$this->loggedIn(),
				'user_id'=>$this->userId(),
				'auth_type'=>$this->authContext()['auth_type'] ?? null,
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class PermissionController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exposes all-of and any-of permission helper decisions.
		 *
		 * @return array{can_view:bool,can_any:bool} Permission helper payload.
		 */
		public function show(): array {
			return [
				'can_view'=>$this->can('orders.view'),
				'can_any'=>$this->canAny(['orders.refund', 'orders.cancel']),
			];
		}

		/**
		 * Exercises exception-oriented authorization from a controller action.
		 *
		 * @return array{updated:bool} Update payload after authorization succeeds.
		 */
		public function update(): array {
			$this->authorize('orders.update');
			return ['updated'=>true];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class StorageController extends \Dataphyre\Mvc\Controller {
		/**
		 * Streams a seeded storage object inline through the controller helper.
		 *
		 * @return \Dataphyre\Http\Response Inline storage file response.
		 */
		public function inline(): \Dataphyre\Http\Response {
			return $this->storageFile('docs/readme.txt', 'public');
		}

		/**
		 * Streams a seeded storage object as a named download.
		 *
		 * @return \Dataphyre\Http\Response Storage download response.
		 */
		public function storedDownload(): \Dataphyre\Http\Response {
			return $this->storageDownload('docs/readme.txt', 'public', 'readme-download.txt');
		}

		/**
		 * Generates a seeded temporary storage URL through the controller helper.
		 *
		 * @return array{url:string|false} Temporary storage URL payload.
		 */
		public function temporary(): array {
			return [
				'url'=>$this->storageTemporaryUrl('docs/readme.txt', time()+60, 'public'),
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class MailerController extends \Dataphyre\Mvc\Controller {
		/**
		 * Sends a deterministic mail payload through the controller helper.
		 *
		 * @return array Mail delivery receipt fixture.
		 */
		public function send(): array {
			return $this->sendMail([
				'to'=>'customer@example.test',
				'subject'=>'Receipt',
				'html'=>'<p>Receipt</p>',
			], 'log', ['campaign'=>'orders']);
		}

		/**
		 * Queues a deterministic mail payload through the controller helper.
		 *
		 * @return array Mail queue receipt fixture.
		 */
		public function queue(): array {
			return $this->queueMail([
				'to'=>'customer@example.test',
				'subject'=>'Queued',
				'text'=>'Queued',
			], 'log', ['delay'=>30]);
		}

		/**
		 * Renders a deterministic mail preview through the controller helper.
		 *
		 * @return array Rendered mail payload fixture.
		 */
		public function render(): array {
			return $this->renderMail('mail.receipt', ['name'=>'Avery'], ['locale'=>'en']);
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class TemplatingController extends \Dataphyre\Mvc\Controller {
		/**
		 * Renders a named template through the controller view helper.
		 *
		 * @return array{html:string} Rendered template payload.
		 */
		public function renderFile(): array {
			return [
				'html'=>$this->renderTemplate('home.tpl', ['title'=>'Welcome'], ['theme'=>'dark'], ['aside'=>'A']),
			];
		}

		/**
		 * Renders an inline template string through the controller helper.
		 *
		 * @return array{html:string} Rendered inline template payload.
		 */
		public function renderInline(): array {
			return [
				'html'=>$this->renderTemplateString('Hello {{ name }}', ['name'=>'Avery'], [], [], 'hello.inline.tpl'),
			];
		}

		/**
		 * Exposes named and inline template asset helper outputs.
		 *
		 * @return array{head:string,body:string,inline:string,manifest:array} Template asset payload.
		 */
		public function assets(): array {
			return [
				'head'=>$this->templateAssetHtml('home.tpl', 'head'),
				'body'=>\Dataphyre\Mvc\Mvc::templateAssetHtml('home.tpl', 'body'),
				'inline'=>$this->templateStringAssetHtml('<x-card />', 'all', 'card.inline.tpl'),
				'manifest'=>$this->templateAssets('home.tpl'),
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class LocalizationController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exercises translation, existence, missing, and plural-choice helpers.
		 *
		 * @return array Localization helper payload.
		 */
		public function show(): array {
			return [
				'title'=>$this->translate('local:title', 'Hello <{name}>', ['name'=>'Avery'], 'fr-CA', 'home'),
				'missing'=>$this->translateOrNull('missing:key'),
				'has'=>$this->translationHas('global:known', 'fr-CA'),
				'missing_check'=>$this->translationMissing('missing:key'),
				'choice'=>$this->choice(2, 'items.one', 'items.many', 'items.zero', ['thing'=>'orders'], 'fr-CA', 'cart'),
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class CurrencyController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exercises all controller money helper paths against the currency fixture.
		 *
		 * @return array Currency helper payload.
		 */
		public function show(): array {
			return [
				'format'=>$this->moneyFormat(12.5, false, 'CAD'),
				'convert'=>$this->moneyConvert(10, 'USD', 'CAD', true, false),
				'display'=>$this->moneyToDisplay(8, true, true, 'EUR'),
				'base'=>$this->moneyToBase(20, 'EUR'),
				'round'=>$this->moneyRound(12.345, 'CAD'),
				'split'=>$this->moneySplit(10, 'CAD', 3),
				'allocate'=>$this->moneyAllocate(10, 'CAD', [1, 3], true),
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class DateTranslationController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exercises translated-date and localized-date controller helpers.
		 *
		 * @return array{french:string|null,english:string|null} Date translation payload.
		 */
		public function show(): array {
			return [
				'french'=>$this->translateDate('March 15th', 'fr-CA', 'F jS'),
				'english'=>$this->localizedDate('March 15th', 'en-CA', 'F jS'),
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class SanitationController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exercises request sanitation, input bag email cleanup, schema defaults, and anonymization.
		 *
		 * @param Request $request Current request fixture.
		 * @return array Sanitation helper payload.
		 */
		public function cleanRequest(Request $request): array {
			$bag=$this->inputBag($request);
			return [
				'name'=>$this->sanitize($request->input('name'), 'default'),
				'email'=>$bag->email('email'),
				'validated'=>$this->sanitized($request, [
					'name'=>'default',
					'age'=>'integer',
				], ['role'=>'customer']),
				'anonymized'=>$this->anonymizeEmail('avery@example.test', 2),
			];
		}

		/**
		 * Applies the registered profile sanitation preset to request input.
		 *
		 * @param Request $request Current request fixture.
		 * @return array Sanitized preset payload.
		 */
		public function preset(Request $request): array {
			return $this->sanitizedPreset('profile', $request);
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class CacheController extends \Dataphyre\Mvc\Controller {
		/**
		 * Verifies cache remember computes once and preserves the stored TTL marker.
		 *
		 * @return array{first:mixed,second:mixed,computed:int,ttl:int|null} Cache remember payload.
		 */
		public function remember(): array {
			$computed=0;
			$first=$this->cacheRemember('mvc:remember', 120, static function() use (&$computed): string {
				$computed++;
				return 'computed';
			});
			$second=$this->cacheRemember('mvc:remember', 120, static function() use (&$computed): string {
				$computed++;
				return 'again';
			});
			return [
				'first'=>$first,
				'second'=>$second,
				'computed'=>$computed,
				'ttl'=>\dataphyre\cache::$expirations['mvc:remember'] ?? null,
			];
		}

		/**
		 * Exercises cache put, increment, decrement, and read helpers together.
		 *
		 * @return array{increment:int,decrement:int,value:mixed} Cache counter payload.
		 */
		public function counters(): array {
			$this->cachePut('mvc:counter', 5);
			return [
				'increment'=>$this->cacheIncrement('mvc:counter', 3),
				'decrement'=>$this->cacheDecrement('mvc:counter', 4),
				'value'=>$this->cacheGet('mvc:counter'),
			];
		}

		/**
		 * Exercises cache deletion followed by a defaulted read.
		 *
		 * @return array{forgotten:bool,value:mixed} Cache forget payload.
		 */
		public function forget(): array {
			$this->cachePut('mvc:forget', 'value');
			$forgotten=$this->cacheForget('mvc:forget');
			return [
				'forgotten'=>$forgotten,
				'value'=>$this->cacheGet('mvc:forget', 'fallback'),
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class AsyncController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exercises controller async dispatch, inline execution, and batch helpers.
		 *
		 * @return array{dispatch:array,inline:array,all:array} Async helper payload.
		 */
		public function dispatch(): array {
			return [
				'dispatch'=>$this->asyncDispatch(static fn(int $left, int $right): int => $left+$right, [2, 5], 'inline'),
				'inline'=>$this->asyncInline(static fn(string $name): string => 'hello '.$name, ['Avery']),
				'all'=>$this->asyncAll([
					static fn(): string => 'a',
					static fn(): string => 'b',
				], 'inline'),
			];
		}

		/**
		 * Exercises one-shot, recurring, and cancellation timer helpers.
		 *
		 * @return array{after:int,every:int,events:array<int,string>} Timer helper payload.
		 */
		public function timers(): array {
			$events=[];
			$after=$this->asyncAfter(static function() use (&$events): void {
				$events[]='after';
			}, 250);
			$every=$this->asyncEvery(static function() use (&$events): void {
				$events[]='every';
			}, 500);
			$this->asyncCancel($every);
			return [
				'after'=>$after,
				'every'=>$every,
				'events'=>$events,
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ReactorController extends \Dataphyre\Mvc\Controller {
		/**
		 * Mounts a Reactor component through the controller helper.
		 *
		 * @return array{html:string} Reactor mount payload.
		 */
		public function mount(): array {
			return [
				'html'=>$this->reactorMount('counter', ['label'=>'Count'], ['class'=>'widget']),
			];
		}

		/**
		 * Dispatches a single Reactor request and converts it to an HTTP response.
		 *
		 * @return \Dataphyre\Http\Response Reactor dispatch response.
		 */
		public function dispatch(): \Dataphyre\Http\Response {
			return $this->reactorDispatch(['component'=>'counter', 'action'=>'increment']);
		}

		/**
		 * Dispatches multiple Reactor requests through the batch helper.
		 *
		 * @return \Dataphyre\Http\Response Reactor batch response.
		 */
		public function batch(): \Dataphyre\Http\Response {
			return $this->reactorBatch([
				['component'=>'counter', 'action'=>'increment'],
				['component'=>'counter', 'action'=>'decrement'],
			]);
		}

		/**
		 * Returns the Reactor component manifest through the controller helper.
		 *
		 * @return array Reactor manifest payload.
		 */
		public function manifest(): array {
			return $this->reactorManifest();
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class InvokableController extends \Dataphyre\Mvc\Controller {
		/**
		 * Verifies invokable controller dispatch with request and route injection.
		 *
		 * @param Request $request Current request fixture.
		 * @param string $name Route parameter injected by the dispatcher.
		 * @return array{invoked:string,path:string} Invokable controller payload.
		 */
		public function __invoke(Request $request, string $name): array {
			return [
				'invoked'=>$name,
				'path'=>$request->path(),
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class GroupedController extends \Dataphyre\Mvc\Controller {
		/**
		 * Returns the grouped index route payload.
		 *
		 * @return array{grouped:string} Grouped index payload.
		 */
		public function index(): array {
			return ['grouped'=>'index'];
		}

		/**
		 * Returns the grouped route parameter payload.
		 *
		 * @param string $name Group route parameter.
		 * @return array{grouped:string} Grouped show payload.
		 */
		public function show(string $name): array {
			return ['grouped'=>$name];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ResourceController extends \Dataphyre\Mvc\Controller {
		/**
		 * Resource index action used by route expansion assertions.
		 *
		 * @return array{action:string} Resource action payload.
		 */
		public function index(): array {
			return ['action'=>'index'];
		}

		/**
		 * Resource create action used by route expansion assertions.
		 *
		 * @return array{action:string} Resource action payload.
		 */
		public function create(): array {
			return ['action'=>'create'];
		}

		/**
		 * Resource store action used by route expansion assertions.
		 *
		 * @return array{action:string} Resource action payload.
		 */
		public function store(): array {
			return ['action'=>'store'];
		}

		/**
		 * Resource show action with route-model parameter naming coverage.
		 *
		 * @param string $product Product route parameter.
		 * @return array{action:string,product:string} Resource action payload.
		 */
		public function show(string $product): array {
			return ['action'=>'show', 'product'=>$product];
		}

		/**
		 * Resource edit action with route-model parameter naming coverage.
		 *
		 * @param string $product Product route parameter.
		 * @return array{action:string,product:string} Resource action payload.
		 */
		public function edit(string $product): array {
			return ['action'=>'edit', 'product'=>$product];
		}

		/**
		 * Resource update action with route-model parameter naming coverage.
		 *
		 * @param string $product Product route parameter.
		 * @return array{action:string,product:string} Resource action payload.
		 */
		public function update(string $product): array {
			return ['action'=>'update', 'product'=>$product];
		}

		/**
		 * Resource destroy action with route-model parameter naming coverage.
		 *
		 * @param string $product Product route parameter.
		 * @return array{action:string,product:string} Resource action payload.
		 */
		public function destroy(string $product): array {
			return ['action'=>'destroy', 'product'=>$product];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ItemResourceController extends \Dataphyre\Mvc\Controller {
		/**
		 * Verifies singular resource parameter naming for an item route.
		 *
		 * @param string $item Item route parameter.
		 * @return array{action:string,item:string} Item resource payload.
		 */
		public function show(string $item): array {
			return ['action'=>'show', 'item'=>$item];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class RemappedResourceController extends \Dataphyre\Mvc\Controller {
		/**
		 * Remapped resource index action.
		 *
		 * @return array{action:string} Remapped resource payload.
		 */
		public function listing(): array {
			return ['action'=>'listing'];
		}

		/**
		 * Remapped resource show action.
		 *
		 * @param string $product Product route parameter.
		 * @return array{action:string,product:string} Remapped resource payload.
		 */
		public function display(string $product): array {
			return ['action'=>'display', 'product'=>$product];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class CommentResourceController extends \Dataphyre\Mvc\Controller {
		/**
		 * Nested resource index action for a parent post route.
		 *
		 * @param string $post Parent post route parameter.
		 * @return array{action:string,post:string} Nested resource payload.
		 */
		public function index(string $post): array {
			return ['action'=>'index', 'post'=>$post];
		}

		/**
		 * Nested resource store action for a parent post route.
		 *
		 * @param string $post Parent post route parameter.
		 * @return array{action:string,post:string} Nested resource payload.
		 */
		public function store(string $post): array {
			return ['action'=>'store', 'post'=>$post];
		}

		/**
		 * Nested resource show action for a comment route.
		 *
		 * @param string $comment Comment route parameter.
		 * @return array{action:string,comment:string} Nested resource payload.
		 */
		public function show(string $comment): array {
			return ['action'=>'show', 'comment'=>$comment];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class SingletonResourceController extends \Dataphyre\Mvc\Controller {
		/**
		 * Singleton resource create action.
		 *
		 * @return array{action:string} Singleton resource payload.
		 */
		public function create(): array {
			return ['action'=>'create'];
		}

		/**
		 * Singleton resource store action.
		 *
		 * @return array{action:string} Singleton resource payload.
		 */
		public function store(): array {
			return ['action'=>'store'];
		}

		/**
		 * Singleton resource show action.
		 *
		 * @return array{action:string} Singleton resource payload.
		 */
		public function show(): array {
			return ['action'=>'show'];
		}

		/**
		 * Singleton resource edit action.
		 *
		 * @return array{action:string} Singleton resource payload.
		 */
		public function edit(): array {
			return ['action'=>'edit'];
		}

		/**
		 * Singleton resource update action.
		 *
		 * @return array{action:string} Singleton resource payload.
		 */
		public function update(): array {
			return ['action'=>'update'];
		}

		/**
		 * Singleton resource destroy action.
		 *
		 * @return array{action:string} Singleton resource payload.
		 */
		public function destroy(): array {
			return ['action'=>'destroy'];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class RemappedSingletonResourceController extends \Dataphyre\Mvc\Controller {
		/**
		 * Remapped singleton show action.
		 *
		 * @return array{action:string} Remapped singleton payload.
		 */
		public function display(): array {
			return ['action'=>'display'];
		}

		/**
		 * Remapped singleton update action.
		 *
		 * @return array{action:string} Remapped singleton payload.
		 */
		public function save(): array {
			return ['action'=>'save'];
		}
	}

	/**
	 * Regression fixture contract used to prove MVC container interface binding.
	 *
	 * The container tests bind this interface to GreetingService and then resolve it
	 * through controllers, verifying that dependency injection handles interface
	 * contracts without touching external services.
	 */
	interface GreetingServiceContract {
		/**
		 * Provides a greeting used to prove container interface binding.
		 *
		 * @return string Greeting value resolved through the service contract.
		 */
		public function greeting(): string;
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class GreetingService implements GreetingServiceContract {
		/**
		 * Returns the concrete greeting resolved from the MVC container.
		 *
		 * @return string Container fixture greeting.
		 */
		public function greeting(): string {
			return 'container';
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ContainerController extends \Dataphyre\Mvc\Controller {
		/**
		 * Receives a service contract resolved by constructor injection.
		 *
		 * @param GreetingServiceContract $service Container-bound greeting service.
		 */
		public function __construct(private GreetingServiceContract $service){}

		/**
		 * Exposes the injected service result from a controller action.
		 *
		 * @return array{greeting:string} Container injection payload.
		 */
		public function show(): array {
			return ['greeting'=>$this->service->greeting()];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class RegressionServiceProvider extends \Dataphyre\Mvc\ServiceProvider {
		/**
		 * Registers fixture services used by container resolution tests.
		 *
		 * @param \Dataphyre\Mvc\MvcApplication $app MVC application under test.
		 * @param \Dataphyre\Mvc\ProviderRegistry $providers Provider registry coordinating lifecycle calls.
		 * @return void
		 */
		public function register(\Dataphyre\Mvc\MvcApplication $app, \Dataphyre\Mvc\ProviderRegistry $providers): void {
			parent::register($app, $providers);
			$app->container()->bind(GreetingServiceContract::class, GreetingService::class);
		}

		/**
		 * Adds a boot-time route after provider registration has completed.
		 *
		 * @param \Dataphyre\Mvc\MvcApplication $app MVC application under test.
		 * @param \Dataphyre\Mvc\ProviderRegistry $providers Provider registry coordinating lifecycle calls.
		 * @return void
		 */
		public function boot(\Dataphyre\Mvc\MvcApplication $app, \Dataphyre\Mvc\ProviderRegistry $providers): void {
			parent::boot($app, $providers);
			$app->routes()->get('/provider-booted', static fn(): array => ['booted'=>true]);
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class CreateProductRequest extends \Dataphyre\Mvc\FormRequest {
		/**
		 * Defines the base product creation validation rules.
		 *
		 * @return array<string,string> Validation rule map.
		 */
		public function rules(): array {
			return [
				'name'=>'required|string|min:3',
				'email'=>'required|email',
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class ProfileProductRequest extends \Dataphyre\Mvc\FormRequest {
		protected string $errorBag='profile';

		/**
		 * Defines profile-specific product validation rules and error bag behavior.
		 *
		 * @return array<string,string> Validation rule map.
		 */
		public function rules(): array {
			return [
				'display_name'=>'required|string|min:3',
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class UploadAvatarRequest extends \Dataphyre\Mvc\FormRequest {
		/**
		 * Defines upload validation rules for the avatar fixture.
		 *
		 * @return array<string,string> Validation rule map.
		 */
		public function rules(): array {
			return [
				'avatar'=>'required|file|image|mimes:jpg,png|max:8',
			];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class PreparedProductRequest extends \Dataphyre\Mvc\FormRequest {
		protected bool $stopOnFirstFailure=true;

		/**
		 * Normalizes product input before validation rules are evaluated.
		 *
		 * The hook trims the name, derives a slug, and lowercases nested metadata
		 * so tests can verify dot-path input merging and preparation lifecycle.
		 *
		 * @return void
		 */
		protected function prepareForValidation(): void {
			$this->merge([
				'name'=>trim((string)$this->input('name')),
				'slug'=>strtolower(str_replace(' ', '-', trim((string)$this->input('name')))),
				'meta'=>[
					'category'=>strtolower((string)$this->input('meta.category')),
				],
			]);
		}

		/**
		 * Defines validation rules that depend on prepared input.
		 *
		 * @return array<string,string> Validation rule map.
		 */
		public function rules(): array {
			return [
				'name'=>'required|string|min:3',
				'slug'=>'required|regex:/^[a-z0-9-]+$/',
				'meta.category'=>'required|in:tools,books',
			];
		}

		/**
		 * Provides custom validation messages for prepared input failures.
		 *
		 * @return array<string,string> Validation message map.
		 */
		public function messages(): array {
			return [
				'slug.regex'=>'The :attribute generated from the name is not URL safe.',
			];
		}

		/**
		 * Provides human-readable attribute names for prepared input failures.
		 *
		 * @return array<string,string> Validation attribute map.
		 */
		public function attributes(): array {
			return [
				'slug'=>'product slug',
				'meta.category'=>'product category',
			];
		}

		/**
		 * Mutates the validator before execution to stop after the first failure.
		 *
		 * @param \Dataphyre\Mvc\Validator $validator Validator instance created for the request.
		 * @return void
		 */
		public function withValidator(\Dataphyre\Mvc\Validator $validator): void {
			$validator->stopOnFirstFailure();
		}

		/**
		 * Marks the request after validation succeeds.
		 *
		 * @return void
		 */
		protected function passedValidation(): void {
			$this->merge(['passed_hook'=>'yes']);
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class DeniedProductRequest extends \Dataphyre\Mvc\FormRequest {
		/**
		 * Denies authorization using the default failure response.
		 *
		 * @return bool Always false for this fixture.
		 */
		public function authorize(): bool {
			return false;
		}

		/**
		 * Defines a minimal rule map that is bypassed by authorization failure.
		 *
		 * @return array<string,string> Validation rule map.
		 */
		public function rules(): array {
			return ['name'=>'required'];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class CustomDeniedProductRequest extends \Dataphyre\Mvc\FormRequest {
		/**
		 * Denies authorization using custom failure metadata.
		 *
		 * @return bool Always false for this fixture.
		 */
		public function authorize(): bool {
			return false;
		}

		/**
		 * Defines a minimal rule map that is bypassed by authorization failure.
		 *
		 * @return array<string,string> Validation rule map.
		 */
		public function rules(): array {
			return ['name'=>'required'];
		}

		/**
		 * Supplies the custom authorization failure message.
		 *
		 * @return string Authorization failure message.
		 */
		protected function authorizationMessage(): string {
			return 'Only product managers may do this.';
		}

		/**
		 * Supplies the custom authorization failure status.
		 *
		 * @return int HTTP status code for authorization failure.
		 */
		protected function authorizationStatus(): int {
			return 401;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class CustomValidationFailureRequest extends \Dataphyre\Mvc\FormRequest {
		/**
		 * Defines rules that intentionally fail short product names.
		 *
		 * @return array<string,string> Validation rule map.
		 */
		public function rules(): array {
			return [
				'name'=>'required|string|min:5',
			];
		}

		/**
		 * Throws a custom validation exception for failed form request validation.
		 *
		 * @param \Dataphyre\Mvc\Validator $validator Validator carrying the failure details.
		 * @return never
		 *
		 * @throws \Dataphyre\Mvc\ValidationException Always thrown for validation failure.
		 */
		protected function failedValidation(\Dataphyre\Mvc\Validator $validator): never {
			throw new \Dataphyre\Mvc\ValidationException($validator, 'Product payload rejected.', 409);
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class FormRequestController extends \Dataphyre\Mvc\Controller {
		/**
		 * Returns validated data from an injected creation form request.
		 *
		 * @param CreateProductRequest $request Validated product creation request.
		 * @return array{validated:array} Form request validation payload.
		 */
		public function store(CreateProductRequest $request): array {
			return ['validated'=>$request->validated()];
		}

		/**
		 * Returns metadata from an uploaded avatar validated by a form request.
		 *
		 * @param UploadAvatarRequest $request Validated avatar upload request.
		 * @return array{name:string|null,extension:string|null} Uploaded file metadata payload.
		 */
		public function upload(UploadAvatarRequest $request): array {
			$file=$request->validated('avatar');
			return [
				'name'=>$file instanceof \Dataphyre\Http\UploadedFile ? $file->clientOriginalName() : null,
				'extension'=>$file instanceof \Dataphyre\Http\UploadedFile ? $file->clientExtension() : null,
			];
		}

		/**
		 * Returns prepared and validated product input from a form request.
		 *
		 * @param PreparedProductRequest $request Prepared product request.
		 * @return array{validated:array,category:mixed,passed:mixed} Prepared request payload.
		 */
		public function prepared(PreparedProductRequest $request): array {
			return [
				'validated'=>$request->validated(),
				'category'=>$request->validated('meta.category'),
				'passed'=>$request->input('passed_hook'),
			];
		}

		/**
		 * Action that should not run because request authorization fails.
		 *
		 * @param DeniedProductRequest $request Denied product request.
		 * @return array{ok:bool} Unreachable success payload.
		 */
		public function denied(DeniedProductRequest $request): array {
			return ['ok'=>true];
		}

		/**
		 * Action that should not run because custom authorization fails.
		 *
		 * @param CustomDeniedProductRequest $request Custom denied product request.
		 * @return array{ok:bool} Unreachable success payload.
		 */
		public function customDenied(CustomDeniedProductRequest $request): array {
			return ['ok'=>true];
		}

		/**
		 * Action that should not run because custom validation fails.
		 *
		 * @param CustomValidationFailureRequest $request Custom failure product request.
		 * @return array{ok:bool} Unreachable success payload.
		 */
		public function customValidationFailure(CustomValidationFailureRequest $request): array {
			return ['ok'=>true];
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class BoundProduct extends \Dataphyre\Mvc\Model {
		public static array $records=[
			'native-mvc'=>['slug'=>'native-mvc', 'name'=>'Native MVC'],
		];

		/**
		 * Selects slug as the route-model binding key.
		 *
		 * @return string Route key column name.
		 */
		public static function routeKeyName(): string {
			return 'slug';
		}

		/**
		 * Finds a seeded product record by route binding key.
		 *
		 * @param mixed $id Requested route key value.
		 * @param string $key Record field used for lookup.
		 * @return array|null Seeded record when found.
		 */
		public static function find(mixed $id, string $key='id'): ?array {
			foreach(self::$records as $record){
				if(($record[$key] ?? null)===$id){
					return $record;
				}
			}
			return null;
		}
	}

	/**
	 * MVC regression fixture used to exercise controller, route, and middleware behavior.
	 *
	 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
	 */
	final class BoundProductController extends \Dataphyre\Mvc\Controller {
		/**
		 * Exposes a route-bound model value from the controller action.
		 *
		 * @param BoundProduct $product Product model resolved by route binding.
		 * @return array{product:mixed} Bound product payload.
		 */
		public function show(BoundProduct $product): array {
			return ['product'=>$product->get('name')];
		}
	}
}

namespace {
	use Dataphyre\Http\Request;
	use Dataphyre\Http\Response;
	use Dataphyre\Mvc\Mvc;
	use Dataphyre\Mvc\MvcApplication;
	use Dataphyre\Mvc\RouteCollection;
	use Dataphyre\Mvc\Regression\AccessController;
	use Dataphyre\Mvc\Regression\BoundProductController;
	use Dataphyre\Mvc\Regression\ContainerController;
	use Dataphyre\Mvc\Regression\FormRequestController;
	use Dataphyre\Mvc\Regression\RegressionServiceProvider;
	use Dataphyre\Mvc\Regression\HeaderMiddleware;
	use Dataphyre\Mvc\Regression\StackMiddleware;
	use Dataphyre\Mvc\Regression\TagMiddleware;
	use Dataphyre\Mvc\Regression\TerminableMiddleware;
	use Dataphyre\Routing\RouteCompiler;
	use Dataphyre\Routing\Route;
	use Dataphyre\Routing\RouteManifest;

	if(PHP_SAPI!=='cli'){
		http_response_code(404);
		echo "MVC regression runner is only available from CLI.\n";
		exit(2);
	}

	$failures=[];
	dp_mvc_regression_assert_json_route($failures);
	dp_mvc_regression_assert_request_input_helpers($failures);
	dp_mvc_regression_assert_request_url_helpers($failures);
	dp_mvc_regression_assert_request_content_negotiation($failures);
	dp_mvc_regression_assert_request_route_helpers($failures);
	dp_mvc_regression_assert_uploaded_files($failures);
	dp_mvc_regression_assert_response_normalization($failures);
	dp_mvc_regression_assert_file_responses($failures);
	dp_mvc_regression_assert_response_cookies($failures);
	dp_mvc_regression_assert_response_cache_helpers($failures);
	dp_mvc_regression_assert_response_headers($failures);
	dp_mvc_regression_assert_action_arguments($failures);
	dp_mvc_regression_assert_controller_string($failures);
	dp_mvc_regression_assert_invokable_controller($failures);
	dp_mvc_regression_assert_controller_manifest_descriptor($failures);
	dp_mvc_regression_assert_route_context_injection($failures);
	dp_mvc_regression_assert_controller_route_helpers($failures);
	dp_mvc_regression_assert_controller_response_helpers($failures);
	dp_mvc_regression_assert_http_exceptions($failures);
	dp_mvc_regression_assert_named_routes($failures);
	dp_mvc_regression_assert_signed_urls($failures);
	dp_mvc_regression_assert_signed_url_middleware($failures);
	dp_mvc_regression_assert_grouped_option_names($failures);
	dp_mvc_regression_assert_domain_routes($failures);
	dp_mvc_regression_assert_route_constraints($failures);
	dp_mvc_regression_assert_optional_route_parameters($failures);
	dp_mvc_regression_assert_route_defaults($failures);
	dp_mvc_regression_assert_routing_named_urls($failures);
	dp_mvc_regression_assert_route_metadata($failures);
	dp_mvc_regression_assert_route_list($failures);
	dp_mvc_regression_assert_route_list_cli($failures);
	dp_mvc_regression_assert_route_cache_cli($failures);
	dp_mvc_regression_assert_route_normalization($failures);
	dp_mvc_regression_assert_group_helper_methods($failures);
	dp_mvc_regression_assert_route_collection_macros($failures);
	dp_mvc_regression_assert_controller_groups($failures);
	dp_mvc_regression_assert_head_and_options_routes($failures);
	dp_mvc_regression_assert_method_override($failures);
	dp_mvc_regression_assert_encoded_parameters_decode($failures);
	dp_mvc_regression_assert_redirect_route($failures);
	dp_mvc_regression_assert_named_redirect_routes($failures);
	dp_mvc_regression_assert_resource_routes($failures);
	dp_mvc_regression_assert_middleware_normalization($failures);
	dp_mvc_regression_assert_grouped_middleware($failures);
	dp_mvc_regression_assert_without_middleware($failures);
	dp_mvc_regression_assert_controller_middleware($failures);
	dp_mvc_regression_assert_access_integration($failures);
	dp_mvc_regression_assert_permission_integration($failures);
	dp_mvc_regression_assert_storage_integration($failures);
	dp_mvc_regression_assert_mailer_integration($failures);
	dp_mvc_regression_assert_templating_integration($failures);
	dp_mvc_regression_assert_localization_integration($failures);
	dp_mvc_regression_assert_currency_integration($failures);
	dp_mvc_regression_assert_date_translation_integration($failures);
	dp_mvc_regression_assert_sanitation_integration($failures);
	dp_mvc_regression_assert_cache_integration($failures);
	dp_mvc_regression_assert_async_integration($failures);
	dp_mvc_regression_assert_reactor_integration($failures);
	dp_mvc_regression_assert_app_middleware_stack($failures);
	dp_mvc_regression_assert_terminable_middleware($failures);
	dp_mvc_regression_assert_throttle_middleware($failures);
	dp_mvc_regression_assert_cache_middleware($failures);
	dp_mvc_regression_assert_session_middleware($failures);
	dp_mvc_regression_assert_redirect_flash_helpers($failures);
	dp_mvc_regression_assert_csrf_middleware($failures);
	dp_mvc_regression_assert_manager_registration($failures);
	dp_mvc_regression_assert_config_list_overrides($failures);
	dp_mvc_regression_assert_route_mutation_recompiles($failures);
	dp_mvc_regression_assert_route_definition_mutation_recompiles($failures);
	dp_mvc_regression_assert_route_definition_macros($failures);
	dp_mvc_regression_assert_single_route_arrays($failures);
	dp_mvc_regression_assert_array_view_and_redirect_routes($failures);
	dp_mvc_regression_assert_fallback_route($failures);
	dp_mvc_regression_assert_route_files_load($failures);
	dp_mvc_regression_assert_route_compiler_sources($failures);
	dp_mvc_regression_assert_manifest_file_reads($failures);
	dp_mvc_regression_assert_manifest_cache($failures);
	dp_mvc_regression_assert_manifest_cache_config($failures);
	dp_mvc_regression_assert_manifest_exportability($failures);
	dp_mvc_regression_assert_container_and_providers($failures);
	dp_mvc_regression_assert_validator_extended_rules($failures);
	dp_mvc_regression_assert_validator_upload_rules($failures);
	dp_mvc_regression_assert_controller_validation_helpers($failures);
	dp_mvc_regression_assert_form_request_validation($failures);
	dp_mvc_regression_assert_validation_redirect($failures);
	dp_mvc_regression_assert_route_model_binding($failures);
	dp_mvc_regression_assert_not_found_handler($failures);
	dp_mvc_regression_assert_error_handler($failures);
	dp_mvc_regression_assert_not_found($failures);

	if($failures!==[]){
		foreach($failures as $failure){
			fwrite(STDERR, '[FAIL] '.$failure.PHP_EOL);
		}
		exit(1);
	}

	echo "MVC regression ok\n";
	exit(0);

	/**
	 * Supports app behavior for the MVC regression harness.
	 *
	 * Keeps fixture setup deterministic so route, controller, middleware, and integration assertions can share one test application.
	 */
	function dp_mvc_regression_app(): MvcApplication {
		return new MvcApplication('regression', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'auth'=>HeaderMiddleware::class,
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'tag'=>TagMiddleware::class,
				'callable_tag'=>static function(Request $request, callable $next, string $tag): mixed {
					$response=$next($request);
					$response->headers['X-Callable-Tag']=$tag;
					return $response;
				},
			],
			'response_headers'=>[
				'X-Dataphyre-MVC'=>'1',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/hello/{name}', static fn(Request $request, string $name): array => ['hello'=>$name])->name('hello');
				$routes->get('/controller/{name}', 'ExampleController@show');
				$routes->get('/invoke/{name}', 'InvokableController');
				$routes->get('/context/{name}', 'ExampleController@context')->name('context.show');
				$routes->get('/controller-redirect/{name}', 'ExampleController@redirectToHello');
				$routes->get('/controller-download/{name}', 'ExampleController@downloadSample');
				$routes->get('/controller-abort/{name}', 'ExampleController@abortSample');
				$routes->get('/controller-created/{name}', 'ExampleController@createdSample');
				$routes->get('/controller-no-content', 'ExampleController@noContentSample');
				$routes->post('/controller-validate/{category}', 'ExampleController@validateSample');
				$routes->get('/controller-middleware', 'ControllerMiddlewareController@show');
				$routes->get('/controller-middleware-plain', 'ControllerMiddlewareController@plain');
				$routes->redirect('/go', '/there');
				$routes->group(['prefix'=>'admin', 'as'=>'admin.', 'middleware'=>['header', 'tag:blue']], function(RouteCollection $routes): void {
					$routes->get('/dashboard', static fn(): string => 'dashboard')->name('dashboard');
				});
				$routes->get('/callable-middleware', static fn(): string => 'callable')->middleware('callable_tag:green');
				$routes->get('/alias-override', static fn(): string => 'override')->middleware('auth');
			},
		]);
	}

	/**
	 * Asserts JSON route regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_json_route(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/hello/dataphyre'));
		if($response->status!==200 || $response->body!=='{"hello":"dataphyre"}'){
			$failures[]='JSON route should return route parameter payload.';
		}
		if(($response->headers['Content-Type'] ?? null)!=='application/json; charset=utf-8'){
			$failures[]='JSON route should set application/json content type.';
		}
	}

	/**
	 * Asserts request input helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_request_input_helpers(array &$failures): void {
		$tmp=tempnam(sys_get_temp_dir(), 'dp-mvc-input-helper-');
		if($tmp===false){
			$failures[]='HTTP request helper regression could not create a temporary file.';
			return;
		}
		file_put_contents($tmp, 'upload');
		try{
			Request::flushMacros();
			Request::macro('tenant', fn(string $default='default'): string => (string)$this->input('tenant', $default));
			Request::macro('isDashboard', fn(): bool => $this->path()==='/dashboard');
			$request=Request::create('POST', '/input', [
				'page'=>'2',
				'name'=>'query',
				'filters'=>[
					'status'=>'active',
				],
			], [
				'name'=>'body',
				'published'=>'yes',
				'price'=>'12.5',
				'tenant'=>'acme',
				'empty'=>'',
				'user'=>[
					'email'=>'hello@dataphyre.test',
					'name'=>'Native',
				],
			], [], [], [], [], [], [
				'avatar'=>[
					'name'=>'avatar.jpg',
					'type'=>'image/jpeg',
					'tmp_name'=>$tmp,
					'error'=>UPLOAD_ERR_OK,
					'size'=>6,
				],
				'missing'=>[
					'name'=>'',
					'type'=>'',
					'tmp_name'=>'',
					'error'=>UPLOAD_ERR_NO_FILE,
					'size'=>0,
				],
			]);
			$all=$request->all();
			if(($all['name'] ?? null)!=='body' || ($all['page'] ?? null)!=='2' || !$request->has('name', 'avatar')){
				$failures[]='HTTP request helpers should merge query, body, and files with body precedence.';
			}
			if($request->only('name', 'page')!==['name'=>'body', 'page'=>'2'] || array_key_exists('price', $request->except('price'))){
				$failures[]='HTTP request helpers should support only and except filters.';
			}
			if(!$request->filled('name', 'avatar') || $request->filled('empty') || $request->has('missing')){
				$failures[]='HTTP request helpers should distinguish present, filled, and skipped empty upload values.';
			}
			if($request->boolean('published')!==true || $request->integer('page')!==2 || $request->float('price')!==12.5){
				$failures[]='HTTP request helpers should expose typed scalar readers.';
			}
			if($request->input('user.email')!=='hello@dataphyre.test' || $request->query('filters.status')!=='active'){
				$failures[]='HTTP request helpers should support dot-path query and body lookup.';
			}
			if(!$request->has('user.email', 'filters.status') || !$request->filled('user.email') || $request->only('user.email', 'filters.status')!==[
				'user'=>[
					'email'=>'hello@dataphyre.test',
				],
				'filters'=>[
					'status'=>'active',
				],
			]){
				$failures[]='HTTP request helpers should support dot-path filtering and presence checks.';
			}
			$without_nested=$request->except('user.email', 'filters.status');
			if(
				array_key_exists('email', $without_nested['user'] ?? [])
				|| array_key_exists('status', $without_nested['filters'] ?? [])
				|| ($without_nested['user']['name'] ?? null)!=='Native'
			){
				$failures[]='HTTP request helpers should support dot-path except filters without removing sibling data.';
			}
			if(!$request->has('avatar') || $request->file('avatar')!==$request->files('avatar')){
				$failures[]='HTTP request helpers should preserve flattened upload field lookup.';
			}
			if(!Request::hasMacro('tenant') || $request->tenant('fallback')!=='acme' || $request->isDashboard()){
				$failures[]='HTTP request helpers should support instance macros.';
			}
			Request::flushMacros();
			if(Request::hasMacro('tenant')){
				$failures[]='HTTP request macros should be flushable.';
			}
		}finally{
			Request::flushMacros();
			if(is_file($tmp)){
				@unlink($tmp);
			}
		}
	}

	/**
	 * Asserts request URL helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_request_url_helpers(array &$failures): void {
		$request=Request::create('GET', '/reports/monthly', [
			'page'=>2,
			'filter'=>'data phyre',
		], [], [], [
			'REMOTE_ADDR'=>'10.0.0.10',
		], [
			'Host'=>'internal.test',
			'X-Forwarded-Host'=>'dataphyre.test, proxy.test',
			'X-Forwarded-Proto'=>'https',
			'X-Forwarded-For'=>'203.0.113.10, 10.0.0.10',
			'User-Agent'=>'DataphyreBot/1.0',
		]);
		if($request->scheme()!=='https' || $request->host()!=='dataphyre.test' || $request->root()!=='https://dataphyre.test'){
			$failures[]='HTTP request URL helpers should honor forwarded scheme and host headers.';
		}
		if($request->url()!=='https://dataphyre.test/reports/monthly' || $request->fullUrl()!=='https://dataphyre.test/reports/monthly?page=2&filter=data%20phyre'){
			$failures[]='HTTP request URL helpers should build current URL and full URL with encoded query.';
		}
		if($request->ip()!=='203.0.113.10' || $request->userAgent()!=='DataphyreBot/1.0'){
			$failures[]='HTTP request client helpers should expose forwarded IP and user agent.';
		}
		$direct=Request::create('GET', '/direct', [], [], [], [
			'HTTPS'=>'on',
			'HTTP_HOST'=>'direct.test',
			'REMOTE_ADDR'=>'127.0.0.1',
			'HTTP_USER_AGENT'=>'DirectAgent',
		]);
		if($direct->scheme()!=='https' || $direct->host()!=='direct.test' || $direct->ip()!=='127.0.0.1' || $direct->userAgent()!=='DirectAgent'){
			$failures[]='HTTP request URL helpers should fall back to direct server values.';
		}
	}

	/**
	 * Asserts request content negotiation regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_request_content_negotiation(array &$failures): void {
		$json=Request::create('GET', '/accept', [], [], [], [], ['Accept'=>'application/json']);
		$html_first=Request::create('GET', '/accept', [], [], [], [], ['Accept'=>'text/html, application/json;q=0.5']);
		$ajax=Request::create('GET', '/accept', [], [], [], [], [
			'Accept'=>'*/*',
			'X-Requested-With'=>'XMLHttpRequest',
		]);
		$body_json=Request::create('POST', '/accept', [], [], [], [], ['Content-Type'=>'application/vnd.api+json']);
		if(!$json->wantsJson() || !$json->expectsJson() || !$json->accepts('application/json')){
			$failures[]='HTTP request content negotiation should detect JSON accept headers.';
		}
		if($html_first->wantsJson() || !$html_first->accepts(['text/html', 'application/json'])){
			$failures[]='HTTP request content negotiation should respect accept header priority.';
		}
		if(!$ajax->ajax() || !$ajax->expectsJson()){
			$failures[]='HTTP request content negotiation should treat AJAX any-content requests as expecting JSON.';
		}
		if(!$body_json->isJson()){
			$failures[]='HTTP request content negotiation should detect JSON request bodies.';
		}
	}

	/**
	 * Asserts request route helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_request_route_helpers(array &$failures): void {
		$app=new MvcApplication('request-route-helpers', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/accounts/{account}/orders/{order}', static fn(Request $request): array => [
					'account'=>$request->route('account'),
					'missing'=>$request->route('missing', 'fallback'),
					'name'=>$request->routeName(),
					'is_order'=>$request->routeIs('accounts.*', 'admin.*'),
					'is_admin'=>$request->routeIs('admin.*'),
					'all'=>$request->route(),
				])->name('accounts.orders.show');
			},
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/accounts/acme/orders/42'));
		if($response->status!==200 || $response->body!=='{"account":"acme","missing":"fallback","name":"accounts.orders.show","is_order":true,"is_admin":false,"all":{"account":"acme","order":"42"}}'){
			$failures[]='HTTP request route helpers should expose route parameters, names, and wildcard route matching.';
		}
		$unmatched=Request::create('GET', '/plain', [], [], [], [], [], ['id'=>1]);
		if($unmatched->route('id')!==1 || $unmatched->routeName()!==null || $unmatched->routeIs('*')){
			$failures[]='HTTP request route helpers should fall back cleanly outside MVC route dispatch.';
		}
	}

	/**
	 * Asserts uploaded files regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_uploaded_files(array &$failures): void {
		$tmp=tempnam(sys_get_temp_dir(), 'dp-mvc-upload-');
		$target=$tmp.'.moved';
		if($tmp===false){
			$failures[]='HTTP uploaded file regression could not create a temporary file.';
			return;
		}
		file_put_contents($tmp, 'upload');
		try{
			$request=Request::create('POST', '/upload', [], [], [], [], [], [], [], [
				'avatar'=>[
					'name'=>'Avatar.JPG',
					'type'=>'image/jpeg',
					'tmp_name'=>$tmp,
					'error'=>UPLOAD_ERR_OK,
					'size'=>6,
				],
				'photos'=>[
					'name'=>['a.txt', ''],
					'type'=>['text/plain', ''],
					'tmp_name'=>[$tmp, ''],
					'error'=>[UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
					'size'=>[6, 0],
				],
			]);
			$avatar=$request->file('avatar');
			$photo=$request->file('photos.0');
			if(!$avatar instanceof \Dataphyre\Http\UploadedFile || !$avatar->isValid() || $avatar->clientExtension()!=='jpg'){
				$failures[]='HTTP requests should expose valid uploaded files by field name.';
			}
			if(!$photo instanceof \Dataphyre\Http\UploadedFile || $request->file('photos.1')!==null){
				$failures[]='HTTP requests should normalize nested upload arrays and skip empty files.';
			}
			if(!$request->hasFile('avatar') || $avatar->mimeType()!=='image/jpeg' || $avatar->size()!==6){
				$failures[]='HTTP uploaded files should expose metadata helpers.';
			}
			if(!$avatar->moveTo($target) || !is_file($target) || file_get_contents($target)!=='upload'){
				$failures[]='HTTP uploaded files should move valid temporary files.';
			}
		}finally{
			if(is_file($tmp)){
				@unlink($tmp);
			}
			if(is_file($target)){
				@unlink($target);
			}
		}
	}

	/**
	 * Asserts response normalization regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_response_normalization(array &$failures): void {
		\Dataphyre\Http\Response::flushMacros();
		\Dataphyre\Http\Response::macro('problem', static fn(string $title, int $status=400): \Dataphyre\Http\Response => \Dataphyre\Http\Response::json([
			'type'=>'about:blank',
			'title'=>$title,
			'status'=>$status,
		], $status, [
			'Content-Type'=>'application/problem+json',
		]));
		\Dataphyre\Http\Response::macro('tagged', fn(string $tag): \Dataphyre\Http\Response => $this->withHeader('X-Tag', $tag));
		$problem=\Dataphyre\Http\Response::problem('Invalid payload', 422);
		$tagged=\Dataphyre\Http\Response::make('ok')->tagged('macro');
		if(
			!\Dataphyre\Http\Response::hasMacro('problem')
			|| $problem->status!==422
			|| ($problem->headers['Content-Type'] ?? null)!=='application/problem+json'
			|| !str_contains($problem->body, '"title":"Invalid payload"')
			|| ($tagged->headers['X-Tag'] ?? null)!=='macro'
		){
			$failures[]='HTTP responses should support static and instance macros.';
		}
		\Dataphyre\Http\Response::flushMacros();
		if(\Dataphyre\Http\Response::hasMacro('problem')){
			$failures[]='HTTP response macros should be flushable.';
		}
		$json=\Dataphyre\Http\Response::normalize(['ok'=>true]);
		if($json->body!=='{"ok":true}' || ($json->headers['Content-Type'] ?? null)!=='application/json; charset=utf-8'){
			$failures[]='HTTP response normalization should convert arrays to JSON responses.';
		}
		$html=\Dataphyre\Http\Response::normalize('hello', 'html');
		if($html->body!=='hello' || ($html->headers['Content-Type'] ?? null)!=='text/html; charset=utf-8'){
			$failures[]='HTTP response normalization should support HTML string mode.';
		}
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/admin/dashboard'));
		if(($response->headers['Content-Type'] ?? null)!=='text/html; charset=utf-8'){
			$failures[]='MVC dispatcher should normalize string action results through HTTP HTML mode.';
		}
	}

	/**
	 * Asserts file responses regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_file_responses(array &$failures): void {
		$file=tempnam(sys_get_temp_dir(), 'dp-mvc-response-file-');
		if($file===false){
			$failures[]='HTTP file response regression could not create a temporary file.';
			return;
		}
		file_put_contents($file, 'file-body');
		try{
			$inline=\Dataphyre\Http\Response::file($file, 'report.txt');
			$download=\Dataphyre\Http\Response::download($file, 'sales report.txt');
			if($inline->status!==200 || $inline->body!=='file-body' || ($inline->headers['Content-Length'] ?? null)!=='9'){
				$failures[]='HTTP file responses should include file body and content length.';
			}
			if(!str_starts_with((string)($inline->headers['Content-Disposition'] ?? ''), 'inline; filename="report.txt"')){
				$failures[]='HTTP file responses should default to inline content disposition.';
			}
			if(!str_starts_with((string)($download->headers['Content-Disposition'] ?? ''), 'attachment; filename="sales report.txt"')){
				$failures[]='HTTP download responses should use attachment content disposition.';
			}
			$static=\Dataphyre\Mvc\Mvc::download($file, 'static.txt');
			if(!str_contains((string)($static->headers['Content-Disposition'] ?? ''), 'static.txt')){
				$failures[]='MVC static download helper should proxy HTTP file downloads.';
			}
		}finally{
			if(is_file($file)){
				@unlink($file);
			}
		}
		$controller=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller-download/native'));
		if($controller->status!==200 || $controller->body!=='download-native' || !str_contains((string)($controller->headers['Content-Disposition'] ?? ''), 'native.txt')){
			$failures[]='MVC controller download helper should return file download responses.';
		}
	}

	/**
	 * Asserts response cookies regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_response_cookies(array &$failures): void {
		$response=\Dataphyre\Http\Response::make('ok')
			->withCookie('theme', 'dark mode', 30, '/', '', true, true, 'Strict')
			->withoutCookie('legacy');
		$cookies=$response->headers['Set-Cookie'] ?? [];
		if(!is_array($cookies) || count($cookies)!==2){
			$failures[]='HTTP responses should preserve multiple Set-Cookie headers.';
			return;
		}
		if(!str_starts_with($cookies[0], 'theme=dark%20mode;') || !str_contains($cookies[0], '; Secure; HttpOnly; SameSite=Strict')){
			$failures[]='HTTP responses should format persistent cookies with attributes.';
		}
		if(!str_starts_with($cookies[1], 'legacy=;') || !str_contains($cookies[1], '; Max-Age=0')){
			$failures[]='HTTP responses should expire forgotten cookies.';
		}
		$app=new MvcApplication('redirect-cookies', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/cookie', static fn(): \Dataphyre\Mvc\RedirectResult => (new \Dataphyre\Mvc\RedirectResult('/next'))
					->withCookie('seen', 'yes')
					->withoutCookie('old'));
			},
		]);
		$redirect=$app->dispatcher()->dispatch(Request::create('GET', '/cookie'));
		$redirect_cookies=$redirect->headers['Set-Cookie'] ?? [];
		if($redirect->status!==302 || !is_array($redirect_cookies) || count($redirect_cookies)!==2){
			$failures[]='MVC redirects should carry multiple Set-Cookie headers.';
		}
	}

	/**
	 * Asserts response cache helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_response_cache_helpers(array &$failures): void {
		$response=\Dataphyre\Http\Response::make('ok');
		$cached=$response
			->cacheFor(60)
			->withEtag('abc123')
			->withLastModified(0);
		$private=$response->privateCacheFor(30);
		$no_cache=$response->noCache();
		$weak=$response->withEtag('"weak-value"', true);
		$etag_request=Request::create('GET', '/cache', [], [], [], [], ['If-None-Match'=>'W/"abc123"']);
		$modified_request=Request::create('GET', '/cache', [], [], [], [], ['If-Modified-Since'=>'Thu, 01 Jan 1970 00:00:00 GMT']);
		$not_modified=$cached->withHeader('Content-Length', '2')->withConditionalHeaders($etag_request);
		$last_modified_match=$cached->withConditionalHeaders($modified_request);
		if(($cached->headers['Cache-Control'] ?? null)!=='public, max-age=60' || ($cached->headers['ETag'] ?? null)!=='"abc123"' || ($cached->headers['Last-Modified'] ?? null)!=='Thu, 01 Jan 1970 00:00:00 GMT'){
			$failures[]='HTTP response cache helpers should set public cache, ETag, and Last-Modified headers.';
		}
		if(($private->headers['Cache-Control'] ?? null)!=='private, max-age=30'){
			$failures[]='HTTP response cache helpers should set private cache headers.';
		}
		if(($no_cache->headers['Cache-Control'] ?? null)!=='no-store, no-cache, must-revalidate, max-age=0' || ($no_cache->headers['Pragma'] ?? null)!=='no-cache' || ($no_cache->headers['Expires'] ?? null)!=='0'){
			$failures[]='HTTP response cache helpers should set no-cache headers.';
		}
		if(($weak->headers['ETag'] ?? null)!=='W/"weak-value"'){
			$failures[]='HTTP response ETag helper should support weak tags and normalize quotes.';
		}
		if($not_modified->status!==304 || $not_modified->body!=='' || isset($not_modified->headers['Content-Length']) || ($not_modified->headers['ETag'] ?? null)!=='"abc123"'){
			$failures[]='HTTP response conditional helpers should convert matching ETags to 304 responses.';
		}
		if($last_modified_match->status!==304 || $last_modified_match->body!==''){
			$failures[]='HTTP response conditional helpers should convert matching Last-Modified dates to 304 responses.';
		}
		if(isset($response->headers['Cache-Control']) || isset($response->headers['ETag'])){
			$failures[]='HTTP response cache helpers should not mutate the original response.';
		}
	}

	/**
	 * Asserts response headers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_response_headers(array &$failures): void {
		$response=\Dataphyre\Http\Response::html('ok', 200, ['X-App'=>'route']);
		$merged=$response->withHeaders(['X-App'=>'default', 'X-Default'=>'yes']);
		if(($merged->headers['X-App'] ?? null)!=='route' || ($merged->headers['X-Default'] ?? null)!=='yes'){
			$failures[]='HTTP response header defaults should preserve existing response headers.';
		}
		if(isset($response->headers['X-Default'])){
			$failures[]='HTTP response header merging should not mutate the original response.';
		}
		$app=dp_mvc_regression_app();
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/hello/header'));
		if(($response->headers['X-Dataphyre-MVC'] ?? null)!=='1'){
			$failures[]='MVC dispatcher should apply configured default response headers via HTTP response helpers.';
		}
	}

	/**
	 * Asserts action arguments regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_action_arguments(array &$failures): void {
		$request=Request::create('GET', '/argument-test');
		$arguments=\Dataphyre\Http\ActionArguments::resolve(
			static fn(Request $request, string $name, string $fallback='ok'): array => [$request, $name, $fallback],
			$request,
			['name'=>'dataphyre']
		);
		if(($arguments[0] ?? null)!==$request || ($arguments[1] ?? null)!=='dataphyre' || ($arguments[2] ?? null)!=='ok'){
			$failures[]='HTTP action argument resolver should inject requests, route parameters, and defaults.';
		}
	}

	/**
	 * Asserts controller string regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_controller_string(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller/native'));
		if($response->status!==200 || $response->body!=='{"controller":"native"}'){
			$failures[]='Controller string should resolve through the configured controller namespace.';
		}
	}

	/**
	 * Asserts invokable controller regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_invokable_controller(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/invoke/native'));
		if($response->status!==200 || $response->body!=='{"invoked":"native","path":"/invoke/native"}'){
			$failures[]='Controller strings without an explicit method should dispatch to __invoke.';
		}
	}

	/**
	 * Asserts controller manifest descriptor regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_controller_manifest_descriptor(array &$failures): void {
		$app=dp_mvc_regression_app();
		$manifest=$app->routes()->compile();
		$route=null;
		foreach($manifest['routes'] ?? [] as $compiled_route){
			if(($compiled_route['path'] ?? null)==='/controller/{name}'){
				$route=$compiled_route;
				break;
			}
		}
		$handler=$route['handler'] ?? null;
		if(!is_array($handler) || ($handler['type'] ?? null)!=='controller' || ($handler['class'] ?? null)!=='Dataphyre\\Mvc\\Regression\\ExampleController'){
			$failures[]='MVC controller strings should compile through Routing ControllerAction descriptors.';
		}
	}

	/**
	 * Asserts route context injection regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_context_injection(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/context/contextual'));
		if($response->status!==200 || $response->body!=='{"route":"context.show","name":"contextual","parameter":"contextual"}'){
			$failures[]='Controller actions should receive optional MVC route context metadata.';
		}
	}

	/**
	 * Asserts controller route helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_controller_route_helpers(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller-redirect/native'));
		if($response->status!==302 || ($response->headers['Location'] ?? null)!=='/hello/native?from=controller'){
			$failures[]='Controller route helpers should generate named-route redirects.';
		}
	}

	/**
	 * Asserts controller response helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_controller_response_helpers(array &$failures): void {
		$created=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller-created/native'));
		if($created->status!==201 || $created->body!=='{"created":"native"}'){
			$failures[]='Controller created helper should return a 201 JSON response.';
		}
		if(($created->headers['Location'] ?? null)!=='/controller/native'){
			$failures[]='Controller created helper should attach the Location header.';
		}
		$empty=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller-no-content'));
		if($empty->status!==204 || $empty->body!==''){
			$failures[]='Controller noContent helper should return an empty 204 response.';
		}
		$static=\Dataphyre\Mvc\Mvc::created(['ok'=>true], '/ok');
		if($static->status!==201 || $static->body!=='{"ok":true}' || ($static->headers['Location'] ?? null)!=='/ok'){
			$failures[]='Static MVC created helper should return a 201 JSON response with optional location.';
		}
		if(\Dataphyre\Mvc\Mvc::noContent()->status!==204){
			$failures[]='Static MVC noContent helper should return a 204 response.';
		}
	}

	/**
	 * Asserts http exceptions regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_http_exceptions(array &$failures): void {
		$app=new MvcApplication('http-exceptions', [
			'response_headers'=>[
				'X-Dataphyre-MVC'=>'1',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/abort', static function(): void {
					\Dataphyre\Mvc\Mvc::abort(418, 'Teapot', ['X-Abort'=>'static']);
				});
				$routes->get('/abort-if', static function(): array {
					\Dataphyre\Mvc\Mvc::abortIf(false, 500);
					\Dataphyre\Mvc\Mvc::abortUnless(true, 500);
					return ['ok'=>true];
				});
			},
		]);
		$static=$app->dispatcher()->dispatch(Request::create('GET', '/abort'));
		$json=$app->dispatcher()->dispatch(Request::create('GET', '/abort', [], [], [], [], ['Accept'=>'application/json']));
		$pass=$app->dispatcher()->dispatch(Request::create('GET', '/abort-if'));
		$controller=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller-abort/native'));
		if($static->status!==418 || $static->body!=='Teapot' || ($static->headers['X-Abort'] ?? null)!=='static' || ($static->headers['X-Dataphyre-MVC'] ?? null)!=='1'){
			$failures[]='MVC abort helper should convert HTTP exceptions into responses with headers.';
		}
		if($json->status!==418 || $json->body!=='{"message":"Teapot","status":418}' || ($json->headers['Content-Type'] ?? null)!=='application/json; charset=utf-8' || ($json->headers['X-Abort'] ?? null)!=='static'){
			$failures[]='MVC abort helper should return structured JSON for requests that expect JSON.';
		}
		if($pass->status!==200 || $pass->body!=='{"ok":true}'){
			$failures[]='MVC abortIf and abortUnless should allow requests when conditions pass.';
		}
		if($controller->status!==403 || $controller->body!=='No native' || ($controller->headers['X-Abort'] ?? null)!=='controller'){
			$failures[]='MVC controller abort helper should throw HTTP exceptions.';
		}
	}

	/**
	 * Asserts named routes regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_named_routes(array &$failures): void {
		$routes=dp_mvc_regression_app()->routes();
		try{
			if($routes->url('hello', ['name'=>'data phyre'], ['page'=>2])!=='/hello/data%20phyre?page=2'){
				$failures[]='Named route URL should fill parameters and query values.';
			}
			if($routes->url('admin.dashboard')!=='/admin/dashboard'){
				$failures[]='Grouped route name prefix should apply to route URLs.';
			}
		}catch(\Throwable $throwable){
			$failures[]='Named route URL generation threw: '.$throwable->getMessage();
		}
	}

	/**
	 * Asserts signed URLs regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_signed_urls(array &$failures): void {
		$app=new MvcApplication('signed-urls', [
			'signed_url_secret'=>'secret',
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/signed/{id}', static fn(string $id): array => ['id'=>$id])->name('signed.show');
			},
		]);
		$url=$app->routes()->signedUrl('signed.show', ['id'=>'native mvc'], ['b'=>2, 'a'=>1]);
		$parts=parse_url($url);
		parse_str((string)($parts['query'] ?? ''), $query);
		$request=Request::create('GET', (string)($parts['path'] ?? '/'), $query);
		if(($parts['path'] ?? null)!=='/signed/native%20mvc' || !\Dataphyre\Mvc\SignedUrl::valid($request, 'secret')){
			$failures[]='MVC signed URLs should sign named route URLs with stable query ordering.';
		}
		$query['a']='tampered';
		if(\Dataphyre\Mvc\SignedUrl::valid(Request::create('GET', (string)$parts['path'], $query), 'secret')){
			$failures[]='MVC signed URL validation should reject tampered query values.';
		}
		$expired=$app->routes()->temporarySignedUrl('signed.show', time()-5, ['id'=>'native mvc']);
		$expired_parts=parse_url($expired);
		parse_str((string)($expired_parts['query'] ?? ''), $expired_query);
		if(\Dataphyre\Mvc\SignedUrl::valid(Request::create('GET', (string)$expired_parts['path'], $expired_query), 'secret')){
			$failures[]='MVC signed URL validation should reject expired signatures.';
		}
	}

	/**
	 * Asserts signed URL middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_signed_url_middleware(array &$failures): void {
		$app=new MvcApplication('signed-url-middleware', [
			'signed_url_secret'=>'secret',
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/guarded/{id}', static fn(string $id): array => ['id'=>$id])
					->name('guarded.show')
					->middleware('signed');
			},
		]);
		$signed=$app->routes()->signedUrl('guarded.show', ['id'=>'42']);
		$parts=parse_url($signed);
		parse_str((string)($parts['query'] ?? ''), $query);
		$valid=$app->dispatcher()->dispatch(Request::create('GET', (string)($parts['path'] ?? '/'), $query));
		$invalid_query=$query;
		$invalid_query['signature']='bad';
		$invalid=$app->dispatcher()->dispatch(Request::create('GET', (string)($parts['path'] ?? '/'), $invalid_query));
		if($valid->status!==200 || $valid->body!=='{"id":"42"}'){
			$failures[]='MVC signed middleware should allow valid signed route requests.';
		}
		if($invalid->status!==403){
			$failures[]='MVC signed middleware should reject invalid signed route requests.';
		}
	}

	/**
	 * Asserts grouped option names regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_grouped_option_names(array &$failures): void {
		$app=new MvcApplication('option-names', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->group(['prefix'=>'admin', 'as'=>'admin.'], function(RouteCollection $routes): void {
					$routes->get('/reports', static fn(): string => 'reports', ['name'=>'reports']);
				});
			},
		]);
		if($app->routes()->url('admin.reports')!=='/admin/reports'){
			$failures[]='Grouped route option names should receive the group prefix exactly once.';
		}
		try{
			$app->routes()->url('admin.admin.reports');
			$failures[]='Grouped route option names should not be double-prefixed.';
		}catch(\RuntimeException){
		}
	}

	/**
	 * Asserts domain routes regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_domain_routes(array &$failures): void {
		$app=new MvcApplication('domain-routes', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/dashboard', static fn(): array => ['admin'=>true], [
					'domain'=>'admin.example.test',
					'name'=>'admin.dashboard',
				]);
				$routes->domain('{tenant}.example.test', function(RouteCollection $routes): void {
					$routes->get('/orders/{order}', static fn(string $tenant, string $order): array => [
						'tenant'=>$tenant,
						'order'=>$order,
					])->name('tenant.orders.show');
				});
			},
		]);
		$admin=$app->dispatcher()->dispatch(Request::create('GET', '/dashboard', [], [], [], ['HTTP_HOST'=>'admin.example.test']));
		if($admin->status!==200 || $admin->body!=='{"admin":true}'){
			$failures[]='MVC domain routes should match exact request hosts.';
		}
		$wrong_host=$app->dispatcher()->dispatch(Request::create('GET', '/dashboard', [], [], [], ['HTTP_HOST'=>'shop.example.test']));
		if($wrong_host->status!==404){
			$failures[]='MVC domain routes should not match a different request host.';
		}
		$tenant=$app->dispatcher()->dispatch(Request::create('GET', '/orders/42', [], [], [], ['HTTP_HOST'=>'acme.example.test']));
		if($tenant->status!==200 || $tenant->body!=='{"tenant":"acme","order":"42"}'){
			$failures[]='MVC domain route parameters should be injected with path parameters.';
		}
		if($app->routes()->url('tenant.orders.show', ['tenant'=>'acme', 'order'=>'42'])!=='//acme.example.test/orders/42'){
			$failures[]='MVC named URL generation should fill domain and path parameters.';
		}
		$list=$app->routes()->list();
		if(($list[0]['domain'] ?? null)!=='admin.example.test' || ($list[1]['domain'] ?? null)!=='{tenant}.example.test'){
			$failures[]='MVC route list should expose route domains.';
		}
		$route=Route::get('/routing-domain/{id}', static fn() => null)->domain('{tenant}.routing.test')->compile();
		$matched=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$route], 'GET', '/routing-domain/9', 'blue.routing.test');
		if(($matched['parameters']['tenant'] ?? null)!=='blue' || ($matched['parameters']['id'] ?? null)!=='9'){
			$failures[]='Routing domain matcher should share host parameter extraction with MVC.';
		}
		$parameters=[];
		$definition=\Dataphyre\Mvc\RouteDefinition::make('GET', '/match-domain', static fn() => null, ['domain'=>'match.example.test']);
		if(!$definition->matches('GET', '/match-domain', $parameters, 'match.example.test') || $parameters!==[]){
			$failures[]='MVC route definitions should expose host-aware matching.';
		}
	}

	/**
	 * Asserts route constraints regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_constraints(array &$failures): void {
		$app=new MvcApplication('route-constraints', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/orders/{order}', static fn(string $order): array => ['order'=>$order])
					->whereNumber('order')
					->name('orders.show');
				$routes->get('/posts/{slug}', static fn(string $slug): array => ['slug'=>$slug], [
					'where'=>['slug'=>'[a-z0-9-]+'],
				]);
				$routes->group(['where'=>['tenant'=>'[a-z]+']], function(RouteCollection $routes): void {
					$routes->domain('{tenant}.constraints.test', function(RouteCollection $routes): void {
						$routes->get('/dashboard', static fn(string $tenant): array => ['tenant'=>$tenant]);
					});
				});
				$routes->get('/states/{state}', static fn(string $state): array => ['state'=>$state])
					->whereIn('state', ['open', 'closed']);
				$routes->get('/ulids/{id}', static fn(string $id): array => ['id'=>$id])
					->whereUlid('id');
			},
		]);
		$numeric=$app->dispatcher()->dispatch(Request::create('GET', '/orders/42'));
		if($numeric->status!==200 || $numeric->body!=='{"order":"42"}'){
			$failures[]='MVC route constraints should allow matching parameters.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/orders/not-number'))->status!==404){
			$failures[]='MVC route constraints should reject non-matching path parameters.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/posts/native-mvc'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/posts/Native'))->status!==404){
			$failures[]='MVC route option constraints should apply during dispatch.';
		}
		$tenant=$app->dispatcher()->dispatch(Request::create('GET', '/dashboard', [], [], [], ['HTTP_HOST'=>'acme.constraints.test']));
		$bad_tenant=$app->dispatcher()->dispatch(Request::create('GET', '/dashboard', [], [], [], ['HTTP_HOST'=>'acme1.constraints.test']));
		if($tenant->status!==200 || $tenant->body!=='{"tenant":"acme"}' || $bad_tenant->status!==404){
			$failures[]='MVC grouped constraints should apply to domain parameters.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/states/open'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/states/pending'))->status!==404){
			$failures[]='MVC whereIn constraints should limit parameters to allowed values.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/ulids/01ARZ3NDEKTSV4RRFFQ69G5FAV'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/ulids/01arz3ndektsv4rrffq69g5fav'))->status!==404){
			$failures[]='MVC whereUlid constraints should limit parameters to canonical ULIDs.';
		}
		$list=$app->routes()->list();
		if(($list[0]['constraints']['order'] ?? null)!=='[0-9]+'){
			$failures[]='MVC route list should expose route constraints.';
		}
		$global=new MvcApplication('global-route-patterns', [
			'route_patterns'=>[
				'id'=>'[0-9]+',
				'product'=>'[A-Z]+',
			],
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/global/{id}', static fn(string $id): array => ['id'=>$id]);
				$routes->get('/global-override/{id}', static fn(string $id): array => ['id'=>$id], [
					'where'=>['id'=>'[a-z]+'],
				]);
				$routes->resource('global/products', 'ResourceController', [
					'only'=>['show'],
					'param'=>'product',
				]);
			},
		]);
		$global_match=$global->dispatcher()->dispatch(Request::create('GET', '/global/42'));
		$global_reject=$global->dispatcher()->dispatch(Request::create('GET', '/global/native'));
		$global_override=$global->dispatcher()->dispatch(Request::create('GET', '/global-override/native'));
		$global_resource=$global->dispatcher()->dispatch(Request::create('GET', '/global/products/ABC'));
		$global_resource_reject=$global->dispatcher()->dispatch(Request::create('GET', '/global/products/abc'));
		if($global_match->status!==200 || $global_reject->status!==404){
			$failures[]='MVC app route_patterns should apply to route parameters.';
		}
		if($global_override->status!==200){
			$failures[]='MVC route constraints should override app route_patterns.';
		}
		if($global_resource->status!==200 || $global_resource_reject->status!==404){
			$failures[]='MVC app route_patterns should apply to resource route parameters.';
		}
		$route=Route::get('/routing-constraints/{id}', static fn() => null)->whereNumber('id')->compile();
		if(\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$route], 'GET', '/routing-constraints/9')===null){
			$failures[]='Routing matcher should allow constrained parameters.';
		}
		if(\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$route], 'GET', '/routing-constraints/nope')!==null){
			$failures[]='Routing matcher should reject constrained parameters.';
		}
	}

	/**
	 * Asserts optional route parameters regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_optional_route_parameters(array &$failures): void {
		$app=new MvcApplication('optional-route-parameters', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/reports/{filter?}', static fn(string $filter='all'): array => ['filter'=>$filter])
					->where('filter', '[a-z]+')
					->name('reports.filter');
				$routes->get('/archive/{year}/{month?}', static fn(string $year, string $month='all'): array => [
					'year'=>$year,
					'month'=>$month,
				])->whereNumber(['year', 'month'])->name('archive.month');
			},
		]);
		$default=$app->dispatcher()->dispatch(Request::create('GET', '/reports'));
		$filtered=$app->dispatcher()->dispatch(Request::create('GET', '/reports/open'));
		$bad_filter=$app->dispatcher()->dispatch(Request::create('GET', '/reports/123'));
		if($default->status!==200 || $default->body!=='{"filter":"all"}'){
			$failures[]='MVC optional route parameters should allow missing trailing parameters and use action defaults.';
		}
		if($filtered->status!==200 || $filtered->body!=='{"filter":"open"}'){
			$failures[]='MVC optional route parameters should publish present route parameters.';
		}
		if($bad_filter->status!==404){
			$failures[]='MVC optional route parameters should still honor constraints.';
		}
		if($app->routes()->url('reports.filter')!=='/reports' || $app->routes()->url('reports.filter', ['filter'=>'open'])!=='/reports/open'){
			$failures[]='MVC named URLs should omit missing optional route parameters.';
		}
		if($app->routes()->url('archive.month', ['year'=>'2026'])!=='/archive/2026' || $app->routes()->url('archive.month', ['year'=>'2026', 'month'=>'05'])!=='/archive/2026/05'){
			$failures[]='MVC named URLs should compose required and optional route parameters.';
		}
		$route=Route::get('/routing-optional/{name?}', static fn() => null)->whereAlpha('name')->compile();
		$missing=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$route], 'GET', '/routing-optional');
		$present=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$route], 'GET', '/routing-optional/native');
		$rejected=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$route], 'GET', '/routing-optional/123');
		if(($missing['parameters'] ?? null)!==[] || ($present['parameters']['name'] ?? null)!=='native' || $rejected!==null){
			$failures[]='Routing optional parameters should share matching and constraints with MVC.';
		}
	}

	/**
	 * Asserts route defaults regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_defaults(array &$failures): void {
		$app=new MvcApplication('route-defaults', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/localized/{locale?}/products/{product}', static fn(string $locale, string $product): array => [
					'locale'=>$locale,
					'product'=>$product,
				])->defaults('locale', 'en')->name('localized.products.show');
				$routes->group(['defaults'=>['format'=>'json']], function(RouteCollection $routes): void {
					$routes->get('/exports/{format?}', static fn(string $format): array => ['format'=>$format])->name('exports.show');
				});
				$routes->domain('{tenant}.defaults.test', function(RouteCollection $routes): void {
					$routes->get('/dashboard', static fn(string $tenant): array => ['tenant'=>$tenant])
						->defaults('tenant', 'main')
						->name('tenant.dashboard');
				});
			},
		]);
		$localized=$app->dispatcher()->dispatch(Request::create('GET', '/localized/products/desk'));
		$explicit=$app->dispatcher()->dispatch(Request::create('GET', '/localized/fr/products/desk'));
		if($localized->status!==200 || $localized->body!=='{"locale":"en","product":"desk"}'){
			$failures[]='MVC route defaults should fill missing optional parameters during dispatch.';
		}
		if($explicit->status!==200 || $explicit->body!=='{"locale":"fr","product":"desk"}'){
			$failures[]='MVC route parameters should override route defaults during dispatch.';
		}
		if($app->routes()->url('localized.products.show', ['product'=>'desk'])!=='/localized/en/products/desk'){
			$failures[]='MVC named URLs should use route defaults for missing placeholders.';
		}
		if($app->routes()->url('localized.products.show', ['locale'=>'fr', 'product'=>'desk'])!=='/localized/fr/products/desk'){
			$failures[]='MVC named URLs should let explicit parameters override route defaults.';
		}
		$export=$app->dispatcher()->dispatch(Request::create('GET', '/exports'));
		if($export->status!==200 || $export->body!=='{"format":"json"}'){
			$failures[]='MVC grouped route defaults should apply to child routes.';
		}
		if($app->routes()->url('tenant.dashboard')!=='//main.defaults.test/dashboard'){
			$failures[]='MVC named URLs should use route defaults for domain placeholders.';
		}
		$tenant=$app->dispatcher()->dispatch(Request::create('GET', '/dashboard', [], [], [], ['HTTP_HOST'=>'acme.defaults.test']));
		if($tenant->status!==200 || $tenant->body!=='{"tenant":"acme"}'){
			$failures[]='MVC domain parameters should override domain route defaults during dispatch.';
		}
		$list=$app->routes()->list();
		if(($list[0]['defaults']['locale'] ?? null)!=='en'){
			$failures[]='MVC route list should expose route defaults.';
		}
		$global=new MvcApplication('global-route-defaults', [
			'route_defaults'=>[
				'locale'=>'en',
				'format'=>'json',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/global-defaults/{locale?}/feed/{format?}', static fn(string $locale, string $format): array => [
					'locale'=>$locale,
					'format'=>$format,
				])->name('global-defaults.feed');
				$routes->get('/global-defaults-override/{locale?}', static fn(string $locale): array => ['locale'=>$locale], [
					'defaults'=>['locale'=>'fr'],
					'name'=>'global-defaults.override',
				]);
			},
		]);
		$global_default=$global->dispatcher()->dispatch(Request::create('GET', '/global-defaults/feed'));
		$global_override=$global->dispatcher()->dispatch(Request::create('GET', '/global-defaults-override'));
		if($global_default->status!==200 || $global_default->body!=='{"locale":"en","format":"json"}'){
			$failures[]='MVC app route_defaults should fill missing optional parameters during dispatch.';
		}
		if($global->routes()->url('global-defaults.feed')!=='/global-defaults/en/feed/json'){
			$failures[]='MVC app route_defaults should fill missing named URL parameters.';
		}
		if($global_override->status!==200 || $global_override->body!=='{"locale":"fr"}'){
			$failures[]='MVC route defaults should override app route_defaults.';
		}
		$global_list=$global->routes()->list();
		if(($global_list[0]['defaults']['locale'] ?? null)!=='en' || ($global_list[0]['defaults']['format'] ?? null)!=='json'){
			$failures[]='MVC route list should expose app route_defaults on routes.';
		}
		$route=Route::get('/routing-default/{name?}', static fn() => null)->defaults('name', 'native')->compile();
		$matched=\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request([$route], 'GET', '/routing-default');
		if(($matched['parameters']['name'] ?? null)!=='native'){
			$failures[]='Routing matcher should merge defaults into missing optional parameters.';
		}
	}

	/**
	 * Asserts routing named URLs regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_routing_named_urls(array &$failures): void {
		$manifest=RouteManifest::compile([
			Route::get('/routing/{name}', static fn(): string => 'routing')->name('routing.show'),
		]);
		if(RouteManifest::namedUrl($manifest, 'routing.show', ['name'=>'native mvc'])!=='/routing/native%20mvc'){
			$failures[]='Routing compiled routes should preserve source paths for named URL generation.';
		}
	}

	/**
	 * Asserts route metadata regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_metadata(array &$failures): void {
		$manifest=RouteManifest::compile([
			Route::get('/metadata', static fn(): string => 'metadata')
				->metadata(['feature'=>['name'=>'routing']]),
		]);
		$metadata=RouteManifest::routeMetadata($manifest['routes'][0] ?? [], 'feature', []);
		if(($metadata['name'] ?? null)!=='routing'){
			$failures[]='Routing compiled routes should expose generic route metadata.';
		}
		$route=RouteManifest::withRouteMetadata([], 'feature', ['name'=>'attached']);
		if((RouteManifest::routeMetadata($route, 'feature', [])['name'] ?? null)!=='attached'){
			$failures[]='Routing compiled routes should attach generic route metadata.';
		}
		$app=dp_mvc_regression_app();
		$manifest=$app->routes()->compile();
		$route=$manifest['routes'][0] ?? [];
		$mvc_metadata=RouteManifest::routeMetadata($route, 'mvc', []);
		if(($mvc_metadata['route_index'] ?? null)!==0){
			$failures[]='MVC compiled routes should store source route pointers in route metadata.';
		}
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/hello/metadata'));
		if($response->status!==200 || $response->body!=='{"hello":"metadata"}'){
			$failures[]='MVC dispatcher should resolve source routes from route metadata.';
		}
	}

	/**
	 * Asserts route list regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_list(array &$failures): void {
		$app=dp_mvc_regression_app();
		$list=$app->routes()->list();
		$by_path=[];
		foreach($list as $entry){
			$by_path[$entry['path']]=$entry;
		}
		if(($by_path['/hello/{name}']['name'] ?? null)!=='hello'){
			$failures[]='MVC route list should expose route names.';
		}
		if(($by_path['/controller/{name}']['action'] ?? null)!=='Dataphyre\\Mvc\\Regression\\ExampleController@show'){
			$failures[]='MVC route list should expose controller action labels.';
		}
		if(($by_path['/admin/dashboard']['middleware'] ?? [])!==['header', 'tag:blue']){
			$failures[]='MVC route list should expose normalized middleware labels.';
		}
		if(($by_path['/hello/{name}']['methods'] ?? [])!==['GET']){
			$failures[]='MVC route list should expose route methods.';
		}
	}

	/**
	 * Asserts route list CLI regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_list_cli(array &$failures): void {
		$directory=sys_get_temp_dir().'/dataphyre_mvc_route_list_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary MVC route list directory.';
			return;
		}
		$config_file=$directory.'/mvc.php';
		file_put_contents($config_file, <<<'PHP'
<?php
return [
	'routes'=>[
		['path'=>'/cli-route', 'name'=>'cli.route', 'handler'=>static fn(): string => 'cli'],
	],
];
PHP);
		try{
			$php=PHP_BINARY;
			$script=__DIR__.'/route_list.php';
			$command=escapeshellarg($php).' '.escapeshellarg($script).' --config='.escapeshellarg($config_file).' --json';
			$output=[];
			$exit_code=0;
			exec($command, $output, $exit_code);
			$json=implode("\n", $output);
			$routes=json_decode($json, true);
			if($exit_code!==0 || !is_array($routes) || (($routes[0]['name'] ?? null)!=='cli.route') || (($routes[0]['path'] ?? null)!=='/cli-route')){
				$failures[]='MVC route list CLI should emit JSON route introspection from config files.';
			}
		}finally{
			if(is_file($config_file)){
				unlink($config_file);
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts route cache CLI regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_cache_cli(array &$failures): void {
		$directory=sys_get_temp_dir().'/dataphyre_mvc_route_cache_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary MVC route cache directory.';
			return;
		}
		$config_file=$directory.'/mvc.php';
		$cache_file=$directory.'/routes.php';
		file_put_contents($config_file, <<<PHP
<?php
return [
	'manifest_cache'=>'{$cache_file}',
	'routes'=>[
		['path'=>'/cached-cli-route', 'name'=>'cached.cli.route', 'handler'=>'Dataphyre\\\\Mvc\\\\Regression\\\\ExampleController@show'],
	],
];
PHP);
		try{
			$php=PHP_BINARY;
			$script=__DIR__.'/cache_routes.php';
			$command=escapeshellarg($php).' '.escapeshellarg($script).' --config='.escapeshellarg($config_file);
			$output=[];
			$exit_code=0;
			exec($command, $output, $exit_code);
			$manifest=is_file($cache_file) ? require($cache_file) : null;
			if($exit_code!==0 || !is_array($manifest) || (($manifest['metadata']['signature'] ?? '')==='')){
				$failures[]='MVC route cache CLI should write exportable route manifests with signatures.';
			}
			$clear_script=__DIR__.'/clear_cached_routes.php';
			$clear_command=escapeshellarg($php).' '.escapeshellarg($clear_script).' --config='.escapeshellarg($config_file);
			$clear_output=[];
			$clear_exit_code=0;
			exec($clear_command, $clear_output, $clear_exit_code);
			clearstatcache(true, $cache_file);
			if($clear_exit_code!==0 || is_file($cache_file)){
				$failures[]='MVC route cache clear CLI should remove configured manifest cache files.';
			}
		}finally{
			foreach([$config_file, $cache_file] as $file){
				if(is_file($file)){
					unlink($file);
				}
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts route normalization regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_normalization(array &$failures): void {
		$route=\Dataphyre\Mvc\RouteDefinition::make([''], '///normalized/path///', static fn(): string => 'normalized');
		if($route->methods()!==['GET'] || $route->path()!=='/normalized/path'){
			$failures[]='MVC route definitions should use Routing method and path normalization.';
		}
		$app=new MvcApplication('normalized-groups', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->group(['prefix'=>'/nested///'], function(RouteCollection $routes): void {
					$routes->get('///path///', static fn(): string => 'normalized');
				});
			},
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/nested/path'));
		if($response->status!==200 || $response->body!=='normalized'){
			$failures[]='MVC route groups should normalize prefixed paths through Routing.';
		}
	}

	/**
	 * Asserts group helper methods regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_group_helper_methods(array &$failures): void {
		$app=new MvcApplication('group-helper-methods', [
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->prefix('api', function(RouteCollection $routes): void {
					$routes->name('api.', function(RouteCollection $routes): void {
						$routes->middleware('header', function(RouteCollection $routes): void {
							$routes->where('id', '[0-9]+', function(RouteCollection $routes): void {
								$routes->get('/orders/{id}', static fn(string $id): array => ['id'=>$id])->name('orders.show');
							});
						});
					});
				});
				$routes->where(['slug'=>'[a-z-]+'], function(RouteCollection $routes): void {
					$routes->get('/slugs/{slug}', static fn(string $slug): array => ['slug'=>$slug])->name('slugs.show');
				});
				$routes->whereNumber(['year', 'month'], function(RouteCollection $routes): void {
					$routes->get('/archive/{year}/{month}', static fn(string $year, string $month): array => ['year'=>$year, 'month'=>$month])->name('archive.numeric');
				});
				$routes->whereAlpha('code', function(RouteCollection $routes): void {
					$routes->get('/alpha/{code}', static fn(string $code): array => ['code'=>$code]);
				});
				$routes->whereAlphaNumeric('token', function(RouteCollection $routes): void {
					$routes->get('/alnum/{token}', static fn(string $token): array => ['token'=>$token]);
				});
				$routes->whereUuid('uuid', function(RouteCollection $routes): void {
					$routes->get('/uuids/{uuid}', static fn(string $uuid): array => ['uuid'=>$uuid]);
				});
				$routes->whereUlid('ulid', function(RouteCollection $routes): void {
					$routes->get('/group-ulids/{ulid}', static fn(string $ulid): array => ['ulid'=>$ulid]);
				});
				$routes->whereIn('state', ['open', 'closed'], function(RouteCollection $routes): void {
					$routes->get('/group-states/{state}', static fn(string $state): array => ['state'=>$state]);
				});
				$routes->defaults(['locale'=>'en'], function(RouteCollection $routes): void {
					$routes->get('/defaulted/{locale?}/feed', static fn(string $locale): array => ['locale'=>$locale])->name('defaulted.feed');
				});
				$routes->defaults('format', 'json', function(RouteCollection $routes): void {
					$routes->get('/scalar-default/{format?}', static fn(string $format): array => ['format'=>$format])->name('defaulted.scalar');
				});
			},
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/api/orders/42'));
		if($response->status!==200 || $response->body!=='{"id":"42"}'){
			$failures[]='MVC prefix/name/middleware/where group helpers should compose around route dispatch.';
		}
		if(($response->headers['X-Middleware'] ?? null)!=='seen'){
			$failures[]='MVC middleware group helper should apply middleware aliases.';
		}
		if($app->routes()->url('api.orders.show', ['id'=>'42'])!=='/api/orders/42'){
			$failures[]='MVC name and prefix group helpers should compose named route URLs.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/api/orders/nope'))->status!==404){
			$failures[]='MVC where group helper should apply scalar route constraints.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/slugs/native-mvc'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/slugs/Native'))->status!==404){
			$failures[]='MVC where group helper should apply array route constraints.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/archive/2026/05'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/archive/2026/may'))->status!==404){
			$failures[]='MVC whereNumber group helper should apply numeric constraints.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/alpha/Native'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/alpha/Native1'))->status!==404){
			$failures[]='MVC whereAlpha group helper should apply alphabetic constraints.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/alnum/Native1'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/alnum/native-mvc'))->status!==404){
			$failures[]='MVC whereAlphaNumeric group helper should apply alpha-numeric constraints.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/uuids/550e8400-e29b-41d4-a716-446655440000'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/uuids/not-a-uuid'))->status!==404){
			$failures[]='MVC whereUuid group helper should apply UUID constraints.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/group-ulids/01ARZ3NDEKTSV4RRFFQ69G5FAV'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/group-ulids/01arz3ndektsv4rrffq69g5fav'))->status!==404){
			$failures[]='MVC whereUlid group helper should apply ULID constraints.';
		}
		if($app->dispatcher()->dispatch(Request::create('GET', '/group-states/open'))->status!==200 || $app->dispatcher()->dispatch(Request::create('GET', '/group-states/pending'))->status!==404){
			$failures[]='MVC whereIn group helper should apply listed value constraints.';
		}
		$defaulted=$app->dispatcher()->dispatch(Request::create('GET', '/defaulted/feed'));
		$scalar_defaulted=$app->dispatcher()->dispatch(Request::create('GET', '/scalar-default'));
		if($defaulted->status!==200 || $defaulted->body!=='{"locale":"en"}' || $app->routes()->url('defaulted.feed')!=='/defaulted/en/feed'){
			$failures[]='MVC defaults group helper should apply array defaults to dispatch and URLs.';
		}
		if($scalar_defaulted->status!==200 || $scalar_defaulted->body!=='{"format":"json"}' || $app->routes()->url('defaulted.scalar')!=='/scalar-default/json'){
			$failures[]='MVC defaults group helper should apply scalar defaults to dispatch and URLs.';
		}
	}

	/**
	 * Asserts route collection macros regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_collection_macros(array &$failures): void {
		RouteCollection::flushMacros();
		RouteCollection::macro('adminPage', function(string $path, string $name, mixed $handler): ?\Dataphyre\Mvc\RouteDefinition {
			$this->prefix('admin', function(RouteCollection $routes) use ($path, $name, $handler): void {
				$routes->get($path, $handler)
					->middleware('header')
					->name('admin.'.$name);
			});
			return $this->named('admin.'.$name);
		});
		$app=new MvcApplication('route-collection-macros', [
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->adminPage('/reports', 'reports.index', static fn(): array => ['admin'=>true]);
			},
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/admin/reports'));
		$route=$app->routes()->named('admin.reports.index');
		if(!RouteCollection::hasMacro('adminPage')){
			$failures[]='MVC route collection macros should be registered by name.';
		}
		if(
			$response->status!==200
			|| $response->body!=='{"admin":true}'
			|| ($response->headers['X-Middleware'] ?? null)!=='seen'
			|| $route===null
		){
			$failures[]='MVC route collection macros should compose native collection and route mutators.';
		}
		RouteCollection::flushMacros();
		if(RouteCollection::hasMacro('adminPage')){
			$failures[]='MVC route collection macros should be flushable between runtimes.';
		}
	}

	/**
	 * Asserts controller groups regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_controller_groups(array &$failures): void {
		$app=new MvcApplication('controller-groups', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->controller('GroupedController', function(RouteCollection $routes): void {
					$routes->get('/grouped', 'index')->name('grouped.index');
					$routes->get('/grouped/{name}', 'show')->name('grouped.show');
				});
				$routes->group(['controller'=>'GroupedController', 'prefix'=>'option-group'], function(RouteCollection $routes): void {
					$routes->get('/{name}', 'show')->name('grouped.option.show');
				});
			},
		]);
		$index=$app->dispatcher()->dispatch(Request::create('GET', '/grouped'));
		$show=$app->dispatcher()->dispatch(Request::create('GET', '/grouped/native'));
		$option=$app->dispatcher()->dispatch(Request::create('GET', '/option-group/native'));
		if($index->status!==200 || $index->body!=='{"grouped":"index"}'){
			$failures[]='MVC controller groups should resolve action-only route handlers.';
		}
		if($show->status!==200 || $show->body!=='{"grouped":"native"}'){
			$failures[]='MVC controller groups should preserve route parameter injection.';
		}
		if($option->status!==200 || $option->body!=='{"grouped":"native"}'){
			$failures[]='MVC controller group options should resolve action-only route handlers.';
		}
		$list=$app->routes()->list();
		if(($list[0]['action'] ?? null)!=='Dataphyre\\Mvc\\Regression\\GroupedController@index'){
			$failures[]='MVC route lists should expose expanded controller group action labels.';
		}
	}

	/**
	 * Asserts head and options routes regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_head_and_options_routes(array &$failures): void {
		$manifest=RouteManifest::compile([
			Route::head('/routing-head', static fn(): string => 'head'),
			Route::options('/routing-options', static fn(): string => 'options'),
		]);
		if(\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request($manifest['routes'], 'HEAD', '/routing-head')===null){
			$failures[]='Routing should match HEAD route builders.';
		}
		if(\dataphyre\routing\compiled_route_dispatcher::match_routes_for_request($manifest['routes'], 'OPTIONS', '/routing-options')===null){
			$failures[]='Routing should match OPTIONS route builders.';
		}
		$app=new MvcApplication('verbs', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->head('/head', static fn(): string => 'head');
				$routes->options('/options', static fn(): string => 'options');
			},
		]);
		$head=$app->dispatcher()->dispatch(Request::create('HEAD', '/head'));
		$options=$app->dispatcher()->dispatch(Request::create('OPTIONS', '/options'));
		if($head->status!==200 || $head->body!=='head'){
			$failures[]='MVC should dispatch HEAD routes.';
		}
		if($options->status!==200 || $options->body!=='options'){
			$failures[]='MVC should dispatch OPTIONS routes.';
		}
	}

	/**
	 * Asserts method override regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_method_override(array &$failures): void {
		$app=new MvcApplication('method-override', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->patch('/override/{id}', static fn(Request $request, string $id): array => [
					'method'=>$request->method(),
					'effective'=>$request->effectiveMethod(),
					'id'=>$id,
				]);
				$routes->delete('/override/{id}', static fn(Request $request, string $id): array => [
					'method'=>$request->method(),
					'effective'=>$request->effectiveMethod(),
					'id'=>$id,
				]);
			},
		]);
		$body_override=$app->dispatcher()->dispatch(Request::create('POST', '/override/42', [], [
			'_method'=>'PATCH',
		]));
		$header_override=$app->dispatcher()->dispatch(Request::create('POST', '/override/42', [], [], [], [], [
			'X-HTTP-Method-Override'=>'DELETE',
		]));
		$invalid_override=$app->dispatcher()->dispatch(Request::create('POST', '/override/42', [], [
			'_method'=>'TRACE',
		]));
		if($body_override->status!==200 || $body_override->body!=='{"method":"POST","effective":"PATCH","id":"42"}'){
			$failures[]='MVC should match POST form requests using _method overrides while preserving original method.';
		}
		if($header_override->status!==200 || $header_override->body!=='{"method":"POST","effective":"DELETE","id":"42"}'){
			$failures[]='MVC should match POST requests using X-HTTP-Method-Override headers.';
		}
		if($invalid_override->status!==404){
			$failures[]='MVC should ignore unsupported method override values.';
		}
	}

	/**
	 * Asserts encoded parameters decode regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_encoded_parameters_decode(array &$failures): void {
		$app=dp_mvc_regression_app();
		$url=$app->routes()->url('hello', ['name'=>'data phyre']);
		$response=$app->dispatcher()->dispatch(Request::create('GET', $url));
		if($url!=='/hello/data%20phyre' || $response->status!==200 || $response->body!=='{"hello":"data phyre"}'){
			$failures[]='Compiled routing should decode encoded route parameters after URL generation.';
		}
	}

	/**
	 * Asserts redirect route regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_redirect_route(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/go'));
		if($response->status!==302 || ($response->headers['Location'] ?? null)!=='/there'){
			$failures[]='Redirect route should return 302 with Location header.';
		}
	}

	/**
	 * Asserts named redirect routes regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_named_redirect_routes(array &$failures): void {
		$app=new MvcApplication('named-redirects', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/products/{id}', static fn(string $id): array => ['id'=>$id])->name('products.show');
				$routes->redirectToRoute('/latest-product', 'products.show', ['id'=>'data phyre'], ['from'=>'route'], 301);
			},
		]);
		$route_response=$app->dispatcher()->dispatch(Request::create('GET', '/latest-product'));
		if($route_response->status!==301 || ($route_response->headers['Location'] ?? null)!=='/products/data%20phyre?from=route'){
			$failures[]='MVC redirectToRoute route helper should generate Location headers from named routes.';
		}
		$app=new MvcApplication('array-named-redirects', [
			'routes'=>[
				['path'=>'/posts/{slug}', 'name'=>'posts.show', 'handler'=>static fn(string $slug): array => ['slug'=>$slug]],
				[
					'path'=>'/featured-post',
					'redirect_route'=>'posts.show',
					'parameters'=>['slug'=>'native mvc'],
					'query'=>['ref'=>'config'],
					'status'=>308,
				],
			],
		]);
		$config_response=$app->dispatcher()->dispatch(Request::create('GET', '/featured-post'));
		if($config_response->status!==308 || ($config_response->headers['Location'] ?? null)!=='/posts/native%20mvc?ref=config'){
			$failures[]='MVC array route definitions should support named-route redirect shortcuts.';
		}
	}

	/**
	 * Asserts resource routes regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_resource_routes(array &$failures): void {
		$app=new MvcApplication('resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
			],
			'routes'=>function(RouteCollection $routes): void {
				$definitions=$routes->resource('products', 'ResourceController', [
					'except'=>['destroy'],
					'middleware'=>'header',
				]);
				if(array_keys($definitions)!==['index', 'create', 'store', 'show', 'edit', 'update']){
					throw new \RuntimeException('Unexpected resource route keys.');
				}
			},
		]);
		$index=$app->dispatcher()->dispatch(Request::create('GET', '/products'));
		$create=$app->dispatcher()->dispatch(Request::create('GET', '/products/create'));
		$show=$app->dispatcher()->dispatch(Request::create('GET', '/products/native-mvc'));
		$update=$app->dispatcher()->dispatch(Request::create('PATCH', '/products/native-mvc'));
		$destroy=$app->dispatcher()->dispatch(Request::create('DELETE', '/products/native-mvc'));
		if($index->status!==200 || $index->body!=='{"action":"index"}' || ($index->headers['X-Middleware'] ?? null)!=='seen'){
			$failures[]='MVC resource routes should dispatch index actions with shared route options.';
		}
		if($create->status!==200 || $create->body!=='{"action":"create"}'){
			$failures[]='MVC resource routes should dispatch create before show routes.';
		}
		if($show->status!==200 || $show->body!=='{"action":"show","product":"native-mvc"}'){
			$failures[]='MVC resource routes should dispatch show actions with singular route parameters.';
		}
		if($update->status!==200 || $update->body!=='{"action":"update","product":"native-mvc"}'){
			$failures[]='MVC resource routes should dispatch PATCH update actions.';
		}
		if($destroy->status!==404){
			$failures[]='MVC resource routes should honor except filters.';
		}
		if($app->routes()->url('products.show', ['product'=>'native mvc'])!=='/products/native%20mvc'){
			$failures[]='MVC resource routes should register conventional named routes.';
		}
		$custom=new MvcApplication('custom-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->resource('admin/products', 'ResourceController', [
					'only'=>['show'],
					'param'=>'product',
					'names'=>['show'=>'admin.products.display'],
				]);
			},
		]);
		$response=$custom->dispatcher()->dispatch(Request::create('GET', '/admin/products/custom'));
		if($response->status!==200 || $response->body!=='{"action":"show","product":"custom"}'){
			$failures[]='MVC resource routes should honor custom resource parameters.';
		}
		if($custom->routes()->url('admin.products.display', ['product'=>'custom'])!=='/admin/products/custom'){
			$failures[]='MVC resource routes should honor custom action names.';
		}
		$base_named=new MvcApplication('base-named-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->resource('admin/products', 'ResourceController', [
					'only'=>['index', 'show'],
					'name'=>'products',
					'names'=>['show'=>'products.display'],
					'param'=>'product',
				]);
			},
		]);
		if($base_named->routes()->url('products.index')!=='/admin/products'){
			$failures[]='MVC resource routes should support custom base route names.';
		}
		if($base_named->routes()->url('products.display', ['product'=>'custom'])!=='/admin/products/custom'){
			$failures[]='MVC resource per-action names should override custom base route names.';
		}
		$custom_verbs=new MvcApplication('custom-resource-verbs', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->resource('verb/products', 'ResourceController', [
					'only'=>['create', 'edit'],
					'param'=>'product',
					'verbs'=>[
						'create'=>'new',
						'edit'=>'modify',
					],
				]);
			},
		]);
		$custom_verb_create=$custom_verbs->dispatcher()->dispatch(Request::create('GET', '/verb/products/new'));
		$custom_verb_old_create=$custom_verbs->dispatcher()->dispatch(Request::create('GET', '/verb/products/create'));
		$custom_verb_edit=$custom_verbs->dispatcher()->dispatch(Request::create('GET', '/verb/products/widget/modify'));
		$custom_verb_old_edit=$custom_verbs->dispatcher()->dispatch(Request::create('GET', '/verb/products/widget/edit'));
		if($custom_verb_create->status!==200 || $custom_verb_create->body!=='{"action":"create"}' || $custom_verb_edit->status!==200 || $custom_verb_edit->body!=='{"action":"edit","product":"widget"}'){
			$failures[]='MVC resource routes should support custom create and edit URI verbs.';
		}
		if($custom_verb_old_create->status!==404 || $custom_verb_old_edit->status!==404){
			$failures[]='MVC resource routes should omit default create and edit URI verbs when custom verbs are configured.';
		}
		$configured_verbs=new MvcApplication('configured-resource-verbs', [
			'resource_verbs'=>[
				'create'=>'novo',
				'edit'=>'editar',
			],
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->resource('configured/products', 'ResourceController', [
					'only'=>['create', 'edit'],
					'param'=>'product',
				]);
				$routes->resource('configured/override-products', 'ResourceController', [
					'only'=>['create'],
					'verbs'=>['create'=>'new'],
				]);
			},
		]);
		$configured_verb_create=$configured_verbs->dispatcher()->dispatch(Request::create('GET', '/configured/products/novo'));
		$configured_verb_edit=$configured_verbs->dispatcher()->dispatch(Request::create('GET', '/configured/products/widget/editar'));
		$configured_verb_old_edit=$configured_verbs->dispatcher()->dispatch(Request::create('GET', '/configured/products/widget/edit'));
		$configured_verb_override=$configured_verbs->dispatcher()->dispatch(Request::create('GET', '/configured/override-products/new'));
		$configured_verb_configured_override=$configured_verbs->dispatcher()->dispatch(Request::create('GET', '/configured/override-products/novo'));
		if($configured_verb_create->status!==200 || $configured_verb_edit->status!==200 || $configured_verb_old_edit->status!==404){
			$failures[]='MVC resource routes should support app-level custom create and edit URI verbs.';
		}
		if($configured_verb_override->status!==200 || $configured_verb_configured_override->status!==404){
			$failures[]='MVC resource route URI verbs should override app-level resource verbs.';
		}
		$mapped=new MvcApplication('mapped-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->resource('catalog/products', 'ItemResourceController', [
					'only'=>['show'],
					'parameters'=>['products'=>'item'],
					'names'=>['show'=>'catalog.products.display'],
				]);
			},
		]);
		$mapped_response=$mapped->dispatcher()->dispatch(Request::create('GET', '/catalog/products/widget'));
		if($mapped_response->status!==200 || $mapped_response->body!=='{"action":"show","item":"widget"}'){
			$failures[]='MVC nested resource routes should use leaf parameter map entries during dispatch.';
		}
		if($mapped->routes()->url('catalog.products.display', ['item'=>'widget'])!=='/catalog/products/widget'){
			$failures[]='MVC nested resource routes should use leaf parameter map entries for URLs.';
		}
		$remapped=new MvcApplication('remapped-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->resource('remapped/products', 'RemappedResourceController', [
					'only'=>['index', 'show'],
					'actions'=>[
						'index'=>'listing',
						'show'=>'display',
					],
					'param'=>'product',
				]);
			},
		]);
		$remapped_index=$remapped->dispatcher()->dispatch(Request::create('GET', '/remapped/products'));
		$remapped_show=$remapped->dispatcher()->dispatch(Request::create('GET', '/remapped/products/widget'));
		if($remapped_index->status!==200 || $remapped_index->body!=='{"action":"listing"}' || $remapped_show->status!==200 || $remapped_show->body!=='{"action":"display","product":"widget"}'){
			$failures[]='MVC resource routes should support custom controller action methods.';
		}
		$shallow=new MvcApplication('shallow-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$definitions=$routes->resource('posts/{post}/comments', 'CommentResourceController', [
					'only'=>['index', 'store', 'show'],
					'shallow'=>true,
				]);
				if(array_keys($definitions)!==['index', 'store', 'show']){
					throw new \RuntimeException('Unexpected shallow resource route keys.');
				}
			},
		]);
		$shallow_index=$shallow->dispatcher()->dispatch(Request::create('GET', '/posts/alpha/comments'));
		$shallow_store=$shallow->dispatcher()->dispatch(Request::create('POST', '/posts/alpha/comments'));
		$shallow_show=$shallow->dispatcher()->dispatch(Request::create('GET', '/comments/bravo'));
		$shallow_nested_show=$shallow->dispatcher()->dispatch(Request::create('GET', '/posts/alpha/comments/bravo'));
		if($shallow_index->status!==200 || $shallow_index->body!=='{"action":"index","post":"alpha"}' || $shallow_store->status!==200 || $shallow_store->body!=='{"action":"store","post":"alpha"}'){
			$failures[]='MVC shallow resource routes should keep collection actions nested.';
		}
		if($shallow_show->status!==200 || $shallow_show->body!=='{"action":"show","comment":"bravo"}' || $shallow_nested_show->status!==404){
			$failures[]='MVC shallow resource routes should register member actions at the leaf path.';
		}
		if($shallow->routes()->url('comments.show', ['comment'=>'bravo'])!=='/comments/bravo'){
			$failures[]='MVC shallow resource routes should use leaf member route names for URLs.';
		}
		if($shallow->routes()->url('posts.comments.index', ['post'=>'alpha'])!=='/posts/alpha/comments'){
			$failures[]='MVC shallow resource routes should keep nested collection route names.';
		}
		$action_options=new MvcApplication('resource-action-options', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->resource('optioned/products', 'ResourceController', [
					'only'=>['index', 'show'],
					'param'=>'product',
					'middleware_for'=>[
						'show'=>'header',
					],
					'action_options'=>[
						'show'=>[
							'where'=>['product'=>'[0-9]+'],
						],
					],
				]);
			},
		]);
		$action_options_index=$action_options->dispatcher()->dispatch(Request::create('GET', '/optioned/products'));
		$action_options_show=$action_options->dispatcher()->dispatch(Request::create('GET', '/optioned/products/42'));
		$action_options_invalid=$action_options->dispatcher()->dispatch(Request::create('GET', '/optioned/products/native'));
		if($action_options_index->status!==200 || isset($action_options_index->headers['X-Middleware'])){
			$failures[]='MVC resource action middleware should only apply to configured actions.';
		}
		if($action_options_show->status!==200 || ($action_options_show->headers['X-Middleware'] ?? null)!=='seen'){
			$failures[]='MVC resource action middleware should apply to targeted actions.';
		}
		if($action_options_invalid->status!==404){
			$failures[]='MVC resource action options should apply targeted route constraints.';
		}
		$singleton_action_options=new MvcApplication('singleton-resource-action-options', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->singletonResource('optioned/profile', 'SingletonResourceController', [
					'only'=>['show', 'update'],
					'middleware'=>'header',
					'without_middleware_for'=>[
						'show'=>'header',
					],
				]);
			},
		]);
		$singleton_action_show=$singleton_action_options->dispatcher()->dispatch(Request::create('GET', '/optioned/profile'));
		$singleton_action_update=$singleton_action_options->dispatcher()->dispatch(Request::create('PATCH', '/optioned/profile'));
		if($singleton_action_show->status!==200 || isset($singleton_action_show->headers['X-Middleware'])){
			$failures[]='MVC singleton resource action options should remove middleware from targeted actions.';
		}
		if($singleton_action_update->status!==200 || ($singleton_action_update->headers['X-Middleware'] ?? null)!=='seen'){
			$failures[]='MVC singleton resource shared middleware should remain on untargeted actions.';
		}
		$api=new MvcApplication('api-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$definitions=$routes->apiResource('api/products', 'ResourceController', [
					'only'=>['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'],
					'param'=>'product',
				]);
				if(array_keys($definitions)!==['index', 'store', 'show', 'update', 'destroy']){
					throw new \RuntimeException('Unexpected API resource route keys.');
				}
			},
		]);
		$api_index=$api->dispatcher()->dispatch(Request::create('GET', '/api/products'));
		$api_create=$api->dispatcher()->dispatch(Request::create('GET', '/api/products/create'));
		$api_show=$api->dispatcher()->dispatch(Request::create('GET', '/api/products/native'));
		$api_edit=$api->dispatcher()->dispatch(Request::create('GET', '/api/products/native/edit'));
		if($api_index->status!==200 || $api_index->body!=='{"action":"index"}'){
			$failures[]='MVC apiResource routes should dispatch index actions.';
		}
		if($api_show->status!==200 || $api_show->body!=='{"action":"show","product":"native"}'){
			$failures[]='MVC apiResource routes should dispatch show actions with resource parameters.';
		}
		if($api_create->status!==200 || $api_create->body!=='{"action":"show","product":"create"}' || $api_edit->status!==404){
			$failures[]='MVC apiResource routes should omit create and edit action routes.';
		}
		$singleton=new MvcApplication('singleton-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$definitions=$routes->singletonResource('profile', 'SingletonResourceController', [
					'except'=>['destroy'],
					'names'=>['show'=>'profile.display'],
				]);
				if(array_keys($definitions)!==['create', 'store', 'show', 'edit', 'update']){
					throw new \RuntimeException('Unexpected singleton resource route keys.');
				}
			},
		]);
		$singleton_show=$singleton->dispatcher()->dispatch(Request::create('GET', '/profile'));
		$singleton_edit=$singleton->dispatcher()->dispatch(Request::create('GET', '/profile/edit'));
		$singleton_update=$singleton->dispatcher()->dispatch(Request::create('PATCH', '/profile'));
		$singleton_destroy=$singleton->dispatcher()->dispatch(Request::create('DELETE', '/profile'));
		if($singleton_show->status!==200 || $singleton_show->body!=='{"action":"show"}'){
			$failures[]='MVC singletonResource routes should dispatch show without route parameters.';
		}
		if($singleton_edit->status!==200 || $singleton_edit->body!=='{"action":"edit"}'){
			$failures[]='MVC singletonResource routes should dispatch edit routes.';
		}
		if($singleton_update->status!==200 || $singleton_update->body!=='{"action":"update"}'){
			$failures[]='MVC singletonResource routes should dispatch update routes.';
		}
		if($singleton_destroy->status!==404){
			$failures[]='MVC singletonResource routes should honor except filters.';
		}
		if($singleton->routes()->url('profile.display')!=='/profile'){
			$failures[]='MVC singletonResource routes should honor custom action names.';
		}
		$base_named_singleton=new MvcApplication('base-named-singleton-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->singletonResource('account/profile', 'SingletonResourceController', [
					'only'=>['show', 'update'],
					'as'=>'profile',
				]);
			},
		]);
		if($base_named_singleton->routes()->url('profile.show')!=='/account/profile' || $base_named_singleton->routes()->url('profile.update')!=='/account/profile'){
			$failures[]='MVC singletonResource routes should support custom base route names.';
		}
		$custom_singleton_verbs=new MvcApplication('custom-singleton-resource-verbs', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->singletonResource('verb/profile', 'SingletonResourceController', [
					'only'=>['create', 'edit'],
					'uri_verbs'=>[
						'create'=>'new',
						'edit'=>'modify',
					],
				]);
			},
		]);
		$custom_singleton_create=$custom_singleton_verbs->dispatcher()->dispatch(Request::create('GET', '/verb/profile/new'));
		$custom_singleton_edit=$custom_singleton_verbs->dispatcher()->dispatch(Request::create('GET', '/verb/profile/modify'));
		$custom_singleton_old_edit=$custom_singleton_verbs->dispatcher()->dispatch(Request::create('GET', '/verb/profile/edit'));
		if($custom_singleton_create->status!==200 || $custom_singleton_create->body!=='{"action":"create"}' || $custom_singleton_edit->status!==200 || $custom_singleton_edit->body!=='{"action":"edit"}' || $custom_singleton_old_edit->status!==404){
			$failures[]='MVC singletonResource routes should support custom create and edit URI verbs.';
		}
		$configured_singleton_verbs=new MvcApplication('configured-singleton-resource-verbs', [
			'resource_uri_verbs'=>[
				'create'=>'novo',
				'edit'=>'editar',
			],
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->singletonResource('configured/profile', 'SingletonResourceController', [
					'only'=>['create', 'edit'],
				]);
			},
		]);
		$configured_singleton_create=$configured_singleton_verbs->dispatcher()->dispatch(Request::create('GET', '/configured/profile/novo'));
		$configured_singleton_edit=$configured_singleton_verbs->dispatcher()->dispatch(Request::create('GET', '/configured/profile/editar'));
		if($configured_singleton_create->status!==200 || $configured_singleton_edit->status!==200){
			$failures[]='MVC singletonResource routes should support app-level custom create and edit URI verbs.';
		}
		$remapped_singleton=new MvcApplication('remapped-singleton-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->singletonResource('preferences', 'RemappedSingletonResourceController', [
					'only'=>['show', 'update'],
					'actions'=>[
						'show'=>'display',
						'update'=>'save',
					],
				]);
			},
		]);
		$remapped_singleton_show=$remapped_singleton->dispatcher()->dispatch(Request::create('GET', '/preferences'));
		$remapped_singleton_update=$remapped_singleton->dispatcher()->dispatch(Request::create('PATCH', '/preferences'));
		if($remapped_singleton_show->status!==200 || $remapped_singleton_show->body!=='{"action":"display"}' || $remapped_singleton_update->status!==200 || $remapped_singleton_update->body!=='{"action":"save"}'){
			$failures[]='MVC singletonResource routes should support custom controller action methods.';
		}
		$api_singleton=new MvcApplication('api-singleton-resource-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$definitions=$routes->apiSingletonResource('settings', 'SingletonResourceController', [
					'only'=>['create', 'store', 'show', 'edit', 'update', 'destroy'],
				]);
				if(array_keys($definitions)!==['store', 'show', 'update', 'destroy']){
					throw new \RuntimeException('Unexpected API singleton resource route keys.');
				}
			},
		]);
		$api_singleton_create=$api_singleton->dispatcher()->dispatch(Request::create('GET', '/settings/create'));
		$api_singleton_show=$api_singleton->dispatcher()->dispatch(Request::create('GET', '/settings'));
		if($api_singleton_create->status!==404 || $api_singleton_show->status!==200 || $api_singleton_show->body!=='{"action":"show"}'){
			$failures[]='MVC apiSingletonResource routes should omit create and edit while keeping singleton show.';
		}
		$batch=new MvcApplication('resource-batch-routes', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$resources=$routes->resources([
					'catalog/products'=>'ResourceController',
				], [
					'only'=>['index', 'show'],
					'param'=>'product',
				]);
				$api_resources=$routes->apiResources([
					'v1/products'=>'ResourceController',
				], [
					'param'=>'product',
				]);
				$singletons=$routes->singletonResources([
					'account/profile'=>'SingletonResourceController',
				], [
					'only'=>['show'],
				]);
				$api_singletons=$routes->apiSingletonResources([
					'account/settings'=>'SingletonResourceController',
				]);
				$customized=$routes->resources([
					'custom/products'=>[
						'controller'=>'ItemResourceController',
						'only'=>['show'],
						'param'=>'item',
						'names'=>['show'=>'custom.products.display'],
					],
					'custom/orders'=>[
						'ResourceController',
						[
							'only'=>['index'],
						],
					],
					'custom/remapped'=>[
						'controller'=>'RemappedResourceController',
						'only'=>['index'],
						'actions'=>['index'=>'listing'],
					],
					'custom/named'=>[
						'controller'=>'ResourceController',
						'only'=>['index'],
						'name'=>'named.products',
					],
				]);
				if(array_keys($resources)!==['catalog/products'] || array_keys($resources['catalog/products'])!==['index', 'show']){
					throw new \RuntimeException('Unexpected batched resource route keys.');
				}
				if(array_keys($api_resources)!==['v1/products'] || array_key_exists('create', $api_resources['v1/products'])){
					throw new \RuntimeException('Unexpected batched API resource route keys.');
				}
				if(array_keys($singletons)!==['account/profile'] || array_keys($singletons['account/profile'])!==['show']){
					throw new \RuntimeException('Unexpected batched singleton resource route keys.');
				}
				if(array_keys($api_singletons)!==['account/settings'] || array_key_exists('edit', $api_singletons['account/settings'])){
					throw new \RuntimeException('Unexpected batched API singleton resource route keys.');
				}
				if(array_keys($customized)!==['custom/products', 'custom/orders', 'custom/remapped', 'custom/named'] || array_keys($customized['custom/products'])!==['show'] || array_keys($customized['custom/orders'])!==['index'] || array_keys($customized['custom/remapped'])!==['index'] || array_keys($customized['custom/named'])!==['index']){
					throw new \RuntimeException('Unexpected per-resource batch option route keys.');
				}
			},
		]);
		$batch_index=$batch->dispatcher()->dispatch(Request::create('GET', '/catalog/products'));
		$batch_show=$batch->dispatcher()->dispatch(Request::create('GET', '/catalog/products/native'));
		$batch_api_edit=$batch->dispatcher()->dispatch(Request::create('GET', '/v1/products/native/edit'));
		$batch_singleton=$batch->dispatcher()->dispatch(Request::create('GET', '/account/profile'));
		$batch_api_singleton=$batch->dispatcher()->dispatch(Request::create('GET', '/account/settings'));
		$batch_custom_show=$batch->dispatcher()->dispatch(Request::create('GET', '/custom/products/widget'));
		$batch_custom_store=$batch->dispatcher()->dispatch(Request::create('POST', '/custom/products'));
		$batch_custom_index=$batch->dispatcher()->dispatch(Request::create('GET', '/custom/orders'));
		$batch_custom_remapped=$batch->dispatcher()->dispatch(Request::create('GET', '/custom/remapped'));
		if($batch_index->status!==200 || $batch_index->body!=='{"action":"index"}' || $batch_show->status!==200 || $batch_show->body!=='{"action":"show","product":"native"}'){
			$failures[]='MVC resources should register multiple conventional resource routes.';
		}
		if($batch_api_edit->status!==404){
			$failures[]='MVC apiResources should register API resource routes without edit actions.';
		}
		if($batch_singleton->status!==200 || $batch_singleton->body!=='{"action":"show"}' || $batch_api_singleton->status!==200 || $batch_api_singleton->body!=='{"action":"show"}'){
			$failures[]='MVC singleton resource batch helpers should register singleton show routes.';
		}
		if($batch_custom_show->status!==200 || $batch_custom_show->body!=='{"action":"show","item":"widget"}' || $batch_custom_store->status!==404 || $batch_custom_index->status!==200){
			$failures[]='MVC resource batch helpers should honor per-resource options.';
		}
		if($batch->routes()->url('custom.products.display', ['item'=>'widget'])!=='/custom/products/widget'){
			$failures[]='MVC resource batch helpers should honor per-resource custom names and parameters.';
		}
		if($batch_custom_remapped->status!==200 || $batch_custom_remapped->body!=='{"action":"listing"}'){
			$failures[]='MVC resource batch helpers should honor per-resource custom action methods.';
		}
		if($batch->routes()->url('named.products.index')!=='/custom/named'){
			$failures[]='MVC resource batch helpers should honor per-resource custom base route names.';
		}
	}

	/**
	 * Asserts middleware normalization regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_middleware_normalization(array &$failures): void {
		$definition=Route::normalizeMiddleware('tag:blue');
		if(($definition['alias'] ?? null)!=='tag' || ($definition['parameters'][0] ?? null)!=='blue'){
			$failures[]='Routing should expose middleware string normalization for MVC dispatch.';
		}
	}

	/**
	 * Asserts grouped middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_grouped_middleware(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/admin/dashboard'));
		if($response->status!==200 || $response->body!=='dashboard'){
			$failures[]='Grouped route should dispatch under its prefix.';
		}
		if(($response->headers['X-Middleware'] ?? null)!=='seen'){
			$failures[]='Grouped middleware alias should run and mutate the response.';
		}
		if(($response->headers['X-Tag'] ?? null)!=='blue'){
			$failures[]='Parameterized middleware alias should pass constructor parameters.';
		}
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/callable-middleware'));
		if(($response->headers['X-Callable-Tag'] ?? null)!=='green'){
			$failures[]='Callable middleware aliases should run through the routing resolver.';
		}
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/alias-override'));
		if(($response->headers['X-Middleware'] ?? null)!=='seen'){
			$failures[]='MVC app middleware aliases should override built-in routing aliases.';
		}
	}

	/**
	 * Asserts without middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_without_middleware(array &$failures): void {
		$app=new MvcApplication('without-middleware', [
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'tag'=>TagMiddleware::class,
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'stack'=>StackMiddleware::class,
			],
			'global_middleware'=>[
				'stack:global>',
			],
			'middleware_groups'=>[
				'web'=>[
					'header',
					'tag:group',
				],
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/without-direct', static fn(): string => 'without')
					->middleware('header', 'tag:direct')
					->withoutMiddleware('tag');
				$routes->get('/without-group', static fn(): string => 'without')
					->middleware('web')
					->withoutMiddleware('web');
				$routes->get('/without-option', static fn(): string => 'without', [
					'middleware'=>['header', 'tag:option'],
					'without_middleware'=>['header'],
				]);
			},
		]);
		$direct=$app->dispatcher()->dispatch(Request::create('GET', '/without-direct'));
		if(($direct->headers['X-Middleware'] ?? null)!=='seen' || isset($direct->headers['X-Tag'])){
			$failures[]='MVC withoutMiddleware should remove matching route middleware aliases and keep other route middleware.';
		}
		if(($direct->headers['X-Stack'] ?? null)!=='global>'){
			$failures[]='MVC withoutMiddleware should not remove global middleware.';
		}
		$group=$app->dispatcher()->dispatch(Request::create('GET', '/without-group'));
		if(isset($group->headers['X-Middleware']) || isset($group->headers['X-Tag'])){
			$failures[]='MVC withoutMiddleware should remove middleware expanded from route middleware groups.';
		}
		$option=$app->dispatcher()->dispatch(Request::create('GET', '/without-option'));
		if(isset($option->headers['X-Middleware']) || ($option->headers['X-Tag'] ?? null)!=='option'){
			$failures[]='MVC without_middleware route options should remove selected route middleware.';
		}
		$list=$app->routes()->list();
		if(($list[0]['without_middleware'] ?? [])!==['tag']){
			$failures[]='MVC route list should expose excluded route middleware.';
		}
	}

	/**
	 * Asserts controller middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_controller_middleware(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller-middleware'));
		if($response->status!==200 || $response->body!=='{"controller_middleware":true}'){
			$failures[]='Controller middleware routes should still return normalized controller responses.';
		}
		if(($response->headers['X-Middleware'] ?? null)!=='seen'){
			$failures[]='Controller-declared middleware should resolve aliases through the shared middleware resolver.';
		}
		if(($response->headers['X-Tag'] ?? null)!=='controller'){
			$failures[]='Controller-declared middleware should support parameterized middleware aliases.';
		}
		$plain=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/controller-middleware-plain'));
		if($plain->status!==200 || $plain->body!=='{"plain":true}'){
			$failures[]='Controller middleware scoping should still dispatch unwrapped controller actions.';
		}
		if(isset($plain->headers['X-Middleware']) || isset($plain->headers['X-Tag'])){
			$failures[]='Controller middleware only/except scoping should skip non-matching controller actions.';
		}
	}

	/**
	 * Asserts access integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_access_integration(array &$failures): void {
		\dataphyre\access::reset();
		$app=new MvcApplication('access-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/auth', 'AccessController@show')->middleware('auth');
				$routes->get('/guest', static fn(): array => ['guest'=>true])->middleware('guest');
				$routes->get('/api-auth', static fn(): array => [
					'logged_in'=>Mvc::loggedIn('api'),
					'user_id'=>Mvc::userId('api'),
					'auth_type'=>Mvc::authContext('api')['auth_type'] ?? null,
				])->middleware('auth:api');
			},
		]);
		$unauthenticated=$app->dispatcher()->dispatch(Request::create('GET', '/auth'));
		$guest=$app->dispatcher()->dispatch(Request::create('GET', '/guest'));
		if($unauthenticated->status!==401 || $guest->status!==200 || $guest->body!=='{"guest":true}'){
			$failures[]='MVC auth and guest aliases should delegate unauthenticated checks to dataphyre access.';
		}
		\dataphyre\access::$logged_in=true;
		\dataphyre\access::$userid=42;
		$authenticated=$app->dispatcher()->dispatch(Request::create('GET', '/auth'));
		$blocked_guest=$app->dispatcher()->dispatch(Request::create('GET', '/guest'));
		$api=$app->dispatcher()->dispatch(Request::create('GET', '/api-auth'));
		if($authenticated->status!==200 || $authenticated->body!=='{"logged_in":true,"user_id":42,"auth_type":"session"}'){
			$failures[]='MVC controller access helpers should expose dataphyre access context.';
		}
		if($blocked_guest->status!==403){
			$failures[]='MVC guest alias should reject authenticated users through dataphyre access.';
		}
		if($api->status!==200 || $api->body!=='{"logged_in":true,"user_id":42,"auth_type":"api"}' || \dataphyre\access::$auth_type!=='api'){
			$failures[]='MVC parameterized auth aliases should pass auth types into dataphyre access.';
		}
		\dataphyre\access::reset();
	}

	/**
	 * Asserts permission integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_permission_integration(array &$failures): void {
		\dataphyre\permission::reset();
		$app=new MvcApplication('permission-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/can-all', static fn(): array => ['ok'=>true])->middleware('can:orders.view,orders.update');
				$routes->get('/can-any', static fn(): array => ['ok'=>true])->middleware('can_any:orders.refund,orders.cancel');
				$routes->get('/permission-controller', 'PermissionController@show');
				$routes->post('/permission-controller', 'PermissionController@update');
			},
		]);
		$denied_all=$app->dispatcher()->dispatch(Request::create('GET', '/can-all'));
		$denied_any=$app->dispatcher()->dispatch(Request::create('GET', '/can-any'));
		if($denied_all->status!==403 || $denied_any->status!==403){
			$failures[]='MVC permission middleware should deny routes through Dataphyre Permission.';
		}
		\dataphyre\permission::$allowed=['orders.view', 'orders.update', 'orders.cancel'];
		$allowed_all=$app->dispatcher()->dispatch(Request::create('GET', '/can-all'));
		$allowed_any=$app->dispatcher()->dispatch(Request::create('GET', '/can-any'));
		$controller=$app->dispatcher()->dispatch(Request::create('GET', '/permission-controller'));
		$authorized=$app->dispatcher()->dispatch(Request::create('POST', '/permission-controller'));
		if($allowed_all->status!==200 || $allowed_all->body!=='{"ok":true}' || $allowed_any->status!==200 || $allowed_any->body!=='{"ok":true}'){
			$failures[]='MVC permission middleware should allow routes through Dataphyre Permission.';
		}
		if($controller->status!==200 || $controller->body!=='{"can_view":true,"can_any":true}'){
			$failures[]='MVC controller permission helpers should delegate to Dataphyre Permission.';
		}
		if($authorized->status!==200 || $authorized->body!=='{"updated":true}'){
			$failures[]='MVC authorize helper should continue controller actions on allowed permissions.';
		}
		$last_call=end(\dataphyre\permission::$calls);
		if(($last_call['permissions'] ?? [])!==['orders.update'] || ($last_call['has_request'] ?? true)!==false){
			$failures[]='MVC direct permission helpers should pass permissions to Dataphyre Permission without inventing request context.';
		}
		$controller_call=\dataphyre\permission::$calls[4] ?? [];
		if(($controller_call['mode'] ?? null)!=='all' || ($controller_call['permissions'] ?? [])!==['orders.view'] || ($controller_call['has_request'] ?? true)!==false){
			$failures[]='MVC controller permission helpers should leave subject/context selection to Dataphyre Permission.';
		}
		$middleware_call=\dataphyre\permission::$calls[0] ?? [];
		if(($middleware_call['permissions'] ?? [])!==['orders.view', 'orders.update'] || ($middleware_call['has_request'] ?? false)!==true){
			$failures[]='MVC permission middleware should pass the request as permission context.';
		}
		\dataphyre\permission::reset();
	}

	/**
	 * Asserts storage integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_storage_integration(array &$failures): void {
		\dataphyre\storage::reset();
		\dataphyre\storage::$files=[
			'public'=>[
				'docs/readme.txt'=>'Stored hello',
			],
		];
		\dataphyre\storage::$metadata=[
			'public'=>[
				'docs/readme.txt'=>[
					'path'=>'docs/readme.txt',
					'size'=>12,
					'modified_at'=>1700000000,
					'mime_type'=>'text/plain; charset=utf-8',
				],
			],
		];
		\dataphyre\storage::$temporary_urls=[
			'public'=>[
				'docs/readme.txt'=>'https://storage.example.test/docs/readme.txt?signature=abc',
			],
		];
		$app=new MvcApplication('storage-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/stored-inline', 'StorageController@inline');
				$routes->get('/stored-download', 'StorageController@storedDownload');
				$routes->get('/stored-temporary', 'StorageController@temporary');
				$routes->get('/stored-missing', static fn(): \Dataphyre\Http\Response => Mvc::storageFile('missing.txt', 'public'));
			},
		]);
		$inline=$app->dispatcher()->dispatch(Request::create('GET', '/stored-inline'));
		if(
			$inline->status!==200
			|| $inline->body!=='Stored hello'
			|| ($inline->headers['Content-Type'] ?? null)!=='text/plain; charset=utf-8'
			|| ($inline->headers['Content-Length'] ?? null)!=='12'
			|| !str_starts_with((string)($inline->headers['Content-Disposition'] ?? ''), 'inline; filename="readme.txt"')
			|| ($inline->headers['Last-Modified'] ?? null)!=='Tue, 14 Nov 2023 22:13:20 GMT'
		){
			$failures[]='MVC storageFile should serve stored bytes with metadata-derived response headers.';
		}
		$download=$app->dispatcher()->dispatch(Request::create('GET', '/stored-download'));
		if($download->status!==200 || !str_starts_with((string)($download->headers['Content-Disposition'] ?? ''), 'attachment; filename="readme-download.txt"')){
			$failures[]='MVC storageDownload should serve stored bytes as an attachment.';
		}
		$temporary=$app->dispatcher()->dispatch(Request::create('GET', '/stored-temporary'));
		if($temporary->status!==200 || $temporary->body!=='{"url":"https://storage.example.test/docs/readme.txt?signature=abc"}'){
			$failures[]='MVC storageTemporaryUrl should delegate temporary URL generation to Dataphyre Storage.';
		}
		$missing=$app->dispatcher()->dispatch(Request::create('GET', '/stored-missing'));
		if($missing->status!==404){
			$failures[]='MVC storageFile should return a 404 when the stored object is missing.';
		}
		$first_call=\dataphyre\storage::$calls[0] ?? [];
		if(($first_call['method'] ?? null)!=='get' || ($first_call['path'] ?? null)!=='docs/readme.txt' || ($first_call['disk'] ?? null)!=='public'){
			$failures[]='MVC storage helpers should pass path and disk through to Dataphyre Storage.';
		}
		\dataphyre\storage::reset();
	}

	/**
	 * Asserts mailer integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_mailer_integration(array &$failures): void {
		\dataphyre\mailer::reset();
		$app=new MvcApplication('mailer-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->post('/mail/send', 'MailerController@send');
				$routes->post('/mail/queue', 'MailerController@queue');
				$routes->get('/mail/render', 'MailerController@render');
				$routes->post('/mail/static', static fn(): array => Mvc::sendMail([
					'to'=>'ops@example.test',
					'subject'=>'Static',
					'text'=>'Static',
				], 'smtp', ['priority'=>'high']));
			},
		]);
		$sent=$app->dispatcher()->dispatch(Request::create('POST', '/mail/send'));
		$queued=$app->dispatcher()->dispatch(Request::create('POST', '/mail/queue'));
		$rendered=$app->dispatcher()->dispatch(Request::create('GET', '/mail/render'));
		$static=$app->dispatcher()->dispatch(Request::create('POST', '/mail/static'));
		if($sent->status!==200 || $sent->body!=='{"ok":true,"queued":false,"provider":"log","message_id":"sent-1","status":202}'){
			$failures[]='MVC sendMail helper should delegate messages to Dataphyre Mailer.';
		}
		if($queued->status!==200 || $queued->body!=='{"ok":true,"queued":true,"provider":"log","message_id":"queued-1","status":202}'){
			$failures[]='MVC queueMail helper should delegate queued messages to Dataphyre Mailer.';
		}
		if($rendered->status!==200 || $rendered->body!=='{"subject":"Hello Avery","html":"<p>mail.receipt</p>","text":"mail.receipt"}'){
			$failures[]='MVC renderMail helper should delegate template rendering to Dataphyre Mailer.';
		}
		if($static->status!==200 || $static->body!=='{"ok":true,"queued":false,"provider":"smtp","message_id":"sent-1","status":202}'){
			$failures[]='MVC static sendMail helper should delegate to Dataphyre Mailer.';
		}
		$send_call=\dataphyre\mailer::$calls[0] ?? [];
		if(($send_call['method'] ?? null)!=='send' || ($send_call['provider'] ?? null)!=='log' || ($send_call['options']['campaign'] ?? null)!=='orders' || ($send_call['message']['subject'] ?? null)!=='Receipt'){
			$failures[]='MVC mailer helpers should pass message, provider, and options unchanged.';
		}
		$render_call=\dataphyre\mailer::$calls[2] ?? [];
		if(($render_call['method'] ?? null)!=='render' || ($render_call['template'] ?? null)!=='mail.receipt' || ($render_call['data']['name'] ?? null)!=='Avery' || ($render_call['options']['locale'] ?? null)!=='en'){
			$failures[]='MVC renderMail helper should pass template data and options unchanged.';
		}
		\dataphyre\mailer::reset();
	}

	/**
	 * Asserts templating integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_templating_integration(array &$failures): void {
		\dataphyre\templating::reset();
		$app=new MvcApplication('templating-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/template/render-file', 'TemplatingController@renderFile');
				$routes->get('/template/render-inline', 'TemplatingController@renderInline');
				$routes->get('/template/assets', 'TemplatingController@assets');
			},
		]);
		$file=$app->dispatcher()->dispatch(Request::create('GET', '/template/render-file'));
		$inline=$app->dispatcher()->dispatch(Request::create('GET', '/template/render-inline'));
		$assets=$app->dispatcher()->dispatch(Request::create('GET', '/template/assets'));
		if($file->status!==200 || $file->body!=='{"html":"<main>Welcome</main>"}'){
			$failures[]='MVC renderTemplate helper should delegate file rendering to Dataphyre Templating.';
		}
		if($inline->status!==200 || $inline->body!=='{"html":"Hello Avery"}'){
			$failures[]='MVC renderTemplateString helper should delegate inline rendering to Dataphyre Templating.';
		}
		$assets_body=json_decode($assets->body, true);
		if(
			$assets->status!==200
			|| ($assets_body['head'] ?? null)!=='<link rel="stylesheet" href="/assets/app.css">'
			|| ($assets_body['body'] ?? null)!=='<script src="/assets/app.js"></script>'
			|| ($assets_body['inline'] ?? null)!=='<link rel="preload" href="/assets/card.css" as="style">'
			|| !is_array($assets_body['manifest'] ?? null)
		){
			$failures[]='MVC template asset helpers should expose Dataphyre Templating manifests and asset HTML.';
		}
		$render_call=\dataphyre\templating::$calls[0] ?? [];
		if(($render_call['method'] ?? null)!=='render' || ($render_call['template'] ?? null)!=='home.tpl' || ($render_call['data']['title'] ?? null)!=='Welcome' || ($render_call['theme_values']['theme'] ?? null)!=='dark' || ($render_call['slots']['aside'] ?? null)!=='A'){
			$failures[]='MVC renderTemplate helper should pass template data, theme values, and slots unchanged.';
		}
		$inline_call=\dataphyre\templating::$calls[1] ?? [];
		if(($inline_call['method'] ?? null)!=='render_string' || ($inline_call['template_name'] ?? null)!=='hello.inline.tpl' || ($inline_call['data']['name'] ?? null)!=='Avery'){
			$failures[]='MVC renderTemplateString helper should pass inline template names and data unchanged.';
		}
		$inline_asset_call=null;
		foreach(\dataphyre\templating::$calls as $call){
			if(($call['method'] ?? null)==='asset_manifest_string'){
				$inline_asset_call=$call;
				break;
			}
		}
		if(($inline_asset_call['template_name'] ?? null)!=='card.inline.tpl'){
			$failures[]='MVC templateStringAssetHtml helper should delegate inline asset manifests to Dataphyre Templating.';
		}
		\dataphyre\templating::reset();
	}

	/**
	 * Asserts localization integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_localization_integration(array &$failures): void {
		\dataphyre\localization::reset();
		\dataphyre\localization::$strings=[
			'fr-CA|home|local:title'=>'Bonjour <{name}>',
			'fr-CA||global:known'=>'Connu',
			'fr-CA|cart|items.many'=>'<{count}> <{thing}>',
			'global:static'=>'Static :name',
		];
		$app=new MvcApplication('localization-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/locale/show', 'LocalizationController@show');
				$routes->get('/locale/static', static fn(): array => [
					'value'=>Mvc::translate('global:static', null, ['name'=>'Avery']),
					'choice'=>Mvc::choice(0, 'items.one', 'items.many', 'items.zero', [], 'fr-CA', 'cart'),
				]);
			},
		]);
		$show=$app->dispatcher()->dispatch(Request::create('GET', '/locale/show'));
		if($show->status!==200 || $show->body!=='{"title":"Bonjour Avery","missing":null,"has":true,"missing_check":true,"choice":"2 orders"}'){
			$failures[]='MVC localization controller helpers should delegate translations, null lookups, checks, and choices to Dataphyre Localization.';
		}
		$static=$app->dispatcher()->dispatch(Request::create('GET', '/locale/static'));
		if($static->status!==200 || $static->body!=='{"value":"Static Avery","choice":"items.zero"}'){
			$failures[]='MVC static localization helpers should delegate translation and choice lookups to Dataphyre Localization.';
		}
		$first_call=\dataphyre\localization::$calls[0] ?? [];
		if(($first_call['key'] ?? null)!=='local:title' || ($first_call['language'] ?? null)!=='fr-CA' || ($first_call['page'] ?? null)!=='home' || ($first_call['parameters']['name'] ?? null)!=='Avery'){
			$failures[]='MVC translate helper should pass key, language, page, and parameters unchanged.';
		}
		$choice_call=null;
		foreach(\dataphyre\localization::$calls as $call){
			if(($call['key'] ?? null)==='items.many'){
				$choice_call=$call;
				break;
			}
		}
		if(($choice_call['parameters']['count'] ?? null)!==2 || ($choice_call['parameters']['thing'] ?? null)!=='orders' || ($choice_call['page'] ?? null)!=='cart'){
			$failures[]='MVC choice helper should pass count parameters and selected choice keys to Dataphyre Localization.';
		}
		\dataphyre\localization::reset();
	}

	/**
	 * Asserts currency integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_currency_integration(array &$failures): void {
		\dataphyre\currency::reset();
		$app=new MvcApplication('currency-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/currency/show', 'CurrencyController@show');
				$routes->get('/currency/static', static fn(): array => [
					'format'=>Mvc::moneyFormat(0, true, 'CAD'),
					'convert'=>Mvc::moneyConvert(4, 'USD', 'EUR'),
					'round'=>Mvc::moneyRound(3.44, 'CHF', true),
				]);
			},
		]);
		$show=$app->dispatcher()->dispatch(Request::create('GET', '/currency/show'));
		if($show->status!==200 || $show->body!=='{"format":"CAD 12.50","convert":"CAD 15.00","display":"EUR 10.00","base":16,"round":12.35,"split":[3.33,3.33,3.33],"allocate":[2.5,7.5]}'){
			$failures[]='MVC currency controller helpers should delegate formatting, conversion, rounding, splitting, and allocation to Dataphyre Currency.';
		}
		$static=$app->dispatcher()->dispatch(Request::create('GET', '/currency/static'));
		if($static->status!==200 || $static->body!=='{"format":"Free","convert":6,"round":3.4}'){
			$failures[]='MVC static currency helpers should delegate to Dataphyre Currency.';
		}
		$convert_call=\dataphyre\currency::$calls[1] ?? [];
		if(($convert_call['method'] ?? null)!=='convert' || ($convert_call['source'] ?? null)!=='USD' || ($convert_call['target'] ?? null)!=='CAD' || ($convert_call['formatted'] ?? null)!==true || ($convert_call['show_free'] ?? null)!==false){
			$failures[]='MVC moneyConvert should pass currencies and formatting flags unchanged.';
		}
		$allocate_call=null;
		foreach(\dataphyre\currency::$calls as $call){
			if(($call['method'] ?? null)==='allocate_amount'){
				$allocate_call=$call;
				break;
			}
		}
		if(($allocate_call['ratios'] ?? [])!==[1, 3] || ($allocate_call['cash'] ?? null)!==true){
			$failures[]='MVC moneyAllocate should pass ratios and cash rounding flags to Dataphyre Currency.';
		}
		\dataphyre\currency::reset();
	}

	/**
	 * Asserts date translation integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_date_translation_integration(array &$failures): void {
		\dataphyre\date_translation::reset();
		$app=new MvcApplication('date-translation-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/date-translation/show', 'DateTranslationController@show');
				$routes->get('/date-translation/static', static fn(): array => [
					'date'=>Mvc::translateDate('January 1st', 'fr', 'd M Y'),
				]);
			},
		]);
		$show=$app->dispatcher()->dispatch(Request::create('GET', '/date-translation/show'));
		if($show->status!==200 || $show->body!=='{"french":"fr-CA|F jS|March 15th","english":"March 15th"}'){
			$failures[]='MVC date translation helpers should delegate date localization to Dataphyre Date Translation.';
		}
		$static=$app->dispatcher()->dispatch(Request::create('GET', '/date-translation/static'));
		if($static->status!==200 || $static->body!=='{"date":"fr|d M Y|January 1st"}'){
			$failures[]='MVC static translateDate helper should delegate to Dataphyre Date Translation.';
		}
		$first_call=\dataphyre\date_translation::$calls[0] ?? [];
		if(($first_call['date'] ?? null)!=='March 15th' || ($first_call['language'] ?? null)!=='fr-CA' || ($first_call['format'] ?? null)!=='F jS'){
			$failures[]='MVC translateDate should pass date string, language, and format unchanged.';
		}
		\dataphyre\date_translation::reset();
	}

	/**
	 * Asserts sanitation integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_sanitation_integration(array &$failures): void {
		\Dataphyre\Sanitation\Sanitation::reset();
		\Dataphyre\Sanitation\Sanitation::registerPreset('profile', [
			'schema'=>[
				'name'=>'default',
				'email'=>'email',
				'slug'=>'slug',
			],
		]);
		$app=new MvcApplication('sanitation-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->post('/sanitation/clean', 'SanitationController@cleanRequest');
				$routes->post('/sanitation/preset', 'SanitationController@preset');
				$routes->post('/sanitation/static', static fn(Request $request): array => [
					'slug'=>Mvc::sanitizer($request->input('title'))->slug(),
					'fail_fast'=>Mvc::sanitizedOrFail($request, ['age'=>'integer'], [], [], 'Bad input'),
					'available'=>Mvc::sanitationAvailable(),
				]);
			},
		]);
		$clean=$app->dispatcher()->dispatch(Request::create('POST', '/sanitation/clean', [], [
			'name'=>' <b>Avery</b> ',
			'email'=>' AVERY@EXAMPLE.TEST ',
			'age'=>'42',
		]));
		if($clean->status!==200 || $clean->body!=='{"name":"Avery","email":"avery@example.test","validated":{"role":"customer","name":"Avery","age":42},"anonymized":"av***@example.test"}'){
			$failures[]='MVC sanitation controller helpers should delegate value, bag, schema, and anonymization work to Dataphyre Sanitation.';
		}
		$preset=$app->dispatcher()->dispatch(Request::create('POST', '/sanitation/preset', [], [
			'name'=>' <i>Avery</i> ',
			'email'=>' AVERY@EXAMPLE.TEST ',
			'slug'=>'Native MVC!',
		]));
		if($preset->status!==200 || $preset->body!=='{"name":"Avery","email":"avery@example.test","slug":"native-mvc"}'){
			$failures[]='MVC sanitation preset helpers should delegate preset resolution to Dataphyre Sanitation.';
		}
		$static=$app->dispatcher()->dispatch(Request::create('POST', '/sanitation/static', [], [
			'title'=>'Native MVC!',
			'age'=>'7',
		]));
		if($static->status!==200 || $static->body!=='{"slug":"native-mvc","fail_fast":{"age":7},"available":true}'){
			$failures[]='MVC static sanitation helpers should expose fluent sanitizers and fail-fast schema sanitation.';
		}
		$validated_call=null;
		foreach(\Dataphyre\Sanitation\Sanitation::$calls as $call){
			if(($call['method'] ?? null)==='validated'){
				$validated_call=$call;
				break;
			}
		}
		if(($validated_call['input']['name'] ?? null)!==' <b>Avery</b> ' || ($validated_call['schema']['age'] ?? null)!=='integer' || ($validated_call['defaults']['role'] ?? null)!=='customer'){
			$failures[]='MVC sanitized helper should pass merged request input, schema, and defaults unchanged to Dataphyre Sanitation.';
		}
		$preset_call=null;
		foreach(\Dataphyre\Sanitation\Sanitation::$calls as $call){
			if(($call['method'] ?? null)==='validatedPreset'){
				$preset_call=$call;
				break;
			}
		}
		if(($preset_call['name'] ?? null)!=='profile' || ($preset_call['input']['slug'] ?? null)!=='Native MVC!'){
			$failures[]='MVC sanitizedPreset helper should pass preset names and request input to Dataphyre Sanitation.';
		}
		\Dataphyre\Sanitation\Sanitation::reset();
	}

	/**
	 * Asserts cache integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_cache_integration(array &$failures): void {
		\dataphyre\cache::reset();
		$app=new MvcApplication('cache-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/cache/remember', 'CacheController@remember');
				$routes->get('/cache/counters', 'CacheController@counters');
				$routes->get('/cache/forget', 'CacheController@forget');
				$routes->get('/cache/static', static fn(): array => [
					'put'=>Mvc::cachePut('mvc:static', ['ok'=>true], 45),
					'value'=>Mvc::cacheGet('mvc:static'),
				]);
			},
		]);
		$remember=$app->dispatcher()->dispatch(Request::create('GET', '/cache/remember'));
		if($remember->status!==200 || $remember->body!=='{"first":"computed","second":"computed","computed":1,"ttl":120}'){
			$failures[]='MVC cacheRemember should compute missing values once and store them through Dataphyre Cache.';
		}
		$counters=$app->dispatcher()->dispatch(Request::create('GET', '/cache/counters'));
		if($counters->status!==200 || $counters->body!=='{"increment":8,"decrement":4,"value":4}'){
			$failures[]='MVC cache counter helpers should delegate increments and decrements to Dataphyre Cache.';
		}
		$forget=$app->dispatcher()->dispatch(Request::create('GET', '/cache/forget'));
		if($forget->status!==200 || $forget->body!=='{"forgotten":true,"value":"fallback"}'){
			$failures[]='MVC cacheForget should remove values through Dataphyre Cache.';
		}
		$static=$app->dispatcher()->dispatch(Request::create('GET', '/cache/static'));
		if($static->status!==200 || $static->body!=='{"put":true,"value":{"ok":true}}' || (\dataphyre\cache::$expirations['mvc:static'] ?? null)!==45){
			$failures[]='MVC static cache helpers should pass values and TTLs through to Dataphyre Cache.';
		}
		$set_call=null;
		foreach(\dataphyre\cache::$calls as $call){
			if(($call['method'] ?? null)==='set' && ($call['key'] ?? null)==='mvc:remember'){
				$set_call=$call;
				break;
			}
		}
		if(($set_call['expiration'] ?? null)!==120 || ($set_call['value'] ?? null)!=='computed'){
			$failures[]='MVC cacheRemember should pass the resolved value and expiration to Dataphyre Cache.';
		}
		\dataphyre\cache::reset();
	}

	/**
	 * Asserts async integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_async_integration(array &$failures): void {
		\Dataphyre\Async\Async::reset();
		$app=new MvcApplication('async-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/async/dispatch', 'AsyncController@dispatch');
				$routes->get('/async/timers', 'AsyncController@timers');
				$routes->get('/async/static', static fn(): array => [
					'dispatch'=>Mvc::asyncDispatch(static fn(string $value): string => strtoupper($value), ['mvc'], 'coroutine'),
				]);
			},
		]);
		$dispatch=$app->dispatcher()->dispatch(Request::create('GET', '/async/dispatch'));
		if($dispatch->status!==200 || $dispatch->body!=='{"dispatch":{"method":"dispatch","driver":"inline","value":7},"inline":{"method":"inline","value":"hello Avery"},"all":{"method":"all","driver":"inline","count":2}}'){
			$failures[]='MVC async helpers should delegate dispatch, inline, and all operations to Dataphyre Async.';
		}
		$timers=$app->dispatcher()->dispatch(Request::create('GET', '/async/timers'));
		if($timers->status!==200 || $timers->body!=='{"after":100,"every":101,"events":["after","every"]}'){
			$failures[]='MVC async timer helpers should delegate timer registration and cancellation to Dataphyre Async.';
		}
		$static=$app->dispatcher()->dispatch(Request::create('GET', '/async/static'));
		if($static->status!==200 || $static->body!=='{"dispatch":{"method":"dispatch","driver":"coroutine","value":"MVC"}}'){
			$failures[]='MVC static async helper should pass tasks, arguments, and drivers to Dataphyre Async.';
		}
		$calls=\Dataphyre\Async\Async::$calls;
		if(($calls[0]['method'] ?? null)!=='dispatch' || ($calls[0]['arguments'] ?? [])!==[2, 5] || ($calls[0]['driver'] ?? null)!=='inline'){
			$failures[]='MVC asyncDispatch should pass task arguments and driver names unchanged.';
		}
		if(($calls[5]['method'] ?? null)!=='cancel' || ($calls[5]['task_id'] ?? null)!==101){
			$failures[]='MVC asyncCancel should pass timer ids through to Dataphyre Async.';
		}
		\Dataphyre\Async\Async::reset();
	}

	/**
	 * Asserts reactor integration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_reactor_integration(array &$failures): void {
		\Dataphyre\Reactor\Reactor::reset();
		$app=new MvcApplication('reactor-integration', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/reactor/mount', 'ReactorController@mount');
				$routes->post('/reactor/dispatch', 'ReactorController@dispatch');
				$routes->post('/reactor/batch', 'ReactorController@batch');
				$routes->get('/reactor/manifest', 'ReactorController@manifest');
				$routes->post('/reactor/static', static fn(): \Dataphyre\Http\Response => Mvc::reactorDispatch(['component'=>'counter', 'action'=>'static']));
			},
		]);
		$mount=$app->dispatcher()->dispatch(Request::create('GET', '/reactor/mount'));
		if($mount->status!==200 || $mount->body!=='{"html":"<div data-reactor-component=\"counter\">Count</div>"}'){
			$failures[]='MVC reactorMount should delegate component mounting to Dataphyre Reactor.';
		}
		$dispatch=$app->dispatcher()->dispatch(Request::create('POST', '/reactor/dispatch'));
		if($dispatch->status!==200 || ($dispatch->headers['X-Dataphyre-Reactor'] ?? null)!=='1' || $dispatch->body!=='{"status":200,"ok":true,"html":"<strong>increment</strong>","state":{"action":"increment"},"effects":[],"message":""}'){
			$failures[]='MVC reactorDispatch should normalize Reactor responses into Dataphyre HTTP JSON responses.';
		}
		$batch=$app->dispatcher()->dispatch(Request::create('POST', '/reactor/batch'));
		if($batch->status!==200 || ($batch->headers['X-Dataphyre-Reactor-Batch'] ?? null)!=='1' || $batch->body!=='{"status":200,"ok":true,"batch":[{"status":200,"ok":true,"html":"<strong>increment</strong>","state":{"action":"increment"},"effects":[],"message":""},{"status":200,"ok":true,"html":"<strong>decrement</strong>","state":{"action":"decrement"},"effects":[],"message":""}],"message":""}'){
			$failures[]='MVC reactorBatch should normalize batched Reactor responses.';
		}
		$manifest=$app->dispatcher()->dispatch(Request::create('GET', '/reactor/manifest'));
		if($manifest->status!==200 || $manifest->body!=='{"module":"reactor","components":{"counter":{"name":"counter"}}}'){
			$failures[]='MVC reactorManifest should expose the Dataphyre Reactor manifest.';
		}
		$static=$app->dispatcher()->dispatch(Request::create('POST', '/reactor/static'));
		if($static->status!==200 || $static->body!=='{"status":200,"ok":true,"html":"<strong>static</strong>","state":{"action":"static"},"effects":[],"message":""}'){
			$failures[]='MVC static reactorDispatch helper should delegate to Dataphyre Reactor.';
		}
		$calls=\Dataphyre\Reactor\Reactor::$calls;
		if(($calls[0]['method'] ?? null)!=='mount' || ($calls[0]['component'] ?? null)!=='counter' || ($calls[0]['state']['label'] ?? null)!=='Count' || ($calls[0]['attributes']['class'] ?? null)!=='widget'){
			$failures[]='MVC reactorMount should pass component, state, and attributes unchanged.';
		}
		if(($calls[1]['method'] ?? null)!=='endpoint.handle' || ($calls[1]['request']['action'] ?? null)!=='increment'){
			$failures[]='MVC reactorDispatch should pass request payloads to the Reactor endpoint.';
		}
		\Dataphyre\Reactor\Reactor::reset();
	}

	/**
	 * Asserts app middleware stack regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_app_middleware_stack(array &$failures): void {
		$app=new MvcApplication('middleware-stack', [
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'stack'=>StackMiddleware::class,
			],
			'global_middleware'=>[
				'stack:global>',
			],
			'middleware_groups'=>[
				'web'=>[
					'stack:group>',
				],
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/stack', static fn(): string => 'stack')->middleware('web', 'stack:route');
			},
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/stack'));
		if($response->status!==200 || ($response->headers['X-Stack'] ?? null)!=='routegroup>global>'){
			$failures[]='MVC should run route, group, and global middleware through one app stack.';
		}
	}

	/**
	 * Asserts terminable middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_terminable_middleware(array &$failures): void {
		$app=new MvcApplication('terminable-middleware', [
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'terminable'=>TerminableMiddleware::class,
			],
			'global_middleware'=>[
				'terminable:done',
			],
			'routes'=>[
				['path'=>'/terminable', 'handler'=>static fn(): array => ['ok'=>true]],
			],
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/terminable'));
		if(($response->headers['X-Handled'] ?? null)!=='done'){
			$failures[]='MVC terminable middleware should still run normal handle logic.';
		}
		if(($response->headers['X-Terminated'] ?? null)!=='done:/terminable:200'){
			$failures[]='MVC terminable middleware should receive the normalized response after dispatch.';
		}
	}

	/**
	 * Asserts throttle middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_throttle_middleware(array &$failures): void {
		\Dataphyre\Mvc\ThrottleMiddleware::flush();
		$app=new MvcApplication('throttle-middleware', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/limited', static fn(): array => ['ok'=>true])->middleware('throttle:2,60,limited');
			},
		]);
		$first=$app->dispatcher()->dispatch(Request::create('GET', '/limited'));
		$second=$app->dispatcher()->dispatch(Request::create('GET', '/limited'));
		$third=$app->dispatcher()->dispatch(Request::create('GET', '/limited'));
		if($first->status!==200 || ($first->headers['X-RateLimit-Remaining'] ?? null)!=='1'){
			$failures[]='MVC throttle middleware should allow requests under the configured limit.';
		}
		if($second->status!==200 || ($second->headers['X-RateLimit-Remaining'] ?? null)!=='0'){
			$failures[]='MVC throttle middleware should decrement remaining request count.';
		}
		if($third->status!==429 || ($third->headers['Retry-After'] ?? null)===''){
			$failures[]='MVC throttle middleware should reject requests over the configured limit.';
		}
		\Dataphyre\Mvc\ThrottleMiddleware::flush();
	}

	/**
	 * Asserts cache middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_cache_middleware(array &$failures): void {
		$app=new MvcApplication('cache-middleware', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/cached', static fn(): string => 'cached')->middleware('cache:120,private,stable,0');
			},
		]);
		$first=$app->dispatcher()->dispatch(Request::create('GET', '/cached'));
		$conditional=$app->dispatcher()->dispatch(Request::create('GET', '/cached', [], [], [], [], [
			'If-None-Match'=>'"stable"',
		]));
		if($first->status!==200 || $first->body!=='cached' || ($first->headers['Cache-Control'] ?? null)!=='private, max-age=120' || ($first->headers['ETag'] ?? null)!=='"stable"'){
			$failures[]='MVC cache middleware should apply cache headers, visibility, and ETags.';
		}
		if($conditional->status!==304 || $conditional->body!=='' || ($conditional->headers['ETag'] ?? null)!=='"stable"'){
			$failures[]='MVC cache middleware should honor conditional request headers.';
		}
	}

	/**
	 * Asserts session middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_session_middleware(array &$failures): void {
		\Dataphyre\Mvc\Session::flush();
		\Dataphyre\Mvc\Session::put('cart.items.0.sku', 'ABC123');
		\Dataphyre\Mvc\Session::put('cart.items.0.qty', 2);
		\Dataphyre\Mvc\Session::forget('cart.items.0.qty');
		\Dataphyre\Mvc\Session::put('cart.items.0.note', 'fragile');
		$pulled=\Dataphyre\Mvc\Session::pull('cart.items.0.note');
		$remember_count=0;
		$remembered_first=\Dataphyre\Mvc\Session::remember('cart.summary.count', static function() use (&$remember_count): int {
			$remember_count++;
			return 1;
		});
		$remembered_second=\Dataphyre\Mvc\Session::remember('cart.summary.count', static function() use (&$remember_count): int {
			$remember_count++;
			return 2;
		});
		$incremented=\Dataphyre\Mvc\Session::increment('cart.summary.count', 4);
		$decremented=\Dataphyre\Mvc\Session::decrement('cart.summary.count', 2);
		$missing_incremented=\Dataphyre\Mvc\Session::increment('cart.summary.views');
		$pushed_first=\Dataphyre\Mvc\Session::push('cart.events', 'created');
		$pushed_second=\Dataphyre\Mvc\Session::push('cart.events', 'updated');
		if(
			\Dataphyre\Mvc\Session::get('cart.items.0.sku')!=='ABC123'
			|| \Dataphyre\Mvc\Session::has('cart.items.0.sku')!==true
			|| \Dataphyre\Mvc\Session::has('cart.items.0.qty')!==false
			|| \Dataphyre\Mvc\Session::get('cart.items.0.missing', 'fallback')!=='fallback'
			|| $pulled!=='fragile'
			|| \Dataphyre\Mvc\Session::has('cart.items.0.note')!==false
			|| $remembered_first!==1
			|| $remembered_second!==1
			|| $remember_count!==1
			|| $incremented!==5
			|| $decremented!==3
			|| $missing_incremented!==1
			|| \Dataphyre\Mvc\Session::get('cart.summary.count')!==3
			|| $pushed_first!==['created']
			|| $pushed_second!==['created', 'updated']
			|| \Dataphyre\Mvc\Session::get('cart.events')!==['created', 'updated']
		){
			$failures[]='MVC session helper should support dot-path get, put, has, forget, pull, remember, increment, decrement, and push operations.';
		}
		\Dataphyre\Mvc\Session::flush();
		$app=new MvcApplication('session-middleware', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->post('/flash', static function(Request $request): array {
					\Dataphyre\Mvc\Session::flashInput($request->input());
					\Dataphyre\Mvc\Session::flash('notice', 'saved');
					return ['flashed'=>true];
				})->middleware('session');
				$routes->get('/old', static fn(): array => [
					'notice'=>\Dataphyre\Mvc\Session::get('notice'),
					'name'=>\Dataphyre\Mvc\Session::old('name'),
					'email'=>\Dataphyre\Mvc\Session::old('user.email'),
				])->middleware('session');
				$routes->get('/aged', static fn(): array => [
					'notice'=>\Dataphyre\Mvc\Session::get('notice', 'missing'),
					'name'=>\Dataphyre\Mvc\Session::old('name', 'missing'),
				])->middleware('session');
			},
		]);
		$app->dispatcher()->dispatch(Request::create('POST', '/flash', [], ['name'=>'dataphyre', 'user'=>['email'=>'hello@dataphyre.test']]));
		$old=$app->dispatcher()->dispatch(Request::create('GET', '/old'));
		$aged=$app->dispatcher()->dispatch(Request::create('GET', '/aged'));
		if($old->status!==200 || $old->body!=='{"notice":"saved","name":"dataphyre","email":"hello@dataphyre.test"}'){
			$failures[]='MVC session middleware should expose flashed data on the next request.';
		}
		if($aged->status!==200 || $aged->body!=='{"notice":"missing","name":"missing"}'){
			$failures[]='MVC session middleware should age flashed data after one request.';
		}
		\Dataphyre\Mvc\Session::flush();
	}

	/**
	 * Asserts redirect flash helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_redirect_flash_helpers(array &$failures): void {
		\Dataphyre\Mvc\Session::flush();
		$app=new MvcApplication('redirect-flash', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->post('/save', static function(Request $request): \Dataphyre\Mvc\RedirectResult {
					return \Dataphyre\Mvc\Mvc::redirect('/form')
						->with('notice', 'saved')
						->withInput($request->input())
						->withErrors(['email'=>['Email is required.'], 'user'=>['email'=>['Nested email is required.']]]);
				})->middleware('session');
				$routes->get('/form', static fn(): array => [
					'notice'=>\Dataphyre\Mvc\Session::get('notice'),
					'name'=>\Dataphyre\Mvc\Session::old('name'),
					'nested_email'=>\Dataphyre\Mvc\Session::old('user.email'),
					'nested_error'=>\Dataphyre\Mvc\Session::error('user.email'),
					'has_errors'=>\Dataphyre\Mvc\Session::hasErrors(),
					'errors'=>\Dataphyre\Mvc\Session::errors(),
				])->middleware('session');
				$routes->get('/expired', static fn(): array => [
					'notice'=>\Dataphyre\Mvc\Session::get('notice', 'missing'),
					'name'=>\Dataphyre\Mvc\Session::old('name', 'missing'),
					'errors'=>\Dataphyre\Mvc\Session::errors(),
				])->middleware('session');
			},
		]);
		$redirect=$app->dispatcher()->dispatch(Request::create('POST', '/save', [], ['name'=>'dataphyre', 'user'=>['email'=>'bad@dataphyre.test']]));
		$form=$app->dispatcher()->dispatch(Request::create('GET', '/form'));
		$expired=$app->dispatcher()->dispatch(Request::create('GET', '/expired'));
		if($redirect->status!==302 || ($redirect->headers['Location'] ?? null)!=='/form'){
			$failures[]='MVC redirect flash helpers should preserve the redirect response.';
		}
		if(
			$form->status!==200
			|| !str_contains($form->body, '"notice":"saved"')
			|| !str_contains($form->body, '"nested_email":"bad@dataphyre.test"')
			|| !str_contains($form->body, '"nested_error":["Nested email is required."]')
			|| !str_contains($form->body, '"has_errors":true')
		){
			$failures[]='MVC redirect flash helpers should expose flashed data, input, and errors on the next request.';
		}
		if($expired->status!==200 || $expired->body!=='{"notice":"missing","name":"missing","errors":[]}'){
			$failures[]='MVC redirect flash helpers should age flashed data, input, and errors after one request.';
		}
		\Dataphyre\Mvc\Session::flush();
	}

	/**
	 * Asserts CSRF middleware regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_csrf_middleware(array &$failures): void {
		\Dataphyre\Mvc\Session::flush();
		$app=new MvcApplication('csrf-middleware', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/csrf', static fn(): array => ['token'=>\Dataphyre\Mvc\Session::token()])->middleware('session', 'csrf');
				$routes->post('/csrf', static fn(): array => ['ok'=>true])->middleware('session', 'csrf');
			},
		]);
		$seed=$app->dispatcher()->dispatch(Request::create('GET', '/csrf'));
		$payload=json_decode($seed->body, true);
		$token=is_array($payload) ? (string)($payload['token'] ?? '') : '';
		$valid=$app->dispatcher()->dispatch(Request::create('POST', '/csrf', [], ['_token'=>$token]));
		$header=$app->dispatcher()->dispatch(Request::create('POST', '/csrf', [], [], [], [], ['X-CSRF-Token'=>$token]));
		$invalid=$app->dispatcher()->dispatch(Request::create('POST', '/csrf', [], ['_token'=>'bad']));
		if($seed->status!==200 || $token===''){
			$failures[]='MVC CSRF middleware should allow safe requests and seed a session token.';
		}
		if($valid->status!==200 || $header->status!==200){
			$failures[]='MVC CSRF middleware should allow valid body and header tokens.';
		}
		if($invalid->status!==419){
			$failures[]='MVC CSRF middleware should reject invalid unsafe requests.';
		}
		\Dataphyre\Mvc\Session::flush();
	}

	/**
	 * Asserts manager registration regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_manager_registration(array &$failures): void {
		Mvc::flush();
		Mvc::register('registered', [
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/registered', static fn(): array => ['registered'=>true]);
			},
		]);
		$response=Mvc::dispatch(Request::create('GET', '/registered'), 'registered');
		if($response->status!==200 || $response->body!=='{"registered":true}'){
			$failures[]='MVC manager should dispatch programmatically registered apps.';
		}
		$response=Mvc::host('registered')->dispatch(Request::create('GET', '/registered'));
		if($response->status!==200 || $response->body!=='{"registered":true}'){
			$failures[]='MVC host should dispatch programmatically registered apps.';
		}
		Mvc::flush();
	}

	/**
	 * Asserts config list overrides regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_config_list_overrides(array &$failures): void {
		/**
		 * MVC regression fixture used to exercise controller, route, and middleware behavior.
		 *
		 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
		 */
		$reflection=new \ReflectionClass(\Dataphyre\Mvc\MvcManager::class);
		$method=$reflection->getMethod('mergeConfig');
		$method->setAccessible(true);
		$config=$method->invoke(null, [
			'controllers'=>['namespace'=>'Base\\Controllers'],
			'routes'=>[
				['path'=>'/base', 'handler'=>static fn(): string => 'base'],
			],
		], [
			'controllers'=>['namespace'=>'App\\Controllers'],
			'routes'=>[
				['path'=>'/app', 'handler'=>static fn(): string => 'app'],
			],
		]);
		if(($config['controllers']['namespace'] ?? null)!=='App\\Controllers'){
			$failures[]='MVC app config should deeply merge associative config values.';
		}
		if(count($config['routes'] ?? [])!==1 || (($config['routes'][0]['path'] ?? null)!=='/app')){
			$failures[]='MVC app config should replace list-like route config instead of merging by index.';
		}
	}

	/**
	 * Asserts route mutation recompiles regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_mutation_recompiles(array &$failures): void {
		$app=dp_mvc_regression_app();
		$dispatcher=$app->dispatcher();
		$first=$dispatcher->dispatch(Request::create('GET', '/missing-later'));
		$app->routes()->get('/missing-later', static fn(): string => 'now-here');
		$second=$dispatcher->dispatch(Request::create('GET', '/missing-later'));
		if($first->status!==404 || $second->status!==200 || $second->body!=='now-here'){
			$failures[]='Dispatcher should recompile its MVC route manifest after routes mutate.';
		}
	}

	/**
	 * Asserts route definition mutation recompiles regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_definition_mutation_recompiles(array &$failures): void {
		$app=dp_mvc_regression_app();
		$route=$app->routes()->get('/late-middleware', static fn(): string => 'late');
		$dispatcher=$app->dispatcher();
		$first=$dispatcher->dispatch(Request::create('GET', '/late-middleware'));
		$route->middleware('header');
		$second=$dispatcher->dispatch(Request::create('GET', '/late-middleware'));
		if(
			$first->status!==200
			|| isset($first->headers['X-Middleware'])
			|| ($second->headers['X-Middleware'] ?? null)!=='seen'
		){
			$failures[]='Dispatcher should recompile when an existing MVC route definition mutates.';
		}
	}

	/**
	 * Asserts route definition macros regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_definition_macros(array &$failures): void {
		\Dataphyre\Mvc\RouteDefinition::flushMacros();
		\Dataphyre\Mvc\RouteDefinition::macro('adminRoute', function(string $name): \Dataphyre\Mvc\RouteDefinition {
			return $this
				->middleware('header')
				->defaults('guard', 'admin')
				->name($name);
		});
		$app=new MvcApplication('route-definition-macros', [
			'middleware'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				'header'=>HeaderMiddleware::class,
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/macro-route/{guard?}', static fn(string $guard): array => ['guard'=>$guard])
					->adminRoute('macro.route.show');
			},
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/macro-route'));
		$route=$app->routes()->named('macro.route.show');
		if(!\Dataphyre\Mvc\RouteDefinition::hasMacro('adminRoute')){
			$failures[]='MVC route definition macros should be registered by name.';
		}
		if(
			$response->status!==200
			|| $response->body!=='{"guard":"admin"}'
			|| ($response->headers['X-Middleware'] ?? null)!=='seen'
			|| $route===null
		){
			$failures[]='MVC route definition macros should compose existing route mutators.';
		}
		\Dataphyre\Mvc\RouteDefinition::flushMacros();
		if(\Dataphyre\Mvc\RouteDefinition::hasMacro('adminRoute')){
			$failures[]='MVC route definition macros should be flushable between runtimes.';
		}
	}

	/**
	 * Asserts single route arrays regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_single_route_arrays(array &$failures): void {
		$app=new MvcApplication('single-route-array', [
			'routes'=>[
				'method'=>'GET',
				'path'=>'/single-array',
				'handler'=>static fn(): array => ['single'=>true],
			],
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/single-array'));
		if($response->status!==200 || $response->body!=='{"single":true}'){
			$failures[]='MVC should load a single associative route array from config.';
		}
		$directory=sys_get_temp_dir().'/dataphyre_mvc_single_array_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary single route array directory.';
			return;
		}
		$file=$directory.'/route.php';
		file_put_contents($file, <<<'PHP'
<?php
return [
	'method'=>'GET',
	'path'=>'/single-file-array',
	'handler'=>static fn(): array => ['file_single'=>true],
];
PHP);
		try{
			$app=new MvcApplication('single-file-route-array', [
				'routes'=>$file,
			]);
			$response=$app->dispatcher()->dispatch(Request::create('GET', '/single-file-array'));
			if($response->status!==200 || $response->body!=='{"file_single":true}'){
				$failures[]='MVC should load a single associative route array from route files.';
			}
		}finally{
			if(is_file($file)){
				unlink($file);
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts array view and redirect routes regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_array_view_and_redirect_routes(array &$failures): void {
		$app=new MvcApplication('array-shortcuts', [
			'routes'=>[
				[
					'path'=>'/array-view',
					'name'=>'array.view',
					'view'=>'dashboard.index',
					'data'=>['title'=>'Array View'],
				],
				[
					'path'=>'/array-template',
					'name'=>'array.template',
					'template'=>'dashboard.show',
				],
				[
					'path'=>'/array-redirect',
					'redirect'=>'/target',
					'status'=>301,
				],
			],
		]);
		$view=$app->routes()->named('array.view')?->handler();
		$template=$app->routes()->named('array.template')?->handler();
		$redirect=$app->dispatcher()->dispatch(Request::create('GET', '/array-redirect'));
		if(!is_callable($view) || !$view() instanceof \Dataphyre\Mvc\ViewResult){
			$failures[]='MVC array route definitions should support view shortcuts.';
		}
		if(!is_callable($template) || !$template() instanceof \Dataphyre\Mvc\ViewResult){
			$failures[]='MVC array route definitions should support template aliases for view shortcuts.';
		}
		if($redirect->status!==301 || ($redirect->headers['Location'] ?? null)!=='/target'){
			$failures[]='MVC array route definitions should support redirect shortcuts.';
		}
	}

	/**
	 * Asserts fallback route regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_fallback_route(array &$failures): void {
		$app=dp_mvc_regression_app();
		$app->routes()->get('/fallback/known', static fn(): string => 'known');
		$app->routes()->fallback(static fn(array $path): array => ['fallback'=>$path]);
		$known=$app->dispatcher()->dispatch(Request::create('GET', '/fallback/known'));
		$fallback=$app->dispatcher()->dispatch(Request::create('GET', '/fallback/missing/page'));
		if($known->status!==200 || $known->body!=='known'){
			$failures[]='MVC fallback routes should not shadow earlier concrete routes.';
		}
		if($fallback->status!==200 || $fallback->body!=='{"fallback":["fallback","missing","page"]}'){
			$failures[]='MVC fallback route should receive unmatched path segments from shared Routing splats.';
		}
	}

	/**
	 * Asserts route files load regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_files_load(array &$failures): void {
		$directory=sys_get_temp_dir().'/dataphyre_mvc_routes_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary route directory.';
			return;
		}
		$file=$directory.'/web.php';
		file_put_contents($file, <<<'PHP'
<?php
return function(\Dataphyre\Mvc\RouteCollection $routes): void {
	$routes->get('/file-route', static fn(): array => ['file'=>true])->name('file.route');
};
PHP);
		try{
			$app=new MvcApplication('file-routes', [
				'routes'=>$directory,
			]);
			$response=$app->dispatcher()->dispatch(Request::create('GET', '/file-route'));
			if($response->status!==200 || $response->body!=='{"file":true}'){
				$failures[]='MVC route directory should load PHP route files.';
			}
			if($app->routes()->url('file.route')!=='/file-route'){
				$failures[]='Named routes loaded from route files should be registered.';
			}
		}finally{
			if(is_file($file)){
				unlink($file);
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts route compiler sources regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_compiler_sources(array &$failures): void {
		$directory=sys_get_temp_dir().'/dataphyre_mvc_sources_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary route source directory.';
			return;
		}
		$first=$directory.'/a.php';
		$second=$directory.'/b.php';
		file_put_contents($first, '<?php return [];');
		file_put_contents($second, '<?php return [];');
		try{
			$files=RouteCompiler::routeFiles($directory);
			if($files!==[$first, $second]){
				$failures[]='Routing compiler should discover route directory PHP files in stable order.';
			}
			$sources=RouteCompiler::sourceMtimes($directory);
			if(!isset($sources[$first], $sources[$second])){
				$failures[]='Routing compiler should expose route source mtimes.';
			}
			$signature=RouteCompiler::manifestSignature([
				'app'=>'sources',
				'revision'=>1,
				'sources'=>$sources,
			]);
			if(strlen($signature)!==64){
				$failures[]='Routing compiler should produce stable manifest signatures.';
			}
		}finally{
			foreach([$first, $second] as $file){
				if(is_file($file)){
					unlink($file);
				}
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts manifest file reads regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_manifest_file_reads(array &$failures): void {
		$directory=sys_get_temp_dir().'/dataphyre_mvc_manifest_read_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary manifest read directory.';
			return;
		}
		$valid=$directory.'/valid.php';
		$invalid=$directory.'/invalid.php';
		try{
			RouteCompiler::writeManifestFile($valid, [
				'version'=>1,
				'metadata'=>[],
				'routes'=>[],
			]);
			$manifest=RouteCompiler::readManifestFile($valid);
			if(($manifest['version'] ?? null)!==1 || ($manifest['routes'] ?? null)!==[]){
				$failures[]='Routing compiler should read manifest files it writes.';
			}
			file_put_contents($invalid, '<?php return "bad";');
			try{
				RouteCompiler::readManifestFile($invalid);
				$failures[]='Routing compiler should reject invalid manifest files.';
			}catch(\RuntimeException){
			}
		}finally{
			foreach([$valid, $invalid] as $file){
				if(is_file($file)){
					unlink($file);
				}
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts manifest cache regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_manifest_cache(array &$failures): void {
		$directory=sys_get_temp_dir().'/dataphyre_mvc_cache_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary manifest cache directory.';
			return;
		}
		$route_file=$directory.'/routes.php';
		$cache_file=$directory.'/manifest.php';
		file_put_contents($route_file, <<<'PHP'
<?php
return function(\Dataphyre\Mvc\RouteCollection $routes): void {
	$routes->get('/cached-route/{name}', 'ExampleController@show')->name('cached.route');
};
PHP);
		try{
			$app=new MvcApplication('cached-routes', [
				'controllers'=>[
					'namespace'=>'Dataphyre\\Mvc\\Regression',
				],
				'manifest_cache'=>$cache_file,
				'routes'=>$route_file,
			]);
			$response=$app->dispatcher()->dispatch(Request::create('GET', '/cached-route/cacheable'));
			if($response->status!==200 || $response->body!=='{"controller":"cacheable"}' || !is_file($cache_file)){
				$failures[]='MVC dispatcher should write and use a compiled manifest cache.';
			}
			$cached=require($cache_file);
			if(!is_array($cached) || ($cached['metadata']['signature'] ?? '')===''){
				$failures[]='MVC manifest cache should include a route signature.';
			}
		}finally{
			foreach([$route_file, $cache_file] as $file){
				if(is_file($file)){
					unlink($file);
				}
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts manifest cache config regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_manifest_cache_config(array &$failures): void {
		$cache_file=sys_get_temp_dir().'/dataphyre_mvc_cache_config_'.bin2hex(random_bytes(4)).'.php';
		$app=new MvcApplication('cache-config', [
			'manifest_cache'=>$cache_file,
			'routes'=>[],
		]);
		if($app->manifestCacheEnabled()!==true){
			$failures[]='MVC applications should expose manifest cache config.';
		}
		if($app->manifestCacheFile()!==$cache_file){
			$failures[]='MVC manifest cache config should preserve explicit cache files.';
		}
	}

	/**
	 * Asserts manifest exportability regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_manifest_exportability(array &$failures): void {
		$directory=sys_get_temp_dir().'/dataphyre_mvc_export_'.bin2hex(random_bytes(4));
		if(!mkdir($directory, 0777, true) && !is_dir($directory)){
			$failures[]='Unable to create temporary manifest export directory.';
			return;
		}
		$cache_file=$directory.'/manifest.php';
		$manifest=[
			'version'=>1,
			'metadata'=>[],
			'routes'=>[
				['handler'=>static fn(): string => 'not exportable'],
			],
		];
		try{
			if(RouteCompiler::manifestExportable($manifest)!==false){
				$failures[]='Routing compiler should reject non-exportable route manifests.';
			}
			if(RouteCompiler::tryWriteManifestFile($cache_file, $manifest)!==false || is_file($cache_file)){
				$failures[]='Routing compiler should skip non-exportable manifest cache writes.';
			}
			try{
				RouteCompiler::writeManifestFile($cache_file, $manifest);
				$failures[]='Routing compiler should throw when asked to write a non-exportable manifest.';
			}catch(\RuntimeException){
			}
		}finally{
			if(is_file($cache_file)){
				unlink($cache_file);
			}
			if(is_dir($directory)){
				rmdir($directory);
			}
		}
	}

	/**
	 * Asserts container and providers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_container_and_providers(array &$failures): void {
		$app=new MvcApplication('container-provider', [
			'controllers'=>[
				'namespace'=>'Dataphyre\\Mvc\\Regression',
			],
			'providers'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				RegressionServiceProvider::class,
			],
			'routes'=>function(RouteCollection $routes): void {
				$routes->get('/container-controller', 'ContainerController@show');
			},
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/container-controller'));
		$booted=$app->dispatcher()->dispatch(Request::create('GET', '/provider-booted'));
		if($response->status!==200 || $response->body!=='{"greeting":"container"}'){
			$failures[]='MVC container should autowire controller constructors through provider bindings.';
		}
		if($booted->status!==200 || $booted->body!=='{"booted":true}' || $app->providers()->booted()!==true){
			$failures[]='MVC provider registry should register and boot configured providers before dispatch.';
		}
	}

	/**
	 * Asserts validator extended rules regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_validator_extended_rules(array &$failures): void {
		$validated=\Dataphyre\Mvc\Validator::validate([
			'enabled'=>'true',
			'price'=>'12.50',
			'tags'=>['mvc'],
			'note'=>null,
		], [
			'enabled'=>'required|boolean',
			'price'=>'required|numeric|min:10|max:20',
			'tags'=>'array',
			'note'=>'nullable|string',
			'missing'=>'sometimes|string',
		]);
		if(($validated['enabled'] ?? null)!=='true' || !array_key_exists('note', $validated) || $validated['note']!==null || array_key_exists('missing', $validated)){
			$failures[]='MVC validator should support boolean, numeric, array, nullable, and sometimes rules.';
		}
		$validator=\Dataphyre\Mvc\Validator::make([
			'enabled'=>'maybe',
			'price'=>'free',
			'tags'=>'mvc',
		], [
			'enabled'=>'boolean',
			'price'=>'numeric',
			'tags'=>'array',
		]);
		if($validator->passes() || !isset($validator->errors()['enabled'], $validator->errors()['price'], $validator->errors()['tags'])){
			$failures[]='MVC validator extended rules should report invalid values.';
		}
		$custom=\Dataphyre\Mvc\Validator::make([
			'slug'=>'native-mvc',
			'title'=>'no',
		], [
			'slug'=>['required', static fn(mixed $value): bool => str_contains((string)$value, '-')],
			'title'=>[static fn(mixed $value, string $field): string|bool => strlen((string)$value)>=4 ? true : $field.' is too short.'],
		]);
		if($custom->passes() || ($custom->errors()['title'][0] ?? null)!=='title is too short.' || isset($custom->errors()['slug'])){
			$failures[]='MVC validator should support callable custom validation rules.';
		}
		$matching=\Dataphyre\Mvc\Validator::make([
			'terms'=>'on',
			'password'=>'secret123',
			'password_confirmation'=>'secret123',
			'email'=>'hello@dataphyre.test',
			'backup_email'=>'backup@dataphyre.test',
			'slug'=>'native-mvc',
		], [
			'terms'=>'accepted',
			'password'=>'confirmed|same:password_confirmation',
			'backup_email'=>'different:email',
			'slug'=>'regex:/^[a-z]+-[a-z]+$/',
		]);
		if(!$matching->passes()){
			$failures[]='MVC validator should accept accepted, confirmed, same, different, and regex rules.';
		}
		$mismatched=\Dataphyre\Mvc\Validator::make([
			'terms'=>'no',
			'password'=>'secret123',
			'password_confirmation'=>'secret456',
			'email'=>'hello@dataphyre.test',
			'backup_email'=>'hello@dataphyre.test',
			'slug'=>'Native MVC',
		], [
			'terms'=>'accepted',
			'password'=>'confirmed|same:password_confirmation',
			'backup_email'=>'different:email',
			'slug'=>'regex:/^[a-z]+-[a-z]+$/',
		]);
		$errors=$mismatched->errors();
		if(!isset($errors['terms'], $errors['password'], $errors['backup_email'], $errors['slug'])){
			$failures[]='MVC validator should reject failed accepted, confirmed/same, different, and regex rules.';
		}
		$dates=\Dataphyre\Mvc\Validator::make([
			'url'=>'https://dataphyre.test/docs',
			'starts_at'=>'2026-05-12',
			'ends_at'=>'2026-05-13',
			'published_at'=>'2026-05-12',
			'expires_at'=>'2026-05-13',
		], [
			'url'=>'url',
			'starts_at'=>'date|before:ends_at|before_or_equal:published_at',
			'ends_at'=>'date|after:starts_at|after_or_equal:expires_at',
		]);
		if(!$dates->passes()){
			$failures[]='MVC validator should accept url, date, before, after, before_or_equal, and after_or_equal rules.';
		}
		$bad_dates=\Dataphyre\Mvc\Validator::make([
			'url'=>'not a url',
			'starts_at'=>'tomorrowish',
			'ends_at'=>'2026-05-10',
		], [
			'url'=>'url',
			'starts_at'=>'date|before:ends_at',
			'ends_at'=>'after:starts_at',
		]);
		$bad_date_errors=$bad_dates->errors();
		if(!isset($bad_date_errors['url'], $bad_date_errors['starts_at'], $bad_date_errors['ends_at'])){
			$failures[]='MVC validator should reject invalid url and date comparison rules.';
		}
		$conditional=\Dataphyre\Mvc\Validator::make([
			'type'=>'company',
			'company_name'=>'Dataphyre',
			'contact_email'=>'hello@dataphyre.test',
			'archived'=>'no',
			'status'=>'draft',
		], [
			'type'=>'present|in:person,company',
			'company_name'=>'required_if:type,company|string',
			'contact_email'=>'required_with:company_name|email',
			'backup_email'=>'required_without:contact_email|email',
			'admin_note'=>'prohibited_unless:status,review',
			'archived_reason'=>'prohibited_if:archived,no',
		]);
		if(!$conditional->passes() || isset($conditional->validated()['backup_email'], $conditional->validated()['admin_note'], $conditional->validated()['archived_reason'])){
			$failures[]='MVC validator should accept conditional required, present, and prohibited rules when their conditions pass.';
		}
		$conditional_errors=\Dataphyre\Mvc\Validator::make([
			'type'=>'company',
			'status'=>'draft',
			'admin_note'=>'not yet',
			'archived'=>'no',
			'archived_reason'=>'still active',
		], [
			'type'=>'present',
			'company_name'=>'required_if:type,company',
			'contact_email'=>'required_with:company_name',
			'backup_email'=>'required_without:contact_email',
			'admin_note'=>'prohibited_unless:status,review',
			'archived_reason'=>'prohibited_if:archived,no',
			'must_exist'=>'present',
		]);
		$conditional_error_bag=$conditional_errors->errors();
		if(!isset(
			$conditional_error_bag['company_name'],
			$conditional_error_bag['backup_email'],
			$conditional_error_bag['admin_note'],
			$conditional_error_bag['archived_reason'],
			$conditional_error_bag['must_exist']
		)){
			$failures[]='MVC validator should reject failing conditional required, present, and prohibited rules.';
		}
		$shape=\Dataphyre\Mvc\Validator::make([
			'code'=>'ABC123',
			'letters'=>'Native',
			'pin'=>'123456',
			'short_pin'=>'1234',
			'title'=>'Dataphyre MVC',
			'tags'=>['php', 'mvc'],
			'handle'=>'dp_native',
		], [
			'code'=>'alpha_num|starts_with:ABC,XYZ|ends_with:123,999',
			'letters'=>'alpha',
			'pin'=>'digits:6',
			'short_pin'=>'digits_between:3,5',
			'title'=>'size:13',
			'tags'=>'between:1,3',
			'handle'=>'min:3|max:20',
		]);
		if(!$shape->passes()){
			$failures[]='MVC validator should accept alpha, alpha_num, starts_with, ends_with, digits, digits_between, size, and between rules.';
		}
		$bad_shape=\Dataphyre\Mvc\Validator::make([
			'code'=>'ABC-123',
			'letters'=>'Native1',
			'pin'=>'12345',
			'short_pin'=>'12',
			'title'=>'Too long',
			'tags'=>[],
			'handle'=>'x',
		], [
			'code'=>'alpha_num|starts_with:XYZ|ends_with:999',
			'letters'=>'alpha',
			'pin'=>'digits:6',
			'short_pin'=>'digits_between:3,5',
			'title'=>'size:6',
			'tags'=>'between:1,3',
			'handle'=>'min:3',
		]);
		$bad_shape_errors=$bad_shape->errors();
		if(!isset(
			$bad_shape_errors['code'],
			$bad_shape_errors['letters'],
			$bad_shape_errors['pin'],
			$bad_shape_errors['short_pin'],
			$bad_shape_errors['title'],
			$bad_shape_errors['tags'],
			$bad_shape_errors['handle']
		)){
			$failures[]='MVC validator should reject failing alpha, alpha_num, starts_with, ends_with, digits, digits_between, size, between, and min rules.';
		}
		$nested=\Dataphyre\Mvc\Validator::make([
			'user'=>[
				'email'=>'nested@dataphyre.test',
				'password'=>'secret123',
				'password_confirmation'=>'secret123',
				'type'=>'company',
				'company'=>[
					'name'=>'Dataphyre',
				],
			],
			'meta'=>[
				'starts_at'=>'2026-05-12',
				'ends_at'=>'2026-05-13',
			],
		], [
			'user.email'=>'required|email',
			'user.password'=>'required|confirmed',
			'user.company.name'=>'required_if:user.type,company|string',
			'meta.starts_at'=>'date|before:meta.ends_at',
			'user.optional_note'=>'nullable|string',
		]);
		$nested_validated=$nested->validated();
		if(
			!$nested->passes()
			|| ($nested_validated['user']['email'] ?? null)!=='nested@dataphyre.test'
			|| ($nested_validated['user']['company']['name'] ?? null)!=='Dataphyre'
			|| $nested->safe('user.email')!=='nested@dataphyre.test'
		){
			$failures[]='MVC validator should support dot-path validation and nested validated output.';
		}
		$bad_nested=\Dataphyre\Mvc\Validator::make([
			'user'=>[
				'email'=>'bad',
				'password'=>'secret123',
				'password_confirmation'=>'secret456',
				'type'=>'company',
			],
			'meta'=>[
				'starts_at'=>'2026-05-14',
				'ends_at'=>'2026-05-13',
			],
		], [
			'user.email'=>'required|email',
			'user.password'=>'confirmed',
			'user.company.name'=>'required_if:user.type,company|string',
			'meta.starts_at'=>'before:meta.ends_at',
		]);
		$bad_nested_errors=$bad_nested->errors();
		if(!isset(
			$bad_nested_errors['user.email'],
			$bad_nested_errors['user.password'],
			$bad_nested_errors['user.company.name'],
			$bad_nested_errors['meta.starts_at']
		)){
			$failures[]='MVC validator should reject invalid dot-path fields and nested comparison rules.';
		}
		$wildcard=\Dataphyre\Mvc\Validator::make([
			'items'=>[
				[
					'sku'=>'ABC123',
					'quantity'=>'2',
					'starts_at'=>'2026-05-12',
					'ends_at'=>'2026-05-13',
					'discounted'=>'false',
				],
				[
					'sku'=>'XYZ999',
					'quantity'=>'5',
					'starts_at'=>'2026-05-14',
					'ends_at'=>'2026-05-15',
					'discounted'=>'true',
					'discount_reason'=>'launch',
				],
			],
		], [
			'items'=>'array|size:2',
			'items.*.sku'=>'required|alpha_num|distinct',
			'items.*.quantity'=>'required|integer|min:1',
			'items.*.starts_at'=>'date|before:items.*.ends_at',
			'items.*.discount_reason'=>'required_if:items.*.discounted,true|string',
		]);
		$wildcard_validated=$wildcard->validated();
		if(
			!$wildcard->passes()
			|| ($wildcard_validated['items'][1]['sku'] ?? null)!=='XYZ999'
			|| $wildcard->safe('items.1.discount_reason')!=='launch'
		){
			$failures[]='MVC validator should support wildcard array validation and nested wildcard validated output.';
		}
		$distinct=\Dataphyre\Mvc\Validator::make([
			'codes'=>['A1', 'B2', 'C3'],
			'emails'=>[
				['address'=>'One@dataphyre.test'],
				['address'=>'two@dataphyre.test'],
			],
		], [
			'codes'=>'array|distinct',
			'emails.*.address'=>'email|distinct:ignore_case',
		]);
		if(!$distinct->passes()){
			$failures[]='MVC validator should accept distinct array and wildcard values.';
		}
		$duplicate=\Dataphyre\Mvc\Validator::make([
			'codes'=>['A1', 'B2', 'A1'],
			'emails'=>[
				['address'=>'DUP@dataphyre.test'],
				['address'=>'dup@dataphyre.test'],
			],
		], [
			'codes'=>'array|distinct',
			'emails.*.address'=>'email|distinct:ignore_case',
		]);
		$duplicate_errors=$duplicate->errors();
		if(!isset($duplicate_errors['codes'], $duplicate_errors['emails.0.address'], $duplicate_errors['emails.1.address'])){
			$failures[]='MVC validator should reject duplicate array and wildcard values with distinct.';
		}
		$bad_wildcard=\Dataphyre\Mvc\Validator::make([
			'items'=>[
				[
					'quantity'=>'0',
					'starts_at'=>'2026-05-14',
					'ends_at'=>'2026-05-13',
					'discounted'=>'true',
				],
				[
					'sku'=>'BAD-1',
					'quantity'=>'two',
					'starts_at'=>'2026-05-12',
					'ends_at'=>'2026-05-13',
					'discounted'=>'false',
				],
			],
		], [
			'items.*.sku'=>'required|alpha_num',
			'items.*.quantity'=>'required|integer|min:1',
			'items.*.starts_at'=>'before:items.*.ends_at',
			'items.*.discount_reason'=>'required_if:items.*.discounted,true',
		]);
		$bad_wildcard_errors=$bad_wildcard->errors();
		if(!isset(
			$bad_wildcard_errors['items.0.sku'],
			$bad_wildcard_errors['items.0.quantity'],
			$bad_wildcard_errors['items.0.starts_at'],
			$bad_wildcard_errors['items.0.discount_reason'],
			$bad_wildcard_errors['items.1.sku'],
			$bad_wildcard_errors['items.1.quantity']
		)){
			$failures[]='MVC validator should reject invalid wildcard array fields and wildcard-relative comparison rules.';
		}
		$excluded=\Dataphyre\Mvc\Validator::make([
			'type'=>'guest',
			'nickname'=>'Guest',
			'password'=>'secret',
			'password_confirmation'=>'secret',
			'profile'=>[
				'company'=>'Dataphyre',
				'role'=>'viewer',
			],
			'items'=>[
				['kind'=>'digital', 'shipping_weight'=>'heavy', 'sku'=>'DIGI1'],
				['kind'=>'physical', 'shipping_weight'=>'2', 'sku'=>'BOX1'],
			],
		], [
			'nickname'=>'required|string',
			'password'=>'exclude',
			'password_confirmation'=>'exclude',
			'profile.company'=>'exclude_if:type,guest|string|min:99',
			'profile.role'=>'exclude_unless:type,member|string|min:99',
			'items.*.sku'=>'required|alpha_num',
			'items.*.shipping_weight'=>'exclude_if:items.*.kind,digital|numeric|min:1',
			'internal_note'=>'exclude_with:profile.company|required',
		]);
		$excluded_validated=$excluded->validated();
		if(
			!$excluded->passes()
			|| isset(
				$excluded_validated['password'],
				$excluded_validated['password_confirmation'],
				$excluded_validated['profile']['company'],
				$excluded_validated['profile']['role'],
				$excluded_validated['items'][0]['shipping_weight'],
				$excluded_validated['internal_note']
			)
			|| ($excluded_validated['items'][1]['shipping_weight'] ?? null)!=='2'
		){
			$failures[]='MVC validator should exclude top-level, nested, and wildcard fields from validated data before normal rule checks.';
		}
		$bail=\Dataphyre\Mvc\Validator::make([
			'email'=>'not-an-email',
			'slug'=>'Bad Slug',
		], [
			'email'=>'bail|email|ends_with:.test',
			'slug'=>'alpha_num|starts_with:ok',
		]);
		$bail_errors=$bail->errors();
		if(
			count($bail_errors['email'] ?? [])!==1
			|| count($bail_errors['slug'] ?? [])!==2
		){
			$failures[]='MVC validator should support bail for field-local first failure while non-bailing fields collect all rule errors.';
		}
		$stop=\Dataphyre\Mvc\Validator::make([
			'email'=>'bad',
			'name'=>123,
		], [
			'email'=>'email',
			'name'=>'string',
		])->stopOnFirstFailure();
		$stop_errors=$stop->errors();
		if(!isset($stop_errors['email']) || isset($stop_errors['name'])){
			$failures[]='MVC validator should support global stopOnFirstFailure behavior.';
		}
		$custom_messages=\Dataphyre\Mvc\Validator::make([
			'items'=>[
				['sku'=>'BAD-1', 'starts_at'=>'2026-05-14', 'ends_at'=>'2026-05-13'],
			],
		], [
			'items.*.sku'=>'alpha_num',
			'items.*.starts_at'=>'before:items.*.ends_at',
		], [
			'items.*.sku.alpha_num'=>'Each :attribute must be letters and numbers.',
			'before'=>'The :attribute must be before :date.',
		], [
			'items.*.sku'=>'line item SKU',
			'items.*.starts_at'=>'line item start date',
			'items.*.ends_at'=>'line item end date',
		]);
		$custom_message_errors=$custom_messages->errors();
		if(
			($custom_message_errors['items.0.sku'][0] ?? null)!=='Each line item SKU must be letters and numbers.'
			|| ($custom_message_errors['items.0.starts_at'][0] ?? null)!=='The line item start date must be before line item end date.'
		){
			$failures[]='MVC validator should resolve wildcard custom messages and attributes for nested array fields.';
		}
	}

	/**
	 * Asserts validator upload rules regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_validator_upload_rules(array &$failures): void {
		$tmp=tempnam(sys_get_temp_dir(), 'dp-mvc-validate-upload-');
		if($tmp===false){
			$failures[]='MVC upload validation regression could not create a temporary file.';
			return;
		}
		file_put_contents($tmp, 'avatar');
		try{
			$file=\Dataphyre\Http\UploadedFile::fromArray([
				'name'=>'avatar.JPG',
				'type'=>'image/jpeg',
				'tmp_name'=>$tmp,
				'error'=>UPLOAD_ERR_OK,
				'size'=>6,
			]);
			$valid=\Dataphyre\Mvc\Validator::make([
				'avatar'=>$file,
			], [
				'avatar'=>'required|file|image|mimes:jpg,png|mimetypes:image/*|max:1',
			]);
			if(!$valid->passes() || ($valid->validated()['avatar'] ?? null)!==$file){
				$failures[]='MVC validator should accept valid uploaded files, images, mimes, mimetypes, and max kilobytes.';
			}
			$invalid=\Dataphyre\Mvc\Validator::make([
				'avatar'=>$file,
			], [
				'avatar'=>'mimes:png|mimetypes:application/pdf|max:0',
			]);
			$errors=$invalid->errors()['avatar'] ?? [];
			if(count($errors)!==3){
				$failures[]='MVC validator should reject uploaded files with invalid extension, mimetype, and max size.';
			}
			$missing=\Dataphyre\Mvc\Validator::make([], [
				'avatar'=>'required|file',
			]);
			if(!isset($missing->errors()['avatar'])){
				$failures[]='MVC validator should require missing uploaded files.';
			}
		}finally{
			if(is_file($tmp)){
				@unlink($tmp);
			}
		}
	}

	/**
	 * Asserts controller validation helpers regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_controller_validation_helpers(array &$failures): void {
		$valid=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('POST', '/controller-validate/tools', [], [
			'name'=>'Native MVC',
			'email'=>'hello@dataphyre.test',
		]));
		if($valid->status!==200 || $valid->body!=='{"validated":{"category":"tools","name":"Native MVC","email":"hello@dataphyre.test"}}'){
			$failures[]='Controller validation helper should validate merged route and body data.';
		}
		$invalid=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('POST', '/controller-validate/tools', [], [
			'name'=>'No',
			'email'=>'bad',
		], [], [], [
			'Accept'=>'application/json',
		]));
		if($invalid->status!==422 || !str_contains($invalid->body, '"email"') || !str_contains($invalid->body, '"name"')){
			$failures[]='Controller validation helper should use shared validation failure responses.';
		}
		$static=\Dataphyre\Mvc\Mvc::validate([
			'name'=>'Static MVC',
		], [
			'name'=>'required|string|min:3',
		]);
		if(($static['name'] ?? null)!=='Static MVC'){
			$failures[]='Static MVC validation helper should validate plain arrays.';
		}
	}

	/**
	 * Asserts form request validation regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_form_request_validation(array &$failures): void {
		$app=new MvcApplication('form-request', [
			'routes'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				['method'=>'POST', 'path'=>'/products', 'handler'=>[FormRequestController::class, 'store']],
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				['method'=>'POST', 'path'=>'/avatar', 'handler'=>[FormRequestController::class, 'upload']],
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				['method'=>'POST', 'path'=>'/prepared-products', 'handler'=>[FormRequestController::class, 'prepared']],
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				['method'=>'POST', 'path'=>'/denied-products', 'handler'=>[FormRequestController::class, 'denied']],
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				['method'=>'POST', 'path'=>'/custom-denied-products', 'handler'=>[FormRequestController::class, 'customDenied']],
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				['method'=>'POST', 'path'=>'/custom-validation-failure-products', 'handler'=>[FormRequestController::class, 'customValidationFailure']],
			],
		]);
		$tmp=tempnam(sys_get_temp_dir(), 'dp-mvc-form-upload-');
		if($tmp!==false){
			file_put_contents($tmp, 'avatar');
		}
		$valid=$app->dispatcher()->dispatch(Request::create('POST', '/products', [], [
			'name'=>'Native MVC',
			'email'=>'hello@dataphyre.test',
		]));
		$invalid=$app->dispatcher()->dispatch(Request::create('POST', '/products', [], [
			'name'=>'No',
			'email'=>'invalid',
		]));
		$upload=$tmp===false ? null : $app->dispatcher()->dispatch(Request::create('POST', '/avatar', [], [], [], [], [], [], [], [
			'avatar'=>[
				'name'=>'avatar.JPG',
				'type'=>'image/jpeg',
				'tmp_name'=>$tmp,
				'error'=>UPLOAD_ERR_OK,
				'size'=>6,
			],
		]));
		if($valid->status!==200 || $valid->body!=='{"validated":{"name":"Native MVC","email":"hello@dataphyre.test"}}'){
			$failures[]='MVC should inject validated FormRequest instances into actions.';
		}
		if($invalid->status!==422 || !str_contains($invalid->body, '"errors"') || !str_contains($invalid->body, '"email"')){
			$failures[]='MVC should convert validation failures into JSON 422 responses.';
		}
		if($upload===null || $upload->status!==200 || $upload->body!=='{"name":"avatar.JPG","extension":"jpg"}'){
			$failures[]='MVC should include uploaded files in FormRequest validation data.';
		}
		$prepared=$app->dispatcher()->dispatch(Request::create('POST', '/prepared-products', [], [
			'name'=>' Native MVC ',
			'meta'=>[
				'category'=>'TOOLS',
			],
		]));
		if($prepared->status!==200 || $prepared->body!=='{"validated":{"name":"Native MVC","slug":"native-mvc","meta":{"category":"tools"}},"category":"tools","passed":"yes"}'){
			$failures[]='MVC FormRequest should support prepareForValidation, nested validated lookup, and passedValidation hooks.';
		}
		$invalid_prepared=$app->dispatcher()->dispatch(Request::create('POST', '/prepared-products', [], [
			'name'=>'Native MVC!',
			'meta'=>[
				'category'=>'INVALID',
			],
		], [], [], [
			'Accept'=>'application/json',
		]));
		if(
			$invalid_prepared->status!==422
			|| !str_contains($invalid_prepared->body, 'The product slug generated from the name is not URL safe.')
			|| str_contains($invalid_prepared->body, 'product category')
		){
			$failures[]='MVC FormRequest should support custom messages, attributes, withValidator, and stop-on-first-failure.';
		}
		$denied=$app->dispatcher()->dispatch(Request::create('POST', '/denied-products', [], [
			'name'=>'Native MVC',
		], [], [], [
			'Accept'=>'application/json',
		]));
		if($denied->status!==403 || !str_contains($denied->body, 'This action is unauthorized.') || !str_contains($denied->body, '"authorization"')){
			$failures[]='MVC FormRequest should convert failed authorization into the default authorization validation response.';
		}
		$custom_denied=$app->dispatcher()->dispatch(Request::create('POST', '/custom-denied-products', [], [
			'name'=>'Native MVC',
		], [], [], [
			'Accept'=>'application/json',
		]));
		if($custom_denied->status!==401 || !str_contains($custom_denied->body, 'Only product managers may do this.')){
			$failures[]='MVC FormRequest should support custom failed authorization messages and statuses.';
		}
		$custom_validation=$app->dispatcher()->dispatch(Request::create('POST', '/custom-validation-failure-products', [], [
			'name'=>'No',
		], [], [], [
			'Accept'=>'application/json',
		]));
		if($custom_validation->status!==409 || !str_contains($custom_validation->body, 'Product payload rejected.') || !str_contains($custom_validation->body, '"name"')){
			$failures[]='MVC FormRequest should support custom failed validation responses.';
		}
		if($tmp!==false && is_file($tmp)){
			@unlink($tmp);
		}
	}

	/**
	 * Asserts validation redirect regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_validation_redirect(array &$failures): void {
		\Dataphyre\Mvc\Session::flush();
		$app=new MvcApplication('validation-redirect', [
			'validation_redirect'=>true,
			'routes'=>function(RouteCollection $routes): void {
				$routes->post('/products', static fn(\Dataphyre\Mvc\Regression\CreateProductRequest $request): array => ['ok'=>true])->middleware('session');
				$routes->post('/profile-products', static fn(\Dataphyre\Mvc\Regression\ProfileProductRequest $request): array => ['ok'=>true])->middleware('session');
				$routes->get('/products/create', static fn(): array => [
					'name'=>\Dataphyre\Mvc\Session::old('name'),
					'errors'=>\Dataphyre\Mvc\Session::errors(),
					'profile_errors'=>\Dataphyre\Mvc\Session::errors('profile'),
				])->middleware('session');
				$routes->get('/products/expired', static fn(): array => [
					'name'=>\Dataphyre\Mvc\Session::old('name', 'missing'),
					'errors'=>\Dataphyre\Mvc\Session::errors(),
				])->middleware('session');
			},
		]);
		$redirect=$app->dispatcher()->dispatch(Request::create('POST', '/products', [], [
			'name'=>'desk',
		], [], [], [
			'Referer'=>'/products/create',
		]));
		$form=$app->dispatcher()->dispatch(Request::create('GET', '/products/create'));
		$expired=$app->dispatcher()->dispatch(Request::create('GET', '/products/expired'));
		$json=$app->dispatcher()->dispatch(Request::create('POST', '/products', [], [
			'name'=>'desk',
		], [], [], [
			'Accept'=>'application/json',
			'Referer'=>'/products/create',
		]));
		$profile_redirect=$app->dispatcher()->dispatch(Request::create('POST', '/profile-products', [], [
			'display_name'=>'x',
		], [], [], [
			'Referer'=>'/products/create',
		]));
		$profile_form=$app->dispatcher()->dispatch(Request::create('GET', '/products/create'));
		if($redirect->status!==302 || ($redirect->headers['Location'] ?? null)!=='/products/create'){
			$failures[]='MVC validation_redirect should redirect validation failures back to the referer.';
		}
		if($form->status!==200 || !str_contains($form->body, '"name":"desk"') || !str_contains($form->body, '"email"')){
			$failures[]='MVC validation_redirect should flash old input and validation errors.';
		}
		if($expired->status!==200 || $expired->body!=='{"name":"missing","errors":[]}'){
			$failures[]='MVC validation_redirect should age flashed input and errors after one request.';
		}
		if($json->status!==422 || !str_contains($json->body, '"errors"') || ($json->headers['Location'] ?? null)!==null){
			$failures[]='MVC validation_redirect should keep JSON validation responses for requests that expect JSON.';
		}
		if(
			$profile_redirect->status!==302
			|| !str_contains($profile_form->body, '"profile_errors"')
			|| !str_contains($profile_form->body, '"display_name"')
		){
			$failures[]='MVC validation_redirect should flash FormRequest validation errors into custom error bags.';
		}
		\Dataphyre\Mvc\Session::flush();
	}

	/**
	 * Asserts route model binding regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_route_model_binding(array &$failures): void {
		$app=new MvcApplication('route-model-binding', [
			'routes'=>[
				/**
				 * MVC regression fixture used to exercise controller, route, and middleware behavior.
				 *
				 * Fixture state is process-local so assertions can run without booting external Dataphyre services.
				 */
				['path'=>'/bound-products/{product}', 'handler'=>[BoundProductController::class, 'show']],
			],
		]);
		$found=$app->dispatcher()->dispatch(Request::create('GET', '/bound-products/native-mvc'));
		$missing=$app->dispatcher()->dispatch(Request::create('GET', '/bound-products/missing'));
		if($found->status!==200 || $found->body!=='{"product":"Native MVC"}'){
			$failures[]='MVC should implicitly bind route parameters to typed model action parameters.';
		}
		if($missing->status!==404){
			$failures[]='MVC should treat missing route model bindings as not found responses.';
		}
	}

	/**
	 * Asserts not found handler regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_not_found_handler(array &$failures): void {
		$app=new MvcApplication('not-found-handler', [
			'not_found_handler'=>static fn(Request $request): array => ['missing'=>$request->path()],
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/custom-missing'));
		if($response->status!==200 || $response->body!=='{"missing":"/custom-missing"}'){
			$failures[]='MVC not_found_handler should use HTTP action argument resolution and response normalization.';
		}
	}

	/**
	 * Asserts error handler regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_error_handler(array &$failures): void {
		$app=new MvcApplication('error-handler', [
			'routes'=>[
				['path'=>'/explode', 'handler'=>static function(): void {
					throw new \RuntimeException('boom');
				}],
			],
			'error_handler'=>static fn(\Exception $throwable, Request $request): Response => Response::json([
				'error'=>$throwable->getMessage(),
				'path'=>$request->path(),
			], 503),
		]);
		$response=$app->dispatcher()->dispatch(Request::create('GET', '/explode'));
		if($response->status!==503 || $response->body!=='{"error":"boom","path":"/explode"}'){
			$failures[]='MVC error_handler should receive typed throwables and requests through HTTP action argument resolution.';
		}
	}

	/**
	 * Asserts not found regression coverage.
	 *
	 * Adds human-readable failure messages to the shared failure bag when MVC behavior drifts.
	 *
	 * @param list<string> &$failures Failure messages collected by the regression harness.
	 * @return void
	 */
	function dp_mvc_regression_assert_not_found(array &$failures): void {
		$response=dp_mvc_regression_app()->dispatcher()->dispatch(Request::create('GET', '/missing'));
		if($response->status!==404){
			$failures[]='Missing route should return 404.';
		}
	}
}
