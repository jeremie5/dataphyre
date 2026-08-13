<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre\access;

if(\function_exists('dp_define_module_config')){
	\dp_define_module_config('access', 'DP_ACCESS_CFG');
}

/** Audits Access configuration, runtime state, and persistence prerequisites. */
final class diagnostic {
	/**
	 * Runtime observations are injectable so diagnostics can be proven without
	 * mutating process constants, sessions, extensions, or database transports.
	 *
	 * @param array<string,mixed> $observations
	 * @return list<array<string,mixed>>
	 */
	public static function tests(array $observations=[]): array {
		$config=array_key_exists('config', $observations) && is_array($observations['config'])
			? $observations['config']
			: (defined('DP_ACCESS_CFG') && is_array(DP_ACCESS_CFG) ? DP_ACCESS_CFG : []);
		$required=is_callable($observations['module_required'] ?? null) ? $observations['module_required'] : '\\dp_module_required';
		$extension_loaded=is_callable($observations['extension_loaded'] ?? null) ? $observations['extension_loaded'] : 'extension_loaded';
		$clock=is_callable($observations['clock'] ?? null) ? $observations['clock'] : 'time';
		$php_version=(string)($observations['php_version'] ?? PHP_VERSION);
		$runtime_available=array_key_exists('access_runtime_available', $observations)
			? (bool)$observations['access_runtime_available']
			: class_exists('\\dataphyre\\access', false);
		$sql_query=array_key_exists('sql_query', $observations)
			? $observations['sql_query']
			: (\function_exists('sql_query') ? '\\sql_query' : null);
		$session_active=array_key_exists('session_active', $observations)
			? (bool)$observations['session_active']
			: session_status()===PHP_SESSION_ACTIVE;
		$session=is_array($observations['session'] ?? null) ? $observations['session'] : ($_SESSION ?? []);
		$dpid_defined=array_key_exists('dpid_defined', $observations) ? (bool)$observations['dpid_defined'] : defined('DPID');
		$dpid=array_key_exists('dpid', $observations) ? $observations['dpid'] : ($dpid_defined ? DPID : null);
		$verbose=[];

		if(is_callable($required)){
			$required('access', 'sql');
			$required('access', 'firewall');
		}
		if(version_compare($php_version, $minimum='8.1.0')<0){
			$verbose[]=self::error('PHP version '.$minimum.' or higher is required.', $clock);
		}
		foreach(['session','filter','hash','openssl','json','pcre','mbstring'] as $extension){
			if(!$extension_loaded($extension)){
				$verbose[]=self::error("PHP extension '{$extension}' is not loaded.", $clock);
			}
		}
		if(!$runtime_available){
			$verbose[]=self::warning('Access runtime checks were skipped because the Access module entrypoint was not loaded for this embedded diagnostic scan.', $clock);
		}
		if(isset($config['sessions_cookie_name']) && $runtime_available){
			$cookie_name=$observations['session_cookie_name'] ?? static fn(): string=>\dataphyre\access::get_session_cookie_name();
			$cookie_name=is_callable($cookie_name) ? $cookie_name() : $cookie_name;
			if($cookie_name!=='__Host-'.(string)$config['sessions_cookie_name']){
				$verbose[]=self::error('Session cookie name does not match configuration.', $clock);
			}
		}
		if(trim((string)($config['default_auth_type'] ?? ''))===''){
			$verbose[]=self::error('Missing default auth type in configuration.', $clock);
		}
		$auth_types=$config['auth_types'] ?? $config['enabled_auth_types'] ?? [];
		if(!is_array($auth_types) || $auth_types===[]){
			$verbose[]=self::error('Enabled auth types are missing or invalid.', $clock);
		}
		$framework_config=is_array($config['framework'] ?? null) ? $config['framework'] : [];
		$oauth_config=is_array($framework_config['oauth'] ?? null) ? $framework_config['oauth'] : [];
		$oauth_providers=$oauth_config['providers'] ?? null;
		if($oauth_providers!==null && !is_array($oauth_providers)){
			$verbose[]=self::error('OAuth providers configuration must be an array.', $clock);
		}
		if(is_array($oauth_providers)){
			foreach($oauth_providers as $provider_name=>$provider_config){
				if(!is_array($provider_config)){
					$verbose[]=self::error("OAuth provider '{$provider_name}' configuration must be an array.", $clock);
					continue;
				}
				if(empty($provider_config['client_id'])){
					$verbose[]=self::error("OAuth provider '{$provider_name}' is missing 'client_id'.", $clock);
				}
				$has_discovery=!empty($provider_config['discover'])
					|| !empty($provider_config['issuer'])
					|| !empty($provider_config['discovery_url'])
					|| !empty($provider_config['openid_configuration_url']);
				if(!$has_discovery && empty($provider_config['authorization_url'])){
					$verbose[]=self::error("OAuth provider '{$provider_name}' is missing 'authorization_url'.", $clock);
				}
				if(!$has_discovery && empty($provider_config['token_url'])){
					$verbose[]=self::error("OAuth provider '{$provider_name}' is missing 'token_url'.", $clock);
				}
			}
		}
		if($session_active && isset($session['dp_access'])){
			$access_session=is_array($session['dp_access']) ? $session['dp_access'] : [];
			if(!isset($access_session['dpid']) || !is_string($access_session['dpid'])){
				$verbose[]=self::error('dp_access entry in session missing or malformed (dpid).', $clock);
			}
			if(!isset($access_session['userid'])){
				$verbose[]=self::error('dp_access entry in session missing userid.', $clock);
			}
			if(isset($access_session['auth_type']) && !is_string($access_session['auth_type'])){
				$verbose[]=self::error('dp_access entry in session has malformed auth_type.', $clock);
			}
		}
		if($dpid_defined && is_string($dpid) && preg_match('/^DPID_[A-Za-z0-9\-_]{43}_[a-f0-9]{8}$/', $dpid)!==1){
			$verbose[]=self::error('DPID constant is defined but does not match expected format.', $clock);
		}
		$table=trim((string)($config['sessions_table_name'] ?? ''));
		if($table===''){
			$verbose[]=self::error('Missing session table name in configuration.', $clock);
		}
		else
		{
			if(!is_callable($sql_query)){
				$verbose[]=self::warning('SQL-backed Access table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.', $clock);
			}
			else
			{
				$sql_query(self::schemas($table));
			}
		}
		if($runtime_available){
			$create_secret=$observations['create_totp_secret'] ?? static fn(): string|false=>\dataphyre\access::create_totp_secret();
			$totp_secret=is_callable($create_secret) ? $create_secret() : false;
			if($totp_secret===false){
				$verbose[]=self::error('Unable to generate a TOTP secret.', $clock);
			}
			else
			{
				$create_image=$observations['totp_pairing_image'] ?? static fn(string $secret): string|false=>\dataphyre\access::get_totp_pairing_image($secret, 'diagnostic@example.com');
				$totp_image=is_callable($create_image) ? $create_image($totp_secret) : false;
				if(!is_string($totp_image) || !str_starts_with($totp_image, 'data:image/svg+xml;base64,')){
					$verbose[]=self::error('TOTP pairing image generation is not returning a local SVG data URI.', $clock);
				}
			}
		}
		if(array_key_exists('publish', $observations)){
			if(is_callable($observations['publish'])){
				$observations['publish']($verbose);
			}
		}
		elseif(class_exists('\\dataphyre\\dpanel'))
		{
			\dataphyre\dpanel::add_verbose($verbose);
		}
		return $verbose;
	}

