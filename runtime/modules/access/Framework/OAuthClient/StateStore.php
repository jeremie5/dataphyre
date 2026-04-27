<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

final class StateStore {

	private string $provider;
	private int $ttl;

	public function __construct(string $provider, int $ttl=600){
		$this->provider=strtolower(trim($provider));
		$this->ttl=max(60, $ttl);
	}

	public function put(string $state, array $payload): void {
		$this->start_session();
		$this->purge();
		$_SESSION['dp_access']['oauth']['states'][$this->provider][$state]=[
			'stored_at'=>time(),
			'payload'=>$payload,
		];
	}

	public function pull(string $state): ?array {
		$this->start_session();
		$this->purge();
		$entry=$_SESSION['dp_access']['oauth']['states'][$this->provider][$state] ?? null;
		if(!is_array($entry)){
			return null;
		}
		unset($_SESSION['dp_access']['oauth']['states'][$this->provider][$state]);
		return is_array($entry['payload'] ?? null) ? $entry['payload'] : null;
	}

	private function purge(): void {
		$now=time();
		if(!isset($_SESSION['dp_access']['oauth']['states']) || !is_array($_SESSION['dp_access']['oauth']['states'])){
			return;
		}
		foreach($_SESSION['dp_access']['oauth']['states'] as $provider=>$provider_states){
			if(!is_array($provider_states)){
				unset($_SESSION['dp_access']['oauth']['states'][$provider]);
				continue;
			}
			foreach($provider_states as $state=>$entry){
				$stored_at=(int)($entry['stored_at'] ?? 0);
				if($stored_at<=0 || ($stored_at+$this->ttl)<$now){
					unset($_SESSION['dp_access']['oauth']['states'][$provider][$state]);
				}
			}
			if($_SESSION['dp_access']['oauth']['states'][$provider]===[]){
				unset($_SESSION['dp_access']['oauth']['states'][$provider]);
			}
		}
	}

	private function start_session(): void {
		if(session_status()!==PHP_SESSION_ACTIVE){
			session_start();
		}
	}
}
