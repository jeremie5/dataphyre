<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

require_once(__DIR__.'/access.qr.php');

dp_define_module_config('access', 'DP_ACCESS_CFG', [
	'sanction_on_useragent_change'=>false,
	'sessions_table_name'=>'dataphyre.sessions',
	'sessions_cookie_name'=>'DPID',
	'auth_types'=>['session'],
	'default_auth_type'=>'session',
	'botlist'=>['Googlebot', 'bingbot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider', 'YandexBot'],
	'framework'=>[
		'default_guard'=>'session',
		'guards'=>[],
		'providers'=>[],
		'jwt'=>[],
		'oauth'=>[
			'providers'=>[],
		],
	],
	'identity'=>[
		'users_table'=>null,
		'id_column'=>'id',
		'email_column'=>'email',
		'name_column'=>'name',
		'password_column'=>'password',
		'password_hash_column'=>null,
		'email_verified_column'=>'email_verified',
		'email_verified_at_column'=>'email_verified_at',
		'created_at_column'=>'created_at',
		'tokens_table'=>'dataphyre.access_tokens',
		'callbacks'=>[],
	],
	'panel_auth'=>[
		'enabled'=>false,
		'allow_registration'=>true,
		'require_email_verification'=>false,
		'login_page'=>'login',
		'register_page'=>'register',
		'logout_page'=>'logout',
		'verify_page'=>'email_verification',
		'password_reset_page'=>'password_reset',
		'password_change_page'=>'password_change',
		'after_login'=>null,
		'after_logout'=>null,
		'queue_mail'=>true,
		'verification_ttl'=>86400,
		'password_reset_ttl'=>3600,
	],
]);

if(defined('DP_ACCESS_CFG') && !defined('Dataphyre\\Access\\DP_ACCESS_CFG')){
	define('Dataphyre\\Access\\DP_ACCESS_CFG', DP_ACCESS_CFG);
}

$dp_access_sessions_table_name=(string)DP_ACCESS_CFG['sessions_table_name'];
$configured_auth_types=DP_ACCESS_CFG['auth_types']
	?? DP_ACCESS_CFG['enabled_auth_types'];
if(!is_array($configured_auth_types) || $configured_auth_types===[]){
	$configured_auth_types=['session'];
}
$configured_auth_types=array_values(array_unique(array_filter(array_map(
	static fn(mixed $auth_type): string=>strtolower(trim((string)$auth_type)),
	$configured_auth_types
), static fn(string $auth_type): bool=>$auth_type!=='')));
if($configured_auth_types===[]){
	$configured_auth_types=['session'];
}
$default_auth_type=strtolower(trim((string)DP_ACCESS_CFG['default_auth_type']));
if($default_auth_type===''){
	$default_auth_type='session';
}
if(!in_array($default_auth_type, $configured_auth_types, true)){
	array_unshift($configured_auth_types, $default_auth_type);
}
if(!defined('DP_ACCESS_SESSIONS_TABLE_NAME')){
	define('DP_ACCESS_SESSIONS_TABLE_NAME', $dp_access_sessions_table_name);
}
if(!defined('DP_ACCESS_DEFAULT_AUTH_TYPE')){
	define('DP_ACCESS_DEFAULT_AUTH_TYPE', $default_auth_type);
}
if(!defined('DP_ACCESS_AUTH_TYPES')){
	define('DP_ACCESS_AUTH_TYPES', $configured_auth_types);
}
if(function_exists('sql_define_table')){
	sql_define_table(DP_ACCESS_SESSIONS_TABLE_NAME, __DIR__.'/access.tables.php', 'sessions');
	$dp_access_tokens_table_name=(string)(DP_ACCESS_CFG['identity']['tokens_table'] ?? 'dataphyre.access_tokens');
	sql_define_table($dp_access_tokens_table_name, __DIR__.'/access.tables.php', 'tokens');
}

\heisenconstant('DPID', fn()=>$_SESSION['dp_access']['dpid']);
\heisenconstant('AUTH_TYPE', fn()=>\dataphyre\access::current_auth_type());

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/access.diagnostic.php');
}

/**
 * Coordinates Dataphyre's kernel authentication state and session security checks.
 *
 * Access owns the legacy session guard, optional delegated auth types, DPID
 * identifier creation, TOTP utilities, request device heuristics, and page-level
 * access gates. The class reads and mutates `$_SESSION['dp_access']`, secure
 * cookies, the configured sessions table, and firewall escalation hooks when a
 * session identifier or browser fingerprint appears forged or stale.
 */
class access{
	
