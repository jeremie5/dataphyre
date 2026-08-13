<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable actor-to-target authorization scope for authentication operations.
 * Cross-user scopes can only be created from an allowed, named security decision.
 */
final class PanelAuthenticationAccess implements \JsonSerializable {
	public const CROSS_USER_ABILITY='authentication.cross_user';

	private function __construct(
		private readonly string $actorId,
		private readonly string $targetUserId,
		private readonly ?string $elevatedAbility
	){}

	public static function self(string|int $userId): self {
		$user=self::user($userId);
		return new self($user,$user,null);
	}

	public static function forTarget(PanelSecurityContext $context,string|int $targetUserId): self {
		$actor=self::user($context->actorId());
		$target=self::user($targetUserId);
		if(hash_equals($actor,$target)){return new self($actor,$target,null);}
		$decision=PanelSecurityPolicy::make(self::CROSS_USER_ABILITY)
			->permissions(self::CROSS_USER_ABILITY)
			->evaluate($context,['target_user_id'=>$target]);
		return self::elevated($actor,$target,$decision);
	}

	public static function elevated(string|int $actorId,string|int $targetUserId,PanelSecurityDecision $decision): self {
		$actor=self::user($actorId);
		$target=self::user($targetUserId);
		$payload=$decision->jsonSerialize();
		if(hash_equals($actor,$target)){
			throw new PanelAuthenticationOwnershipViolation('Elevated authentication access requires a different target user.');
		}
		if(!$decision->allowed()||($payload['ability']??null)!==self::CROSS_USER_ABILITY){
			throw new PanelAuthenticationOwnershipViolation('Cross-user authentication access requires an explicit allowed authentication.cross_user decision.');
		}
		return new self($actor,$target,self::CROSS_USER_ABILITY);
	}

	public function actorId(): string {return $this->actorId;}
	public function targetUserId(): string {return $this->targetUserId;}
	public function selfService(): bool {return $this->elevatedAbility===null;}
	public function elevatedAccess(): bool {return $this->elevatedAbility!==null;}
	public function elevatedAbility(): ?string {return $this->elevatedAbility;}
	public function allows(string|int $userId): bool {return hash_equals($this->targetUserId,self::user($userId));}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_authentication_access',
			'actor_id'=>$this->actorId,
			'target_user_id'=>$this->targetUserId,
			'self_service'=>$this->selfService(),
			'elevated'=>$this->elevatedAccess(),
			'ability'=>$this->elevatedAbility,
		];
	}

	private static function user(string|int $userId): string {
		$user=trim((string)$userId);
		if($user===''||strlen($user)>190||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D',$user)!==1){
			throw new \InvalidArgumentException('Authentication user id is required.');
		}
		return $user;
	}
}
