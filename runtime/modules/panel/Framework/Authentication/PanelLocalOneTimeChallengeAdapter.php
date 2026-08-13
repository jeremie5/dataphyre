<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Deterministic local adapter for development and repeatable tests; never serialize this adapter's in-memory codes. */
final class PanelLocalOneTimeChallengeAdapter implements PanelOneTimeChallengeAdapter,\JsonSerializable {
	/** @var array<string,PanelOneTimeChallengeDispatch> */ private array $dispatches=[];
	public function __construct(private readonly string $key='dataphyre-panel-local-challenge',private readonly int $digits=6){ if(strlen($key)<16){throw new \InvalidArgumentException('Local challenge key must contain at least 16 bytes.');} if($digits<6||$digits>8){throw new \InvalidArgumentException('Challenge digits must be between 6 and 8.');} }
	public function dispatch(string $challengeId,string $recipient,string $purpose,int $expiresAt,array $context=[]):PanelOneTimeChallengeDispatch{
		if(trim($recipient)===''||trim($purpose)===''){throw new \InvalidArgumentException('Challenge recipient and purpose are required.');}
		$digest=hash_hmac('sha256',$challengeId."\0".$recipient."\0".$purpose,$this->key,true); $number=unpack('N',substr($digest,0,4))[1]&0x7fffffff; $code=str_pad((string)($number%(10**$this->digits)),$this->digits,'0',STR_PAD_LEFT);
		$dispatch=new PanelOneTimeChallengeDispatch($challengeId,$code,'local',$expiresAt,['recipient_hint'=>self::hint($recipient)]); $this->dispatches[$challengeId]=$dispatch; return $dispatch;
	}
	public function codeFor(string $challengeId):?string{return $this->dispatches[$challengeId]?->code();}
	public function jsonSerialize():array{return ['type'=>'panel_local_one_time_challenge_adapter','dispatch_count'=>count($this->dispatches),'digits'=>$this->digits];}
	private static function hint(string $recipient):string{ if(str_contains($recipient,'@')){[$local,$domain]=explode('@',$recipient,2);return substr($local,0,1).'***@'.$domain;} return '***'.substr($recipient,-2); }
}
