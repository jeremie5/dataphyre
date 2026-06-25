<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Access\Exceptions\OAuthException;

/**
 * Fetches and caches OpenID Provider discovery metadata.
 *
 * Discovery accepts an explicit configuration URL, a discovery URL alias, or an
 * issuer with discover enabled. Results are cached in-process by URL so repeated
 * OAuth flows do not refetch provider metadata during the same request.
 */
final class OpenIdDiscovery {

	private static array $cache=[];

	/**
	 * Resolves, fetches, validates, and caches OpenID configuration metadata.
	 *
	 * An empty array means discovery was not configured. HTTP failures and
	 * invalid JSON are treated as OAuth failures because login cannot safely
	 * continue without trusted provider endpoints.
	 *
	 * @param array<string, mixed> $config OAuth provider configuration.
	 * @return array<string, mixed> OpenID configuration document, or an empty array when discovery is disabled.
	 * @throws OAuthException When metadata cannot be fetched or decoded.
	 */
	public static function fetch(array $config): array {
		$url=self::url($config);
		if($url===null){
			return [];
		}
		if(isset(self::$cache[$url])){
			return self::$cache[$url];
		}
		$http=new HttpClient(is_array($config['http'] ?? null) ? $config['http'] : []);
		$response=$http->send('GET', $url);
		$status=(int)($response['status'] ?? 0);
		if($status<200 || $status>=300){
			throw new OAuthException('Failed to fetch OpenID configuration from '.$url);
		}
		$decoded=json_decode((string)($response['body'] ?? ''), true);
		if(!is_array($decoded)){
			throw new OAuthException('OpenID configuration response is invalid JSON.');
		}
		return self::$cache[$url]=$decoded;
	}

	/**
	 * Resolves the discovery endpoint URL from provider configuration.
	 *
	 * @param array<string, mixed> $config OAuth provider configuration.
	 * @return ?string Discovery URL, or null when discovery is disabled or incomplete.
	 */
	private static function url(array $config): ?string {
		foreach(['openid_configuration_url', 'discovery_url'] as $key){
			$value=trim((string)($config[$key] ?? ''));
			if($value!==''){
				return $value;
			}
		}
		if((bool)($config['discover'] ?? false)===false){
			return null;
		}
		$issuer=trim((string)($config['issuer'] ?? ''));
		if($issuer===''){
			return null;
		}
		return rtrim($issuer, '/').'/.well-known/openid-configuration';
	}
}
