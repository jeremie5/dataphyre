<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/**
 * Bootstrap-safe Flightdeck authentication.
 *
 * This file intentionally has no Dataphyre core dependency. It may be loaded
 * from /dataphyre routes after core is available, or from pre_init_error before
 * a full runtime exists.
 */

if(defined('DATAPHYRE_FLIGHTDECK_AUTH_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_AUTH_LOADED', true);

/**
 * Protects the bootstrap-safe Flightdeck console and debugbar surfaces.
 *
 * The authenticator reads configuration from pre-core globals or constants,
 * validates an optional developer password, issues signed HTTP-only cookies,
 * verifies rotating CSRF tokens, and records per-IP login failures in a cache
 * file. It must keep working before the full Dataphyre kernel, session layer,
 * logger, or database layer exists.
 */
final class dataphyre_flightdeck_auth {

	private const COOKIE='dataphyre_flightdeck';

	/**
	 * Checks whether Flightdeck is locked because the runtime is production.
	 *
	 * Production mode is an unconditional guardrail for the developer console and
	 * debugbar, independent of local Flightdeck configuration.
	 *
	 * @return bool True when `IS_PRODUCTION` is explicitly true.
	 */
	public static function production_disabled(): bool {
		return defined('IS_PRODUCTION') && IS_PRODUCTION===true;
	}

	/**
	 * Resolves Flightdeck authentication and debugbar configuration.
	 *
	 * Configuration can come from `DATAPHYRE_FLIGHTDECK_CONFIG`, the
	 * `dataphyre_flightdeck_config` global, or the bootstrap config's
	 * `flightdeck` section. Defaults are merged recursively so missing nested
	 * keys do not break pre-init error pages.
	 *
	 * @return array{enabled: bool, password: ?string, password_hash: ?string, developer_password: ?string, developer_password_hash: ?string, session_ttl: int, rate_limit: array{window: int, max_attempts: int}, debugbar: array{enabled: bool, memory_limit: mixed}} Effective Flightdeck config.
	 */
	public static function config(): array {
		if(defined('DATAPHYRE_FLIGHTDECK_CONFIG') && is_array(DATAPHYRE_FLIGHTDECK_CONFIG)){
			$config=DATAPHYRE_FLIGHTDECK_CONFIG;
		}
		else
		{
			$config=$GLOBALS['dataphyre_flightdeck_config'] ?? null;
			if(!is_array($config)){
				$bootstrap=defined('DATAPHYRE_BOOTSTRAP_CONFIG') ? DATAPHYRE_BOOTSTRAP_CONFIG : ($GLOBALS['dataphyre_bootstrap_config'] ?? []);
				if(!is_array($bootstrap)){
					$bootstrap=[];
				}
				$config=$bootstrap['flightdeck'] ?? [];
				if(!is_array($config)){
					$config=[];
				}
			}
		}
		return array_replace_recursive([
			'enabled'=>true,
			'password'=>null,
			'password_hash'=>null,
			'developer_password'=>null,
			'developer_password_hash'=>null,
			'session_ttl'=>43200,
			'rate_limit'=>[
				'window'=>300,
				'max_attempts'=>5,
			],
			'debugbar'=>[
				'enabled'=>true,
				'memory_limit'=>null,
			],
		], $config);
	}

	/**
	 * Determines whether Flightdeck surfaces may be served at all.
	 *
	 * Production mode disables Flightdeck even when configuration says enabled;
	 * non-production runtimes are enabled unless config explicitly sets
	 * `enabled` to false.
	 *
	 * @return bool True when Flightdeck may respond to requests.
	 */
	public static function enabled(): bool {
		$config=self::config();
		return self::production_disabled()===false && ($config['enabled'] ?? true)!==false;
	}

	/**
	 * Determines whether a password-backed Flightdeck login is required.
	 *
	 * Production always requires authentication so accidental console exposure
	 * fails closed. Development mode only requires login when any password or
	 * password hash is configured.
	 *
	 * @return bool True when console access must present a valid password cookie.
	 */
	public static function auth_required(): bool {
		if(self::production_disabled()===true){
			return true;
		}
		return self::password_secret()!=='';
	}

	/**
	 * Verifies the current request's Flightdeck session cookie.
	 *
	 * Authentication requires Flightdeck to be enabled, password auth to be
	 * active, a cookie to be present, and that cookie to pass signature, expiry,
	 * and user-agent binding checks.
	 *
	 * @return bool True when the request owns a valid Flightdeck auth cookie.
	 */
	public static function authenticated(): bool {
		if(self::enabled()!==true){
			return false;
		}
		if(self::auth_required()===false){
			return false;
		}
		$token=(string)($_COOKIE[self::COOKIE] ?? '');
		if($token===''){
			return false;
		}
		return self::verify_token($token);
	}

	/**
	 * Attempts to authenticate a Flightdeck console password.
	 *
	 * Disabled consoles, missing auth requirements, active rate limits, and bad
	 * passwords all fail closed. Successful logins clear the failed-attempt cache
	 * and issue a signed session cookie.
	 *
	 * @param string $password Submitted plaintext password.
	 * @return bool True when a new Flightdeck session cookie was issued.
	 */
	public static function login(string $password): bool {
		if(self::enabled()!==true || self::auth_required()!==true){
			return false;
		}
		if(self::rate_limited()){
			return false;
		}
		if(self::verify_password($password)!==true){
			self::record_failed_attempt();
			return false;
		}
		self::clear_failed_attempts();
		self::set_session_cookie();
		return true;
	}

	/**
	 * Clears the Flightdeck session cookie for the current browser.
	 *
	 * @return void
	 */
	public static function logout(): void {
		self::expire_cookie(self::COOKIE);
	}

	/**
	 * Returns the current blocking reason for the login form, when any.
	 *
	 * This reports configuration and rate-limit states only; it intentionally
	 * does not reveal whether a submitted password matched.
	 *
	 * @return ?string Human-readable blocking reason, or null when login may be attempted.
	 */
	public static function login_error(): ?string {
		if(self::auth_required()===false){
			return 'Flightdeck console password is not configured.';
		}
		if(self::rate_limited()){
			return 'Too many failed attempts. Wait before trying again.';
		}
		return null;
	}

	/**
	 * Builds the Flightdeck login URL with a return target.
	 *
	 * @param ?string $return_to URL to revisit after login; null uses the current request URI.
	 * @return string `/dataphyre/login` URL with encoded return query.
	 */
	public static function login_url(?string $return_to=null): string {
		$return_to=$return_to ?? self::current_uri();
		return '/dataphyre/login?'.http_build_query(['return'=>$return_to]);
	}

	/**
	 * Redirects the current request to the Flightdeck login form and exits.
	 *
	 * @return never
	 */
	public static function redirect_to_login(): never {
		http_response_code(302);
		header('Location: '.self::login_url());
		exit;
	}

	/**
	 * Checks whether the debugbar may render for the current request.
	 *
	 * The debugbar requires Flightdeck itself to be enabled, a valid authenticated
	 * console cookie, and `debugbar.enabled` not to be explicitly false.
	 *
	 * @return bool True when debugbar output is authorized.
	 */
	public static function debugbar_allowed(): bool {
		$config=self::config();
		$debugbar=$config['debugbar'] ?? [];
		if(!is_array($debugbar)){
			$debugbar=[];
		}
		return self::enabled()===true
			&& self::authenticated()===true
			&& (($debugbar['enabled'] ?? true)!==false);
	}

	/**
	 * Creates the current hour's Flightdeck CSRF token.
	 *
	 * Tokens are HMACs derived from the Flightdeck cookie secret and hour bucket,
	 * allowing bootstrap pages to verify form posts without database or session
	 * services.
	 *
	 * @return string Current CSRF token.
	 */
	public static function csrf_token(): string {
		return self::csrf_token_for_seed(self::csrf_seed(date('YmdH')));
	}

	/**
	 * Verifies a submitted Flightdeck CSRF token.
	 *
	 * Current and previous hour tokens are accepted, including legacy IP-bound
	 * seeds, so existing forms survive normal hour transitions and upgrades.
	 *
	 * @param ?string $token Submitted token.
	 * @return bool True when the token matches an accepted CSRF candidate.
	 */
	public static function verify_csrf(?string $token): bool {
		if(!is_string($token) || $token===''){
			return false;
		}
		foreach(self::csrf_token_candidates() as $candidate){
			if(hash_equals($candidate, $token)===true){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks a submitted password against configured hashes and plain secrets.
	 *
	 * Password hashes are preferred and verified with `password_verify()`. Plain
	 * secrets remain supported for bootstrap config and are compared with
	 * constant-time equality.
	 *
	 * @param string $password Submitted plaintext password.
	 * @return bool True when any configured Flightdeck secret matches.
	 */
	private static function verify_password(string $password): bool {
		$config=self::config();
		$hashes=array_filter([
			$config['password_hash'] ?? null,
			$config['developer_password_hash'] ?? null,
		], static fn($value)=>is_string($value) && trim($value)!=='');
		foreach($hashes as $hash){
			if(password_verify($password, $hash)){
				return true;
			}
		}
		$passwords=array_filter([
			$config['password'] ?? null,
			$config['developer_password'] ?? null,
		], static fn($value)=>is_string($value) && $value!=='');
		foreach($passwords as $configured_password){
			if(hash_equals((string)$configured_password, $password)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the first configured secret material used to protect Flightdeck.
	 *
	 * The value may be a password hash or plaintext password and is used as part
	 * of cookie and CSRF key derivation, so blank configs intentionally produce
	 * an empty secret.
	 *
	 * @return string Configured password material, or an empty string.
	 */
	private static function password_secret(): string {
		$config=self::config();
		foreach(['password_hash', 'developer_password_hash', 'password', 'developer_password'] as $key){
			$value=$config[$key] ?? null;
			if(is_string($value) && trim($value)!==''){
				return trim($value);
			}
		}
		return '';
	}

	/**
	 * Derives the primary cookie signing secret for the current app context.
	 *
	 * @return string SHA-256 cookie signing secret.
	 */
	private static function cookie_secret(): string {
		return self::cookie_secret_for_app(null);
	}

	/**
	 * Derives cookie signing material from password, license, app, and root.
	 *
	 * Binding cookies to project root and license reduces accidental reuse across
	 * local Dataphyre installs that share a developer password.
	 *
	 * @param ?string $app Optional legacy app name to include in the secret.
	 * @return string SHA-256 cookie signing secret.
	 */
	private static function cookie_secret_for_app(?string $app): string {
		$material=[
			self::password_secret(),
			defined('LICENSE') && is_array(LICENSE) ? (LICENSE['key'] ?? '') : '',
			$app,
			self::project_root(),
		];
		return hash('sha256', implode('|', array_map('strval', $material)));
	}

	/**
	 * Builds the current CSRF seed for an hour bucket.
	 *
	 * @param string $hour Hour bucket formatted as `YmdH`.
	 * @return string Seed used to derive the CSRF HMAC.
	 */
	private static function csrf_seed(string $hour): string {
		return self::cookie_secret().'|flightdeck|'.$hour;
	}

	/**
	 * Builds the legacy IP-bound CSRF seed for upgrade compatibility.
	 *
	 * @param string $hour Hour bucket formatted as `YmdH`.
	 * @return string Legacy seed used to derive accepted CSRF HMACs.
	 */
	private static function legacy_csrf_seed(string $hour): string {
		return self::cookie_secret().'|'.self::client_ip().'|'.$hour;
	}

	/**
	 * Derives a CSRF token from seed material.
	 *
	 * @param string $seed CSRF seed material.
	 * @return string Hex-encoded HMAC token.
	 */
	private static function csrf_token_for_seed(string $seed): string {
		return hash_hmac('sha256', 'csrf', $seed);
	}

	/**
	 * Returns all CSRF tokens accepted for the current request.
	 *
	 * The candidate set spans current and previous hour buckets for both current
	 * and legacy seed formats.
	 *
	 * @return array<int, string> Unique accepted CSRF tokens.
	 */
	private static function csrf_token_candidates(): array {
		$candidates=[];
		foreach([time(), time() - 3600] as $timestamp){
			$hour=date('YmdH', $timestamp);
			$candidates[]=self::csrf_token_for_seed(self::csrf_seed($hour));
			$candidates[]=self::csrf_token_for_seed(self::legacy_csrf_seed($hour));
		}
		return array_values(array_unique($candidates));
	}

	/**
	 * Issues a signed Flightdeck session cookie.
	 *
	 * The payload records issue time, expiry, user-agent hash, and nonce. The
	 * serialized payload is HMAC-signed and written as an HTTP-only cookie.
	 *
	 * @return void
	 */
	private static function set_session_cookie(): void {
		$ttl=max(300, (int)(self::config()['session_ttl'] ?? 43200));
		$payload=[
			'iat'=>time(),
			'exp'=>time() + $ttl,
			'ua'=>hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
			'n'=>bin2hex(random_bytes(12)),
		];
		$data=self::base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
		$signature=hash_hmac('sha256', $data, self::cookie_secret());
		self::set_cookie(self::COOKIE, $data.'.'.$signature, time() + $ttl, true);
	}

	/**
	 * Validates a Flightdeck session cookie token.
	 *
	 * Tokens must contain a payload and HMAC signature, match one of the current
	 * or legacy cookie secrets, decode to a JSON object, remain unexpired, and
	 * match the current request user-agent hash.
	 *
	 * @param string $token Cookie token in `payload.signature` form.
	 * @return bool True when the token is authentic and still valid.
	 */
	private static function verify_token(string $token): bool {
		$parts=explode('.', $token, 2);
		if(count($parts)!==2){
			return false;
		}
		[$data, $signature]=$parts;
		$valid_signature=false;
		foreach(self::cookie_secrets() as $secret){
			$expected=hash_hmac('sha256', $data, $secret);
			if(hash_equals($expected, $signature)===true){
				$valid_signature=true;
				break;
			}
		}
		if($valid_signature!==true){
			return false;
		}
		$json=self::base64url_decode($data);
		$payload=json_decode($json, true);
		if(!is_array($payload)){
			return false;
		}
		if((int)($payload['exp'] ?? 0)<time()){
			return false;
		}
		$user_agent_hash=hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
		return hash_equals((string)($payload['ua'] ?? ''), $user_agent_hash);
	}

	/**
	 * Checks whether the client is inside an active login lockout window.
	 *
	 * @return bool True when failed attempts reached the configured limit and the window has not expired.
	 */
	private static function rate_limited(): bool {
		$state=self::rate_limit_state();
		return ($state['attempts'] ?? 0) >= self::rate_limit_max_attempts()
			&& ($state['until'] ?? 0)>time();
	}

	/**
	 * Records one failed login attempt for the client IP.
	 *
	 * Attempts are counted inside the configured window and persisted as JSON in
	 * the Flightdeck cache directory when a writable cache path is available.
	 *
	 * @return void
	 */
	private static function record_failed_attempt(): void {
		$state=self::rate_limit_state();
		$now=time();
		$until=(int)($state['until'] ?? 0);
		if($until<=$now){
			$state=['attempts'=>0, 'until'=>$now + self::rate_limit_window()];
		}
		$state['attempts']=(int)($state['attempts'] ?? 0) + 1;
		$state['until']=(int)($state['until'] ?? ($now + self::rate_limit_window()));
		self::write_rate_limit_state($state);
	}

	/**
	 * Clears the client IP's failed login attempt cache after successful login.
	 *
	 * @return void
	 */
	private static function clear_failed_attempts(): void {
		$file=self::rate_limit_file();
		if($file!==null && is_file($file)){
			@unlink($file);
		}
	}

	/**
	 * Reads the current client's rate-limit state from disk.
	 *
	 * Missing, malformed, or expired files are treated as an empty state so a bad
	 * cache file cannot permanently lock out the console.
	 *
	 * @return array{attempts: int, until: int} Current failed-attempt state.
	 */
	private static function rate_limit_state(): array {
		$file=self::rate_limit_file();
		if($file===null || !is_file($file)){
			return ['attempts'=>0, 'until'=>0];
		}
		$state=json_decode((string)@file_get_contents($file), true);
		if(!is_array($state)){
			return ['attempts'=>0, 'until'=>0];
		}
		if((int)($state['until'] ?? 0)<=time()){
			return ['attempts'=>0, 'until'=>0];
		}
		return $state;
	}

	/**
	 * Persists the current client's rate-limit state when cache storage exists.
	 *
	 * Write errors are intentionally suppressed because lockout persistence must
	 * not break pre-init diagnostics or fatal-error pages.
	 *
	 * @param array{attempts?: int, until?: int} $state State to write.
	 * @return void
	 */
	private static function write_rate_limit_state(array $state): void {
		$file=self::rate_limit_file();
		if($file===null){
			return;
		}
		$directory=dirname($file);
		if(!is_dir($directory)){
			@mkdir($directory, 0777, true);
		}
		@file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
	}

	/**
	 * Resolves the per-client rate-limit cache file.
	 *
	 * @return ?string Cache file path keyed by client IP hash, or null when no cache root exists.
	 */
	private static function rate_limit_file(): ?string {
		$directory=self::cache_directory();
		if($directory===null){
			return null;
		}
		return $directory.'login_'.hash('sha256', self::client_ip()).'.json';
	}

	/**
	 * Resolves the Flightdeck cache directory without requiring the core runtime.
	 *
	 * ROOTPATH is preferred when available. Standalone bootstrap falls back to
	 * the install-relative common cache directory.
	 *
	 * @return ?string Directory path with trailing separator, or null when it cannot be resolved.
	 */
	private static function cache_directory(): ?string {
		if(defined('ROOTPATH') && !empty(ROOTPATH['common_dataphyre'])){
			return rtrim((string)ROOTPATH['common_dataphyre'], '/\\').'/cache/flightdeck/';
		}
		$install_root=dirname(__DIR__, 4);
		if($install_root!=='' && is_dir($install_root)){
			return rtrim($install_root, '/\\').'/cache/flightdeck/';
		}
		return null;
	}

	/**
	 * Returns the configured login rate-limit window.
	 *
	 * @return int Window length in seconds, clamped to at least 30.
	 */
	private static function rate_limit_window(): int {
		$config=self::config();
		$rate_limit=$config['rate_limit'] ?? [];
		return max(30, (int)(is_array($rate_limit) ? ($rate_limit['window'] ?? 300) : 300));
	}

	/**
	 * Returns the configured maximum failed login attempts per window.
	 *
	 * @return int Maximum attempts, clamped to at least one.
	 */
	private static function rate_limit_max_attempts(): int {
		$config=self::config();
		$rate_limit=$config['rate_limit'] ?? [];
		return max(1, (int)(is_array($rate_limit) ? ($rate_limit['max_attempts'] ?? 5) : 5));
	}

	/**
	 * Returns the current request URI for login return links.
	 *
	 * @return string Request URI, or `/dataphyre` when unavailable.
	 */
	public static function current_uri(): string {
		return (string)($_SERVER['REQUEST_URI'] ?? '/dataphyre');
	}

	/**
	 * Resolves the client IP used for legacy CSRF and rate-limit keys.
	 *
	 * @return string Best available client IP signal.
	 */
	private static function client_ip(): string {
		return (string)($_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0');
	}

	/**
	 * Resolves the project root used in cookie secret derivation.
	 *
	 * @return string Configured project root or install-relative fallback.
	 */
	private static function project_root(): string {
		if(defined('DATAPHYRE_PROJECT_ROOT')){
			return (string)DATAPHYRE_PROJECT_ROOT;
		}
		return dirname(__DIR__, 5);
	}

	/**
	 * Returns current and legacy cookie secrets accepted for verification.
	 *
	 * Legacy variants cover historical app-bound and ROOTPATH-rooted derivation
	 * so existing Flightdeck sessions can survive bootstrap migration.
	 *
	 * @return array<int, string> Unique cookie signing secrets.
	 */
	private static function cookie_secrets(): array {
		$secrets=[self::cookie_secret()];
		foreach(self::legacy_cookie_apps() as $app){
			$secrets[]=self::cookie_secret_for_app($app);
		}
		if(defined('ROOTPATH') && defined('DATAPHYRE_PROJECT_ROOT') && !empty(ROOTPATH['root']) && (string)ROOTPATH['root']!==(string)DATAPHYRE_PROJECT_ROOT){
			foreach(array_merge([defined('APP') ? (string)APP : null], self::legacy_cookie_apps()) as $app){
				$material=[
					self::password_secret(),
					defined('LICENSE') && is_array(LICENSE) ? (LICENSE['key'] ?? '') : '',
					$app,
					(string)ROOTPATH['root'],
				];
				$secrets[]=hash('sha256', implode('|', array_map('strval', $material)));
			}
		}
		return array_values(array_unique($secrets));
	}

	/**
	 * Returns legacy app names used by older cookie secret derivation.
	 *
	 * @return array<int, string> Unique legacy app names.
	 */
	private static function legacy_cookie_apps(): array {
		$apps=[];
		if(defined('APP') && is_string(APP) && APP!==''){
			$apps[]=APP;
		}
		$bootstrap=defined('DATAPHYRE_BOOTSTRAP_CONFIG') && is_array(DATAPHYRE_BOOTSTRAP_CONFIG)
			? DATAPHYRE_BOOTSTRAP_CONFIG
			: ($GLOBALS['dataphyre_bootstrap_config'] ?? []);
		if(is_array($bootstrap) && is_string($bootstrap['app'] ?? null) && $bootstrap['app']!==''){
			$apps[]=$bootstrap['app'];
		}
		return array_values(array_unique($apps));
	}

	/**
	 * Writes a Strict SameSite Flightdeck cookie and mirrors it into `$_COOKIE`.
	 *
	 * Mirroring lets the current PHP request observe the newly issued or replaced
	 * value before the browser sends it back on the next request.
	 *
	 * @param string $name Cookie name.
	 * @param string $value Cookie value.
	 * @param int $expires Unix expiration timestamp.
	 * @param bool $http_only Whether JavaScript should be denied cookie access.
	 * @return void
	 */
	private static function set_cookie(string $name, string $value, int $expires, bool $http_only): void {
		setcookie($name, $value, [
			'expires'=>$expires,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>$http_only,
			'samesite'=>'Strict',
		]);
		$_COOKIE[$name]=$value;
	}

	/**
	 * Expires a Strict SameSite Flightdeck cookie and removes the local value.
	 *
	 * @param string $name Cookie name.
	 * @return void
	 */
	private static function expire_cookie(string $name): void {
		setcookie($name, '', [
			'expires'=>time() - 3600,
			'path'=>'/',
			'secure'=>self::secure_cookie(),
			'httponly'=>true,
			'samesite'=>'Strict',
		]);
		unset($_COOKIE[$name]);
	}

	/**
	 * Detects whether cookies should be marked secure for the current request.
	 *
	 * Direct HTTPS and forwarded HTTPS are both accepted for reverse-proxy setups.
	 *
	 * @return bool True when the request appears to be HTTPS.
	 */
	private static function secure_cookie(): bool {
		return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off')
			|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https');
	}

	/**
	 * Encodes a string as unpadded Base64URL text.
	 *
	 * @param string|false $value Raw payload; false is encoded as an empty string.
	 * @return string URL-safe Base64 text without padding.
	 */
	private static function base64url_encode(string|false $value): string {
		return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
	}

	/**
	 * Decodes unpadded Base64URL text.
	 *
	 * Invalid input returns an empty string so token verification can fail closed
	 * without emitting bootstrap-time warnings.
	 *
	 * @param string $value URL-safe Base64 text.
	 * @return string Decoded payload, or an empty string on failure.
	 */
	private static function base64url_decode(string $value): string {
		$padding=strlen($value) % 4;
		if($padding>0){
			$value.=str_repeat('=', 4 - $padding);
		}
		$decoded=base64_decode(strtr($value, '-_', '+/'), true);
		return is_string($decoded) ? $decoded : '';
	}
}