	private static $session_cookie='__Secure-DPID';
	private static $fingerprint=[];
	private static ?string $current_auth_type=null;
	private const BASE32_ALPHABET='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	private static array $auth_type_prefix_map=[
		'session'=>'DPID',
		'jwt'=>'DJTI',
	];
	static $useragent_mismatch=false;
	
	/**
	 * Creates the Access kernel object and stores its initial state.
	 *
	 * Access kernel boundary: authentication, authorization, guard checks, sessions, tokens, and policy decisions.
	 */
	public  function __construct(){
		if(isset(DP_ACCESS_CFG['sessions_cookie_name'])){
			self::$session_cookie='__Secure-'.DP_ACCESS_CFG['sessions_cookie_name'];
		}
		self::mark_auth_type(self::current_auth_type());
		if(isset($_SESSION)){
			if(isset($_SESSION['dp_access']['previous_useragent'])){
				if(DP_ACCESS_CFG['sanction_on_useragent_change']===true){
					if(REQUEST_USER_AGENT!==$_SESSION['dp_access']['previous_useragent']){
						self::$useragent_mismatch=true;
						$_SESSION['dp_access']['minimum_security_alert']=true;
						self::disable_session();
						if(dp_module_present('firewall')===true){
							firewall::captcha_block_user('useragent_mismatch');
						}
					}
				}
			}
			$_SESSION['dp_access']['previous_useragent']=REQUEST_USER_AGENT;
		}
		self::$fingerprint=[
			'user_agent'=>$_SERVER['HTTP_USER_AGENT'] ?? '',
			'accept_language'=>$_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
			'ip_subnet'=>self::extract_subnet(REQUEST_IP_ADDRESS),
			'cf_country'=>$_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
			'cf_connecting_ip'=>self::extract_subnet($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
			'dnt'=>$_SERVER['HTTP_DNT'] ?? '',
		];
		if(self::logged_in()===true){
			if(self::validate_session()===false){
				if(self::recover_session()===false){
					if(self::disable_session()===false){
						core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAccess: User session is invalid, unrecoverable and couldn\'t be destroyed.', 'safemode');
						exit();
					}
				}
			}
		}
		else
		{
			self::recover_session();
		}
		self::enforce_fingerprint_drift();
		DPID->reset();
	}

	/**
	 * Resolves the configured default authentication guard.
	 *
	 * Blank configuration falls back to `session` so callers always receive a
	 * usable guard name for session creation, validation, and delegated auth.
	 *
	 * @return string Lowercase default auth type.
	 */
	public static function default_auth_type(): string {
		$auth_type=strtolower(trim((string)(DP_ACCESS_DEFAULT_AUTH_TYPE ?? 'session')));
		return $auth_type!=='' ? $auth_type : 'session';
	}

	/**
	 * Returns the normalized allow-list of configured authentication guards.
	 *
	 * Values are trimmed, lowercased, deduplicated, and never allowed to become
	 * empty; an invalid configuration collapses to the default guard.
	 *
	 * @return array<int, string> Enabled auth type names in resolution order.
	 */
	public static function enabled_auth_types(): array {
		$auth_types=DP_ACCESS_AUTH_TYPES ?? ['session'];
		if(!is_array($auth_types) || $auth_types===[]){
			return [self::default_auth_type()];
		}
		$normalized=array_values(array_unique(array_filter(array_map(
			static fn(mixed $auth_type): string=>strtolower(trim((string)$auth_type)),
			$auth_types
		), static fn(string $auth_type): bool=>$auth_type!=='')));
		if($normalized===[]){
			return [self::default_auth_type()];
		}
		return $normalized;
	}

	/**
	 * Checks whether a requested auth type is currently enabled.
	 *
	 * The input is normalized before comparison, so callers may pass mixed case
	 * or surrounding whitespace without bypassing the configured guard list.
	 *
	 * @param string $auth_type Candidate guard name.
	 * @return bool True when the normalized guard appears in the enabled auth list.
	 */
	public static function auth_type_enabled(string $auth_type): bool {
		return in_array(self::normalize_auth_type($auth_type), self::enabled_auth_types(), true);
	}

	/**
	 * Returns the auth type selected for the current request.
	 *
	 * Detection is cached for the request and prefers bearer-token guards over
	 * remembered session guards before falling back to cookie presence and then
	 * the configured default.
	 *
	 * @return string Active request guard.
	 */
	public static function current_auth_type(): string {
		if(self::$current_auth_type!==null){
			return self::$current_auth_type;
		}
		return self::$current_auth_type=self::detect_request_auth_type();
	}

	/**
	 * Builds a compact identity snapshot for the requested guard.
	 *
	 * The context resolves the guard, checks login state, exposes the current
	 * user id when available, and names the cookie that backs session auth.
	 *
	 * @param ?string $auth_type Optional guard override; null resolves from the request.
	 * @return array{auth_type: string, logged_in: bool, userid: int|string|null, id: string|null, cookie_name: ?string} Current auth context.
	 */
	public static function auth_context(?string $auth_type=null): array {
		$resolved_auth_type=self::resolve_auth_type($auth_type);
		$userid=self::userid($resolved_auth_type);
		$identifier=$_SESSION['dp_access']['dpid'] ?? null;
		if(!is_string($identifier) || $identifier===''){
			$identifier=null;
		}
		return [
			'auth_type'=>$resolved_auth_type,
			'logged_in'=>self::logged_in($resolved_auth_type),
			'userid'=>($userid!==false && $userid!==null) ? $userid : null,
			'id'=>$identifier,
			'cookie_name'=>self::get_auth_cookie_name($resolved_auth_type),
		];
	}
	
	/**
	 * Returns the secure cookie name used by the session guard.
	 *
	 * The constructor applies the configured base name and always prefixes it as
	 * a `__Secure-` cookie for browser delivery.
	 *
	 * @return string Session guard cookie name.
	 */
	public static function get_session_cookie_name(): string {
		return self::$session_cookie;
	}

	/**
	 * Returns the cookie backing a guard when that guard is cookie-based.
	 *
	 * Only the built-in `session` guard currently owns a cookie; delegated guards
	 * such as bearer-token/JWT flows return null.
	 *
	 * @param ?string $auth_type Optional guard override.
	 * @return ?string Cookie name for session auth, or null for non-cookie guards.
	 */
	public static function get_auth_cookie_name(?string $auth_type=null): ?string {
		$auth_type=self::resolve_auth_type($auth_type);
		if($auth_type==='session'){
			return self::$session_cookie;
		}
		return null;
	}

	/**
	 * Normalizes a guard name for comparison and fallback handling.
	 *
	 * Empty values resolve to the configured default guard so public methods can
	 * accept null overrides without duplicating fallback logic.
	 *
	 * @param ?string $auth_type Raw guard name.
	 * @return string Lowercase guard name.
	 */
	private static function normalize_auth_type(?string $auth_type=null): string {
		$auth_type=strtolower(trim((string)$auth_type));
		return $auth_type!=='' ? $auth_type : self::default_auth_type();
	}

	/**
	 * Resolves a guard name to an enabled auth type.
	 *
	 * Unknown guards collapse to the configured default to keep legacy callers on
	 * the session path instead of raising runtime errors.
	 *
	 * @param ?string $auth_type Requested guard name.
	 * @return string Enabled guard name.
	 */
	private static function resolve_auth_type(?string $auth_type=null): string {
		$auth_type=self::normalize_auth_type($auth_type);
		if(self::auth_type_enabled($auth_type)){
			return $auth_type;
		}
		return self::default_auth_type();
	}

	/**
	 * Detects which authentication guard should handle the current request.
	 *
	 * Bearer tokens win over remembered session state, remembered session state
	 * wins over cookie presence, and all missing signals fall back to the default
	 * guard.
	 *
	 * @return string Detected request guard.
	 */
	private static function detect_request_auth_type(): string {
		if(self::auth_type_enabled('jwt') && self::bearer_token()!==null){
			return 'jwt';
		}
		if(isset($_SESSION['dp_access']['auth_type'])){
			$auth_type=self::normalize_auth_type($_SESSION['dp_access']['auth_type']);
			if(self::auth_type_enabled($auth_type)){
				return $auth_type;
			}
		}
		if(self::auth_type_enabled('session') && isset($_COOKIE[self::$session_cookie])){
			return 'session';
		}
		return self::default_auth_type();
	}

	/**
	 * Stores the resolved guard for the current request and session.
	 *
	 * The in-memory cache prevents later checks from redetecting the request, and
	 * the session field lets future requests remember which guard authenticated.
	 *
	 * @param string $auth_type Guard name to persist.
	 * @return void
	 */
	private static function mark_auth_type(string $auth_type): void {
		$auth_type=self::resolve_auth_type($auth_type);
		self::$current_auth_type=$auth_type;
		if(isset($_SESSION)){
			$_SESSION['dp_access']['auth_type']=$auth_type;
		}
	}

	/**
	 * Extracts a bearer token from server authorization headers.
	 *
	 * Both direct and redirected authorization headers are supported for common
	 * web-server forwarding setups. Non-bearer or blank headers return null.
	 *
	 * @return ?string Bearer token without the scheme prefix.
	 */
	private static function bearer_token(): ?string {
		$authorization=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if(!is_string($authorization) || $authorization===''){
			return null;
		}
		if(preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches)!==1){
			return null;
		}
		return trim($matches[1])!=='' ? trim($matches[1]) : null;
	}

	/**
	 * Returns the four-character identifier prefix assigned to a guard.
	 *
	 * Built-in guards use stable prefixes. Custom guards derive a sanitized,
	 * padded prefix from their name and cache it for reverse lookup.
	 *
	 * @param string $auth_type Guard name.
	 * @return string Four-character DPID prefix.
	 */
	private static function auth_type_prefix(string $auth_type): string {
		$auth_type=self::resolve_auth_type($auth_type);
		if(isset(self::$auth_type_prefix_map[$auth_type])){
			return self::$auth_type_prefix_map[$auth_type];
		}
		$prefix=strtoupper(preg_replace('/[^A-Z0-9]/', '', substr($auth_type, 0, 4)));
		$prefix=substr($prefix.'XXXX', 0, 4);
		self::$auth_type_prefix_map[$auth_type]=$prefix;
		return $prefix;
	}

	/**
	 * Resolves a DPID prefix back to its configured guard.
	 *
	 * Unknown prefixes are treated as the default guard so validation can still
	 * perform signature checks before rejecting forged identifiers.
	 *
	 * @param string $prefix Four-character identifier prefix.
	 * @return string Resolved guard name.
	 */
	private static function auth_type_from_prefix(string $prefix): string {
		$prefix=strtoupper(trim($prefix));
		$auth_type=array_search($prefix, self::$auth_type_prefix_map, true);
		if(is_string($auth_type) && $auth_type!==''){
			return $auth_type;
		}
		return self::default_auth_type();
	}

	/**
	 * Offers auth operations to non-session guards through dialback hooks.
	 *
	 * The built-in session guard returns null so the local implementation keeps
	 * running. Other guards can return a concrete result from `core::dialback()`.
	 *
	 * @param string $operation Access operation suffix.
	 * @param string $auth_type Resolved guard name.
	 * @param array<int, mixed> $arguments Operation arguments to forward.
	 * @return mixed Delegated result, or null when the local session path should handle it.
	 */
	private static function delegate_auth_type(string $operation, string $auth_type, array $arguments): mixed {
		if($auth_type==='session'){
			return null;
		}
		return core::dialback('CALL_ACCESS_'.$operation.'_AUTH_TYPE', $auth_type, ...$arguments);
	}

	/**
	 * Logs an unsupported guard operation and returns the caller's fallback.
	 *
	 * Boolean-returning guard helpers stay predictable when an enabled
	 * guard has no delegate implementation for the requested operation.
	 *
	 * @param string $operation Access operation suffix.
	 * @param string $auth_type Resolved guard name.
	 * @param mixed $fallback Value to return to the caller.
	 * @return mixed caller fallback returned after the unsupported guard operation is traced.
	 */
	private static function unsupported_auth_type(string $operation, string $auth_type, mixed $fallback=false): mixed {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Unsupported auth type '$auth_type' for operation '$operation'");
		return $fallback;
	}
	
	/**
	 * Enforces browser fingerprint drift policy for the active session.
	 *
	 * More than one changed fingerprint attribute marks the session as a security
	 * alert, disables it, and escalates to the firewall CAPTCHA hook when present.
	 *
	 * @return void
	 */
	private static function enforce_fingerprint_drift() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(isset($_SESSION['dp_access']['fingerprint'])){
			if(1<self::fingerprint_drift_score(self::$fingerprint, $_SESSION['dp_access']['fingerprint'])){
				$_SESSION['dp_access']['minimum_security_alert']=true;
				if(self::disable_session()===false){
					core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAccess: User session is invalid (fingerprint drift) and couldn\'t be destroyed..', 'safemode');
				}
				if(dp_module_present('firewall')===true){
					firewall::captcha_block_user('fingerprint_drift');
				}
			}
		}
		$_SESSION['dp_access']['fingerprint']=self::$fingerprint;
	}
	
	/**
	 * Reduces an IP address to the subnet used in session fingerprints.
	 *
	 * IPv4 addresses use the first three octets and IPv6 addresses use the first
	 * four hextets, trading exact host identity for tolerance of normal address
	 * churn.
	 *
	 * @param string $ip Client IP address.
	 * @return string Fingerprint subnet or original value when parsing fails.
	 */
	private static function extract_subnet(string $ip): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)){
			return implode('.', array_slice(explode('.', $ip), 0, 3)); // Class C
		}
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return implode(':', array_slice(explode(':', $ip), 0, 4)); // Heuristic
		}
		return $ip;
	}
	
	/**
	 * Counts changed browser fingerprint attributes.
	 *
	 * The score is intentionally simple: each missing or changed key contributes
	 * one point, and the enforcement threshold decides when to revoke a session.
	 *
	 * @param array<string, mixed> $stored Fingerprint captured previously.
	 * @param array<string, mixed> $current Fingerprint captured for the request.
	 * @return int Number of changed fingerprint fields.
	 */
	private static function fingerprint_drift_score(array $stored, array $current): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$diffs=0;
		foreach($stored as $key=>$value){
			if(!isset($current[$key]) || $current[$key] !== $value){
				$diffs++;
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Fingerprint drift detected for ".$key, $S="warning");
			}
			else
			{
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="No fingerprint drift for ".$key);
			}
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Returning $diffs mismatches");
		return $diffs;
	}
	
	/**
	  * Create an user session
	  *
	  * @version 	1.0.0
	  *
	  * @param int 			$userid
	  * @param bool 		$keepalive
	  * @return bool			True on success, false on failure
	  */
	public static function create_session(int $userid, bool $keepalive=false, ?string $auth_type=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_CREATE_SESSION",...func_get_args())) return $early_return;
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('CREATE_SESSION', $auth_type, [$userid, $keepalive])){
			return (bool)$delegated;
		}
		if($auth_type!=='session'){
			return (bool)self::unsupported_auth_type('CREATE_SESSION', $auth_type, false);
		}
		if(session_status()!==PHP_SESSION_ACTIVE){
			session_start();
		}
		if(false!==sql_insert(
			$L=DP_ACCESS_SESSIONS_TABLE_NAME, 
			$F=[
				"id"=>$dpid=self::create_id($auth_type), 
				"userid"=>$userid,
				"useragent"=>REQUEST_USER_AGENT,
				"ipaddress"=>REQUEST_IP_ADDRESS, 
				"keepalive"=>$keepalive, 
				"active"=>true
			],
			$V=null,
			$CC=true
		)){
			$website_name=parse_url($_SERVER['HTTP_HOST'], PHP_URL_HOST);
			if(!is_string($website_name) || $website_name===''){
				$website_name=explode(':', (string)$_SERVER['HTTP_HOST'], 2)[0] ?? '';
			}
			$website_name=strtolower($website_name);
			setcookie(self::$session_cookie, $dpid, time()+(86400*7), '/', strtolower($website_name), true, true);
			$_SESSION['dp_access']['userid']=$userid;
			$_SESSION['dp_access']['dpid']=$dpid;
			$_SESSION['dp_access']['ip_address']=REQUEST_IP_ADDRESS;
			$_SESSION['dp_access']['auth_type']=$auth_type;
			unset($_SESSION['dp_access']['no_known_recoverable_session']);
			self::mark_auth_type($auth_type);
			return true;
		}
		return false;
	}
	
	/**
	  * Create a cryptographically secure session identifier
	  *
	  * @version 	1.0.0
	  *
	  * @return string Session identifier
	  */
	public static function create_id(?string $auth_type=null) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$auth_type=self::resolve_auth_type($auth_type);
		$identifier=rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$prefix=self::auth_type_prefix($auth_type);
		$signature=substr(hash_hmac('sha256', $prefix.'|'.$identifier, dpvk()), 0, 8);
		return $prefix.'_'.$identifier.'_'.$signature;
	}
	
	/**
	 * Validates a Dataphyre session identifier signature and guard prefix.
	 *
	 * DPID values contain a four-character guard prefix, 32 bytes of random
	 * entropy encoded URL-safely, and an HMAC suffix derived from `dpvk()`. A
	 * forged or malformed identifier marks the session with a security alert,
	 * disables the relevant session, and asks the firewall module for a CAPTCHA
	 * challenge when available.
	 *
	 * @param string $dpid Candidate Dataphyre session identifier.
	 * @param ?string $auth_type Optional guard that the identifier must belong to.
	 * @return bool True when format, prefix, and HMAC signature all match.
	 */
	public static function validate_id(string $dpid, ?string $auth_type=null) : bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_ACCESS_VALIDATE_ID", ...func_get_args())) return $early_return;
		$auth_type=$auth_type!==null ? self::resolve_auth_type($auth_type) : null;
		if(null!==$delegated=self::delegate_auth_type('VALIDATE_ID', $auth_type ?? self::auth_type_from_prefix(substr($dpid, 0, 4)), [$dpid])){
			return (bool)$delegated;
		}
		if(preg_match('/^([A-Z0-9]{4})_([A-Za-z0-9\-_]{43})_([a-f0-9]{8})$/', $dpid, $matches)){
			$prefix=$matches[1];
			$identifier=$matches[2];
			$signature=$matches[3];
			if($auth_type!==null && $prefix!==self::auth_type_prefix($auth_type)){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Identifier prefix does not match auth type: $auth_type");
				return false;
			}
			$expected_signature=substr(hash_hmac('sha256', $prefix.'|'.$identifier, dpvk()), 0, 8);
			if(hash_equals($expected_signature, $signature)){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Valid DPID");
				return true;
			}
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Invalid DPID signature");
		}
		else
		{
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Invalid DPID format: $dpid");
		}
		$_SESSION['dp_access']['minimum_security_alert']=true;
		self::disable_session($auth_type);
		if(dp_module_present('firewall')===true){
			firewall::captcha_block_user('forged_dpid');
		}
		return false;
	}

	/**
	 * Encodes binary data with the unpadded Base32 alphabet used by TOTP.
	 *
	 * Bits are grouped in five-bit chunks and padded only for the final symbol,
	 * matching the format expected by authenticator applications.
	 *
	 * @param string $binary Raw bytes to encode.
	 * @return string Unpadded Base32 text.
	 */
	private static function base32_encode(string $binary): string {
		if($binary===''){
			return '';
		}
		$alphabet=self::BASE32_ALPHABET;
		$bit_buffer='';
		$encoded='';
		$length=strlen($binary);
		for($i=0; $i<$length; $i++){
			$bit_buffer.=str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
		}
		$chunks=str_split($bit_buffer, 5);
		foreach($chunks as $chunk){
			if($chunk===''){
				continue;
			}
			if(strlen($chunk)<5){
				$chunk=str_pad($chunk, 5, '0', STR_PAD_RIGHT);
			}
			$encoded.=$alphabet[bindec($chunk)];
		}
		return $encoded;
	}

	/**
	 * Decodes an unpadded Base32 TOTP secret to raw bytes.
	 *
	 * Spaces, hyphens, and padding are ignored for enrollment copy/paste
	 * tolerance. Characters outside the TOTP alphabet fail validation.
	 *
	 * @param string $input Base32 secret text.
	 * @return string|false Raw secret bytes, or false for invalid input.
	 */
	private static function base32_decode(string $input): string|false {
		$normalized=strtoupper(trim($input));
		$normalized=str_replace([' ', '-', '='], '', $normalized);
		if($normalized===''){
			return false;
		}
		if(preg_match('/[^A-Z2-7]/', $normalized)===1){
			return false;
		}
		$alphabet_map=array_flip(str_split(self::BASE32_ALPHABET));
		$bit_buffer='';
		foreach(str_split($normalized) as $character){
			$bit_buffer.=str_pad(decbin($alphabet_map[$character]), 5, '0', STR_PAD_LEFT);
		}
		$decoded='';
		$chunks=str_split($bit_buffer, 8);
		foreach($chunks as $chunk){
			if(strlen($chunk)!==8){
				continue;
			}
			$decoded.=chr(bindec($chunk));
		}
		return $decoded!=='' ? $decoded : false;
	}

	/**
	 * Converts a stored TOTP secret into raw bytes for HMAC operations.
	 *
	 * Even-length hexadecimal secrets are accepted for storage migrations;
	 * otherwise the value is treated as Base32.
	 *
	 * @param string $secret Stored shared secret.
	 * @return string|false Raw secret bytes, or false when decoding fails.
	 */
	private static function normalize_totp_secret(string $secret): string|false {
		$secret=trim($secret);
		if($secret===''){
			return false;
		}
		if(preg_match('/^[a-f0-9]+$/', $secret)===1 && strlen($secret)%2===0){
			$binary=hex2bin($secret);
			return $binary!==false ? $binary : false;
		}
		return self::base32_decode($secret);
	}

	/**
	 * Generates a Base32 TOTP shared secret.
	 *
	 * The entropy size is clamped to at least 10 bytes and produced with
	 * `random_bytes()`. Runtime entropy failures are logged and reported as
	 * false so enrollment flows can fail closed.
	 *
	 * @param int $bytes Requested random byte length before Base32 encoding.
	 * @return string|false Base32 secret suitable for authenticator apps, or false on entropy failure.
	 */
	public static function create_totp_secret(int $bytes=20): string|false {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_ACCESS_CREATE_TOTP_SECRET",...func_get_args())) return $early_return;
		if($bytes<10){
			$bytes=10;
		}
		try{
			return self::base32_encode(random_bytes($bytes));
		} catch(\Throwable $exception){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed generating TOTP secret: ".$exception->getMessage(), $S='warning');
			return false;
		}
	}

	/**
	 * Computes an RFC 6238-style TOTP code from a shared secret.
	 *
	 * Secrets may be stored as Base32 or even-length hexadecimal. Period is
	 * clamped to a positive interval, digits are clamped between 1 and 10, and
	 * invalid secrets return false without producing a code.
	 *
	 * @param string $secret Base32 or hexadecimal shared secret.
	 * @param ?int $timestamp Unix timestamp to evaluate; null uses current time.
	 * @param int $period Step duration in seconds.
	 * @param int $digits Number of output digits.
	 * @return string|false Zero-padded numeric TOTP code, or false for invalid inputs.
	 */
	public static function totp_code(string $secret, ?int $timestamp=null, int $period=30, int $digits=6): string|false {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_ACCESS_TOTP_CODE",...func_get_args())) return $early_return;
		$binary_secret=self::normalize_totp_secret($secret);
		if($binary_secret===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed generating TOTP code because the secret is invalid", $S='warning');
			return false;
		}
		$period=max(1, $period);
		$digits=max(1, min(10, $digits));
		$timestamp=$timestamp ?? time();
		$counter=(int)floor($timestamp/$period);
		$counter_bytes=pack('N*', 0).pack('N*', $counter);
		$hash=hash_hmac('sha1', $counter_bytes, $binary_secret, true);
		$offset=ord(substr($hash, -1)) & 0x0F;
		$segment=substr($hash, $offset, 4);
		if($segment===false || strlen($segment)!==4){
			return false;
		}
		$value=unpack('N', $segment)[1] & 0x7FFFFFFF;
		$modulus=10 ** $digits;
		return str_pad((string)($value % $modulus), $digits, '0', STR_PAD_LEFT);
	}

	/**
	 * Verifies a numeric TOTP code across a configurable time window.
	 *
	 * Whitespace is ignored, non-numeric codes fail immediately, and matching is
	 * performed with `hash_equals()` across the current step plus the requested
	 * number of previous and future steps.
	 *
	 * @param string $secret Base32 or hexadecimal shared secret.
	 * @param string $code User-supplied TOTP code.
	 * @param int $window Number of periods to allow on either side of the timestamp.
	 * @param ?int $timestamp Unix timestamp to evaluate; null uses current time.
	 * @param int $period Step duration in seconds.
	 * @param int $digits Expected number of output digits.
	 * @return bool True when any code in the allowed window matches.
	 */
	public static function verify_totp(string $secret, string $code, int $window=1, ?int $timestamp=null, int $period=30, int $digits=6): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_ACCESS_VERIFY_TOTP",...func_get_args())) return $early_return;
		$code=preg_replace('/\s+/', '', trim($code));
		if($code==='' || preg_match('/^\d+$/', $code)!==1){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed verifying TOTP because the code format is invalid", $S='warning');
			return false;
		}
		$timestamp=$timestamp ?? time();
		$window=max(0, $window);
		for($offset=-$window; $offset<=$window; $offset++){
			$expected=self::totp_code($secret, $timestamp+($offset*$period), $period, $digits);
			if($expected!==false && hash_equals($expected, $code)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds an `otpauth://` enrollment URI for authenticator applications.
	 *
	 * The secret is normalized for QR payloads, issuer defaults to the public app
	 * name, and an empty account name or invalid secret returns false.
	 *
	 * @param string $secret Base32 or hexadecimal shared secret.
	 * @param string $account_name Account label shown by authenticator apps.
	 * @param ?string $issuer Optional issuer label; null uses application config.
	 * @return string|false TOTP provisioning URI, or false when enrollment data is invalid.
	 */
	public static function totp_uri(string $secret, string $account_name, ?string $issuer=null): string|false {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_ACCESS_TOTP_URI",...func_get_args())) return $early_return;
		$normalized_secret=strtoupper(trim($secret));
		$normalized_secret=str_replace([' ', '-', '='], '', $normalized_secret);
		if($normalized_secret==='' || self::normalize_totp_secret($secret)===false){
			return false;
		}
		$issuer=trim((string)($issuer ?? (DP_CORE_CFG['public_app_name'] ?? null) ?? core::get_config('public_app_name') ?? 'Dataphyre'));
		$account_name=trim($account_name);
		if($account_name===''){
			return false;
		}
		$label=$issuer!=='' ? $issuer.':'.$account_name : $account_name;
		$query=[
			'secret'=>$normalized_secret,
		];
		if($issuer!==''){
			$query['issuer']=$issuer;
		}
		return 'otpauth://totp/'.rawurlencode($label).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Renders a QR-code data URI for TOTP pairing.
	 *
	 * The URI is generated through `totp_uri()` first, so invalid secrets or
	 * account labels fail before the QR renderer is invoked.
	 *
	 * @param string $secret Base32 or hexadecimal shared secret.
	 * @param string $account_name Account label shown by authenticator apps.
	 * @param ?string $issuer Optional issuer label; null uses application config.
	 * @param int $size Requested QR image size in pixels.
	 * @return string|false SVG data URI for enrollment, or false when pairing data is invalid.
	 */
	public static function get_totp_pairing_image(string $secret, string $account_name, ?string $issuer=null, int $size=200): string|false {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_ACCESS_GET_TOTP_PAIRING_IMAGE",...func_get_args())) return $early_return;
		$uri=self::totp_uri($secret, $account_name, $issuer);
		if($uri===false){
			return false;
		}
		return access_qr_renderer::svg_data_uri($uri, $size);
	}

	/**
	  * Get the userid of a current user session
	  *
	  * @version 	1.0.0
	  *
	  * @return mixed		Userid if user is logged in otherwise false
	  */
	public static function userid(?string $auth_type=null) : bool|int|string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_USERID",...func_get_args())) return $early_return;
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('USERID', $auth_type, [])){
			return $delegated;
		}
		if($auth_type!=='session'){
			return self::unsupported_auth_type('USERID', $auth_type, false);
		}
		if(self::logged_in($auth_type)===true){
			return $_SESSION['dp_access']['userid'];
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed, user not logged-in");
		return false;
	}
	
	/**
	  * Search for clues identifying an user as a web crawler
	  *
	  * @version 	1.0.0
	  *
	  * @return bool		True if positive, false on negative
	  */
	public static function is_bot() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		static $cache=null;
		if($cache!==null)return $cache;
		if(null!==$early_return=core::dialback("CALL_ACCESS_IS_BOT",...func_get_args())) return $early_return;
		$user_agent=(string)($_SERVER['HTTP_USER_AGENT'] ?? (defined('REQUEST_USER_AGENT') ? REQUEST_USER_AGENT : ''));
		foreach((DP_ACCESS_CFG['botlist'] ?? []) as $bl){
			if(stripos(strtolower($user_agent), strtolower($bl))!==false){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is a bot");
				$cache=true;
				return true;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is not a bot");
		$cache=false;
		return false;
	}
	
	/**
	  * Search for clues identifying an user as using a mobile device
	  *
	  * @version 	1.0.0
	  *
	  * @return bool		True on success, false on failure
	  */
	public static function is_mobile() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		static $cache=null;
		if($cache!==null)return $cache;
		if(null!==$early_return=core::dialback("CALL_ACCESS_IS_MOBILE",...func_get_args())) return $early_return;
		$user_agent=(string)($_SERVER['HTTP_USER_AGENT'] ?? (defined('REQUEST_USER_AGENT') ? REQUEST_USER_AGENT : ''));
		$mobile_list=['Android', 'iOS', 'iPhone', 'iPad', 'Windows Phone', 'Opera Mini', 'IEMobile', 'Mobile'];
		foreach($mobile_list as $mobile){
			if(stripos($user_agent, $mobile)!==false){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is on a mobile device");
				$cache=true;
				return true;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is not using a mobile device");
		$cache=false;
		return false;
	}
	
	/**
	  * Delete session variables and destroy user session in database
	  *
	  * @version 	1.0.0
	  *
	  * @return bool		True on success, false on failure
	  */
	public static function disable_session(?string $auth_type=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_DISABLE_SESSION",...func_get_args())) return $early_return;
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('DISABLE_SESSION', $auth_type, [])){
			return (bool)$delegated;
		}
		if($auth_type!=='session'){
			return (bool)self::unsupported_auth_type('DISABLE_SESSION', $auth_type, false);
		}
		$cookie_name=self::get_auth_cookie_name($auth_type);
		if($cookie_name!==null && isset($_COOKIE[$cookie_name])){
			$dpid=$_COOKIE[$cookie_name];
			if(false!==sql_update(
				$L=DP_ACCESS_SESSIONS_TABLE_NAME, 
				$F=[
					"mysql"=>"active=0", 
					"postgresql"=>"active=false"
				],
				$P="WHERE id=?", 
				$V=array($dpid), 
				$CC=true
			)){
				unset($_SESSION['dp_access']['userid']);
				unset($_SESSION['dp_access']['dpid']);
				unset($_SESSION['dp_access']['auth_type']);
				$_SESSION['dp_access']['no_known_recoverable_session']=true;
				unset($_SESSION['dp_access']['last_valid_session']);
				setcookie($cookie_name, "", time()-3600, '/');
				setcookie("__Secure-DPID", "", time()-3600, '/');
				setcookie("__Secure-SID", "", time()-3600, '/');
			}
		}
		self::$current_auth_type=null;
		return true;
	}
	
	/**
	  * Delete session variables and destroy user session in database
	  *
	  * @version 	1.0.0
	  *
	  * @return bool		True on success, false on failure
	  */
	public static function disable_all_sessions_of_user(int $userid, ?string $auth_type=null) : bool {
		if(null!==$early_return=core::dialback("CALL_ACCESS_DISABLE_ALL_SESSIONS_OF_USER",...func_get_args())) return $early_return;
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('DISABLE_ALL_SESSIONS_OF_USER', $auth_type, [$userid])){
			return (bool)$delegated;
		}
		if($auth_type!=='session'){
			return (bool)self::unsupported_auth_type('DISABLE_ALL_SESSIONS_OF_USER', $auth_type, false);
		}
		if(false!==sql_update(
			$L=DP_ACCESS_SESSIONS_TABLE_NAME, 
			$F="active=?", 
			$P="WHERE userid=?", 
			$V=array(false, $userid), 
			$CC=true
		)){
			$_SESSION['dp_access']['no_known_recoverable_session']=true;
			return true;
		}
		return false;
	}

	/**
	 * Revokes every session for a user except the supplied current session id.
	 *
	 * Session guards update the configured sessions table in place. Delegated
	 * auth types may override the operation through the access dialback hook.
	 *
	 * @param int $userid User whose other sessions should be disabled.
	 * @param string $current_session_id Session identifier that must remain active.
	 * @param ?string $auth_type Optional guard override.
	 * @return bool True when revocation succeeds; false for blank current ids or failed updates.
	 */
	public static function disable_other_sessions_of_user(int $userid, string $current_session_id, ?string $auth_type=null) : bool {
		if(null!==$early_return=core::dialback("CALL_ACCESS_DISABLE_OTHER_SESSIONS_OF_USER",...func_get_args())) return $early_return;
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('DISABLE_OTHER_SESSIONS_OF_USER', $auth_type, [$userid, $current_session_id])){
			return (bool)$delegated;
		}
		if($auth_type!=='session'){
			return (bool)self::unsupported_auth_type('DISABLE_OTHER_SESSIONS_OF_USER', $auth_type, false);
		}
		$current_session_id=trim($current_session_id);
		if($current_session_id===''){
			return false;
		}
		if(false!==sql_update(
			$L=DP_ACCESS_SESSIONS_TABLE_NAME,
			$F="active=?",
			$P="WHERE userid=? AND id<>?",
			$V=array(false, $userid, $current_session_id),
			$CC=true
		)){
			return true;
		}
		return false;
	}
	
	/**
	 * Validates the active session cookie against session memory and storage.
	 *
	 * A recent positive validation may be reused for 30 seconds. Otherwise the
	 * DPID cookie must pass signature validation and match an active row for the
	 * current user agent and IP address; moved IP addresses are written back when
	 * the stored session still proves ownership.
	 *
	 * @param bool $cache Whether to trust the recent validation timestamp in session memory.
	 * @param ?string $auth_type Optional guard override.
	 * @return bool True when the request has a valid active session for the guard.
	 */
	public static function validate_session(bool $cache=true, ?string $auth_type=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_VALIDATE_SESSION",...func_get_args())) return $early_return;
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('VALIDATE_SESSION', $auth_type, [$cache])){
			return (bool)$delegated;
		}
		if($auth_type!=='session'){
			return (bool)self::unsupported_auth_type('VALIDATE_SESSION', $auth_type, false);
		}
		if(
			$cache===true
			&& isset($_SESSION['dp_access']['last_valid_session'])
			&& (!isset($_SESSION['dp_access']['auth_type']) || self::normalize_auth_type($_SESSION['dp_access']['auth_type'])===$auth_type)
		){
			if($_SESSION['dp_access']['last_valid_session']>strtotime("-30 seconds")){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Session was known as valid less than 30 seconds ago");
				return true;
			}
		}
		$cookie_name=self::get_auth_cookie_name($auth_type);
		if($cookie_name!==null && isset($_COOKIE[$cookie_name])){
			$dpid=$_COOKIE[$cookie_name];
			if(!empty($_SESSION['dp_access']['userid']) && !empty($_SESSION['dp_access']['dpid'])){
				if(self::validate_id($dpid, $auth_type)){
					if($_SESSION['dp_access']['ip_address']!==REQUEST_IP_ADDRESS){
						sql_update(
							$L=DP_ACCESS_SESSIONS_TABLE_NAME, 
							$F="ipaddress=?", 
							$P=[
								"mysql"=>"WHERE id=? AND userid=? AND active=1 AND useragent=? AND ipaddress=?", 
								"postgresql"=>"WHERE id=? AND userid=? AND active=true AND useragent=? AND ipaddress=?"
							],
							$V=array(REQUEST_IP_ADDRESS,$dpid,$_SESSION['dp_access']['userid'],REQUEST_USER_AGENT,$_SESSION['dp_access']['ip_address']), 
							$CC=true
						);
					}
					if(false!==$row=sql_select(
						$S="*", 
						$L=DP_ACCESS_SESSIONS_TABLE_NAME, 
						$P=[
							"mysql"=>"WHERE id=? AND userid=? AND active=1 AND useragent=? AND ipaddress=?", 
							"postgresql"=>"WHERE id=? AND userid=? AND active=true AND useragent=? AND ipaddress=?"
						],
						$V=array($dpid, $_SESSION['dp_access']['userid'], REQUEST_USER_AGENT, REQUEST_IP_ADDRESS), 
						$F=false, 
						$C=false
					)){
						if($row['date']>strtotime('-7 days') && $row['keepalive']==true || $row['date']>strtotime('-30 minutes')){
							$_SESSION['dp_access']['ip_address']=REQUEST_IP_ADDRESS;
							$_SESSION['dp_access']['last_valid_session']=time();
							$_SESSION['dp_access']['auth_type']=$auth_type;
							self::mark_auth_type($auth_type);
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Session is valid");
							return true;
						}
					}
				}
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="No session");
		return false;
	}
	
	/**
	  * Search for an active user session using the dataphyre id, ipaddress and useragent
	  *
	  * @version 	1.0.0
	  *
	  * @return bool		 True on success, false on failure
	  */
	public static function recover_session(?string $auth_type=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_RECOVER_SESSION",...func_get_args())) return $early_return;
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('RECOVER_SESSION', $auth_type, [])){
			return (bool)$delegated;
		}
		if($auth_type!=='session'){
			return (bool)self::unsupported_auth_type('RECOVER_SESSION', $auth_type, false);
		}
		if(!isset($_SESSION['dp_access']['no_known_recoverable_session'])){
			$cookie_name=self::get_auth_cookie_name($auth_type);
			if($cookie_name!==null && isset($_COOKIE[$cookie_name])){
				$dpid=$_COOKIE[$cookie_name];
				if(self::validate_id($dpid, $auth_type)){
					if(!isset($_SESSION['dp_access']['dpid']) || !isset($_SESSION['dp_access']['userid'])){
						if(false!==$row=sql_select(
							$S="*", 
							$L=DP_ACCESS_SESSIONS_TABLE_NAME, 
							$P=[
								"mysql"=>"WHERE id=? AND active=1 AND keepalive=1 AND useragent=? AND ipaddress=?", 
								"postgresql"=>"WHERE id=? AND active=true AND keepalive=true AND useragent=? AND ipaddress=?"
							],
							$V=array($dpid, REQUEST_USER_AGENT, REQUEST_IP_ADDRESS), 
							$F=false, 
							$C=false
						)){
							if($row['date']>strtotime('-7 days') && $row['keepalive']==true || $row['date']>strtotime('-30 minutes')){
								$_SESSION['dp_access']['userid']=$row['userid'];
								$_SESSION['dp_access']['dpid']=$row['id'];
								$_SESSION['dp_access']['ip_address']=REQUEST_IP_ADDRESS;
								$_SESSION['dp_access']['auth_type']=$auth_type;
								self::mark_auth_type($auth_type);
								return true;
							}
						}
					}
				}
				self::disable_session($auth_type);
			}
		}
		$_SESSION['dp_access']['no_known_recoverable_session']=true;
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="No session to recover");
		return false;
	}
	
	/**
	  * Helper function to verify if an user is logged in or not
	  *
	  * @version 	1.0.0
	  *
	  * @return bool		True on positive, false on negative
	  */
	public static function logged_in(?string $auth_type=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_LOGGED_IN",...func_get_args())) return $early_return;
		$auth_type=self::resolve_auth_type($auth_type);
		if(null!==$delegated=self::delegate_auth_type('LOGGED_IN', $auth_type, [])){
			return (bool)$delegated;
		}
		if($auth_type!=='session'){
			return (bool)self::unsupported_auth_type('LOGGED_IN', $auth_type, false);
		}
		if(isset($_SESSION)){
			if(
				(!isset($_SESSION['dp_access']['auth_type']) || self::normalize_auth_type($_SESSION['dp_access']['auth_type'])===$auth_type)
				&& !empty($_SESSION['dp_access']['userid'])
				&& !empty($_SESSION['dp_access']['dpid'])
			){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is logged in");
				return true;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User isn't logged in");
		return false;
	}
	
	/**
	  * Verify if content can be displayed to the user
	  *
	  * @version 	1.0.0
	  *
	  * @param bool $session_required		If user must be logged in to display the page
	  * @param bool $must_no_session		If user must not be logged in to display the page
	  * @param bool $prevent_mobile		If user must not be using a mobile device to display the page
	  * @param bool $prevent_robot			If user must not be a robot to display the page
	  * @return bool		True on success, false on failure
	  */
	public static function access(bool $session_required=true, bool $must_no_session=false, bool $prevent_mobile=false, bool $prevent_robot=false) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_ACCESS",...func_get_args()))return $early_return;
		$error=function(string $error_string='Unknown error', int $response_code=403){
			if(defined('RUN_MODE') && RUN_MODE==='diagnostic'){
				return false;
			}
			ob_end_clean();
			flush();
			http_response_code($response_code);
			header('Content-Type:text/html; charset=UTF-8');
			header('Server: Dataphyre');
			echo'<!DOCTYPE html>';
			echo'<html>';
			echo'<head>';
			echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
			echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
			echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
			echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
			echo'<style>'.minified_font().'</style>';
			echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
			echo'</head>';
			echo'<body>';
			echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
			echo'<h3>'.$error_string.'</h3>';
			exit();
		};
		if($prevent_robot===true && self::is_bot()===true){
			if(!(defined('RUN_MODE') && RUN_MODE==='diagnostic') && !empty(DP_ACCESS_CFG['requires_app_redirect'])){
				header('Location: '.(string)(DP_ACCESS_CFG['robot_redirect'] ?? ''));
				exit();
			}
			return $error('This page cannot be selfed by robots.', 403);
		}
		else
		{
			if($prevent_mobile===true && self::is_mobile()===true){
				if(!(defined('RUN_MODE') && RUN_MODE==='diagnostic') && !empty(DP_ACCESS_CFG['requires_app_redirect'])){
					header('Location: '.(string)DP_ACCESS_CFG['requires_app_redirect']);
					exit();
				}
				return $error('This page cannot be selfed by mobile devices without an application.', 403);
			}
			else
			{
				if($must_no_session===true){
					if(self::logged_in()===true){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can't be loaded as user is logged in, redirecting to homepage");
						if(!(defined('RUN_MODE') && RUN_MODE==='diagnostic') && !empty(DP_ACCESS_CFG['must_no_session_redirect'])){
							header('Location: '.(string)DP_ACCESS_CFG['must_no_session_redirect']);
							exit();
						}
						return $error('This page requires you to not have an active session.', 401);
					}
					else
					{
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, not logged in");
						return true;
					}
				}
				else
				{	
					if($session_required===false){
						if(self::logged_in()===false){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, not logged in");
							return true;
						}
						else
						{
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, logged in");
							return true;
						}
					}
					else
					{
						if(self::logged_in()===false){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User needs to be logged in, redirecting to login page");
							if(!(defined('RUN_MODE') && RUN_MODE==='diagnostic') && !empty(DP_ACCESS_CFG['require_session_redirect'])){
								header('Location: '.(string)DP_ACCESS_CFG['require_session_redirect'].'?redir='.rtrim(base64_encode(ltrim($_SERVER["REQUEST_URI"], "/")), '='));
								exit();
							}
							return $error('This page requires authentication.', 401);
						}
						else
						{
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, logged in");
							return true;
						}
					}
				}
			}
		}
	}
	
}