	/** @return array<string,mixed> */
	private static function error(string $message, callable $clock): array {
		return ['module'=>'access', 'error'=>$message, 'time'=>(int)$clock()];
	}

	/** @return array<string,mixed> */
	private static function warning(string $message, callable $clock): array {
		return ['module'=>'access', 'level'=>'warning', 'message'=>$message, 'time'=>(int)$clock()];
	}

	/** @return array{mysql:string,postgresql:string,sqlite:string} */
	private static function schemas(string $table): array {
		$index_table=str_replace('.', '_', $table);
		return [
			'mysql'=>"CREATE TABLE IF NOT EXISTS `$table` (`id` VARCHAR(64) PRIMARY KEY, `userid` BIGINT UNSIGNED NOT NULL, `useragent` TEXT NOT NULL, `ipaddress` TEXT NOT NULL, `keepalive` BOOLEAN NOT NULL DEFAULT FALSE, `active` BOOLEAN NOT NULL DEFAULT TRUE, `date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; CREATE INDEX `idx_{$table}_userid_active` ON `$table` (`userid`, `active`); CREATE INDEX `idx_{$table}_full_lookup` ON `$table` (`id`, `userid`, `useragent`(255), `ipaddress`, `active`); CREATE INDEX `idx_{$table}_date` ON `$table` (`date`);",
			'postgresql'=>"CREATE TABLE IF NOT EXISTS \"{$table}\" (id TEXT PRIMARY KEY, userid BIGINT NOT NULL, useragent TEXT NOT NULL, ipaddress TEXT NOT NULL, keepalive BOOLEAN NOT NULL DEFAULT FALSE, active BOOLEAN NOT NULL DEFAULT TRUE, date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP); CREATE INDEX IF NOT EXISTS idx_{$index_table}_userid_active ON \"{$table}\" (userid, active); CREATE INDEX IF NOT EXISTS idx_{$index_table}_full_lookup ON \"{$table}\" (id, userid, useragent, ipaddress, active); CREATE INDEX IF NOT EXISTS idx_{$index_table}_date ON \"{$table}\" (date);",
			'sqlite'=>"CREATE TABLE IF NOT EXISTS \"$table\" (id TEXT PRIMARY KEY, userid INTEGER NOT NULL, useragent TEXT NOT NULL, ipaddress TEXT NOT NULL, keepalive BOOLEAN NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT 1, date TEXT NOT NULL DEFAULT (datetime('now'))); CREATE INDEX IF NOT EXISTS idx_{$table}_userid_active ON \"$table\" (userid, active); CREATE INDEX IF NOT EXISTS idx_{$table}_full_lookup ON \"$table\" (id, userid, useragent, ipaddress, active); CREATE INDEX IF NOT EXISTS idx_{$table}_date ON \"$table\" (date);",
		];
	}
}

if(!defined('DATAPHYRE_ACCESS_DIAGNOSTIC_NO_DISPATCH')){
	diagnostic::tests();
}
