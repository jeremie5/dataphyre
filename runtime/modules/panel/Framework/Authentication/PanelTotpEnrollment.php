<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One-time enrollment material; JSON intentionally omits secret, URI, and recovery codes. */
final class PanelTotpEnrollment implements \JsonSerializable {
	/** @param list<string> $recoveryCodes */
	public function __construct(private readonly string $factorId,private readonly string $label,private readonly string $issuer,private readonly string $secret,private readonly string $uri,private readonly array $recoveryCodes,private readonly int $digits,private readonly int $period){}
	public function factorId(): string{return $this->factorId;} public function secret(): string{return $this->secret;} public function provisioningUri(): string{return $this->uri;} /** @return list<string> */ public function recoveryCodes(): array{return $this->recoveryCodes;}
	public function jsonSerialize(): array{return ['type'=>'panel_totp_enrollment','factor_id'=>$this->factorId,'label'=>$this->label,'issuer'=>$this->issuer,'digits'=>$this->digits,'period'=>$this->period,'recovery_code_count'=>count($this->recoveryCodes),'contains_one_time_material'=>true];}
}
