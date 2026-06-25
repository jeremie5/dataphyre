<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\access;

\dp_define_module_config('access', 'DP_ACCESS_CFG');

\dataphyre\access\diagnostic::tests();

/**
 * Audits Access module authentication configuration and runtime dependencies.
 *
 * The diagnostic checks required modules, PHP extensions, OAuth provider
 * metadata, enabled auth types, session state shape, DPID format, SQL-backed
 * session storage, and TOTP generation without requiring the full runtime to be
 * present during embedded tooling scans.
 */
class diagnostic{

	/**
	 * Collects Access health findings and bootstraps session storage when possible.
	 *
	 * SQL and Access runtime checks degrade to warnings when diagnostic files are
	 * loaded in isolation, preserving documentation and panel scans while still
	 * surfacing missing production capabilities.
	 *
	 * @return void Findings are appended to dpanel verbose output.
	 */
	public static function tests(): void {
		$verbose=[];
		$framework_config=is_array(DP_ACCESS_CFG['framework'] ?? null) ? DP_ACCESS_CFG['framework'] : [];
		$oauth_config=is_array($framework_config['oauth'] ?? null) ? $framework_config['oauth'] : [];
		$oauth_providers=$oauth_config['providers'] ?? null;
		$auth_types=DP_ACCESS_CFG['auth_types'] ?? DP_ACCESS_CFG['enabled_auth_types'] ?? [];
		$sql_helpers_available=\function_exists('sql_query');
		$access_runtime_available=\class_exists('\dataphyre\access', false);
		\dp_module_required('access', 'sql');
		\dp_module_required('access', 'firewall');
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'access', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		$required_extensions=[
			'session',
			'filter',
			'hash',
			'openssl',
			'json',
			'pcre',
			'mbstring',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'access', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		if($access_runtime_available!==true){
			$verbose[]=[
				'module'=>'access',
				'level'=>'warning',
				'message'=>'Access runtime checks were skipped because the Access module entrypoint was not loaded for this embedded diagnostic scan.',
				'time'=>time(),
			];
		}
		if(isset(DP_ACCESS_CFG['sessions_cookie_name']) && $access_runtime_available===true){
			$expected = '__Secure-' . DP_ACCESS_CFG['sessions_cookie_name'];
			if(\dataphyre\access::get_session_cookie_name() !== $expected){
				$verbose[]=['module'=>'access', 'error'=>'Session cookie name does not match configuration.', 'time'=>time()];
			}
		}
		if(trim((string)(DP_ACCESS_CFG['default_auth_type'] ?? ''))===''){
			$verbose[]=['module'=>'access', 'error'=>'Missing default auth type in configuration.', 'time'=>time()];
		}
		if(!is_array($auth_types) || $auth_types===[]){
			$verbose[]=['module'=>'access', 'error'=>'Enabled auth types are missing or invalid.', 'time'=>time()];
		}
		if($oauth_providers!==null && !is_array($oauth_providers)){
			$verbose[]=['module'=>'access', 'error'=>'OAuth providers configuration must be an array.', 'time'=>time()];
		}
		if(is_array($oauth_providers)){
			foreach($oauth_providers as $provider_name=>$provider_config){
				if(!is_array($provider_config)){
					$verbose[]=['module'=>'access', 'error'=>"OAuth provider '{$provider_name}' configuration must be an array.", 'time'=>time()];
					continue;
				}
				if(empty($provider_config['client_id'])){
					$verbose[]=['module'=>'access', 'error'=>"OAuth provider '{$provider_name}' is missing 'client_id'.", 'time'=>time()];
				}
				$has_discovery=!empty($provider_config['discover'])
					|| !empty($provider_config['issuer'])
					|| !empty($provider_config['discovery_url'])
					|| !empty($provider_config['openid_configuration_url']);
				if($has_discovery===false && empty($provider_config['authorization_url'])){
					$verbose[]=['module'=>'access', 'error'=>"OAuth provider '{$provider_name}' is missing 'authorization_url'.", 'time'=>time()];
				}
				if($has_discovery===false && empty($provider_config['token_url'])){
					$verbose[]=['module'=>'access', 'error'=>"OAuth provider '{$provider_name}' is missing 'token_url'.", 'time'=>time()];
				}
			}
		}
		if(session_status() === PHP_SESSION_ACTIVE){
			if(isset($_SESSION['dp_access'])){
				if(!isset($_SESSION['dp_access']['dpid']) || !is_string($_SESSION['dp_access']['dpid'])){
					$verbose[]=['module'=>'access', 'error'=>'dp_access entry in session missing or malformed (dpid).', 'time'=>time()];
				}
				if(!isset($_SESSION['dp_access']['userid'])){
					$verbose[]=['module'=>'access', 'error'=>'dp_access entry in session missing userid.', 'time'=>time()];
				}
				if(isset($_SESSION['dp_access']['auth_type']) && !is_string($_SESSION['dp_access']['auth_type'])){
					$verbose[]=['module'=>'access', 'error'=>'dp_access entry in session has malformed auth_type.', 'time'=>time()];
				}
			}
		}
		if(defined('DPID') && is_string(DPID)){
			if(!preg_match('/^DPID_[A-Za-z0-9\-_]{43}_[a-f0-9]{8}$/', DPID)){
				$verbose[]=['module'=>'access', 'error'=>'DPID constant is defined but does not match expected format.', 'time'=>time()];
			}
		}
		if(trim((string)(DP_ACCESS_CFG['sessions_table_name'] ?? ''))===''){
			$verbose[]=['module'=>'access', 'error'=>'Missing session table name in configuration.', 'time'=>time()];
		}
		if(!empty($table=(string)(DP_ACCESS_CFG['sessions_table_name'] ?? ''))){
			if($sql_helpers_available!==true){
				$verbose[]=[
					'module'=>'access',
					'level'=>'warning',
					'message'=>'SQL-backed Access table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
					'time'=>time(),
				];
			}
			else
			{
				\sql_query(
					$Q=[
						"mysql"=>"
							CREATE TABLE IF NOT EXISTS `$table` (
								`id` VARCHAR(64) PRIMARY KEY,
								`userid` BIGINT UNSIGNED NOT NULL,
								`useragent` TEXT NOT NULL,
								`ipaddress` TEXT NOT NULL,
								`keepalive` BOOLEAN NOT NULL DEFAULT FALSE,
								`active` BOOLEAN NOT NULL DEFAULT TRUE,
								`date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
							) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
							CREATE INDEX `idx_{$table}_userid_active` ON `$table` (`userid`, `active`);
							CREATE INDEX `idx_{$table}_full_lookup` ON `$table` (`id`, `userid`, `useragent`(255), `ipaddress`, `active`);
							CREATE INDEX `idx_{$table}_date` ON `$table` (`date`);
						",
						"postgresql" => "
							CREATE TABLE IF NOT EXISTS \"{$table}\" (
								id TEXT PRIMARY KEY,
								userid BIGINT NOT NULL,
								useragent TEXT NOT NULL,
								ipaddress TEXT NOT NULL,
								keepalive BOOLEAN NOT NULL DEFAULT FALSE,
								active BOOLEAN NOT NULL DEFAULT TRUE,
								date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
							);
							CREATE INDEX IF NOT EXISTS idx_" . str_replace('.', '_', $table) . "_userid_active ON \"{$table}\" (userid, active);
							CREATE INDEX IF NOT EXISTS idx_" . str_replace('.', '_', $table) . "_full_lookup ON \"{$table}\" (id, userid, useragent, ipaddress, active);
							CREATE INDEX IF NOT EXISTS idx_" . str_replace('.', '_', $table) . "_date ON \"{$table}\" (date);
						",
						"sqlite"=> "
							CREATE TABLE IF NOT EXISTS \"$table\" (
								id TEXT PRIMARY KEY,
								userid INTEGER NOT NULL,
								useragent TEXT NOT NULL,
								ipaddress TEXT NOT NULL,
								keepalive BOOLEAN NOT NULL DEFAULT 0,
								active BOOLEAN NOT NULL DEFAULT 1,
								date TEXT NOT NULL DEFAULT (datetime('now'))
							);
							CREATE INDEX IF NOT EXISTS idx_{$table}_userid_active ON \"$table\" (userid, active);
							CREATE INDEX IF NOT EXISTS idx_{$table}_full_lookup ON \"$table\" (id, userid, useragent, ipaddress, active);
							CREATE INDEX IF NOT EXISTS idx_{$table}_date ON \"$table\" (date);
						"
					]
				);
			}
		}
		if($access_runtime_available!==true){
			\dataphyre\dpanel::add_verbose($verbose);
			return;
		}
		$totp_secret=\dataphyre\access::create_totp_secret();
		if($totp_secret===false){
			$verbose[]=['module'=>'access', 'error'=>'Unable to generate a TOTP secret.', 'time'=>time()];
		}
		else
		{
			$totp_image=\dataphyre\access::get_totp_pairing_image($totp_secret, 'diagnostic@example.com');
			if(!is_string($totp_image) || str_starts_with($totp_image, 'data:image/svg+xml;base64,')!==true){
				$verbose[]=['module'=>'access', 'error'=>'TOTP pairing image generation is not returning a local SVG data URI.', 'time'=>time()];
			}
		}
        \dataphyre\dpanel::add_verbose($verbose);
    }

}
