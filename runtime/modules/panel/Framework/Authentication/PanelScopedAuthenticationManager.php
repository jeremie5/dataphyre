<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Owner-bound authentication facade for request and controller boundaries. */
final class PanelScopedAuthenticationManager implements \JsonSerializable {
	public function __construct(
		private readonly PanelAuthenticationManager $manager,
		private readonly PanelAuthenticationAccess $access
	){}

	public function access(): PanelAuthenticationAccess {return $this->access;}
	public function targetUserId(): string {return $this->access->targetUserId();}

	/** @param array<string,mixed> $options */
	public function provisionTotp(string $label='Authenticator',array $options=[]): PanelTotpEnrollment {return $this->manager->provisionTotp($this->targetUserId(),$label,$options);}
	public function confirmTotp(string $factorId,string $code,int $timestamp): PanelAuthenticationDecision {return $this->manager->confirmTotp($factorId,$code,$timestamp,$this->targetUserId());}
	public function verifyTotp(string $code,int $timestamp): PanelAuthenticationDecision {return $this->manager->verifyTotp($this->targetUserId(),$code,$timestamp);}
	public function useRecoveryCode(string $code,?int $now=null): PanelAuthenticationDecision {return $this->manager->useRecoveryCode($this->targetUserId(),$code,$now);}
	public function disableTotp(string $factorId,?int $now=null): bool {return $this->manager->disableTotp($factorId,$now,$this->targetUserId());}
	/** @return list<array<string,mixed>> */ public function factors(): array {return $this->manager->factors($this->targetUserId());}

	/** @param array<string,mixed> $options */
	public function beginChallenge(string $purpose,string $method='totp',array $options=[]): PanelStepUpChallenge {return $this->manager->beginChallenge($this->targetUserId(),$purpose,$method,$options);}
	public function challenge(string $id): ?PanelStepUpChallenge {return $this->manager->challenge($id,$this->targetUserId());}
	public function verifyChallenge(string $challengeId,string $response,?int $now=null): PanelAuthenticationDecision {return $this->manager->verifyChallenge($challengeId,$response,$now,$this->targetUserId());}
	public function cancelChallenge(string $challengeId,?int $now=null): bool {return $this->manager->cancelChallenge($challengeId,$now,$this->targetUserId());}

	/** @param array<string,mixed> $options */
	public function trustDevice(string $label,string $fingerprint,array $options=[]): PanelTrustedDeviceCredential {return $this->manager->trustDevice($this->targetUserId(),$label,$fingerprint,$options);}
	public function verifyTrustedDevice(string $deviceId,string $token,string $fingerprint,?int $now=null): ?PanelTrustedDevice {return $this->manager->verifyTrustedDevice($deviceId,$token,$fingerprint,$now,$this->targetUserId());}
	public function revokeDevice(string $deviceId,?int $now=null): bool {return $this->manager->revokeDevice($deviceId,$now,$this->targetUserId());}
	/** @return list<PanelTrustedDevice> */ public function devices(): array {return $this->manager->devices($this->targetUserId());}
	public function revokeAllDevices(?int $now=null): int {return $this->manager->revokeAllDevices($this->targetUserId(),$now);}

	/** @param array<string,mixed> $options */
	public function createSession(array $options=[]): PanelAuthenticationSessionCredential {return $this->manager->createSession($this->targetUserId(),$options);}
	public function authenticateSession(string $sessionId,string $token,?int $now=null): ?PanelAuthenticationSession {return $this->manager->authenticateSession($sessionId,$token,$now,$this->targetUserId());}
	public function revokeSession(string $sessionId,?int $now=null): bool {return $this->manager->revokeSession($sessionId,$now,$this->targetUserId());}
	/** @return list<PanelAuthenticationSession> */ public function sessions(): array {return $this->manager->sessions($this->targetUserId());}
	public function revokeAllSessions(?string $exceptSessionId=null,?int $now=null): int {return $this->manager->revokeAllSessions($this->targetUserId(),$exceptSessionId,$now);}

	public function jsonSerialize(): array {
		return ['type'=>'panel_scoped_authentication_manager','access'=>$this->access->jsonSerialize(),'capabilities'=>$this->manager->jsonSerialize()['capabilities']];
	}
}
