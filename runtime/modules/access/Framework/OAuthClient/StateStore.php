<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

/**
 * Stores one-time OAuth state payloads in the PHP session.
 *
 * State values are namespaced by provider, timestamped, and removed on successful pull so OAuth
 * callback validation has replay protection. Expired entries are purged opportunistically on
 * every read/write.
 */
final class StateStore {

	/**
	 * Lowercase provider namespace for stored state values.
	 */
	private string $provider;

	/**
	 * State time-to-live in seconds, clamped to at least sixty seconds.
	 */
	private int $ttl;

	/**
	 * Creates a provider-scoped OAuth state store.
	 *
	 * @param string $provider OAuth provider identifier.
	 * @param int $ttl State lifetime in seconds.
	 */
	public function __construct(string $provider, int $ttl=600){
		$this->provider=strtolower(trim($provider));
		$this->ttl=max(60, $ttl);
	}

	/**
	 * Stores an OAuth state payload for later one-time retrieval.
	 *
	 * @param string $state Opaque OAuth state token.
	 * @param array<string, mixed> $payload Provider callback data associated with the state.
	 * @return void
	 */
	public function put(string $state, array $payload): void {
		$this->startSession();
		$this->purge();
		$_SESSION['dp_access']['oauth']['states'][$this->provider][$state]=[
			'stored_at'=>time(),
			'payload'=>$payload,
		];
	}

	/**
	 * Retrieves and removes an OAuth state payload.
	 *
	 * @param string $state Opaque OAuth state token.
	 * @return array<string, mixed>|null Stored payload, or null when the state is missing, expired, or malformed.
	 */
	public function pull(string $state): ?array {
		$this->startSession();
		$this->purge();
		$entry=$_SESSION['dp_access']['oauth']['states'][$this->provider][$state] ?? null;
		if(!is_array($entry)){
			return null;
		}
		unset($_SESSION['dp_access']['oauth']['states'][$this->provider][$state]);
		return is_array($entry['payload'] ?? null) ? $entry['payload'] : null;
	}

	/**
	 * Removes expired or malformed state entries across all providers.
	 *
	 * @return void
	 */
	private function purge(): void {
		$now=time();
		if(!isset($_SESSION['dp_access']['oauth']['states']) || !is_array($_SESSION['dp_access']['oauth']['states'])){
			return;
		}
		foreach($_SESSION['dp_access']['oauth']['states'] as $provider=>$providerStates){
			if(!is_array($providerStates)){
				unset($_SESSION['dp_access']['oauth']['states'][$provider]);
				continue;
			}
			foreach($providerStates as $state=>$entry){
				$storedAt=(int)($entry['stored_at'] ?? 0);
				if($storedAt<=0 || ($storedAt+$this->ttl)<$now){
					unset($_SESSION['dp_access']['oauth']['states'][$provider][$state]);
				}
			}
			if($_SESSION['dp_access']['oauth']['states'][$provider]===[]){
				unset($_SESSION['dp_access']['oauth']['states'][$provider]);
			}
		}
	}

	/**
	 * Starts the PHP session before reading or writing state.
	 *
	 * @return void
	 */
	private function startSession(): void {
		if(session_status()!==PHP_SESSION_ACTIVE){
			session_start();
		}
	}
}
