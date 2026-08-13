<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Opaque renewable ownership proof for one distributed event subscriber. */
final class PanelCommandFabricSubscriberLease implements \JsonSerializable {
	private function __construct(
		private readonly string $subscriber,private readonly string $worker,private readonly string $token,private readonly int $fence,
		private readonly string $acquiredAt,private readonly string $renewedAt,private readonly string $expiresAt,
	){
		PanelOperationsGuard::name($subscriber,'command fabric subscriber',128);PanelOperationsGuard::identifier($worker,'command fabric subscriber worker',190);
		if(strlen($token)<32||strlen($token)>512||str_contains($token,"\0")){throw new \InvalidArgumentException('Command fabric subscriber lease token is invalid.');}
		if($fence<1){throw new \InvalidArgumentException('Command fabric subscriber lease fence is invalid.');}
		$acquired=PanelOperationsGuard::instant($acquiredAt);$renewed=PanelOperationsGuard::instant($renewedAt);$expires=PanelOperationsGuard::instant($expiresAt);
		if(strcmp($renewed,$acquired)<0||strcmp($expires,$renewed)<=0){throw new \InvalidArgumentException('Command fabric subscriber lease window is invalid.');}
	}

	public static function make(string $subscriber,string $worker,string $token,int $fence,string|int|\DateTimeInterface $acquiredAt,string|int|\DateTimeInterface $expiresAt,string|int|\DateTimeInterface|null $renewedAt=null):self {
		$acquired=PanelOperationsGuard::instant($acquiredAt);return new self($subscriber,$worker,$token,$fence,$acquired,PanelOperationsGuard::instant($renewedAt??$acquired),PanelOperationsGuard::instant($expiresAt));
	}

	public function subscriber():string{return$this->subscriber;}public function worker():string{return$this->worker;}public function token():string{return$this->token;}public function fence():int{return$this->fence;}public function acquiredAt():string{return$this->acquiredAt;}public function renewedAt():string{return$this->renewedAt;}public function expiresAt():string{return$this->expiresAt;}
	public function jsonSerialize():array{return[
		'type'=>'panel_command_fabric_subscriber_lease','version'=>1,'subscriber'=>$this->subscriber,'worker'=>$this->worker,'fence'=>$this->fence,
		'acquired_at'=>$this->acquiredAt,'renewed_at'=>$this->renewedAt,'expires_at'=>$this->expiresAt,'token_exposed'=>false,
	];}
}
