<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Process-local replay guard intended for tests and single-process deployments. */
final class PanelInMemoryNavigationReplayGuard implements PanelNavigationReplayGuard, \JsonSerializable {
	/** @var array<string,int> */
	private array $seen=[];

	public function accept(string $nonce, int $expiresAt, array $context=[]): bool {
		$now=(int)($context['now'] ?? time());
		foreach($this->seen as $stored=>$expiry){
			if($expiry<$now){ unset($this->seen[$stored]); }
		}
		$key=hash('sha256', $nonce);
		if(isset($this->seen[$key])){ return false; }
		$this->seen[$key]=$expiresAt;
		return true;
	}

	public function jsonSerialize(): array {
		return ['type'=>'panel_navigation_replay_guard','driver'=>'memory','single_use'=>true,'stored_nonces'=>count($this->seen),'raw_nonces_serialized'=>false];
	}
}
